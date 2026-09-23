# Testing Strategy — Backend

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. Principle — APPROVED

**Tests for authorization, validation, edge cases and abuse cases.**

The last is not optional here. A product with a public leaderboard and
server-authoritative scoring is defined as much by what it refuses as by what it
accepts, and a suite that only tests happy paths tests the half that was never at
risk.

---

## 2. Layers — PROPOSED

| Layer | Runs against | Proves |
| --- | --- | --- |
| **Unit** | Domain services, pure logic | Threshold arithmetic, validation rules, plausibility bounds |
| **Integration** | Services against a **real PostgreSQL** | Constraints, transactions, concurrency, query behavior |
| **Feature / HTTP** | The API through its real middleware stack | Authorization, validation, response shape, error codes |
| **Contract** | The OpenAPI document against the implementation | No drift between contract and code |

**Integration tests run against real PostgreSQL, never SQLite.** Divergence
between development and production SQL is precisely where constraint and
concurrency bugs hide — and concurrency bugs are this product's hardest class.

---

## 3. What must be tested — APPROVED

### Authorization — the negative cases matter most

| Test | Assertion |
| --- | --- |
| Cross-account access | Player A cannot read or modify Player B's run, session, or profile |
| Role escalation | A player cannot reach any admin endpoint |
| **Admin without satisfied 2FA** | **Denied on every admin endpoint, by any route** |
| Unauthenticated access | Every protected endpoint returns `401` |
| Unverified access | Gated endpoints are refused |
| Existence disclosure | Another player's resource returns `404`, not `403` |
| Deny-by-default | A route with no policy is denied |

### Data integrity

| Test | Assertion |
| --- | --- |
| Paw overflow | `198 + 5` → one bonus, cycle becomes `3` |
| Multiple thresholds | A gain crossing 200 twice triggers twice |
| Cycle range | `loli_cycle_paws` never leaves `0..199` — enforced by a check constraint |
| Achievement idempotency | Unique `(player, achievement)`; a re-submitted run never double-unlocks |
| **No persisted Loli queue** | No schema field, and no code path, carries a queued Loli Bonus beyond the run that earned it |
| **Activation ≠ threshold earned** | A run that earns a threshold and ends before the queued bonus starts yields `loli_activations` that excludes it |
| **Audio mute is independent of volume** | Setting a volume, muting, then unmuting restores the previous non-zero volume, not silence |
| **Retired counters are absent** | `lifetime_score`, `max_loli_bonus_in_single_run` and `accepted_run_count_above_min_duration` exist in no migration or model |
| Transaction atomicity | A failure mid-submission leaves **no** partial progression |
| Constraint enforcement | Each constraint is proven by an attempt that must fail |

### Concurrency — PROPOSED

| Test | Assertion |
| --- | --- |
| Concurrent run submissions for one player | No lost update on the paw ledger |
| Concurrent threshold crossings | Bonuses are not double-counted or dropped |
| Concurrent achievement unlocks | Exactly one unlock row |
| Parallel idempotent submissions with the same key | Exactly one applies; the other returns the original response |

These are written deliberately, not hoped for. A read-modify-write on the ledger
passes every single-threaded test and fails in production the first time a player
has two tabs open.

**How, since M9.** Two complementary layers:

- **Interleavings inside the feature suite** — two callers holding one snapshot, replayed in
  sequence inside the rolled-back test transaction. Cheap, and enough for a conditional
  `UPDATE`.
- **Real connections in `tests/Concurrency/`** — its own PHPUnit suite, run after Feature and
  **not** wrapped in `RefreshDatabase`: it commits, and truncates the test schema around each
  test. Each request runs through the real HTTP stack in its own PHP process
  (`tests/Concurrency/worker.php`), orchestrated by `Tests\Support\RunWorkers`. A test-only
  trigger parks one tagged request mid-transaction on an advisory lock at a chosen write, and
  the orchestrator proceeds only once `pg_stat_activity` shows the other request actually
  waiting — never on a sleep. A deadlock would surface as a `500` and fails the test. This is
  how the run lifecycle's partial-index inference, row-lock waits and READ COMMITTED re-select
  are proven, and it runs on PostgreSQL 18 in CI as well.

### Abuse cases — APPROVED

| Test | Assertion |
| --- | --- |
| Inflated score submission | Rejected or flagged by plausibility bounds |
| Impossible duration for the submitted score | Rejected or flagged |
| Replayed submission | Returns the original response; nothing double-applies |
| Same idempotency key, different body | `409`, never a silent overwrite |
| Finishing a run that never started | Refused (if the run-token model is adopted) |
| Finishing another player's run | Refused |
| Client-asserted achievement or unlock | Ignored entirely |
| Rate-limit exhaustion | `429` with a retry signal |
| Login enumeration | Identical response and comparable timing for unknown and wrong |
| Forgot-password enumeration | Identical response regardless of existence |
| Recovery-code replay | Refused |
| **Password reset does not touch 2FA** | After a completed reset: enrolment intact, secret unchanged, recovery codes unconsumed, **and the next login is still challenged** |
| Client-asserted achievement counters | **Ignored.** Progression is derived from validated data, never adopted from the payload |
| Inflated near-miss, obstacle-pass or activation counts | Rejected or flagged; never accepted as authoritative progression |
| **Thresholds earned credited as Loli activations** | **Refused.** A threshold crossed whose bonus never started must not increment `lifetime_loli_activations`, and must not advance Sero's unlock |
| TOTP replay within one time step | Refused |

### Authentication flows

Happy and failure paths for each: registration, login, verification (including
expiry, wrong code, attempt limits, resend cooldown), password reset (including
expired, reused and tampered tokens), 2FA enrolment, challenge, and disable —
including the admin `403`.

---

## 4. Contract testing — PROPOSED

| Test | Assertion |
| --- | --- |
| OpenAPI lints | The document is valid |
| Every route appears in the document | An undocumented endpoint fails CI |
| Response shapes match the documented schemas | No drift. **Implemented at M9** for every run and progression response plus `/auth/me` (`tests/Feature/Runs/RunContractTest.php`, `Tests\Support\OpenApiContract`): an undeclared member is a failure, which is how a leaked ANTI-6 field would be caught |
| Error responses use the documented envelope and codes | Clients branch on codes |

---

## 5. What is deliberately not tested here — APPROVED

| Not here | Where |
| --- | --- |
| Game rules | The frontend's pure domain. **The backend does not reimplement them** — see [ADR-0006](../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md). |
| UI behavior | Frontend |
| Whether the game feels good | Playtesting |

---

## 6. Conventions — PROPOSED

- Each test creates its own data; no shared mutable fixture.
- No test depends on wall-clock time; time is injected.
- No test reaches an external service; email and any third party are faked at the
  boundary.
- Factories produce **valid** data by default; invalid data is explicit in the test.
- A flaky test is fixed or deleted, never retried into green.
- Test names state the behavior, including the refusal:
  *"an admin without 2FA cannot list players"*.
