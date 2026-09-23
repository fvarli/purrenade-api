# Anti-Cheat / Run Validation Boundary

**Status: the model is DECIDED** —
[ADR-0006](../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md) was **accepted
on 2026-09-22**. **Layer 1 + Layer 2 ship in v1**; Layer 3 is deferred beyond v1 with the
domain kept portable. One consequence remains open as **ANTI-6** (§8), and it blocks M11
only.

---

## 1. The problem — APPROVED

**The game runs entirely in the player's browser.** Simulation, timers, collision
and the score accumulator all execute on hardware the player controls and can
modify.

v1 includes **weekly and all-time leaderboards**. A public ranking is exactly the
incentive that turns a client-side number into a target — and the most common
adversary is not sophisticated, it is a curious player who found a variable in
devtools.

**No approved reference addresses this.** It is the largest architectural risk in
v1.

---

## 2. What is already settled — APPROVED

| Rule | |
| --- | --- |
| **Score and progression are server-authoritative.** The client proposes; the server decides. | |
| **The frontend is never trusted** for authorization or for score truth. | |
| **Client-side obfuscation is not a control.** It raises effort slightly and proves nothing. | |
| **Submission is idempotent**, enforced by a unique database constraint. | |
| **The game domain is pure, deterministic and portable** — which keeps the strongest validation option available later. | |
| **Client-reported summary counters alone are insufficient for authoritative achievement progression.** | |

### 2.1 The achievement progression authority rule — APPROVED (M0.5)

1. The client may **emit** gameplay events / telemetry.
2. The server **validates** the accepted run.
3. Achievement progression is **derived server-side** from accepted, validated telemetry and
   authoritative persistent data.

A trusted client aggregate capped by plausibility checks is **not** an acceptable final
verification model for achievement progress. **Plausibility bounds cap a number; they do not
establish it** — and with a public leaderboard attached, "capped but unverified" is still a
number a determined client can choose.

#### Two independent questions, two columns

**"Stored in the database" is not the same as "not derived from telemetry."**

```
  Client events → validated telemetry → authoritative run facts → persistent aggregates
                  └── Verification Source ──┘        └─── Progress Persistence ───┘
```

| Column | Values |
| --- | --- |
| **Verification Source** | `DERIVED_PERSISTENT` — evidence from authoritative stored progression and run records · `DERIVED_TELEMETRY` — evidence computed server-side from accepted run telemetry |
| **Progress Persistence** | `PERSISTED_AGGREGATE` — accumulates across runs · `RUN_FACT` — satisfied by a single accepted run's fact |

**Governing rule:** *do not reclassify an achievement as `DERIVED_PERSISTENT` merely because
its cumulative total is stored persistently after derivation.*

**This makes telemetry retention required, not optional.** **Seven of the sixteen** proposed
achievements have a `DERIVED_TELEMETRY` verification source, drawing on four run-level facts:

| Run-level fact | Feeds |
| --- | --- |
| Obstacle passes by class | `cone_dodger` |
| Near misses | `close_call`, `nerves_of_steel` |
| SLAYYY activations | `slayyy_master`, `slayyy_double` |
| **Actual Loli activations** | `lolis_favourite`, `loli_devotee`, and Sero's unlock |

The last is **not inferable from the paw ledger**: queued Loli bonuses are run-scoped and can
expire unstarted, so a threshold *earned* is not an *activation*.

**The retention design is now decided (ANTI-5): data minimization, and no `run_events` table.**
The authority rule is unchanged and still APPROVED.

> **These four facts are not established in v1.** Layers 1 and 2 can bound them but cannot
> establish them, and Layer 3 is deferred. Rather than adopt client counters in violation of
> the rule above, M9 persists and returns **none** of them. That consequence is tracked as
> **ANTI-6** — see §8.

#### The contract enforces this structurally

| Location | Trust |
| --- | --- |
| `FinishRunRequest.telemetry.reported_*` | **Untrusted.** Hints only; the server may ignore them entirely. |
| `RunResult.derived_facts.*` | **Authoritative.** Server-computed, returned not accepted — a client cannot supply these fields. |

