# ADR-0006 — Run validation and anti-cheat boundary

- **Status:** **Accepted (2026-09-22).** M9 and M10 unblocked.
- **Scope:** Product-wide
- **Date:** 2026-09-12
- **Decision owner:** Product owner + backend

> **The largest architectural risk in v1.** No approved reference addressed it,
> and a public leaderboard is in scope. Decided in full below; the one
> consequence left open is **ANTI-6**, which blocks M11 only.

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

### What this ADR had to decide

**How the server decides whether a submitted run is real**, and **what validated event data is
retained** to satisfy the authority rule above. Both are answered in [Decision](#decision).

### The coupled question

This is entangled with **PWA-1: is a run playable offline?** An offline-capable
run cannot be gated by a server-owned run lifecycle, and its timing cannot be bound
to server-observed boundaries. The two had to be decided together, and they were —
see decisions 1 and 2 below.

## Options — the alternatives considered

Recorded as they were weighed. The option adopted, and the form it was adopted in,
is in [Decision](#decision).

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

## Decision

**Layer 1 + Layer 2 ship in v1. Layer 3 is deferred beyond v1, and the domain stays
portable so it remains available. Layer 4 stays rejected.**

### The adopted model

| # | Decision | Register |
| --- | --- | --- |
| 1 | **Layers 1 and 2 ship in v1.** Layer 1 is structural and gameplay plausibility validation. Layer 2 is server-issued run identity, a server-recorded start, a server-issued seed, and a server-owned run lifecycle. **Layer 3 deterministic replay is deferred beyond v1**; the pure domain stays free of browser APIs and ambient state so adding it later remains a deployment decision, not a rewrite. **No duplicate PHP simulation of the game rules** is written to obtain it. Layer 4 remains rejected as disproportionate. | ANTI-1 |
| 2 | **A normal authoritative run requires connectivity to start.** The server creates the run before gameplay begins. Once started, **transient connectivity loss must not destroy the run**: the player continues the already-started run locally, and the finish submission may be retried when connectivity returns under the **same stable idempotency identity**. Starting a brand-new authoritative normal run fully offline is **out of scope for v1**. The tutorial stays isolated and submits no run. | PWA-1 |
| 3 | **The seed for an authoritative normal run is server-issued.** The frontend initializes the deterministic run domain from that seed. A browser-generated seed is never authoritative. | RNG-1 |
| 4 | **No gameplay input log is submitted or retained in v1.** It belongs to the deferred Layer 3 design. A finish submission carries only the compact untrusted hints the adopted validation model actually consumes. | RNG-2 |
| 5 | **Data minimization.** No `run_events` table is created, and no raw per-event gameplay history is retained merely because it may be useful later. Validation happens at submission time, and only the authoritative run record, compact authoritative derived facts, the minimum validation metadata needed to explain a classification, and the approved progression/ledger state are persisted. Any future raw-event retention is a separate privacy/retention decision. | ANTI-5 · DM-1 · GR-5 |
| 6 | **Structural rejection is separated from tuning-dependent flagging** — see below. PROPOSED tuning values are **not** promoted to APPROVED in order to obtain a rejection threshold. | ANTI-4 |
| 7 | **Three explicit outcomes** — `ACCEPTED`, `FLAGGED`, `REJECTED` — with the side effects fixed below. | ANTI-2 · ANTI-3 · GR-1 · GR-2 |
| 8 | **One active authoritative normal run per user**, enforced as a database invariant. | GR-4 |
| 9 | **The idempotency identity lives with the durable run history.** No cleanup window. | GR-3 · API-3 |
| 10 | **A dedicated authenticated submission rate-limit policy exists** for run start and finish. Its existence and semantics are decided here; only its numeric values are selected during M9 planning. | — |

### Run identity — no separate run token

Layer 2 is **server-owned run identity**, not a second browser-held secret. A run is
identified by an **opaque `run_id`**, bound to the **authenticated actor** resolved from
the credential, with its state owned server-side.

The PROPOSED `run_token` field is **not adopted**, because the threat model establishes no
property it would add. [`threat-model.md`](../security/threat-model.md) §4 answers *forged
score* with server-authoritative validation, *replayed submission* with the idempotency
key's unique constraint, and *two concurrent runs* with what is now decision 8. A run that
never started cannot be finished because no `active` row exists for that actor; a guessed
or borrowed `run_id` fails the ownership check; the seed is never client-supplied.

That same document defends session-cookie theft via XSS precisely on the grounds that **the
browser holds no bearer token**, so "a script injection cannot exfiltrate a portable
credential". A run token held in JavaScript is exactly that shape. And because §5 already
assumes the client is modified, a secret handed to the client defends nothing against the
client.

If a future client topology establishes a concrete need, the token returns through an
explicit decision that names the threat, the generation and binding, and the transport.

### Structural rejection versus tuning-dependent flagging

**REJECT** — only impossibilities provable independently of unresolved tuning **and of delayed
submission**:

- malformed or protocol-invalid requests;
- identity and ownership violations;
- impossible lifecycle transitions — finishing a run that is not `active`, or not owned by the
  caller;
- structurally impossible values: non-positive or non-integer durations, values outside the
  representable domain, `finished_at` before `started_at`.

**FLAG ONLY** — every gameplay plausibility anomaly whose bound depends on still-PROPOSED
tuning: score per second, paws per second, score-versus-duration consistency, and deviation
from the player's own history. **A legitimate player's run must never be rejected on an
unresolved tuning number.** Later approval of tuning may make validation policy stricter, but
only through an explicit decision, never silently.

#### Duration is a trust principle here, not a formula

The existing rule stands: **a client-measured duration is never authoritative merely because
it is plausible.**

But decision 2 makes an already-started run finishable locally and submittable later, so
`server receive time − started_at` is an **upper bound only** — never the gameplay duration,
and the gap is unbounded in the entirely legitimate case of a player who finishes in a dead
spot and submits when connectivity returns. This ADR therefore **does not lock a duration
invariant**, because locking one now would encode an arithmetic that the connectivity decision
has just made wrong.

The authoritative duration derivation, the clock-skew and late-submission tolerance, how long
after `started_at` a finish may legitimately arrive, and whether a claimed duration exceeding
the server-observed upper bound rejects or flags, are **M9 implementation parameters** —
constrained by the rule above that nothing tuning-dependent may reject.

### Outcome semantics

| | `ACCEPTED` | `FLAGGED` | `REJECTED` |
| --- | --- | --- | --- |
| Durable run history | yes | retained, with the minimum metadata explaining the classification | minimum attempt/audit information only |
| Progression mutation | yes | **no** | no |
| Paw ledger mutation | yes | **no** | no |
| Personal best | yes | **no** | no |
| Accepted `run_count` | yes | **no** | no |
| Future leaderboard eligibility | yes | **no** | no |
| Achievement / unlock progression | yes | **no** | no |
| The player is told honestly | yes | yes — that the result was not accepted for competitive or progression purposes | yes — an explicit response |

**Never silently accept, never silently discard, and never silently convert a rejection into
an acceptance or a flag.** There is **no automatic later promotion from `FLAGGED` to
`ACCEPTED`.** M13 may introduce administrative review tooling; M9 requires no actively
serviced human review queue, and no player appeal workflow is included in M9.

### One active run, and idempotency

**One authenticated user may hold at most one active authoritative normal run.** The invariant
is enforced in the database, not by a read-then-check application query. Starting while an
active run exists returns **that same run** — deterministic resume, never a second run — which
is also what a player who reloads after a connectivity drop needs. A maximum active-run
lifetime exists so the single slot cannot be held indefinitely; its value is an M9
implementation parameter. The tutorial is not an authoritative normal run and does not occupy
the slot.

For run finish, `(user_id, idempotency_key)` stays database-enforced unique and the identity
lives with the durable run history. **There is no short cleanup window**, so an old retry can
never apply progression a second time. The same key with the **same** effective request
returns the original authoritative result with zero additional side effects; the same key with
a **different** effective request is a `409` with zero additional side effects.

### What is deliberately not built at M9 — ANTI-6

Layers 1 and 2 can bound a number; **they cannot establish one that depends on what the player
did.** Four run-level facts — obstacle passes by class, near misses, SLAYYY activations and
actual Loli activations — are exactly that, and only Layer 3 or an equivalent separately
approved trustworthy derivation mechanism can establish them.

The APPROVED authority rule above is **unchanged**: a trusted client aggregate capped by
plausibility checks is not an acceptable final verification model. It follows directly that,
while no such mechanism exists, those four facts **must not be promoted from client-reported
aggregates into authoritative `DERIVED_TELEMETRY` progression facts**. So M9 does not create
their columns, does not return them, and does not accumulate them. Recording them early would
breach the trust boundary at the outset and buy nothing, because a counter accumulated from an
untrustworthy source would have to be discarded the moment a real mechanism arrived.

This is recorded as **ANTI-6**, an OPEN decision **blocking M11 only**. It blocks neither M9
nor M10. Choosing between adding Layer 3, designing another server-verifiable mechanism, and
changing the affected achievement and unlock behaviour is a product decision for the M11
architecture decision, and is **not** pre-empted here.

## Implementation parameters for M9 planning

These are engineering parameters, not open product decisions. Selecting them does not reopen
anything above.

| Parameter | Constraint |
| --- | --- |
| Submission rate-limit values | Derived from the existing rate-limit conventions and expected legitimate start/retry behaviour. User-scoped primary control, IP as secondary abuse defence; normal mobile retry must stay practical; idempotent retries must not create duplicate state. Tests must cover enforcement. |
| Plausibility bound values | Derived once the relevant tuning values are APPROVED. Until then the bounds they feed are flag-only. |
| Duration derivation and tolerance | See above. Nothing tuning-dependent may reject. |
| Maximum active-run lifetime | Long enough never to end a legitimate run; short enough that the single slot cannot be held indefinitely. |
| OpenAPI → TypeScript generator wiring | The contract generation path is M9 engineering foundation work, not a separate milestone. |

## Consequences

**Enabled.** M9 is unblocked. The leaderboard rests on a server-owned run lifecycle with a
server-issued seed rather than on a number the browser chose, and the architecture keeps the
stronger option in reserve.

**Constrained.** A normal run cannot begin without connectivity. The pure domain must stay
portable — free of browser APIs and ambient state — or Layer 3 stops being a deployment
decision and becomes a rewrite. The `runs` table carries an `active` state and the invariant
that goes with it.

**Deferred, visibly.** M11's telemetry-derived achievements and Sero's unlock have no
verification source in v1. That is recorded as ANTI-6 rather than quietly resolved by
accepting client counters.

**Follow-on work this creates.** The frontend seed source at
the frontend repository's `app/composables/useRunSurface.ts` is still browser-generated via `Math.random()`; its own
comment says the change lands "here and nowhere else" once RNG-1 settles. RNG-1 has now
settled, and that change is M9 implementation work.

**If this had been deferred:** M9 and M10 would have built a ranking whose integrity is
unknown, and retrofitting validation after runs are already recorded means deciding what to do
with a table of unverifiable history.
