# Data Model

PostgreSQL schema **shape**, constraints and indexing rationale.

> **M0 scope: shape only.** No migration exists and none is written in this
> milestone. Column types and names are finalized at the milestone that creates
> each table.

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. Principles — APPROVED

1. **Constraints are correctness mechanisms, not documentation.** If a rule can
   be expressed as a foreign key, a unique constraint, a check, or `NOT NULL`, it
   is — in addition to application validation, never instead of it.
2. **Indexes follow real query patterns**, added with the query that needs them
   and verified against realistic volumes.
3. **Migrations are reversible where practical.**
4. **PostgreSQL only.** MySQL is not used.
5. Money does not exist in this product; **scores and paw counts are integers**.
   No floating point in any authoritative counter.

---

## 2. Identity

### `users` — **IMPLEMENTED (M2, M8)**, with columns still proposed
| Column | Notes |
| --- | --- |
| `id` | Primary key |
| `username` | **Unique, case-insensitively.** Display name on the leaderboard. **v1 baseline APPROVED:** 3–20 chars; Unicode letters/digits plus `_ . -`; at least one letter; no leading/trailing punctuation; rate-limited changes; admin force-rename. Profanity screening and homoglyph/confusable detection are **future hardening, not v1**. |
| `email` | **Unique**, case-insensitive |
| `password_hash` | |
| `email_verified_at` | Null until verified |
| `locale` | `tr` / `en` / `es`; drives emails |
| `music_volume`, `music_muted` | Audio Option C — **mute stored separately from volume**, so unmute restores the previous non-zero level |
| `effects_volume`, `effects_muted` | Same; volumes clamped `0.0`–`1.0` |
| `role` | `player` / `admin`. Constrained to the enumerated set. |
| `created_at`, `updated_at` | "Running since" on the profile derives from `created_at` |
| `deleted_at` | Soft delete; interacts with KVKK — see [`../security/data-protection.md`](../security/data-protection.md) |

**Constraints:** unique `username`; unique lowercased `email`; `role` check.

**What actually shipped, as of M8.** The table exists in production and diverges from the
shape above; the divergence is recorded here rather than silently reconciled.

| Documented | Shipped |
| --- | --- |
| `username` | `display_name` plus `display_name_normalized` (the unique one), and `display_name_changed_at` |
| `locale` | **Not created yet** |
| `music_volume`, `music_muted`, `effects_volume`, `effects_muted` | **Not created yet** |
| `deleted_at` | **Not created yet** — it lands with SEC-3 |
| `role` check constraint | Shipped: an indexed string with a default **and** `users_role_check`, built from the `UserRole` enum |
| — | `tutorial_completed_at` (M8) — legacy mirror since M9, dual-written until the contract deployment drops it; see §4.1 |
| — | The 2FA and session columns, per ADR-0005 |

### `email_verification_codes` — PROPOSED
6-digit code (**hashed, never stored in plaintext**), `expires_at`,
`consumed_at`, `attempts`, `last_sent_at` for the resend cooldown.
**Index:** `(user_id, expires_at)`.

### `password_reset_tokens` — PROPOSED
Hashed token, `expires_at`, `consumed_at`. Single-use.

### `two_factor_secrets` — PROPOSED
Encrypted TOTP secret, `confirmed_at`. One per user.

### `two_factor_recovery_codes` — PROPOSED
**Hashed** codes, `consumed_at`. **Unique** per `(user_id, code_hash)`.
Single-use is enforced by the constraint, not by application logic alone.

### `sessions` / `devices` — PROPOSED
Device label, approximate location, `last_seen_at`, `revoked_at`, and whatever
credential reference [ADR-0005](../decisions/ADR-0005-authentication-and-2fa-strategy.md)
selects. **Index:** `(user_id, last_seen_at desc)`.

---

## 3. Runs

### `runs` — **IMPLEMENTED (M9)** under ADR-0006

