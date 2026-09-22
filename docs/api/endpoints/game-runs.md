# Endpoints — Game Runs

**The most security-sensitive surface in the product.** This is where the client
asks the server to change durable, publicly ranked state.

**The validation model is DECIDED** —
[ADR-0006](../../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md) was accepted
on 2026-09-22. Layer 1 + Layer 2 ship in v1. Contract shape only; neither endpoint is
implemented yet (M9).

---

## Summary

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| POST | `/game-runs` | authenticated + verified | no | submission |
| POST | `/game-runs/{runId}/finish` | authenticated + verified | **yes, required** | submission |

---

## Start a run — APPROVED (ADR-0006)

**A run is created server-side before gameplay begins.** The call yields three things
nothing else provides:

1. a **server-recorded start time**, bounding run duration against observed reality;
2. a **server-issued RNG seed** (RNG-1), making the spawn sequence known to the server;
3. an **opaque run identity** a finish call must reference, so a run that never started
   cannot be finished.

The cost was accepted: **a run cannot begin offline** (PWA-1). Once a run has started, a
transient connectivity loss does **not** destroy it — the player continues the already-started
run locally, and the finish submission is retried when connectivity returns under the same
idempotency identity. Starting a brand-new authoritative normal run fully offline is out of
scope for v1.

### No separate run token — APPROVED

A run is identified by an **opaque `run_id`** bound to the **authenticated actor**, with its
state owned server-side. There is **no `run_token`**: the threat model establishes no property
one would add, and a browser-held run secret cuts against
[`../../security/threat-model.md`](../../security/threat-model.md) §4, which defends XSS on the
grounds that the browser holds no portable credential. The actor is always resolved from the
credential, never from the body.

### One active run, and resume — APPROVED (GR-4)

**One authenticated user may hold at most one active run.** The invariant is enforced by a
**database constraint**, not a read-then-check query — see
[`../../architecture/data-model.md`](../../architecture/data-model.md) §3.

| Situation | Response |
| --- | --- |
| No active run | **`201`** with a newly created run |
| An active run already exists | **`200`** with **that same run** — same `run_id`, same `seed`, same `started_at` |

Starting never creates a second run. Returning the existing one is deterministic resume, and
it is what a player who reloads after a connectivity drop needs. A **maximum active-run
lifetime** exists so the slot cannot be held indefinitely; its value is an M9 implementation
parameter.

**The tutorial is not an authoritative normal run** and does not occupy the slot.

---

## Finish a run — APPROVED

### The core rule — APPROVED

**The client proposes; the server decides.** The request carries the client's
*proposed* score and paw count. The response carries the **authoritative** values,
and the client displays those — not its own.

### Idempotency — APPROVED (GR-3)

`Idempotency-Key` is **required**.

| Rule | Detail |
| --- | --- |
| A repeat with the same key returns the **original response** and performs no further work | Retries, flaky networks and double taps are safe |
| The same key with a **different** effective request is a `409`, not a silent overwrite | A changed request means a client bug or an attack |
| Enforced by a **unique database constraint** on `(user_id, idempotency_key)` | Not by an application check |
| Detecting "different" is enforced by a stored **request fingerprint** | Without one, the `409` rule has nothing to compare against |
| A rate-limit refusal must **not** consume the idempotency slot | Otherwise a `429` would poison a legitimate retry |

Without this, a retried submission double-counts paws, double-unlocks
achievements, and inflates the leaderboard.

**The identity lives with the durable run history — there is no cleanup window.** A short
expiry would let an old retry arrive after the key was forgotten and apply progression a
second time, which is the exact failure the key exists to prevent. Retention follows the run
record itself (DM-3, SEC-3).

**No `idempotency_keys` table is created at M9.** The identity lives on the run record, which
is the simpler model and needs no second source of truth. Generalizing idempotency to other
endpoints remains **API-5**, and would be what reintroduces that table.

### Transaction — APPROVED

An accepted run updates, **atomically**:

