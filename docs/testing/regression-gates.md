# Regression Gates — Backend

What must pass before any milestone closes, and before any change merges.

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. The gate — APPROVED

A change that does not pass every applicable gate is not done. **Do not take
shortcuts merely to make a milestone pass.**

---

## 2. Always-on gates — PROPOSED

| # | Gate | Fails when |
| --- | --- | --- |
| G1 | **Install** | Dependencies do not resolve from a clean checkout |
| G2 | **Formatting** | Code is not formatted to the project standard |
| G3 | **Static analysis** | Any issue at the configured level |
| G4 | **Unit tests** | Domain logic regresses |
| G5 | **Integration tests** (real PostgreSQL) | Constraints, transactions or queries regress |
| G6 | **Feature/HTTP tests** | Authorization, validation or response shape regresses |
| G7 | **Contract lint** | `openapi.draft.yaml` is invalid |
| G8 | **Contract coverage** | A route exists that is not in the document |
| G9 | **Migration up/down** | A migration fails to apply or to reverse |
| G10 | **Secret scan** | A credential-shaped string is committed |
| G11 | **Env-file check** | Any `.env` other than `.env.example` is tracked |
| G12 | **Dependency audit** | An unresolved high-severity advisory |

---

## 3. Security gates — APPROVED

These are separated because they are the ones most likely to be quietly skipped.

| # | Gate |
| --- | --- |
| S1 | **Every new or changed endpoint has an authorization test, including the negative case** |
| S2 | **Admin-without-2FA is denied on every admin endpoint**, re-verified whenever an admin route is added |
| S3 | Cross-account access is refused |
| S4 | No sensitive data appears in logs — asserted, not assumed |
| S5 | Rate limiting is applied and tested on every auth and submission endpoint — **including run start and run finish**, and including that a `429` does not consume an idempotency slot |
| S6 | No client-submitted score or progression value is trusted. A `flagged` or `rejected` run mutates **no** progression, ledger, personal best or accepted `run_count` |
| S7 | Error responses leak no stack trace, SQL, or internal detail |
| **S8** | **A completed password reset leaves 2FA enrolment, secret and recovery codes untouched, and the next login is still challenged.** A named gate because this invariant is one careless "clear the account's auth state" refactor away from silent removal. |
| S9 | No achievement progression is adopted from a client summary counter. While **ANTI-6** is open this is checkable structurally: the four `DERIVED_TELEMETRY` columns must **not exist** on `runs` or `player_progression`, and `RunResult.derived_facts` must not be populated |
| S10 | **At most one `active` run per user**, proven by the database refusing the second — not by an application check |

S2 exists as a standing gate rather than a one-time test precisely because
mandatory admin 2FA is the kind of control that breaks when someone adds a route.

---

## 4. Data-integrity gates — APPROVED

| # | Gate |
| --- | --- |
| D1 | Every new constraint is proven by a test that **attempts to violate it** |
| D2 | Every transactional operation has an atomicity test |
| D3 | Every concurrent-access path has a concurrency test |
| D4 | Every idempotent operation has a replay test: same key + same effective request returns the original result with no further side effects, and same key + different effective request is a `409` with none |
| D5 | Every new index is justified by a query, and the plan was checked |
| D6 | Migrations are reversible, or the reason they are not is stated |
| D7 | No schema field persists a queued Loli Bonus beyond its run (`owed_loli_bonuses` must not exist) |
| D8 | `lifetime_loli_activations` counts only bonuses that actually started — never thresholds earned. **Not created until ANTI-6 resolves**; until then the gate is that it does not exist |
| D9 | Audio mute and volume are separate columns; unmute restores the previous non-zero level |
| D10 | The idempotency identity has **no expiry**: a replay arriving arbitrarily late still returns the original result and applies nothing |

---

## 5. Milestone-exit gates — APPROVED

| # | Gate |
| --- | --- |
| M-A | Every acceptance criterion for the milestone is met |
| M-B | OpenAPI and endpoint documents match the implementation |
| M-C | New or changed decisions are recorded; affected ADRs updated |
| M-D | No OPEN decision was implemented; no PROPOSED value presented as approved |
| M-E | The final diff was reviewed for scope creep and regressions |
| M-F | No approved product behavior was changed silently |

---

## 6. Performance gates — PROPOSED, applied from M10

From M10, when the leaderboard exists. The leaderboard's evidence is produced by
`tests/Performance/leaderboard_explain.php` (one million players, run by hand, not in CI) and
recorded in `docs/architecture/data-model.md` §5; P3 is asserted by
`LeaderboardEndpointTest` (query count independent of page size).

| # | Gate | Threshold |
| --- | --- | --- |
| P1 | Leaderboard query latency **at realistic volume** | Within budget, verified against seeded data — not a toy table |
| P2 | Run submission latency | Within budget under concurrent load |
| P3 | No N+1 queries on any list endpoint | Asserted by query-count tests |
| P4 | Query plans reviewed for every new index | No unexpected sequential scan on a hot path |

P1 says "at realistic volume" deliberately: a leaderboard query is fast on a
thousand rows and slow on a million, and the difference only appears in
production unless the test seeds for it.

---

## 7. When a gate fails — APPROVED

Fix the cause. Do not disable the gate, skip the test, retry until green, or
lower the threshold to accommodate the change.

If a gate is genuinely wrong, change it deliberately, in its own change, with the
reason recorded.