At M9 the second row is **empty by design**: the server computes no derived telemetry fact,
because it cannot establish one (§8). The namespace stays, so that when a mechanism exists the
boundary is already in the right shape.

Untrusted input is namespaced and prefixed so the boundary survives a reader who skips the
prose. An implementer cannot accidentally persist a client total, because the client total and
the authoritative fact do not share a name or a location.

#### Retention latitude — explicit

**Do not assume raw events must be retained forever.** The rule constrains what must be
*establishable*, not what must be *stored*. The implementation may **validate raw telemetry at
run acceptance and persist compact authoritative derived run facts, discarding the raw
events**, where that satisfies replay, audit and security requirements.

| Option | Satisfies the authority rule? | Retention cost |
| --- | --- | --- |
| Validate at acceptance, keep derived run facts, discard raw events | **Yes**, if the derived facts suffice for audit and dispute | Lowest |
| Retain raw events for a bounded window, then reduce | Yes | Medium — the window is the decision |
| Retain raw events indefinitely | Yes | Highest, and hardest to defend under KVKK |
| Accept client summary counters | **No** | — |

The first option is the **data-minimizing** one and is explicitly permitted, not merely
unprohibited.

> **Privacy consequence.** Retained per-event gameplay data is **behavioural personal data**
> and carries a retention obligation that lands on the still-OPEN deletion/retention policy
> (SEC-3). See [data-protection.md](data-protection.md) §2A.

---

## 3. The layers — APPROVED (ADR-0006)

### Layer 1 — Structural and plausibility validation — **ships in v1**

The server checks a submission against limits that are **derived, not guessed**. Most of
the tuning they derive from is still **PROPOSED**, which is exactly why §3A separates what
may reject from what may only flag:

| Bound | Derived from |
| --- | --- |
| Maximum score per second | Peak scroll speed × ×2 SLAYYY multiplier × maximum collectible density |
| Maximum paws per second | Spawn density ceiling and the Loli magnet radius |
| Score-versus-duration consistency | The difficulty curve's soft caps |
| SLAYYY activations per run | Charge rate versus run duration |
| Loli activations per run | Paw threshold versus paws collected |
| Deviation from the player's own history | A sudden 10× is a signal, not proof |

Bounds constrain the ceiling. They do not establish truth: a careful cheater
submits plausible-but-false results. That limit is why §2.1 still holds and why §8 exists.

**Which of these reject and which only flag is decided in §3A**, and depends on whether the
bound can be stated without an unresolved tuning value.

### Layer 2 — Server-owned run identity and seed — **ships in v1**

`POST /game-runs` starts the run server-side, returning an **opaque `run_id`**, a
**server-issued seed** (RNG-1) and a **server-recorded start time**. The run's lifecycle
is owned by the server: a run exists in an `active` state, bound to the authenticated
actor, before any gameplay happens.

**There is no separate `run_token`.** The threat model establishes no property one would
add beyond *authenticated actor + opaque `run_id` + server-side ownership and state*, and
a browser-held run secret cuts against [`threat-model.md`](threat-model.md) §4, which
defends XSS on the grounds that the browser holds no portable credential. See ADR-0006,
"Run identity — no separate run token".

| Gains | |
| --- | --- |
| Run duration is bounded by server-observed time, not client claim | |
| The **spawn sequence is known to the server**, because the seed is | |
| A run that never started cannot be finished | |
| Per-run idempotency falls out naturally | |

**Cost: a run cannot begin offline.** This is the same decision as **PWA-1**, and it was
taken: a normal authoritative run **requires connectivity to start**. Once started,
transient connectivity loss does not destroy it — the player continues locally and the
finish submission is retried under the same idempotency identity. Starting a brand-new
authoritative run fully offline is out of scope for v1.

### Layer 3 — Telemetry replay — **deferred beyond v1; kept available**

The client submits the seed and a compressed input log; the server re-runs **the
same pure domain** and derives the score itself.

This is by far the strongest option, because the client's claimed score becomes
irrelevant. Cheating would require producing an input sequence that genuinely
survives the generated patterns — which is playing the game.