1. the run record,
2. the paw ledger — `lifetime_paws` and `loli_cycle_paws` with **overflow
   preserved** and one Loli Bonus per completed threshold. **`queuedLoliBonuses` is not
   persisted:** the Loli Bonus is a run-scoped reward, not a bankable currency, and all queue
   state ends with the run,
3. achievement progress and any unlocks,
4. character unlock evaluation,
5. best score and run count.

A partial application is a corrupted account.

**Only an `accepted` run does any of this.** A `flagged` or `rejected` run mutates none of it
— see Outcomes below.

At M9, steps 3 and 4 are inert: achievements and character unlocks arrive at M11, and the
counters several of them need are blocked on **ANTI-6**.

### Concurrency — APPROVED

The paw ledger is updated with an **atomic database operation**, never
read-modify-write in application code. Two tabs finishing runs simultaneously is
a real scenario, and read-modify-write loses one of them.

### Validation — APPROVED (ADR-0006)

| Layer | Effect |
| --- | --- |
| **Server-owned run identity** | A run that never started cannot be finished; the finish must reference an `active` run owned by the authenticated actor |
| **Structural validation** | Protocol, identity, lifecycle and tuning-independent impossibilities |
| **Plausibility bounds** | Score-per-second, paws-per-second and score-versus-duration, derived from tuning values that are still **PROPOSED** |
| **Outcome** | `accepted`, `flagged`, or `rejected` |

#### Rejection versus flagging — APPROVED (ANTI-4)

A PROPOSED tuning value is never promoted to APPROVED in order to obtain a rejection
threshold, so the two kinds of failure are kept apart. Full rules in
[`../../security/anti-cheat.md`](../../security/anti-cheat.md) §3A.

| May **reject** | May only **flag** |
| --- | --- |
| Malformed or protocol-invalid requests | Score per second |
| Identity and ownership violations | Paws per second |
| Impossible lifecycle transitions — the run is not `active`, or was never started | Score-versus-duration consistency |
| Structurally impossible values — non-positive or non-integer duration, out-of-domain values, `finished_at` before `started_at` | Deviation from the player's own history |

> **A legitimate player's run is never rejected on an unresolved tuning number.**

**Duration is not a locked invariant.** A client-measured duration is never authoritative
merely because it is plausible; but because a started run may finish locally and submit later,
`server receive time − started_at` is an **upper bound only**, never the duration. The
derivation and its tolerance are M9 implementation parameters.

#### How each outcome is answered

| Failure | Response |
| --- | --- |
| Protocol, identity or lifecycle failure | **4xx** `application/problem+json` — `403`, `404`, `409` or `422`. No run result is produced. |
| Well-formed but structurally impossible | **`200`** with `status: rejected`. Recorded as a rejected attempt; no progression. |
| Anomalous against a tuning-dependent bound | **`200`** with `status: flagged` |
| Valid | **`200`** with `status: accepted` |

#### Outcome side effects — APPROVED (ANTI-2, GR-1)

| | `accepted` | `flagged` | `rejected` |
| --- | --- | --- | --- |
| Durable run history | yes | retained, with the minimum metadata explaining the classification | minimum attempt/audit information only |
| Progression mutation | yes | **no** | no |
| Paw ledger mutation | yes | **no** | no |
| Personal best | yes | **no** | no |
| Accepted `run_count` | yes | **no** | no |
| Future leaderboard eligibility | yes | **no** | no |
| Achievement / unlock progression | yes | **no** | no |

A flagged run is recorded and **excluded from ranking and from progression**, and the player
is told honestly that the result was not accepted for competitive or progression purposes.
Runs are never silently dropped, never silently accepted, and a rejection is **never silently
converted** into an acceptance or a flag. **There is no automatic later promotion from
`flagged` to `accepted`.**

**Review and appeal — APPROVED (ANTI-3, GR-2).** M13 may introduce administrative review
tooling. M9 requires no actively serviced review queue, and **no player appeal workflow is
included in M9**.

Player-facing copy for all three outcomes, and for each flag-reason class, ships in
**tr / en / es** (ADR-0007).

