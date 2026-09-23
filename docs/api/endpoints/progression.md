# Endpoints — Progression

| Method | Path | Auth | Idempotent | Rate-limit class | Status |
| --- | --- | --- | --- | --- | --- |
| GET | `/progression` | authenticated **+ verified** | — | normal | **Implemented at M9** |
| POST | `/progression/tutorial` | authenticated **+ verified** | yes | normal | **Implemented at M8**, storage relocated at M9 |

**`GET /progression` response (M9):**

```json
{"data": {"lifetime_paws": 50, "loli_cycle_paws": 50, "loli_threshold": 200,
          "best_score": 1000, "run_count": 1, "tutorial_completed": true}}
```

Read-only: a player without a progression row reads as zeros and nothing is written. No
`lifetime_*` telemetry counter and no queued-bonus field appear, by design (ANTI-6).

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

Conditional updates — each a no-op once the column is set:

```sql
UPDATE player_progression SET tutorial_completed_at = :now WHERE user_id = ? AND tutorial_completed_at IS NULL;
UPDATE users SET tutorial_completed_at = :now WHERE id = ? AND tutorial_completed_at IS NULL;
```

Both in one transaction (M9 dual-write, below), with the same instant.

Zero rows means it was already completed, which is the ordinary replay case and
returns `200` with the stored state. Reading the column and then writing it
would be the defect the display-name cooldown was fixed for: two concurrent
requests both see null, both stamp, and the second silently overwrites the
first.

### Where the column lives — relocated at M9

**Since M9** the fact lives on `player_progression.tutorial_completed_at`, backfilled exactly
from `users.tutorial_completed_at` by the migration that created the table (which refuses to
commit on any mismatch). During the transition the write goes to **both** columns and every
read accepts **either**; `users.tutorial_completed_at` is dropped only in a later, separate
contract deployment. See [`../../architecture/data-model.md`](../../architecture/data-model.md)
§4.1 for the step table. The history below is kept for the record.

Until M9 the stored fact was **`users.tutorial_completed_at`**, a nullable timestamp.

**Progression still owns it.** `domain-boundaries.md` §4 is unchanged. What M8
chose is only the *physical* location, and it chose `users` because
`player_progression` does not exist yet: it is PROPOSED in
[`../../architecture/data-model.md`](../../architecture/data-model.md) §4, and
every other column in it is derived from accepted run submissions — M9 scope,
and M9 was still blocked on ADR-0006 when M8 shipped. (It no longer is: the ADR
was accepted on 2026-09-22.) `tutorial_completed_at` is also the only row in that
table with no verification source.

**This placement is temporary.** When M9 creates `player_progression`, the
migration is `users.tutorial_completed_at` →
`player_progression.tutorial_completed_at`, backfilling every existing value so
no player is asked to repeat a tutorial they already finished. Recorded in
`data-model.md` §4.1 as well, so it cannot be quietly forgotten.

It is an **expand/contract** move across two deployments: create, backfill, repoint the write
and the read, verify in production — and only then, in a **later separate deployment**, drop
`users.tutorial_completed_at`. The relocation is physical. It changes no ownership, reopens no
product decision, and **the public wire contract stays the boolean `tutorial_completed`**
throughout.

**TU-3 is resolved:** the paw collected during the tutorial does **not** count
toward progression. It cannot: the tutorial submits nothing, and no endpoint
exists by which a client could add to the paw ledger.

---

## Unlock counters — APPROVED, with one class deferred

`player_progression` carries the counters that character unlock criteria need — see
[`../../architecture/data-model.md`](../../architecture/data-model.md) §4.

**From M9:** `best_score` and `run_count`, which are Büşo's and Ogito's approved criteria.
Both are `DERIVED_PERSISTENT` — their evidence is the authoritative run records the server
itself wrote — so Layers 1 and 2 establish them completely.

**Not from M9:** `lifetime_loli_activations`, `lifetime_near_misses`,
`lifetime_lane_blocking_passes` and `lifetime_slayyy_activations`. These are
`DERIVED_TELEMETRY`, and nothing in v1 can establish them: Layers 1 and 2 bound a number but
do not establish it, and Layer 3 is deferred. The APPROVED authority rule forbids adopting the
client's count, so M9 records none of them. Tracked as **ANTI-6**, which blocks **M11**.

The "record it early because it cannot be reconstructed" argument does **not** rescue them.
A counter accumulated from an untrustworthy source would have to be discarded the moment a
real mechanism arrived, so it could not be relied on retroactively either — and meanwhile it
would carry a retention obligation over behavioural personal data (SEC-5) in exchange for
nothing. AU-4 is also resolved now, so the ambiguity that motivated recording both readings no
longer applies.

Whether the counters that do exist are exposed on this endpoint is **PROPOSED**: only the
ones the UI actually displays.