**Why it is not v1:** the domain must run server-side. In PHP that means either
reimplementing it — **two implementations of the same rules that must agree
exactly**, a defect generator — or running the TypeScript domain in a sidecar,
which is real infrastructure. Plus payload size and per-submission CPU.

**Why it stays available:** the domain is deliberately kept free of browser APIs
and ambient state. Adding Layer 3 later is a deployment decision, not a rewrite. **Keeping
it that way is now a constraint, not a preference** — §8 depends on it.

**No input log is submitted or retained in v1 (RNG-2).** A finish submission carries only the
compact untrusted hints Layer 1 actually consumes. The input log belongs to this deferred
design.

---

## 3A. Structural rejection versus tuning-dependent flagging — APPROVED (ANTI-4)

A PROPOSED tuning value is **never** promoted to APPROVED in order to obtain a rejection
threshold. The two kinds of failure are therefore kept apart.

### May REJECT

Only impossibilities provable independently of unresolved tuning **and of delayed
submission**:

| Class | Examples |
| --- | --- |
| Malformed or protocol-invalid | Schema violation; missing `Idempotency-Key` |
| Identity and ownership | The run is not owned by the authenticated actor |
| Impossible lifecycle transition | Finishing a run that is not `active`; finishing one that was never started |
| Structurally impossible values | Non-positive or non-integer duration; values outside the representable domain; `finished_at` before `started_at` |

### May only FLAG

Every gameplay plausibility anomaly whose bound depends on still-PROPOSED tuning — score per
second, paws per second, score-versus-duration consistency, and deviation from the player's
own history.

> **A legitimate player's run must never be rejected on an unresolved tuning number.**

Later approval of tuning may make validation policy stricter, but only through an **explicit
decision**, never silently.

### Duration is a trust principle here, not a formula

The existing rule stands: **a client-measured duration is never authoritative merely because
it is plausible.**

But PWA-1 makes an already-started run finishable locally and submittable later. So
`server receive time − started_at` is an **upper bound only** — never the gameplay duration,
and the gap is unbounded in the entirely legitimate case of a player who finishes in a dead
spot and submits when connectivity returns. No duration invariant is locked here, because
locking one would encode an arithmetic the connectivity decision has already made wrong.

**M9 implementation parameters**, not open product decisions: the authoritative duration
derivation; the clock-skew and late-submission tolerance; how long after `started_at` a
finish may legitimately arrive; and whether a claimed duration exceeding the server-observed
upper bound rejects or flags once that tolerance model exists — constrained by the rule above
that nothing tuning-dependent may reject. **Resolved at M9 — see §3B.**

---

## 3B. The M9 validation model — IMPLEMENTED (M9 engineering parameters)

`App\Services\Runs\RunValidator`, a pure classifier (no database, clock or HTTP), bounds in
`config/game_runs.php`. Each rule is labelled with what kind of number it rests on.

### Time

- `started_at` — server clock, at creation. `finished_at` — server clock, at **finalisation**
  (receive time), explicitly **not** asserted to be the end of play.
- `validation_meta.window_ms = finished_at − started_at` — the server's own upper bound on
  play time.
- `duration_ms` — the **claimed** simulated play time (the frontend's `elapsedMs` excludes the
  ready beat, pauses and dropped catch-up steps, so it can only be ≤ wall time). Kept once it
  passes the structural check; used only by flag-only checks; never a clock fact.
- **`RUN_DURATION_TOLERANCE_MS = 5000`** — deliberate slack against server clock steps (NTP).
  It only loosens the rejection below.
- **Late arrival is never a reason to flag.** A finish on a run that is still `active` is
  classified however late it arrives.

### Rules