Migration `2026_09_23_100200_create_runs_table`. Lifecycle in
`App\Services\Runs\RunLifecycleService`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `uuid` PK | Opaque (UUIDv7). The run identity a finish call references; there is **no separate run token** — see [`../security/anti-cheat.md`](../security/anti-cheat.md) §3. |
| `user_id` | `bigint` FK → `users`, **restrict** | The authenticated actor, resolved from the credential. Restrict, because account deletion is OPEN (DM-4) and run history must not vanish as a side effect. |
| `character_id` | `bigint` FK → `characters.id`, restrict | **Internal** id. The API speaks `characters.key`; the bigint is never serialised. |
| `status` | `varchar(16)` | `active` / `accepted` / `flagged` / `rejected` |
| `seed` | `bigint` | **Server-issued** (RNG-1), uint32 `0..4294967295`, from the CSPRNG. `bigint` because PostgreSQL `integer` is signed 32-bit. |
| `started_at` | `timestamp(3)` | **Server-recorded**, at run creation, before gameplay. Determines weekly attribution (LB-1). |
| `finished_at` | `timestamp(3)` null | The server's **finalisation (receive) time** — not the moment play ended. Null while `active`. |
| `duration_ms` | `integer` null | The **claimed** simulated play time, kept once it passes structural validation. Not a server clock fact. Null while `active` and on `rejected`. |
| `score` | `integer` null | The score as classified. Null while `active` and on `rejected`. Counts toward progression only when `accepted`. |
| `run_paws` | `integer` null | Paws as classified. Null while `active` and on `rejected`. |
| `validation_meta` | `jsonb` null | `{v, window_ms, rules: [{code, observed?, bound?, field?}]}` — `window_ms` is `finished_at − started_at`, the server's own upper bound. **Never raw events.** |
| `result` | `jsonb` null | The `RunResult` payload the finish produced, replayed verbatim for the same idempotency key (GR-3). Server-decided data only. |
| `idempotency_key` | `uuid` null | Set at finish. Null while `active` and for a stale-replaced run. |
| `idempotency_fingerprint` | `char(64)` null | `sha256("finish:v1\n" + run_id + "\n" + duration + "\n" + score + "\n" + paws)`. What makes "same key, different request → `409`" enforceable. |
| `created_at`, `updated_at` | `timestamp(3)` | |

**Constraints (all shipped)**
- `runs_status_check` — the four statuses, built from the `RunStatus` enum.
- `runs_seed_check` — `seed BETWEEN 0 AND 4294967295`.
- `runs_active_shape_check` — `active` exactly when `finished_at IS NULL`, and an active run
  carries no score, paws, duration, key or result.
- `runs_counted_shape_check` — `accepted` and `flagged` carry all of them.
- `runs_finished_order_check` — `finished_at >= started_at`.
- `runs_values_check` — `score >= 0`, `run_paws >= 0`, `duration_ms > 0` where present.
- `runs_idem_pair_check` — key and fingerprint are set together.
- **`runs_one_active_per_user`: `UNIQUE (user_id) WHERE status = 'active'`** — the partial
  unique index that makes "one active run per user" a database invariant rather than a
  read-then-check query that loses a race (GR-4). Start inserts with
  `ON CONFLICT (user_id) WHERE status = 'active' DO NOTHING`, whose predicate matches the
  index so PostgreSQL infers it as the arbiter.
- **`UNIQUE (user_id, idempotency_key)`** — the single constraint that makes retry-safe
  submission real rather than hoped for (GR-3). The identity lives here for the life of the
  run record; **there is no cleanup window**, so an old retry can never apply progression
  twice. NULLs are distinct, so active and stale-replaced runs coexist.

**No time-based expiry.** An `active` run older than 24 hours is normal: it stays active, and
its finish is accepted, until the same player starts again (`RUN_STALE_REPLACEMENT_AFTER`,
[`../security/anti-cheat.md`](../security/anti-cheat.md) §3B).

**No `run_events` table, and no raw per-event history.** See below.

