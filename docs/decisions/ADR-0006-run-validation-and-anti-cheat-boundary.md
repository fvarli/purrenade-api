# ADR-0006 — Run validation and anti-cheat boundary

- **Status:** 🔴 **Proposed — decision required.** Blocks M9/M10.
- **Scope:** Product-wide
- **Date:** 2026-09-12
- **Decision owner:** Product owner + backend

> **The largest architectural risk in v1.** No approved reference addresses it,
> and a public leaderboard is in scope.

## Context

### The problem

v1 includes **weekly and all-time leaderboards**. The game runs entirely in the
player's browser. Everything that produces a score — the simulation, the timers,
the collision checks, the score accumulator — executes on hardware the player
controls and can modify. A determined player can open the console.

This is not a hypothetical: a public ranking is precisely the incentive that
turns a client-side number into a target.

### What is already decided

- **Score and progression are server-authoritative.** The client proposes; the
  server decides.
- **The frontend is never trusted for authorization.**
- **Client-side obfuscation is not a control.**
- The game domain is **pure, deterministic and portable** —
  ADR-0002 (frontend repository: `purrenade/docs/decisions/ADR-0002-nuxt-phaser-frontend-architecture.md`) — which keeps several
  validation options open.

### The achievement progression authority rule — APPROVED (M0.5)

Added by M0.5 decision closure. It changes what this ADR must deliver.

1. The client may **emit** gameplay events / telemetry.
2. The server **validates** the accepted run.
3. Achievement progression is **derived server-side** from accepted, validated telemetry and
   authoritative persistent data.

A trusted client aggregate capped by plausibility checks is **not** an acceptable final
verification model for achievement progress. Plausibility bounds cap a number; they do not
establish it.

**Consequence: telemetry retention is required, not optional.** Whatever model is chosen below
must retain enough validated event data — or derive enough authoritative run facts at
acceptance — to reproduce achievement progression **without trusting client summary counters**.

#### Two independent questions, two columns

A single classification column conflated two different things. **"Stored in the database" is
not the same as "not derived from telemetry."**

```
  Client events → validated telemetry → authoritative run facts → persistent aggregates
                  └── Verification Source ──┘        └─── Progress Persistence ───┘
```

| Column | Values |
| --- | --- |
| **Verification Source** | `DERIVED_PERSISTENT` — evidence from authoritative stored progression and run records · `DERIVED_TELEMETRY` — evidence computed server-side from accepted run telemetry |
| **Progress Persistence** | `PERSISTED_AGGREGATE` — accumulates across runs in an authoritative counter · `RUN_FACT` — satisfied by a single accepted run's fact |

**Governing rule:** *do not reclassify an achievement as `DERIVED_PERSISTENT` merely because
its cumulative total is stored persistently after derivation.* A lifetime counter built by
incrementing a telemetry-derived run fact has a **telemetry** verification source.

**Seven of the sixteen** proposed achievements have a `DERIVED_TELEMETRY` verification source
and depend directly on this ADR — see
the frontend repository's `docs/product/achievements-and-unlocks.md` §1.5. They
draw on four run-level facts:

| Run-level fact | Feeds |
| --- | --- |
| Obstacle passes by class | `cone_dodger` |
| Near misses | `close_call`, `nerves_of_steel` |
| SLAYYY activations | `slayyy_master`, `slayyy_double` |
| **Actual Loli activations** | `lolis_favourite`, `loli_devotee`, and Sero's character unlock |

The last one is not inferable from the paw ledger: queued bonuses are run-scoped and can expire
unstarted, so a threshold earned is **not** an activation. See
the frontend repository's `docs/product/achievements-and-unlocks.md` §1.5A.

**The retention design remains PROPOSED. The authority rule is APPROVED.** RNG-2 (is an input
log submitted?) and DM-1 (does `run_events` exist?) are therefore **no longer optional
questions**.

#### Retention latitude — explicit

**Do not assume raw events must be retained forever.** The authority rule constrains *what must
be establishable*, not *what must be stored*. The implementation may:

> **Validate raw telemetry at run acceptance and persist compact authoritative derived run
> facts, discarding the raw events**, where that satisfies replay, audit and security
> requirements.

That is the **data-minimizing** option, and it is explicitly permitted rather than merely
unprohibited. Retained per-event data is behavioural personal data with a retention obligation
(SEC-5), so the burden falls on retention, not on discarding.

| Option | Satisfies the authority rule? | Retention cost |
| --- | --- | --- |
| Validate at acceptance, persist derived run facts, discard raw events | **Yes**, if the derived facts are sufficient for audit and dispute | Lowest |
| Retain raw events for a bounded window, then reduce to derived facts | Yes | Medium — the window is the decision |
| Retain raw events indefinitely | Yes | Highest, and hardest to defend under KVKK |
| Accept client summary counters | **No** | — |