### What is never trusted — APPROVED

- the proposed score,
- the proposed paw count,
- the client-measured duration, where a server-recorded start exists,
- any client-asserted achievement or unlock,
- **any client-reported summary counter used for achievement progression** — near misses,
  obstacle passes, SLAYYY activations,
- the player identity in the body — the actor is resolved from the credential.

### The trust boundary is structural, not prose — APPROVED

The request carries **only untrusted input**, namespaced and prefixed so the boundary is visible
in the schema itself:

```
FinishRunRequest.telemetry.reported_*     ← UNTRUSTED. Hints. May be ignored entirely.
RunResult.derived_facts.*                 ← AUTHORITATIVE. Server-computed. Not suppliable.
```

The flow is **client telemetry/events → server validation → authoritative derived run facts →
persistent aggregates**. The authoritative numbers live in the **response**, where a client
cannot provide them at all — `loli_activations`, `near_miss_count`, `lane_blocking_passes` and
`slayyy_activations` are returned, never accepted.

Naming is deliberate: the request says `reported_loli_activations`, the response says
`loli_activations`. An implementer who reads only field names still cannot confuse the two.

**At M9 the authoritative side is empty by design.** Layers 1 and 2 can bound those numbers
but cannot establish them, and Layer 3 is deferred — so rather than adopt client counters in
violation of the rule above, M9 returns none of them. See the boundary below and
[`../../security/anti-cheat.md`](../../security/anti-cheat.md) §8.

### What M9 does not establish — OPEN (ANTI-6)

Four run-level facts depend on **what the player did**, not on what the world generated.
Layers 1 and 2 cannot establish them; only Layer 3, or an equivalent separately approved
trustworthy mechanism, can — and Layer 3 is deferred beyond v1.

| Run-level fact | Feeds |
| --- | --- |
| Obstacle passes by class | `cone_dodger` |
| Near misses | `close_call`, `nerves_of_steel` |
| SLAYYY activations | `slayyy_master`, `slayyy_double` |
| **Actual Loli activations** | `lolis_favourite`, `loli_devotee`, and Sero's unlock |

The APPROVED authority rule is unchanged, so while no such mechanism exists these four
**must not be promoted from client-reported aggregates into authoritative
`DERIVED_TELEMETRY` progression facts.** Therefore, at M9:

- `RunResult.derived_facts` is **not returned**, and is not part of the required response;
- the four matching `reported_*` telemetry hints are **not consumed and not persisted**;
- the four `runs` columns and the four `player_progression` lifetime counters are **not
  created** — see [`../../architecture/data-model.md`](../../architecture/data-model.md);
- no achievement or unlock progression is derived from a run.

**ANTI-6 blocks M11 only.** The shape below is retained as the intended contract for whichever
milestone resolves it.

### Telemetry submitted with a run — shape retained, blocked on ANTI-6

The **achievement authority rule** requires achievement progression to be *derived* server-side
from accepted, validated telemetry rather than adopted from client totals. The finish payload
therefore carries **inputs to derivation, never authoritative totals**:

| Field | Feeds | Note |
| --- | --- | --- |
| Near-miss events | `close_call`, `nerves_of_steel` | Deterministic, at most one per obstacle, **awards no score** |
| Obstacle passes by class | `cone_dodger` | A collision **neither increments nor resets** that obstacle's pass — it simply does not count |
| SLAYYY activations | `slayyy_master`, `slayyy_double` | A player action, not derivable from the ledger |
| **Loli activations** | `lolis_favourite`, `loli_devotee`, **Sero's unlock** | **Actual activations only** — the ENTERING/ACTIVE transition. **Never inferred from the paw ledger**, and never adopted from `reported_loli_activations`. |
| `queuedLoliBonuses` peak | Telemetry only | **Run-scoped.** Never persisted as progression, never carried to a future run. |

#### `loliActivations` is not the threshold count — APPROVED

The accepted run exposes **`loliActivations`** as an authoritative derived fact. It counts
bonuses that **actually started**, not thresholds earned.