**No `queued_loli_bonuses` column.** The Loli Bonus queue is **run-scoped**: it lives in the
client's `RunState` and in run telemetry, and ends with the run. There is **no
`owed_loli_bonuses` field anywhere in this schema** — the Loli Bonus is a gameplay reward, not
a bankable meta-progression currency. A peak queue depth may be recorded as telemetry for
analysis; it is never an entitlement.

**Derived-counter rule — APPROVED.** Every counter here is **derived server-side**, never
adopted from a client summary counter. See
[`../security/anti-cheat.md`](../security/anti-cheat.md) §2.1.

The contract makes this structural: the request carries only
`FinishRunRequest.telemetry.reported_*` (untrusted hints). At M9 the three that exist —
duration, score, paws — are persisted only as **classified claims**, after structural
validation, and move progression only when the run is `accepted`. Layers 1 and 2 allow no
stronger derivation of score and paws; that residual is accepted by ADR-0006.

#### Four columns deliberately absent at M9 — ANTI-6

`near_miss_count`, `lane_blocking_passes`, `slayyy_activations` and `loli_activations` are
**not created at M9.**

They describe what the *player* did, not what the world generated, so Layers 1 and 2 can bound
them but cannot establish them — and Layer 3 is deferred beyond v1. The rule above admits no
exception, so the choice is between adopting a client counter and not having the column. M9
does not have the column.

Creating them early and filling them from client aggregates would breach the trust boundary at
the outset and buy nothing: a counter accumulated from an untrustworthy source would have to
be **discarded** the moment a real mechanism arrived, so it could not be relied on
retroactively either. Adding nullable counter columns later is an ordinary expand migration.

Tracked as **ANTI-6**, which blocks **M11** only.

**Indexes.** M9 ships only the two unique ones above. The query indexes follow their queries
(gate D5), so they arrive with M10:
- `(user_id, created_at desc)` — profile history — **PROPOSED, M10**
- `(status, score desc)` partial on `status = 'accepted'` — all-time ranking — **PROPOSED, M10**
- `(status, started_at, score desc)` partial on `status = 'accepted'` — weekly ranking — **PROPOSED, M10**

### `run_events` — **not created** (DM-1 resolved, ANTI-5)

**ADR-0006 chose data minimization.** No `run_events` table exists at M9, and **no raw
per-event gameplay history is retained** merely because it might be useful later.

Validation happens at submission time. What persists is only:

- the authoritative run record;
- compact authoritative derived facts;
- the minimum validation metadata required to explain or classify the result;
- the progression and ledger state approved product behaviour requires.

Retained per-event data would be **behavioural personal data** carrying a retention obligation
— see [`../security/data-protection.md`](../security/data-protection.md) §2A. Not retaining it
is the smaller surface, and it is the option the register already recorded as explicitly
permitted.

**Any future raw-event retention requires a separate privacy/retention decision** (SEC-3,
SEC-5). It is not implied by ANTI-6 being resolved later.

---

## 4. Progression

### `player_progression` — **IMPLEMENTED (M9)** under ADR-0006
One row per user: `user_id` is the primary key and an FK → `users` with **cascade** (the row is
fully derived). Migration `2026_09_23_100100_create_player_progression_table`. Types:
`lifetime_paws bigint`, `loli_cycle_paws smallint`, `best_score integer`, `run_count integer`,
`tutorial_completed_at timestamp(0)`. `player_progression_values_check` enforces every range.

The row is created idempotently by `ProgressionService::ensure()` — at registration, and as an
autocommitted statement before any run transaction opens (lock order C-1). A missing row reads
as zeros; reads never write.

**The M9 column set:**

| Column | Verification Source | Notes |
| --- | --- | --- |
| `lifetime_paws` | `DERIVED_PERSISTENT` | Never consumed |
| `loli_cycle_paws` | `DERIVED_PERSISTENT` | `0..199`. **Check constraint enforces the range.** |
| `best_score` | `DERIVED_PERSISTENT` | **Büşo's approved criterion** (≥ 2,500) |
| `run_count` | `DERIVED_PERSISTENT` | Accepted runs only. **Ogito's approved criterion** (10). No duration filter. |
| `tutorial_completed_at` | — | Null until first completion; **not** re-stamped on replay. **Relocated here at M9** — see §4.1 |