| Order | Code | Outcome | Condition | Basis |
| --- | --- | --- | --- | --- |
| 0 | — | **422** | a telemetry member missing or not a JSON **integer** (string, float incl. `12.0`, bool, null, object, beyond int64) | protocol (C-7) |
| 1 | `value_out_of_domain` | **reject** | any member `< 0` or `> 2147483647` | STRUCTURAL — the stored domain. Evaluated alone. |
| 2 | `duration_non_positive` | **reject** | `duration == 0` | STRUCTURAL — ADR "non-positive durations" |
| 2 | `duration_exceeds_server_window` | **reject** | `duration > window_ms + 5000` | STRUCTURAL — simulated time cannot exceed server wall time |
| 2 | `score_below_paw_floor` | **reject** | `score < 10 × run_paws` | STRUCTURAL — `perPaw` is APPROVED and LOCKED at 10; every multiplier ≥ 1 |
| 3 | `score_rate_high` | flag | `score / s > 80` | PROPOSED speed and spawn tuning (theoretical max ≈ 78.6) |
| 3 | `paw_rate_high` | flag | `paws / s > 3` | PROPOSED peak spawn ≤ 2.08/s |
| 3 | `score_below_duration_floor` | flag | `score / s < 5` | half the PROPOSED `distancePerSecond` 10 |
| 3 | `duration_below_minimum` | flag | `duration < 4000 ms` | below the PROPOSED fastest three-heart loss (≈ 4900 ms) |

Every hit in a class is recorded; a rejected run is not also evaluated for flags. Rates are
compared in integer arithmetic. **Not implemented:** deviation from the player's own history —
there is no approved basis, and it would flag legitimate improvement.

`validation_meta` keeps `{v: 1, window_ms, rules: [{code, observed, bound, field?}]}` — the
minimum needed to explain the classification, never raw events.

### What a rejected or flagged run keeps

| | `accepted` | `flagged` | `rejected` |
| --- | --- | --- | --- |
| `status`, `finished_at`, `validation_meta`, key, fingerprint, `result` | yes | yes | yes |
| `score`, `run_paws`, `duration_ms` on the row | yes | yes | **null** (the observed values are in `validation_meta`) |
| Progression, ledger, best score, `run_count` | **mutated** | untouched | untouched |

### `RUN_STALE_REPLACEMENT_AFTER = 24 h` — how the "maximum active-run lifetime" is realised

**Not an expiry.** There is no scheduler and no time-based transition. An active run nobody
touches stays `active` indefinitely and its finish stays acceptable. The threshold acts only
when the **same player calls start**:

- run started less than 24 h ago → start **resumes** it (200);
- 24 h or older → start **may replace** it — marking it `rejected` with rule
  `run_stale_replaced` and creating the new run **in one transaction** — but only when the new
  run can actually be created. A start that asks for an unavailable character is refused with
  `422 character_unavailable` and the stale run stays active and untouched (C-11).

A finish from another device that arrives after a replacing start gets `409 run_not_active`.
Why 24 h: it bounds seed reuse through repeated resume to one day, and it never replaces a
legitimately pending finish from the same session, because the client resolves its pending
finish before it starts.

### Start and character (C-11)

1. **Shape first:** `character_id` must be a JSON string matching `^[a-z0-9_]{1,32}$` →
   otherwise `422`, even when an active run exists. No catalogue check here.
2. **Recovery beats availability:** a non-stale active run is returned unchanged with its own
   original character, whatever key was requested — locked or unknown included.
3. **Availability only on creation:** `characters.key = ? AND is_starter AND
   artwork_available`, a plain unlocked read inside the start transaction, after the run
   lock. Not found → rollback, `422 character_unavailable`.

The requested character is untrusted input and only a preference for a new run. Knowing
another character's key gains nothing: it is either irrelevant (recovery) or refused
(creation).

### Lock order and races

Global order **RUN → PLAYER_PROGRESSION → PAW_LEDGER**; start never locks progression; the
progression row is ensured by an autocommitted statement before either transaction opens. Start
re-issues its locked active-run select once when it comes back empty, so under READ COMMITTED a
run committed while it waited is resumed rather than missed. The races are exercised on real
concurrent connections in `tests/Concurrency/RunConcurrencyTest.php`.

---

## 4. Outcomes — APPROVED (ANTI-2, ANTI-3, GR-1, GR-2)

