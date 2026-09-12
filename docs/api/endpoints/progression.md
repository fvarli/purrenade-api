# Endpoints — Progression

**Contract shape only.**

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| GET | `/progression` | authenticated | — | normal |
| POST | `/progression/tutorial` | authenticated | yes | normal |

---

## Get progression — APPROVED

Returns the player's durable progression. **Three distinct paw concepts**, never
a generic `paws` field:

| Field | Meaning |
| --- | --- |
| `lifetime_paws` | Lifetime statistic. Never consumed. Shown on the profile. |
| `loli_cycle_paws` | Persistent progress toward the next Loli Bonus, `0..199`. Shown on the main menu as `n / 200`. |
| `loli_threshold` | Always `200`. |

`run_paws` is **not** here — it belongs to a run, and is returned by the run
result.

**Threshold semantics — APPROVED:** crossing 200 triggers **one Loli Bonus per
completed threshold**, consumes 200, and **preserves overflow**. `198 + 5` →
a bonus triggers and `loli_cycle_paws` becomes `3`.

**Authority — APPROVED:** these values are derived by the server from accepted
runs. There is no endpoint by which a client sets them.

**No queued-bonus field — APPROVED.** `queuedLoliBonuses` is **run-scoped**: it lives in the
client's `RunState` and in run telemetry, and **never** in this schema, in progression, or in
the database. The Loli Bonus is a gameplay reward for the run in which it was earned, not a
bankable meta-progression currency. A threshold consumed during a run is **not** converted into
a future-run entitlement. See the frontend's `docs/product/scoring-and-progression.md` §2.4.

---

## Record tutorial completion — APPROVED

| Rule | Detail |
| --- | --- |
| Persisted **to the player profile**, not device storage | Survives reinstall; follows the account |
| **Idempotent** | Replay does not re-stamp or clear the timestamp |
| Replaying the tutorial from Settings does **not** clear it | Completion is a one-time fact |
| No score, leaderboard, or progression side effects | The tutorial is not a run |

**OPEN (TU-3):** whether the paw collected during the tutorial counts toward
progression. **PROPOSED: it does not**, so the tutorial cannot be farmed.

---

## Unlock counters — APPROVED

`player_progression` also carries the counters that character unlock criteria
need, and it carries **both readings of each ambiguous criterion** — see
[`../../architecture/data-model.md`](../../architecture/data-model.md) §4.

This is deliberate. The criteria semantics are OPEN (AU-4), and **a counter that
was never recorded cannot be reconstructed retroactively.** Recording both from
M9 costs almost nothing; not recording them would mean every existing player's
progress is wrong once the decision is made.

Whether these counters are exposed on this endpoint is **PROPOSED**: only the
ones the UI actually displays.