Every column here is a **`PERSISTED_AGGREGATE`** by progress persistence. The Verification
Source column records something different — where the *evidence* came from. Every M9 column is
`DERIVED_PERSISTENT`, which is exactly the set Layers 1 and 2 **can** establish: the evidence
is the authoritative run records the server itself wrote.

**Only an `accepted` run mutates any of these.** A `flagged` or `rejected` run mutates none —
no progression, no ledger, no personal best, no `run_count`.

#### Four counters deliberately absent at M9 — ANTI-6

| Column | Verification Source | Feeds |
| --- | --- | --- |
| `lifetime_loli_activations` | **`DERIVED_TELEMETRY`** | **Sero's approved criterion** (3) — *actual* activations, not thresholds earned |
| `lifetime_near_misses` | **`DERIVED_TELEMETRY`** | `close_call` |
| `lifetime_lane_blocking_passes` | **`DERIVED_TELEMETRY`** | `cone_dodger` |
| `lifetime_slayyy_activations` | **`DERIVED_TELEMETRY`** | `slayyy_master` |

These accumulate the four `runs` columns that M9 does not create, for the same reason: no
mechanism in v1 can establish them, and the authority rule forbids adopting the client's
count. They arrive with whatever resolves **ANTI-6**, which blocks **M11** only.

The naming rule below is why this matters: storing a total never reclassifies where its
evidence came from, so a `DERIVED_TELEMETRY` counter cannot be quietly laundered into a
`DERIVED_PERSISTENT` one by being written to a table.

### 4.1 `tutorial_completed_at` relocation — APPROVED (M8), expand shipped at M9

**State after M9 (the expand deployment):**

| Step | Status |
| --- | --- |
| 1 Create `player_progression` with `tutorial_completed_at` | **Done** — same type as the source, `timestamp(0)` |
| 2 Backfill from `users.tutorial_completed_at`, exactly | **Done** — one set-based `INSERT … SELECT … ON CONFLICT DO NOTHING` |
| 2a Assert the backfill | **Done inside the migration**: it throws, rolling back, unless every user has a row whose timestamp `IS NOT DISTINCT FROM` the source. The production check is therefore part of `migrate --force`. |
| 3 Repoint the write and the read | **Done, with compatibility:** `ProgressionService::completeTutorial()` **dual-writes** — progression, then the `users` mirror, same instant, both conditional on `IS NULL`. Every read (`/auth/me`, `/progression/tutorial`, `/progression`, the run result) is **either column non-null** (`User::hasCompletedTutorial()`), which covers the previous release writing only `users` between migrate and reload. |
| 4 Verify in production | After the M9 deploy |
| C1 Contract, later deployment | Re-backfill stragglers (`IS NULL` guarded), then progression-only code; remove the `users` cast |
| C2 Contract, later separate deployment | Drop `users.tutorial_completed_at`; `down()` re-adds and repopulates it from progression |

**`users.tutorial_completed_at` is deliberately not dropped in M9.** Rolling the code back to
the pre-M9 release is safe until C1, because `users` is still written.

The original M8 reasoning follows.

The table above is still where this column **belongs**: Progression owns tutorial
completion ([`domain-boundaries.md`](domain-boundaries.md) §4), and M8 did not change
that. What M8 chose is where the column physically sits until `player_progression`
actually exists.

**Why not create the table at M8.** `player_progression` was PROPOSED, and every other
column in it is derived from accepted run submissions — M9 scope, and M9 was still blocked
on ADR-0006 at the time. (It no longer is: the ADR was accepted on 2026-09-22.)
Creating it to hold one unrelated column would be implementing a proposed design early,
and would invite the rest of it to be filled in piecemeal by whoever needed the next
field. `tutorial_completed_at` is also the only row in the table with **no verification
source**: it is not derived from a run, and it needs none of the machinery the other
columns exist for.