Every submission resolves to exactly one of three:

| Status | Meaning |
| --- | --- |
| `accepted` | Passed validation |
| `flagged` | Anomalous but not conclusively invalid |
| `rejected` | Conclusively invalid |

### What each one does

| | `accepted` | `flagged` | `rejected` |
| --- | --- | --- | --- |
| Durable run history | yes | retained, with the minimum metadata explaining the classification | minimum attempt/audit information only |
| Progression mutation | yes | **no** | no |
| Paw ledger mutation | yes | **no** | no |
| Personal best | yes | **no** | no |
| Accepted `run_count` | yes | **no** | no |
| Future leaderboard eligibility | yes | **no** | no |
| Achievement / unlock progression | yes | **no** | no |
| The player is told | yes | yes — honestly, that the result was not accepted for competitive or progression purposes | yes — an explicit response |

**Three rules — APPROVED:**

1. **Never silently accept** a run that failed validation.
2. **Never silently discard** one. A player whose legitimate run was flagged by a
   false positive must be able to find out, or the product is quietly lying to
   them.
3. **Never silently convert** a rejection into an acceptance or a flag.

False positives are certain. Layer 1 is statistical, and an exceptional
legitimate run looks like a modest cheat. That is why the tuning-dependent bounds may only
flag (§3A).

**There is no automatic later promotion from `flagged` to `accepted`.** A flagged run keeps
only the **minimum validation metadata** needed to explain the classification — structured
rule codes and the observed-versus-bound value — never raw events.

**Review and appeal.** M13 may introduce administrative review tooling for flagged runs. M9
does **not** require an actively serviced human review queue, and **no player appeal workflow
is included in M9**.

**Player-facing copy** for all three outcomes, and for each flag-reason class, ships in
**tr / en / es** like every other player-facing string (ADR-0007).

---

## 4A. Run lifecycle invariants — APPROVED (GR-3, GR-4)

### One active run per user

**One authenticated user may hold at most one active authoritative normal run.** The
invariant is enforced as a **database constraint**, not by a read-then-check application
query — a partial unique index on the owner for rows in the `active` state. See
[`../architecture/data-model.md`](../architecture/data-model.md) §3.

Starting while an active run exists returns **that same run** — same identity, same seed,
same `started_at`. Deterministic resume, never a second run. That is also what a player who
reloads after a connectivity drop needs.

A **maximum active-run lifetime** exists so the single slot cannot be held indefinitely. M9
realises it as `RUN_STALE_REPLACEMENT_AFTER = 24 h`, applied only when the same player starts
again — never as an expiry. See §3B.

**The tutorial is not an authoritative normal run** and does not occupy the slot.

### Idempotency lives with the run

`(user_id, idempotency_key)` is database-enforced unique on the run record, and the
identity lives with the **durable run history**. **There is no short cleanup window**, so an
old retry can never apply progression a second time.

| Case | Result |
| --- | --- |
| Same key, same effective request | The **original authoritative result**, zero additional side effects |
| Same key, different effective request | **409**, zero additional side effects |

A rate-limit refusal must not consume the idempotency slot.

---

## 5. What must never be relied on — APPROVED

| Not a control | Why |
| --- | --- |
| Minification or obfuscation | Slows a reader by minutes |
| Client-side integrity checks | Run by the same client being checked |
| Hidden endpoints | Visible in the network tab |
| A client-generated signature | The signing key ships with the client |
| Rate limiting **alone** | Limits frequency, not falsehood |
| **A run secret handed to the client** | The client is assumed modified; a secret it holds defends nothing against it, and is one more thing XSS can take |

---

## 6. Interaction with other decisions — APPROVED

