# Endpoints — Achievements

**Contract shape only.** Ten of sixteen achievements are undefined; two of the
six defined ones depend on non-mechanics. **Blocks M11.**

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| GET | `/achievements` | authenticated | — | normal |

---

## Behavior — APPROVED in shape

Returns the catalogue with this player's progress: key, progress value, target,
unlocked timestamp, and whether the reward is hidden. The header count (`8/16`
in v0.3 board 16) is derived from the same data.

---

## Authority — APPROVED

**Client-reported summary counters alone are insufficient for authoritative achievement
progression.** The client emits telemetry; the server validates the run; progression is
**derived server-side** from accepted, validated telemetry and authoritative persistent data.

Every achievement carries **two independent classifications**, because "stored in the
database" is not the same as "not derived from telemetry":

| Column | Values | Answers |
| --- | --- | --- |
| **Verification Source** | `DERIVED_PERSISTENT` · `DERIVED_TELEMETRY` | Where the **evidence** comes from |
| **Progress Persistence** | `PERSISTED_AGGREGATE` · `RUN_FACT` | How progress is **stored** after derivation |

**Governing rule:** *do not reclassify an achievement as `DERIVED_PERSISTENT` merely because
its cumulative total is stored persistently after derivation.* A lifetime counter built by
incrementing a telemetry-derived run fact has a **telemetry** verification source.

A trusted client aggregate capped by plausibility checks is **not** acceptable. Bounds cap a
number; they do not establish it. See
[`../../security/anti-cheat.md`](../../security/anti-cheat.md) §2.1.

## Evaluation — PROPOSED

| Rule | Detail |
| --- | --- |
| **Server-side**, inside the run-submission transaction | Not on a schedule, not client-asserted |
| **Idempotent per `(player, achievement)`**, enforced by a unique constraint | Re-submitting a run never double-unlocks |
| Progress counters are **monotonic** | They never decrease |
| There is **no endpoint by which a client unlocks an achievement** | The only path is an accepted run |
| Unlocks are returned in the run result | So the client can celebrate immediately |

### Server-verifiability — PROPOSED

**Every achievement must be verifiable from data the server already trusts.** An
achievement the server cannot verify is one a client can fabricate — and with a
public leaderboard attached, that is not hypothetical.

This is a real constraint on authoring the ten undefined achievements: each must
be expressible in terms of accepted run results and the progression counters, not
in terms of something only the client observed.

---

## Catalogue status — M0.5

### Resolved

| Item | Outcome |
| --- | --- |
| **Çay Molası**, **Trileçe Avcısı** | **Removed as invalid.** They require Çay and Trileçe, which are not functional v1 mechanics. An achievement that can never progress is not an achievement. |
| **Koni Koleksiyoncusu** ("hit 25 cones") | **Superseded by product review** — it rewards intentional collision. Replacement proposed as `cone_dodger`: **successfully avoid 500 traffic cones across accepted runs**; each safely passed cone increments progress by one, and a collision **neither increments nor resets** progress. Not a collision-free streak. |
| **"Ramak Kala"** near-miss mechanic | **APPROVED** — a real v1 statistic mechanic: one event per obstacle, **no score**, deterministic detection. See the frontend's `docs/product/core-run.md` §5A. |
| The former "ten undefined achievements" gap | Closed. A complete 16-item catalogue is now **PROPOSED**. |

### Awaiting approval

A **complete 16-item catalogue is PROPOSED** and must be approved **as a whole**, because it
has to balance as a set: difficulty spread, group coverage, and how many entries force
telemetry retention. See the frontend's `docs/product/achievements-and-unlocks.md` §1.5.

**By verification source: 9 `DERIVED_PERSISTENT`, 7 `DERIVED_TELEMETRY`.**
**By progress persistence: 10 `PERSISTED_AGGREGATE`, 6 `RUN_FACT`.**

The seven telemetry-sourced entries draw on four run-level facts — obstacle passes, near
misses, SLAYYY activations, and **actual Loli activations** — and are precisely what make
telemetry retention mandatory rather than optional under
[ADR-0006](../../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md).

**Loli achievements count *actual* activations**, not thresholds earned: queued bonuses are
run-scoped and can expire unstarted, so `loliActivations` is a telemetry-derived run fact and
never a ledger inference.

Every proposed entry carries a **stable machine key** independent of localized display text,
counts **accepted runs only**, and **none rewards collision, death, or deliberate failure**.

## Open questions

| Ref | Question |
| --- | --- |
| AU-1 | **Approval** of the proposed 16-item catalogue, as a whole |
| AU-6 | What a hidden reward actually grants |
| AU-8 | Display text in tr/en/es for all 16 machine keys, once approved |
| ANTI-5 | What validated event data is retained for the seven `DERIVED_TELEMETRY` entries. Validating at acceptance and keeping only derived run facts is an explicitly permitted answer. |

**Resolved by M0.5:** AU-2 (Çay/Trileçe removed as invalid) and AU-3 (near-miss approved).