| Concept | Counted here? |
| --- | --- |
| Threshold earned (200 paws crossed) | **No** — that is the paw ledger |
| Queued but never started, because the run ended | **No** |
| Actually started (ENTERING/ACTIVE) | **Yes** |

Queued bonuses are run-scoped and discarded at run end, so the two numbers genuinely differ.
Crediting thresholds as activations would award bonuses the player never saw — wrong on its
own, and exploitable on a public leaderboard.

**ANTI-5 is resolved: data minimization.** No `run_events` table is created and **no raw
per-event gameplay history is retained**. Validation happens at submission time, and only the
authoritative run record, compact authoritative derived facts, the minimum validation metadata
and the approved progression state are persisted. Any future raw-event retention is a separate
privacy/retention decision (SEC-3, SEC-5).

**GR-5 follows from that:** these do not arrive as per-event records. The principle stands
unchanged — a count the server derives from validated data is acceptable; a count the client
simply asserts is not — which is precisely why none of the four is established at M9.

Retained per-event data would be **behavioural personal data** with a retention obligation —
see [`../../security/data-protection.md`](../../security/data-protection.md) §2A. Not
retaining it is the smaller surface.

### The M9 finish payload — APPROVED

Only the compact untrusted hints Layer 1 actually consumes:

| Field | Trust |
| --- | --- |
| `reported_duration_ms` | Untrusted hint |
| `reported_score` | Untrusted hint |
| `reported_run_paws` | Untrusted hint |

**No input log (RNG-2).** The client submits no gameplay input trace; that belongs to the
deferred Layer 3 design. The seed is never client-supplied — the server issued it and stored
it against the run.

### Tutorial — APPROVED

**The tutorial submits nothing.** It touches no score, no leaderboard, and no
progression data. There is no tutorial variant of these endpoints.

---

## Rate limiting — APPROVED

Run submission has its own limit class, applied to **both** start and finish. A player cannot
legitimately finish runs faster than runs take to play, which makes the limit both safe and a
useful abuse signal.

| Rule | Detail |
| --- | --- |
| **User-scoped** primary control | The account is what is being protected |
| **IP** as secondary abuse defence | Never the primary control for an authenticated surface |
| Normal mobile retry behaviour stays practical | A player on a flaky connection must not be locked out of their own result |
| An idempotent retry creates no duplicate state | And a `429` does not consume the idempotency slot |

**Numeric values are an M9 implementation parameter**, derived from the existing conventions
and expected legitimate start/retry behaviour, and they live in the central configuration —
never as controller literals. Tests must cover enforcement. See
[`../../security/rate-limiting.md`](../../security/rate-limiting.md).

---

## Open questions

| Ref | Question |
| --- | --- |
| ~~ADR-0006~~ | **Accepted 2026-09-22.** Layer 1 + Layer 2 in v1; Layer 3 deferred. |
| ~~PWA-1~~ | **Resolved.** Connectivity required to start; a started run survives transient loss. |
| ~~RNG-1~~ | **Resolved.** The seed is server-issued. |
| ~~RNG-2~~ | **Resolved.** No input log is submitted or retained in v1. |
| ~~GR-1~~ | **Resolved.** A `flagged` run is retained and excluded from ranking and progression, and the player is told honestly. |
| ~~GR-2~~ | **Resolved.** No appeal workflow and no actively serviced review queue in M9; M13 may add review tooling. |
| ~~GR-3~~ | **Resolved.** No retention window — the identity lives with the durable run history (also resolves API-3 for run submission). |
| ~~GR-4~~ | **Resolved.** At most one active run per user, enforced by a database constraint; starting again resumes it. |
| ~~GR-5~~ | **Resolved.** Not per-event: no raw event history is retained. The four facts they feed are blocked on **ANTI-6**. |
| **ANTI-6** | **How the four `DERIVED_TELEMETRY` run facts are established.** **Blocks M11**, not M9 or M10. |
| API-5 | Is idempotency generalized beyond run submission? Would reintroduce an `idempotency_keys` table. |