| Decision | Interaction | Status |
| --- | --- | --- |
| **PWA-1** — offline play | Decided: connectivity required to **start**; a started run survives transient loss | **Resolved** |
| **RNG-1** — server-issued seed | Layer 2 requires it, and it is adopted | **Resolved** |
| **RNG-2** — input log | Layer 3 requires it; Layer 3 is deferred, so **no input log in v1** | **Resolved** |
| **ANTI-5 / DM-1 / GR-5** — retention | Data minimization: validate at acceptance, persist compact derived facts, **no `run_events` table** | **Resolved** |
| **Near-miss detection** | Deterministic, one event per obstacle, no score — a `DERIVED_TELEMETRY` input, and therefore **not established in v1** (§8) | **ANTI-6** |
| **LB-1** — week boundary | A server-recorded start time makes attribution unambiguous | Resolved |
| **ADR-0005** — auth | A run is always attributable to an authenticated, verified account, and the actor is resolved from the credential — never from the body | Resolved |
| **Submission rate limiting** | A dedicated limiter exists for run start and finish; values are an M9 implementation parameter. See [`rate-limiting.md`](rate-limiting.md) §2 | Resolved |
| **SEC-3 / SEC-5** — retention policy | Still open, but the surface is far smaller: no raw per-event gameplay history is retained | Open |

---

## 7. Non-goals — APPROVED

- **Preventing** client modification. Impossible. The server's job is to make it
  not matter.
- Detecting every cheat. The goal is that the **leaderboard is credible**, not
  that cheating is impossible.
- Punishing players for unusual-but-legitimate skill.

---

## 8. What v1 cannot establish — OPEN (ANTI-6)

Layers 1 and 2 can **bound** a number. They cannot **establish** one that depends on what
the player did. Four run-level facts are exactly that:

| Run-level fact | Feeds |
| --- | --- |
| Obstacle passes by class | `cone_dodger` |
| Near misses | `close_call`, `nerves_of_steel` |
| SLAYYY activations | `slayyy_master`, `slayyy_double` |
| **Actual Loli activations** | `lolis_favourite`, `loli_devotee`, and Sero's unlock |

Only Layer 3, or an equivalent separately approved trustworthy derivation mechanism, can
establish them — and Layer 3 is deferred beyond v1.

**§2.1 is unchanged.** A trusted client aggregate capped by plausibility checks is not an
acceptable final verification model. It follows directly that, while no such mechanism
exists, these four facts **must not be promoted from client-reported aggregates into
authoritative `DERIVED_TELEMETRY` progression facts.**

So M9 **does not create their columns, does not return them, and does not accumulate them.**
Recording them early would breach the trust boundary at the outset and buy nothing: a counter
accumulated from an untrustworthy source would have to be discarded the moment a real
mechanism arrived.

**ANTI-6 blocks M11 only.** It blocks neither M9 nor M10. Choosing between adding Layer 3,
designing another server-verifiable mechanism, and changing the affected achievement and
unlock behaviour is a product decision for the M11 architecture decision, and is **not**
pre-empted here.

---

## 9. Open questions

| Ref | Question |
| --- | --- |
| ~~ANTI-1~~ | **Resolved by ADR-0006.** Layers 1 and 2 ship in v1; Layer 3 is deferred with the domain kept portable. |
| ~~ANTI-2~~ | **Resolved by ADR-0006.** Three outcomes with the side effects fixed in §4; the player is always told. |
| ~~ANTI-3~~ | **Resolved by ADR-0006.** No actively serviced review queue and no appeal workflow in M9; M13 may add administrative review tooling. |
| ~~ANTI-4~~ | **Resolved by ADR-0006.** Structural rejection is separated from tuning-dependent flagging (§3A); bound *values* remain an M9 implementation parameter gated on tuning approval. |
| ~~ANTI-5~~ | **Resolved by ADR-0006.** Data minimization: validate at acceptance, persist compact authoritative derived facts, retain no raw per-event history. |
| **ANTI-6** | **How the four `DERIVED_TELEMETRY` run facts are established** by a server-trustworthy mechanism (§8). **Blocks M11.** Interacts with AU-1, SEC-5. |
| ~~PWA-1~~ | **Resolved by ADR-0006.** Connectivity required to start; a started run survives transient loss. |
| ~~RNG-1~~ | **Resolved by ADR-0006.** The seed is server-issued. |
| ~~RNG-2~~ | **Resolved by ADR-0006.** No input log is submitted or retained in v1. |
