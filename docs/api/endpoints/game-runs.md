# Endpoints — Game Runs

**The most security-sensitive surface in the product.** This is where the client
asks the server to change durable, publicly ranked state.

**Contract shape only.** The validation model is OPEN — see
[ADR-0006](../../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md).

---

## Summary

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| POST | `/game-runs` | authenticated + verified | no | submission |
| POST | `/game-runs/{run}/finish` | authenticated + verified | **yes, required** | submission |

---

## Start a run — OPEN (ADR-0006)

Whether runs are started server-side **at all** depends on the validation model
and on whether runs are playable offline (**PWA-1**).

If adopted, starting a run server-side yields three things nothing else provides:

1. a **server-recorded start time**, bounding run duration against observed reality;
2. a **server-issued RNG seed**, making the spawn sequence known to the server;
3. a **run identity** that a finish call must reference, so a run that never
   started cannot be finished.

The cost is equally concrete: **a run cannot begin offline.**

---

## Finish a run — APPROVED in principle

### The core rule — APPROVED

**The client proposes; the server decides.** The request carries the client's
*proposed* score and paw count. The response carries the **authoritative** values,
and the client displays those — not its own.

### Idempotency — APPROVED

`Idempotency-Key` is **required**.

| Rule | Detail |
| --- | --- |
| A repeat with the same key returns the **original response** and performs no further work | Retries, flaky networks and double taps are safe |
| The same key with a **different body** is a `409`, not a silent overwrite | A changed body means a client bug or an attack |
| Enforced by a **unique database constraint** | Not by an application check |

Without this, a retried submission double-counts paws, double-unlocks
achievements, and inflates the leaderboard.

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

### Concurrency — APPROVED

The paw ledger is updated with an **atomic database operation**, never
read-modify-write in application code. Two tabs finishing runs simultaneously is
a real scenario, and read-modify-write loses one of them.

### Validation — OPEN

Per ADR-0006, the **proposed** layering is:

| Layer | Effect |
| --- | --- |
| **Run token** (if adopted) | A run that never started cannot be finished; duration is bounded by server-observed time |
| **Plausibility bounds** | Score-per-second, paws-per-second, and score-versus-duration checked against limits **derived from the approved tuning values**, not guessed |
| **Outcome** | `accepted`, `flagged`, or `rejected` |

**Flagged runs are recorded and excluded from ranking pending review**, and the
player is told honestly. They are never silently dropped and never silently
accepted.

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

### Telemetry submitted with a run — APPROVED in principle, shape PROPOSED

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

**Whether these arrive as per-event records or as counts derived at acceptance is OPEN
(ANTI-5)** — a count the server derives from validated data is acceptable; a count the client
simply asserts is not.

Retained per-event data is **behavioural personal data** and carries a retention obligation —
see [`../../security/data-protection.md`](../../security/data-protection.md) §2A.

### Tutorial — APPROVED

**The tutorial submits nothing.** It touches no score, no leaderboard, and no
progression data. There is no tutorial variant of these endpoints.

---

## Rate limiting — APPROVED

Run submission has its own limit class. A player cannot legitimately finish runs
faster than runs take to play, which makes the limit both safe and a useful abuse
signal. See [`../../security/rate-limiting.md`](../../security/rate-limiting.md).

---

## Open questions

| Ref | Question |
| --- | --- |
| ADR-0006 | The validation model itself |
| PWA-1 | Are runs playable offline? Decides whether a run token is available |
| RNG-1 | Is the seed server-issued? |
| RNG-2 | Is an input log submitted? |
| GR-1 | What a `flagged` run means for the player: hidden, held, or rejected |
| GR-2 | Is there an appeal path, and who reviews |
| GR-3 | Idempotency key retention window (API-3) |
| GR-4 | May a player hold two runs open at once? |
| GR-5 | Do near-miss, obstacle-pass, SLAYYY-activation and Loli-activation events arrive per-event, or as counts derived at acceptance? (ANTI-5) — the latter is explicitly permitted |