Choosing among the first three is **ANTI-5**. The fourth is not available.

> **Privacy consequence.** Retained per-event gameplay data is **behavioural personal data**
> and carries a retention obligation that lands on the still-OPEN deletion/retention policy
> (SEC-3). See the backend's `docs/security/data-protection.md`.

### What is not decided

**How the server decides whether a submitted run is real**, and **what validated event data is
retained** to satisfy the authority rule above.

### The coupled question

This is entangled with **PWA-1: is a run playable offline?** An offline-capable
run cannot be gated by a server-issued run token, and its timing cannot be bound
to server-observed boundaries. The two must be decided together.

## Options

### Option 1 — Statistical plausibility bounds

The server checks the submission against derived limits: maximum score per
second (peak speed × ×2 × maximum collectible density), maximum paws per second,
score-versus-duration consistency, run-versus-player history.

- **For:** simple; no client changes; cheap; catches naive tampering immediately.
- **Against:** a careful cheater submits plausible-but-false results. Bounds
  constrain the ceiling, not the truth.

### Option 2 — Server-issued run token

`POST /game-runs` starts a run server-side, returning a token, a seed and a
start timestamp. `POST /game-runs/{run}/finish` must present the token.

- **For:** bounds run duration against server-observed time; makes the RNG seed
  server-controlled, so the spawn sequence is known to the server; prevents
  fabricated runs that never started; enables per-run idempotency naturally.
- **Against:** **requires connectivity to start a run** (PWA-1); a cheater can
  still lie about what happened *within* a legitimately started run.

### Option 3 — Telemetry replay

The client submits the seed plus a compressed input log. The server re-runs the
**same pure domain** and computes the score itself.

- **For:** by far the strongest. The client's claimed score becomes irrelevant —
  the server derives the score from inputs. Cheating requires producing an input
  sequence that genuinely survives the generated patterns, which is playing the
  game.
- **Against:** the domain must run server-side. In PHP that means either
  reimplementing it (**two implementations that must not diverge** — a serious
  ongoing hazard) or running the TypeScript domain in a sidecar. Adds payload
  size and CPU cost per submission.

### Option 4 — Full server-authoritative simulation

The server simulates in real time and the client is a thin input/render layer.

- **Against:** disproportionate for a casual runner. Requires persistent
  connectivity and real-time infrastructure, and foreclosed offline play entirely.

## Recommendation — PROPOSED, not decided

**Layer 2 + 1 for v1, with the domain kept portable so 3 remains available.**

1. **Server-issued run token** with a **server-issued seed**, bounding run
   duration and spawn sequence against server-observed reality.
2. **Plausibility bounds** on the submitted result, derived from the approved
   tuning values rather than guessed.
3. **Anomalies are flagged, not silently dropped.** A flagged run is recorded and
   excluded from ranking pending review; the player is told honestly.
4. **Idempotent submission** so retries never double-count.
5. **The pure domain stays portable** so Option 3 can be added later without
   rearchitecting — this is the main reason the engine boundary in
   ADR-0002 (frontend repository: `purrenade/docs/decisions/ADR-0002-nuxt-phaser-frontend-architecture.md`) is worth its cost.

**Explicitly not recommended:** reimplementing the game rules in PHP. Two
implementations of a rule set that must agree exactly is a defect generator.

## Decisions still required

| # | Question |
| --- | --- |
| 1 | Which layers ship in v1 |
| 2 | **PWA-1 — is a run playable offline?** Decides whether Option 2 is even available |
| 3 | Is the RNG seed server-issued? (RNG-1) |
| 4 | Is an input log recorded and submitted? (RNG-2) — payload size vs future replay capability. **No longer optional:** the authority rule requires some validated event retention |
| 4b | What validated event data is retained, in what form, and for how long (interacts with SEC-3 and SEC-5). Validating at acceptance and keeping only derived run facts is an **explicitly permitted** answer. |
| 5 | What happens to a flagged run: hidden, held for review, or rejected outright |
| 6 | Is there an appeal path, and who reviews |
| 7 | Rate limits on run submission per player |
| 8 | Whether the tutorial is exempt (it must be — it submits nothing) |
| 9 | Whether near-miss, obstacle-pass, SLAYYY-activation and **Loli-activation** events are retained per-event or reduced to authoritative per-run counts at acceptance |

## Consequences

**If decided well:** the leaderboard means something, and the architecture keeps
a stronger option in reserve.

**If deferred:** M9 and M10 build a ranking whose integrity is unknown, and
retrofitting validation after runs are already recorded means deciding what to do
with a table of unverifiable history.
