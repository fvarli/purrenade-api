# Anti-Cheat / Run Validation Boundary

**Status: the model is OPEN** — see
[ADR-0006](../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md).
This document states what is already settled and what the options cost.

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

**The retention design remains PROPOSED (ANTI-5). The authority rule is APPROVED.** RNG-2 and
DM-1 are therefore no longer optional questions.

#### The contract enforces this structurally

| Location | Trust |
| --- | --- |
| `FinishRunRequest.telemetry.reported_*` | **Untrusted.** Hints only; the server may ignore them entirely. |
| `RunResult.derived_facts.*` | **Authoritative.** Server-computed, returned not accepted — a client cannot supply these fields. |

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

## 3. The layers — PROPOSED

### Layer 1 — Plausibility bounds

The server checks a submission against limits **derived from the approved tuning
values**, not guessed:

| Bound | Derived from |
| --- | --- |
| Maximum score per second | Peak scroll speed × ×2 SLAYYY multiplier × maximum collectible density |
| Maximum paws per second | Spawn density ceiling and the Loli magnet radius |
| Score-versus-duration consistency | The difficulty curve's soft caps |
| SLAYYY activations per run | Charge rate versus run duration |
| Loli activations per run | Paw threshold versus paws collected |
| Deviation from the player's own history | A sudden 10× is a signal, not proof |

Bounds constrain the ceiling. They do not establish truth: a careful cheater
submits plausible-but-false results.

### Layer 2 — Server-issued run token and seed

`POST /game-runs` starts the run server-side, returning a token, a **seed**, and a
**server-recorded start time**.

| Gains | |
| --- | --- |
| Run duration is bounded by server-observed time, not client claim | |
| The **spawn sequence is known to the server**, because the seed is | |
| A run that never started cannot be finished | |
| Per-run idempotency falls out naturally | |

**Cost: a run cannot begin offline.** This is the same decision as **PWA-1**.

### Layer 3 — Telemetry replay *(not v1; kept available)*

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
and ambient state. Adding Layer 3 later is a deployment decision, not a rewrite.

---

## 4. Outcomes — PROPOSED

Every submission resolves to exactly one:

| Status | Meaning | Effect |
| --- | --- | --- |
| `accepted` | Passed validation | Counts for score, progression and ranking |
| `flagged` | Anomalous but not conclusively invalid | **Recorded, excluded from ranking, pending review.** The player is told. |
| `rejected` | Conclusively invalid | Recorded for analysis; no progression effect. The player is told. |

**Two rules — APPROVED:**

1. **Never silently accept** a run that failed validation.
2. **Never silently discard** one. A player whose legitimate run was flagged by a
   false positive must be able to find out, or the product is quietly lying to
   them.

False positives are certain. Layer 1 is statistical, and an exceptional
legitimate run looks like a modest cheat.

---

## 5. What must never be relied on — APPROVED

| Not a control | Why |
| --- | --- |
| Minification or obfuscation | Slows a reader by minutes |
| Client-side integrity checks | Run by the same client being checked |
| Hidden endpoints | Visible in the network tab |
| A client-generated signature | The signing key ships with the client |
| Rate limiting **alone** | Limits frequency, not falsehood |

---

## 6. Interaction with other decisions — APPROVED

| Decision | Interaction |
| --- | --- |
| **PWA-1** — offline play | Decides whether Layer 2 is available at all |
| **RNG-1** — server-issued seed | Layer 2 requires it |
| **RNG-2** — input log | Layer 3 requires it; **§2.1 requires *some* validated event retention regardless**, so it must be answered rather than deferred |
| **Near-miss detection** | Deterministic, one event per obstacle, no score — a `DERIVED_TELEMETRY` input |
| **LB-1** — week boundary | A server-recorded start time makes attribution unambiguous |
| **ADR-0005** — auth | A run is always attributable to an authenticated, verified account |

---

## 7. Non-goals — APPROVED

- **Preventing** client modification. Impossible. The server's job is to make it
  not matter.
- Detecting every cheat. The goal is that the **leaderboard is credible**, not
  that cheating is impossible.
- Punishing players for unusual-but-legitimate skill.

---

## 8. Open questions

| Ref | Question |
| --- | --- |
| ANTI-1 | Which layers ship in v1 |
| PWA-1 | Are runs playable offline? |
| RNG-1 | Server-issued seed? |
| RNG-2 | Input log submitted? |
| ANTI-2 | What a `flagged` run means for the player, and whether there is an appeal |
| ANTI-3 | Who reviews flagged runs — an admin capability that does not exist yet (SI-1) |
| ANTI-4 | Bound values, derived once the tuning values are APPROVED rather than PROPOSED |