So M8 added it to `users`, beside `display_name_changed_at`, which is the same shape —
a nullable timestamp on the player's own row, written by naming the column explicitly
because `$guarded = ['*']` disables mass assignment outright.

**The M9 migration, recorded now so it is not forgotten later:**

| Step | Action |
| --- | --- |
| 1 | Create `player_progression` with its **approved M9 column set** (above), including `tutorial_completed_at` |
| 2 | **Backfill** `player_progression.tutorial_completed_at` from `users.tutorial_completed_at` for every row, preserving the original timestamp exactly |
| 3 | Repoint the write in `TutorialController` and the read projection in `AuthenticatedUserResource` |
| 4 | **Verify the production backfill and the resulting behaviour** |
| 5 | Drop `users.tutorial_completed_at` — **only in a later, separate contract deployment** |

**Expand and contract must not share a deployment.** Steps 1–4 are the expand; step 5 is the
contract. Collapsing them removes the column in the same release that starts writing
elsewhere, which leaves no safe rollback and no window in which to discover that the backfill
was wrong. The public wire contract is unchanged throughout: it stays the boolean
`tutorial_completed`.

Skipping the backfill would ask every existing player to sit through a tutorial they
have already completed — the precise failure server-side persistence exists to prevent.
The relocation is a physical move; **it does not change ownership**, and no product
decision is reopened by it.

**Naming rule — APPROVED.** A counter whose evidence comes from telemetry keeps a
`DERIVED_TELEMETRY` verification source even though it is stored as a persistent aggregate.
**Storing a total never reclassifies where its evidence came from.**

**Retired counters (M0.6):** `lifetime_score`, `max_loli_bonus_in_single_run` and
`accepted_run_count_above_min_duration` are **not** recorded. The unlock semantics they hedged
against are now decided, and Ogito's criterion is a plain accepted `run_count` — anti-farming
belongs to the run-validation system, not to a second hidden duration rule competing with it.

**Concurrency:** updated with an **atomic database-level operation** inside the
submission transaction. Never read-modify-write in application code — that is the
lost-update bug this table would otherwise produce under two tabs.

### `paw_ledger` — **IMPLEMENTED (M9)**
Append-only: `id`, `user_id` (FK restrict), `run_id` (uuid FK restrict, **UNIQUE** — a run
credits the ledger at most once), `delta integer > 0`, `resulting_cycle smallint 0..199`,
`bonuses_triggered integer >= 0`, `created_at timestamp(3)`.

**Only an `accepted` run with `run_paws > 0` appends to it**, in the finish transaction after
the progression update.

**`bonuses_triggered` is threshold-crossing accounting only:** `floor((previous_cycle +
delta) / 200)`, which can exceed 1. It is **not** a Loli Bonus activation — queued bonuses are
run-scoped and can expire unstarted, so *threshold crossed ≠ Loli activated*. Nothing may
derive `lifetime_loli_activations`, Sero's progress or any ANTI-6 fact from it. The column
carries a `COMMENT` saying so.

An append-only ledger makes the paw total auditable and lets a disputed
progression state be reconstructed. **Index:** `(user_id, created_at desc)` — **PROPOSED**,
added when a query needs it.

### `achievements` — PROPOSED
Catalogue: key, target value, hidden flag, display order.
**6 of 16 defined; 10 OPEN (AU-1); 2 depend on non-mechanics (AU-2).**

### `player_achievements` — PROPOSED
`user_id`, `achievement_id`, `progress`, `unlocked_at`.
**Unique `(user_id, achievement_id)`** — this is what makes unlocking idempotent.
`progress` is monotonic; it never decreases.

