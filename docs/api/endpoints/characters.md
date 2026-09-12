# Endpoints — Characters

**Contract shape only.** Unlock criteria semantics are OPEN.

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| GET | `/characters` | authenticated | — | normal |

---

## Behavior — APPROVED in shape

Returns the catalogue with this player's unlock state (v0.3 board 09):

| Character | State in v0.3 |
| --- | --- |
| **Ayşenur** | Available. "Our star." `özel güç: SLAYYY ✨` |
| **Büşo** | Locked — `2.500 puan` |
| **Ogito** | Locked — `10 koşu` |
| **Sero** | Locked — `Loli Bonusu ×3` |

---

## Two gates — APPROVED

A character is **selectable** only when **both** hold:

1. `is_unlocked` — the player met the unlock criterion;
2. `artwork_available` — **approved artwork exists** (v0.3: *"arkadaş görselleri
   eklenince açılacak"*).

`is_selectable` is the conjunction, computed server-side.

The second gate is a **content gate, not a progress gate**. The UI must be able to
say "you have earned this, it is not ready yet" without implying the player
failed — which is why the two flags are returned separately rather than collapsed.

### Consent is part of the content gate — APPROVED

Büşo, Ogito and Sero are named after **real people**. `artwork_available` is set
only when both the artwork **and** the documented consent exist. See
`purrenade/docs/product/licensing-and-rights.md` §3.

---

## Unlock evaluation — PROPOSED

Server-side, inside the run-submission transaction, idempotent per
`(player, character)` with a unique constraint. There is no endpoint by which a
client unlocks a character.

---

## Unlock semantics — APPROVED

The v0.3 labels were ambiguous; each admitted readings differing by orders of magnitude. These
are now decided.

| Character | Label | **Approved criterion** | Verification Source | Source counter | Rationale |
| --- | --- | --- | --- | --- | --- |
| **Büşo** | `2.500 puan` | **Best accepted single-run score ≥ 2,500** | `DERIVED_PERSISTENT` | `best_score` | v0.3 shows per-run scores of 1,284–5,847, so 2,500 is a real single-run target. As a lifetime aggregate it falls in two runs and means nothing. |
| **Ogito** | `10 koşu` | **Complete 10 accepted runs.** No separate minimum-duration criterion. | `DERIVED_PERSISTENT` | `run_count` (accepted) | Whether a run is accepted is decided by the **run-validation system**. Anti-farming belongs there, not in a second hidden rule competing with it. |
| **Sero** | `Loli Bonusu ×3` | **3 lifetime *actual* Loli Bonus activations.** Threshold crossings and queued-but-never-started bonuses do **not** count. | **`DERIVED_TELEMETRY`** | `lifetime_loli_activations` | 3 in a *single run* requires 600 paws against observed per-run values of +18 and +42 — effectively impossible. Activation is a telemetry-derived run fact, never a ledger inference. |

All three are **deterministic and server-derived**; none depends on a client assertion.

**Sero's counter is telemetry-sourced but persisted.** `lifetime_loli_activations` accumulates
the accepted run's `loliActivations` fact — `DERIVED_TELEMETRY` verification source,
`PERSISTED_AGGREGATE` progress persistence. Storing the total does not make its evidence
persistent-sourced.

**AU-7 is retired.** There is no minimum-duration threshold, and the
`accepted_runs_above_min_duration` counter is removed. The alternative-reading counters
`lifetime_score` and `max_loli_bonus_in_single_run` are likewise retired.

`unlock_criterion` is returned as a **display string** and must not be parsed by the client.

---

## Open questions

| Ref | Question |
| --- | --- |
**Resolved by M0.6:** AU-4 (unlock semantics **APPROVED**) and AU-7 (**retired** — no
minimum-duration criterion).
| AU-5 | Do non-Ayşenur characters have their own special power? **PROPOSED: no — all characters share SLAYYY in v1** |
| CH-1 | Does selecting a character affect gameplay at all in v1, or only presentation? |
