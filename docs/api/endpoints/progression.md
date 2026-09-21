# Endpoints — Progression

**Contract shape only.**

| Method | Path | Auth | Idempotent | Rate-limit class | Status |
| --- | --- | --- | --- | --- | --- |
| GET | `/progression` | authenticated | — | normal | Contract only — needs M9's run-derived counters |
| POST | `/progression/tutorial` | authenticated **+ verified** | yes | normal | **Implemented at M8** |

**The verified requirement is a deliberate narrowing, recorded here so the route
and this table cannot drift.** The API's unverified tier is exactly four
endpoints by design — enough to learn that verification is required, complete
it, and leave — and the tutorial sits behind the frontend's `verified` guard, so
an unverified caller could never legitimately reach it. Admitting a fifth
endpoint to that tier would cost more than the row it satisfies.

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

## Record tutorial completion — APPROVED · implemented at M8

| Rule | Detail |
| --- | --- |
| Persisted **to the player profile**, not device storage | Survives reinstall; follows the account |
| **Idempotent** | Replay does not re-stamp or clear the timestamp |
| Replaying the tutorial from Settings does **not** clear it | Completion is a one-time fact |
| No score, leaderboard, or progression side effects | The tutorial is not a run |

**Request:** no body. The actor is the bearer of the token, so there is no id to
supply and no way to aim this at another account — the endpoint is unaimable by
construction rather than by a check somebody could forget to write. Any body
sent is ignored; `$guarded = ['*']` means the model has no mass-assignable
attribute to reach even if it were not.

**Response:** `TutorialState` — `{"data": {"tutorial_completed": true}}`.

**Skipping and finishing are the same call.** The product counts a skipped
tutorial as completed for first-run routing, and which one happened is not a
fact this API has any use for. Recording the difference would be tutorial
telemetry, which M8 deliberately does not collect: no completion count, no
failure count, no duration, no per-lesson data.

### How idempotence is enforced

A conditional update whose affected-row count is the authority:

```sql
UPDATE users SET tutorial_completed_at = now() WHERE id = ? AND tutorial_completed_at IS NULL
```

Zero rows means it was already completed, which is the ordinary replay case and
returns `200` with the stored state. Reading the column and then writing it
would be the defect the display-name cooldown was fixed for: two concurrent
requests both see null, both stamp, and the second silently overwrites the
first.

### Where the column lives — and where it is going

The stored fact is **`users.tutorial_completed_at`**, a nullable timestamp.

**Progression still owns it.** `domain-boundaries.md` §4 is unchanged. What M8
chose is only the *physical* location, and it chose `users` because
`player_progression` does not exist yet: it is PROPOSED in
[`../../architecture/data-model.md`](../../architecture/data-model.md) §4, and
every other column in it is derived from accepted run submissions — M9 scope,
blocked on ADR-0006. `tutorial_completed_at` is also the only row in that table
with no verification source.

**This placement is temporary.** When M9 creates `player_progression`, the
intended migration is `users.tutorial_completed_at` →
`player_progression.tutorial_completed_at`, backfilling every existing value so
no player is asked to repeat a tutorial they already finished. Recorded in
`data-model.md` §4 as well, so it cannot be quietly forgotten.

**TU-3 is resolved:** the paw collected during the tutorial does **not** count
toward progression. It cannot: the tutorial submits nothing, and no endpoint
exists by which a client could add to the paw ledger.

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