### `characters` — **IMPLEMENTED (M9), minimal**
`id bigint` (internal), `key varchar(32)` **unique** with `characters_key_check`
(`^[a-z0-9_]{1,32}$`; the public `character_id`), `is_starter`, `artwork_available`,
`display_order`. The four APPROVED characters are written **by the migration**, not a seeder:
`aysenur` (starter, artwork), `buso`, `ogito`, `sero` (neither). M9's selectable-for-a-new-run
rule is generic: `is_starter AND artwork_available`. **Unlock criterion columns are M11.**

`artwork_available` encodes the **second unlock gate**: v0.3 states characters
unlock "when the friend artwork is added". A character is selectable only when
the criterion is met **and** the artwork exists.

### `player_characters` — PROPOSED
`user_id`, `character_id`, `unlocked_at`. **Unique `(user_id, character_id)`**.

---

## 5. Leaderboards

### Projection — PROPOSED

The authoritative data is `runs`. The ranking is a **projection** — a
materialized view or a maintained table — refreshed on write or on a short
schedule. **Never a live sort over the whole table**, and never Redis as the
source of truth.

| Concern | Approach |
| --- | --- |
| Weekly window | Derived from `runs.started_at` per LB-1 |
| Total order | `score desc`, then earliest achievement, then shorter duration, then a stable id — so pagination cannot duplicate or skip |
| Pagination | **Cursor-based.** Offsets over a live ranking skip and repeat rows. |
| The player's own rank | Always computed fresh; never served stale to the player it belongs to |

---

## 6. Operational

### `idempotency_keys` — **not created at M9**
Run submission does not need it. The identity lives on `runs` as
`(user_id, idempotency_key)` with a unique constraint and an `idempotency_fingerprint` — the
simpler model, and one that avoids a second source of truth for the same fact (GR-3).

It returns only if idempotency is generalized to other endpoints — **API-5**, still open — in
which case: key, user, endpoint, request fingerprint, stored response, `expires_at`, with
**unique `(user_id, key)`**. Any `expires_at` there would be a *new* decision about *those*
endpoints. It must not be retrofitted onto run submission, where an expiring key is precisely
how an old retry applies progression a second time.

### `audit_log` — PROPOSED
Actor, action, target type and id, correlation id, `created_at`, and a metadata
payload. **Append-only. Never updated, never deleted.** Every admin action writes
here.

---

## 7. Cross-cutting rules — APPROVED

| Rule | Applies to |
| --- | --- |
| Every foreign key is declared, with an explicit delete behavior | All tables |
| Every enumerated column has a check constraint | `role`, `status`, `locale` |
| Every counter that must not go negative has a check constraint | Paws, scores, progress |
| Every "at most one" rule has a unique constraint | Achievements, unlocks, idempotency, recovery codes |
| Timestamps are stored in UTC | All tables |
| No secret, token, or code is stored in plaintext | Verification codes, reset tokens, recovery codes, 2FA secrets |

---

## 8. Open questions

| Ref | Question |
| --- | --- |
| ~~DM-1~~ | **Resolved by ADR-0006 (ANTI-5).** Data minimization: **no `run_events` table**, and no raw per-event history. Validate at submission time and persist compact authoritative facts only. |
| DM-2 | Weekly window: partitioned table, materialized view, or maintained table (LB-1) |
| DM-3 | Retention for `runs`, `paw_ledger`, `audit_log` (SEC-3) |
| DM-4 | What account deletion does to runs and leaderboard entries (LB-5, SEC-3) |
| DM-7 | Index strategy for the lifetime telemetry-derived counters once real query shapes exist. Deferred with the counters themselves — **ANTI-6**. |
| **ANTI-6** | The four `DERIVED_TELEMETRY` columns on `runs` and `player_progression` are **not created at M9** because nothing in v1 can establish them. **Blocks M11.** |

**Resolved by M0.6:** DM-5 (display-name v1 baseline **APPROVED**) and DM-6 (audio **Option C
APPROVED** — four columns: `music_volume`, `music_muted`, `effects_volume`, `effects_muted`).
