# Backend Architecture

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. Responsibility — APPROVED

The backend is the **authoritative boundary** for:

- who the player is (authentication),
- what the player may do (authorization),
- what actually happened (score and progression),
- what is durable (all persistent data).

The browser client is **untrusted by design**. It proposes; the server decides.

---

## 2. Layering — PROPOSED

```
  HTTP boundary
    Route → Middleware → Form Request (validation) → Controller (thin)
                                                          │
                                                          ▼
                                              Application service / action
                                                          │
                                     ┌────────────────────┼────────────────────┐
                                     ▼                    ▼                    ▼
                              Domain services       Repositories          Events / Jobs
                                                          │
                                                          ▼
                                                     PostgreSQL
```

| Layer | Responsibility | Must not |
| --- | --- | --- |
| **Middleware** | Authentication, correlation ID, rate limiting, locale | Contain business rules |
| **Form Request** | Explicit validation and authorization checks at the boundary | Perform writes |
| **Controller** | Translate HTTP to an application call and back. **Thin.** | Contain business logic |
| **Application service / action** | One use case, one transaction boundary | Know about HTTP |
| **Domain service** | Rules that span entities — threshold evaluation, unlock evaluation, validation | Know about HTTP or queues |
| **Repository / model** | Persistence | Contain use-case orchestration |
| **Job** | Deferred work that is genuinely deferrable | Hold rules that must be transactional |

### 2.1 Thin controllers — APPROVED

**No business logic buried in controllers.** A controller validates (via a form
request), calls one application service, and shapes the response. If a controller
contains a conditional about game rules, progression, or authorization, it is in
the wrong place.

---

## 3. Request lifecycle — PROPOSED

| Step | Detail |
| --- | --- |
| 1. Correlation | Accept or generate a correlation ID; attach it to the logging context and echo it in the response |
| 2. Locale | Read the request locale; use it for any player-facing message |
| 3. Rate limit | Apply the endpoint class's limit before authentication where appropriate, and after it where the limit is per-account |
| 4. Authenticate | Per [ADR-0005](../decisions/ADR-0005-authentication-and-2fa-strategy.md) |
| 5. Enforce 2FA | For admin-scoped routes, **unconditionally** |
| 6. Validate | Form request; fail with the documented error envelope |
| 7. Authorize | Policy check on the specific resource, not merely on the role |
| 8. Execute | One application service, one transaction boundary |
| 9. Respond | Documented shape, stable error codes, rate-limit headers |

---

## 4. Transactions — APPROVED

**Transactions wherever data integrity requires them.** The canonical case is run
submission, which must atomically:

1. record the run result,
2. update the paw ledger (`lifetimePaws`, `loliCyclePaws` with overflow),
3. advance achievement progress and record any unlocks,
4. evaluate character unlocks,
5. update the player's best score and run count.

A partial application of that set is a corrupted account.

### 4.1 Rules — PROPOSED

| Rule | Reason |
| --- | --- |
| One transaction per use case, opened in the application service | Not in the controller, not in the model |
| **No HTTP calls, no queue dispatch inside a transaction** | Dispatch after commit; otherwise a job can observe a state that was rolled back |
| Keep transactions short | They hold locks; a long transaction under concurrent submission is a stall |
| Prefer database-level atomicity (`ON CONFLICT`, atomic updates) over read-modify-write | Read-modify-write is the classic lost-update bug |

---

## 5. Idempotency — APPROVED

Operations that must not double-apply carry an idempotency key. The clearest case
is run submission: retries, flaky networks and double taps must never
double-count paws or double-unlock an achievement.

See [`../api/api-conventions.md`](../api/api-conventions.md) for the header
contract and [`data-model.md`](data-model.md) for the storage of keys.

---

## 6. Concurrency — APPROVED

The paw ledger is the sharpest concurrency surface in the product: it is
read-modify-write with a threshold that consumes 200 and preserves overflow, and
it can be hit by two tabs at once.

| Hazard | Mitigation |
| --- | --- |
| Lost update on the ledger | Atomic database-level update, or row lock inside the transaction. Never read-then-write in application code. |
| Double threshold trigger | The bonus count is derived from the ledger's atomic result, not computed separately |
| Duplicate achievement unlock | Unique constraint on `(player, achievement)`; the insert is idempotent |
| Duplicate run submission | Idempotency key with a unique constraint |
| Two concurrent runs on one account | **Impossible by construction (GR-4):** a partial unique index allows one active run per user; start inserts with `ON CONFLICT … WHERE status = 'active' DO NOTHING` and a concurrent start resumes the winner. Lock order RUN → PLAYER_PROGRESSION → PAW_LEDGER everywhere — see `RunLifecycleService` and [`../security/anti-cheat.md`](../security/anti-cheat.md) §3B. |

---

## 7. Domain purity — PROPOSED

Game **rules** live in the frontend's pure domain. The backend does **not**
reimplement them.

This matters: two implementations of the same rule set that must agree exactly
is a defect generator. If run validation ever needs to recompute a score, the
strong option is to run the existing portable domain — not to rewrite it in PHP.
See [ADR-0006](../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md).

What the backend **does** own: whether a submitted result is plausible, what is
persisted, and what the authoritative values become.

---

## 8. Error handling — APPROVED

Consistent and documented. One envelope, stable machine-readable codes, no stack
traces or internal details in responses. See
[`../api/api-conventions.md`](../api/api-conventions.md).

---

## 9. Configuration — APPROVED

- Configuration comes from the environment; **no secret is committed**.
- `.env.example` documents shape, never values.
- Anything that differs between environments is configuration, not a constant.

---

## 10. Open questions

| Ref | Question |
| --- | --- |
| BA-1 | Whether an event-driven internal design is warranted, or direct service calls suffice at this scale (PROPOSED: direct calls; events only where a genuine fan-out exists) |
| BA-2 | Whether the admin surface is a separate route group in this application or a separate application |
| ~~BA-3~~ | **Resolved at M10:** inside the finish transaction, for the affected rows only (`data-model.md` §5). |
