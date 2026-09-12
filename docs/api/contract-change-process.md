# Contract Change Process

The API contract is the **only** integration point between two independent
repositories. This is how it changes without breaking either.

---

## 1. Why this exists — APPROVED

[ADR-0001](../decisions/ADR-0001-separate-frontend-backend-repositories.md)
accepts a specific cost: a change spanning both repositories cannot be atomic.
There is no compiler that sees both sides. This process is what replaces it.

---

## 2. What counts as a contract change — APPROVED

| Change | Breaking? |
| --- | --- |
| New endpoint | No — additive |
| New **optional** response field | No — additive |
| New **optional** request field | No — additive |
| New enum value | **Usually yes** — an existing client may not handle it |
| New error `code` | No — additive, provided clients have a default branch |
| Removing or renaming a field | **Yes** |
| Changing a field's type or nullability | **Yes** |
| Changing semantics without changing shape | **Yes, and the most dangerous** — nothing fails loudly |
| Making an optional field required | **Yes** |
| Changing a status code | **Yes** |
| Tightening validation | **Yes** — previously accepted requests now fail |

The row worth dwelling on is "changing semantics without changing shape". A field
that starts meaning something different passes every type check and breaks
quietly in production.

---

## 3. Additive change — PROPOSED

1. Update `openapi.draft.yaml` and the relevant `endpoints/*.md`.
2. Implement, with tests.
3. Land in the backend.
4. The frontend regenerates its types and adopts the addition when it needs it.

No coordination window required.

---

## 4. Breaking change — PROPOSED

**Expand, migrate, contract.** Never remove and replace in one step.

| Phase | Backend | Frontend |
| --- | --- | --- |
| **1. Expand** | Add the new shape. Keep the old one. Both work. | — |
| **2. Migrate** | — | Adopt the new shape; stop using the old |
| **3. Verify** | Confirm the old shape is unused | — |
| **4. Contract** | Remove the old shape | — |

A breaking change that cannot be expressed this way needs a **new API version**,
not a shortcut. This matters more than it looks: if a native shell ships later, it
will lag the web client, and "all clients update together" stops being true.

---

## 5. Rules — APPROVED

1. **The OpenAPI document and the implementation change in the same commit.** A
   contract documented but not implemented — or implemented but not documented —
   is worse than either alone.
2. **The endpoint document changes too.** OpenAPI carries shape; the endpoint
   documents carry intent, authorization, idempotency and abuse considerations.
3. **The frontend is informed before phase 4**, never after.
4. **Every endpoint exists in the contract.** An undocumented endpoint is invisible
   to the only mechanism keeping the repositories in step.
5. **The contract lints in CI.** A malformed document blocks the merge.
6. **Frontend types are generated, never hand-written.** A contract change then
   surfaces as a build-time type error rather than a runtime surprise.

---

## 6. Checklist for any contract change

- [ ] `openapi.draft.yaml` updated and lints clean
- [ ] `endpoints/*.md` updated — auth requirement, validation intent, idempotency, rate limits, error cases
- [ ] Breaking or additive, explicitly decided and stated in the pull request
- [ ] If breaking: the expand/migrate/contract plan is written down
- [ ] The frontend repository has been informed
- [ ] Tests cover the new shape **and** the old one during the overlap
- [ ] Error codes are stable and documented
- [ ] No new endpoint is missing an authorization requirement

---

## 7. Drift detection — PROPOSED

From M2, when real endpoints exist, generation tooling (Scramble or l5-swagger)
verifies that the implementation matches the document. **Generation supplements
the authored contract; it does not replace it** — the contract must be able to
exist before the code does, which is the entire reason it was authored first.

CI fails on drift.
