# Engineering Standards — Backend

Implementation quality is a **first-class requirement**, not a follow-up task.

---

## 1. The standard — APPROVED

Use current best practices for the selected framework/runtime versions and the
problem domain. For **every** technical decision, evaluate:

correctness · maintainability · readability · testability · separation of
concerns · security · performance · observability · accessibility ·
localization · scalability where justified · developer experience ·
deployment/operations impact · backward compatibility · failure handling ·
data integrity · concurrency and race conditions · idempotency where relevant ·
API consistency · mobile/browser constraints · future Android/iOS compatibility.

- **Do not take shortcuts merely to make a milestone pass.**
- **Do not over-engineer speculative features.** Leave clean extension points
  only where the approved product direction clearly requires them.

When several approaches are technically valid, prefer the one that is **simpler
to operate, easier to test, and easier to understand**, unless there is a strong
product, security, or performance reason to choose otherwise.

---

## 2. Backend-specific expectations — APPROVED

| Expectation | What it means here |
| --- | --- |
| **Idiomatic Laravel** | Follow framework conventions rather than building a parallel structure |
| **Thin controllers** | No business logic buried in them |
| **Explicit validation** | At the boundary, in form requests, with documented error codes |
| **Server-side authorization** | On every request, on the specific resource — not merely on the role |
| **Database constraints in addition to application validation** | Integrity survives application bugs |
| **Transactions where integrity requires them** | Run acceptance + progression is one unit |
| **Race-safe progression and run completion** | Atomic operations, not read-modify-write |
| **Idempotency where relevant** | Run submission above all |
| **PostgreSQL-native schema and index decisions** | Use the database, do not emulate it in PHP |
| **Careful indexing based on actual query patterns** | Verified against realistic volumes, not toy data |
| **Queues only where they provide real value** | A queue is a cost paid for a reason |
| **Structured logging with correlation IDs** | And **no sensitive data in logs** |
| **Consistent, documented API responses and errors** | One envelope, stable codes |
| **OpenAPI in sync with the implementation** | A contract change updates both, plus the frontend |
| **Migrations reversible where practical** | |
| **Tests for authorization, validation, edge cases and abuse cases** | Abuse cases are not optional in a product with a public leaderboard |

---

## 3. Definition of done — APPROVED

- [ ] Behavior matches the written Product/Game Specification; nothing approved changed silently
- [ ] No OPEN decision was implemented; no PROPOSED value was presented as approved
- [ ] Authorization enforced server-side and tested, including the negative cases
- [ ] Validation explicit; error responses use the documented envelope and codes
- [ ] Database constraints added alongside application validation
- [ ] Transactions and concurrency considered; race conditions tested where relevant
- [ ] Idempotency handled where the operation must not double-apply
- [ ] Indexes justified by an actual query pattern
- [ ] No sensitive data reaches logs
- [ ] OpenAPI and endpoint documentation updated in the same change
- [ ] Tests added, including **abuse cases**
- [ ] Regression gates pass
- [ ] Migration reversible, or the reason it is not is stated
- [ ] Final diff reviewed for scope creep and regressions

---

## 4. Code review focus — PROPOSED

In rough order of how often each catches a real problem in this codebase:

1. **Missing or resource-blind authorization** — a role check where an ownership
   check was needed.
2. **Read-modify-write on a counter** — the paw ledger is the sharpest case.
3. **Work outside a transaction that belongs inside it**, or a queue dispatch
   inside one.
4. **A constraint expressed only in application code.**
5. **Trusting a client-supplied value** — score, paw count, unlock, identity.
6. **An index added without a query, or a query added without an index.**
7. **Sensitive data in a log line, a job payload, or an exception message.**
8. **Contract drift** — a response shape changed without the OpenAPI document.
9. **An endpoint that is not in the contract at all.**

---

## 5. Anti-patterns — APPROVED

| Anti-pattern | Why it is rejected here |
| --- | --- |
| Business logic in controllers | Untestable, unreusable, invisible to review |
| Validation only in the application | An application bug becomes corrupt data |
| Trusting the client for score or progression | The public leaderboard makes this exploitable by design |
| Reimplementing the game rules in PHP | Two implementations that must agree exactly is a defect generator. See [ADR-0006](../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md). |
| A cache as the source of truth | Not durable, not auditable, not deletable |
| "We'll add the index when it gets slow" | It gets slow in production, on the leaderboard, under load |
| Catching an exception to make a test pass | Hides the failure rather than fixing it |
| Logging a whole request body while debugging | The single most common way sensitive data escapes |
| An endpoint added without a contract update | Breaks the only integration point between the repositories |
