# Changelog

All notable changes to this repository are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project does not yet have released versions.

## [Unreleased]

### Added — M10: weekly and all-time leaderboards

Only accepted runs rank, the server computes every rank, and the player's own entry is always
fresh.

- **Projection (release A).** `leaderboard_all_time` (one row per player) and
  `leaderboard_weekly` (one per player per Europe/Istanbul week), maintained PostgreSQL tables
  written **inline by the accepted finish**, after progression and the paw ledger — lock order
  RUN → PROGRESSION → PAW_LEDGER → LB_ALL_TIME → LB_WEEKLY. Flagged, rejected and replayed
  finishes never reach it, and a projection failure rolls the whole finish back. No cache, no
  queue, no scheduler, no Redis.
  - **One ORDER everywhere (E1):** `score DESC, achieved_at ASC, duration_ms ASC, run_id ASC`,
    with `achieved_at` = the run's server-recorded finish (D2). The same comparator picks each
    player's representative run and orders the board; `run_id` is unique per table, so the
    order is total.
  - **Monotone upsert:** a row is replaced only by a run that precedes it in ORDER, so writes
    are idempotent and a row only ever moves up (invariant M).
  - **Weeks** are computed with the IANA `Europe/Istanbul` zone in PHP and PostgreSQL alike —
    never a fixed `+03:00` — and attributed by the run's server-recorded start.
  - No public identity is stored: `display_name` is joined live. Foreign keys are
    `ON DELETE RESTRICT` (E2); LB-5 stays open for SEC-3.
  - The migration backfills every existing accepted run through the same upsert.
- **Reconciliation (release B).** A second migration re-merges accepted runs — closing the
  deploy window in which the previous release accepted runs without projecting them — and then
  **fails closed** unless the projection matches `runs`: player sets, best scores (also against
  `player_progression.best_score`), ORDER-first representatives per player and per week, and no
  row naming a run that is not accepted.
- **`GET /api/v1/leaderboards?window=weekly|all_time[&cursor][&limit]`** — verified accounts,
  normal rate limit. Entries are exactly `{rank, display_name, score, is_self}`; the response
  carries the week's UTC `period`, the caller's `own_entry` with its true rank (or `null`), and
  `meta.{next_cursor, has_more}`. Keyset pagination with an encrypted, window- and week-bound
  cursor (`422 cursor_invalid` when unusable); default 25, maximum 100. Each response is read
  from one `REPEATABLE READ READ ONLY` snapshot; across pages the board is live, with the
  consistency contract documented in `docs/api/endpoints/leaderboards.md`.
- **Contract:** the leaderboard operation is marked implemented; `player_id` and `achieved_at`
  are gone from entries, `username` is `display_name`, `is_self` is added, `next_cursor` is
  `string | null`. New validation codes `out_of_range` and `cursor_invalid`.
- **Tests:** representative selection at every tie level; order unity between the live
  projector, the backfill and an independent PHP comparator on random boards; the Istanbul
  calendar from 2015 (DST) to 2028 with PHP ↔ PostgreSQL parity; static pagination at 1, 7, 25
  and 100; every clause of the live consistency contract; cursor tampering; fail-closed
  reconciliation; and concurrency — parallel finishes, a same-key retry, reads during an
  uncommitted finish, and a response that stays one snapshot while other writes commit.
- **Performance:** `tests/Performance/leaderboard_explain.php` seeds one million players in an
  isolated schema and records the real reader's plans; results in `data-model.md` §5.
- **Not in M10:** ban filtering (M13), opt-out (LB-8), previous-week viewing (LB-9),
  deletion/anonymisation (LB-5, SEC-3).

### Added — M9: the server owns the run

ADR-0006 Layers 1 and 2, implemented. The client proposes; the server decides; only an
accepted run changes anything.

- **`POST /api/v1/game-runs`** — the run is created server-side **before** gameplay, with a
  server-recorded `started_at` (millisecond precision) and a server-issued uint32 `seed` from
  the CSPRNG. No run token: the run is its opaque UUID plus the authenticated actor.
  - **One active run per player**, as a partial unique index; the insert is
    `ON CONFLICT (user_id) WHERE status = 'active' DO NOTHING`, so two simultaneous starts
    produce one run and the other resumes it.
  - **Start/resume semantics (C-11):** shape first (`character_id` must be a JSON string
    matching `^[a-z0-9_]{1,32}$`, else `422`, even with an active run); a non-stale active run
    is resumed unchanged with its **original** character whatever was requested; catalogue
    availability is checked only when a run must be created (`422 character_unavailable`,
    nothing written).
  - **`RUN_STALE_REPLACEMENT_AFTER = 24 h`** — not an expiry. A start after 24 h replaces the
    old run (`rejected` / `run_stale_replaced`) and creates the new one in one transaction, and
    only if the new one can actually be created.
- **`POST /api/v1/game-runs/{runId}/finish`** — classified `accepted`, `flagged` or `rejected`.
  - Telemetry members must be JSON **integers** (`422` otherwise, including `12.0`); integers
    outside `0..2147483647` are `rejected`. Structural rules reject; PROPOSED-tuning rules only
    flag. Late arrival is never a reason to flag.
  - **Idempotent, durably:** `Idempotency-Key` (UUID) is stored on the run with a request
    fingerprint and no expiry — same key and request replays the stored result with zero
    writes; a different request is `409 idempotency_key_reused`; a final run under another key
    is `409 run_not_active`. No `idempotency_keys` table.
  - **Only accepted** updates progression (atomic SQL, lock order RUN → PROGRESSION →
    PAW_LEDGER) and appends a paw-ledger row. `bonuses_triggered` counts 200-paw threshold
    crossings — never Loli activations.
  - Unknown body members, including every ANTI-6 counter, are ignored: not persisted, not
    fingerprinted, not returned.
- **`GET /api/v1/progression`** — lifetime paws, Loli cycle, best score, accepted run count and
  tutorial completion. No telemetry counters (ANTI-6).
- **Schema:** `characters` (catalogue rows shipped by the migration), `player_progression`,
  `runs`, `paw_ledger`, each with CHECK constraints for its domain. No `run_events`, no run
  token, no ANTI-6 columns, no speculative leaderboard indexes.
- **Tutorial relocation — expand only.** `player_progression.tutorial_completed_at` is
  backfilled exactly from `users` by a migration that refuses to commit on any mismatch;
  completion is dual-written and read from either column. `users.tutorial_completed_at` is
  **not** dropped — that is a later, separate contract deployment. The wire stays
  `tutorial_completed: boolean`.
- **Rate limiting:** a `game-runs` limiter with separate per-user start and finish buckets,
  30/minute each. No per-IP bucket: behind the BFF every player shares one source address.
- **Contract:** the OpenAPI document now matches the implementation — `data` envelopes
  (including the tutorial response, which had drifted), the uint32 seed as an integer, UUID
  formats, the new problem codes, and no ANTI-6 fields. Response-conformance tests pin every M9
  response to it.
- **Tests:** a real-connection concurrency suite (`tests/Concurrency`) runs start/start,
  start/finish and finish/finish races in separate processes against committed PostgreSQL
  state, pausing one request mid-transaction to force each interleaving — including the READ
  COMMITTED re-select a start relies on after waiting on a stale run.
- **CI:** a PostgreSQL 18 compatibility job, and a migration rollback round-trip in both jobs.

### Fixed — M9 audit

- An accepted finish whose paw delta (or cycle plus delta) exceeded 32767 failed with a `500`
  instead of being applied: PostgreSQL typed the paw arithmetic as `smallint`, the cycle
  column's type. The progression update now computes in `bigint` and stores only the 0..199
  remainder.

### Added — M8: the tutorial is finished once, not once per device

One endpoint, one column, and deliberately nothing else. A player who has completed or
skipped the first-run tutorial should not meet it again because they refreshed, opened
another browser, or signed in on a phone — so completion is the account's fact, not the
device's.

- **`POST /api/v1/progression/tutorial`** — the endpoint the contract has described as
  APPROVED since M0, now implemented. It takes **no request body**: the actor is the
  bearer of the token, so there is no id to supply and no way to aim it at another
  account. Unaimable by construction rather than by a check somebody could forget.
- **Idempotent, structurally.** The write is a conditional
  `UPDATE … WHERE tutorial_completed_at IS NULL` whose affected-row count is the
  authority, so replaying the tutorial from Settings, a double submit and a network retry
  are all the same no-op. Reading the column and then writing it would be the lost-update
  bug the display-name cooldown was fixed for.
- **Skipping and finishing are the same call.** The product counts a skipped tutorial as
  completed, and which one happened is not a fact this API has any use for. Recording it
  would be tutorial telemetry — there is no completion count, no failure count, no
  duration and no per-lesson data, by choice.
- **The timestamp stays on the server.** `users.tutorial_completed_at` is the stored
  fact; what crosses the wire is the boolean derived from it, on `TutorialState` and as a
  read projection on `/auth/me`. **When** a player finished is not a decision any client
  makes differently, and a date in the browser would be analytics nobody asked for.
- **Verified, not merely authenticated** — a deliberate narrowing of the contract's
  "authenticated" row, recorded in `docs/api/endpoints/progression.md` so the route and
  the document cannot drift. The unverified tier is exactly four endpoints by design.

**Where the column lives, and where it is going.** Progression still owns tutorial
completion; `domain-boundaries.md` §4 is unchanged. The column sits on `users` only
because `player_progression` does not exist yet — it is PROPOSED, and every other column
in it is derived from accepted run submissions, which is M9 scope and blocked on
ADR-0006. `tutorial_completed_at` is the one row in that table with no verification
source. `docs/architecture/data-model.md` §4.1 records the M9 relocation **including the
backfill**, because skipping that would ask every existing player to repeat a tutorial
they had already finished — the precise failure server-side persistence exists to prevent.

**What did not change:** no gameplay, no scoring, no paw ledger, no leaderboard, no
authentication or session behaviour. *Tutorial runs are not runs* — the tutorial submits
nothing, and no endpoint exists by which a client could add to progression.

### Added — M7.1 (delivery label): safe initial administrator bootstrap

Production-readiness work, backend only. No gameplay, authentication or contract change;
the frontend is untouched at its M7 commit. The delivery-label row in
`purrenade/docs/product/milestones.md` — which is the canonical table and lives in the
frontend repository — is a deliberate follow-up for the next frontend change, so that this
milestone does not move the frozen frontend commit.

- **`php artisan purrenade:admin:promote <email>`** — the supported way to establish the first
  administrator. It grants the role to an account that **already exists and has already verified
  its address**, and does nothing else: it cannot create an account, set or reset a password,
  mark an address verified, touch a second factor, or grant anything in the game. Unknown and
  unverified addresses are refused; an account that is already an administrator is reported and
  left alone, with no write, revocation or log line.
- **Promotion closes the session it would otherwise hand over.** A player holding a session that
  already passed a two-factor challenge would satisfy every remaining admin condition the instant
  the role flipped. The command revokes all sessions and purges pending challenges in the same
  transaction, so administrative privilege begins at a sign-in performed after the change.
  `two_factor_version` is deliberately not bumped: a role change does not invalidate the factor.
- **`auth.admin.role_granted`** added to the existing `AuthLog` vocabulary, recording the user id,
  the resulting role and the number of sessions revoked — never the address.
- **`docs/architecture/operations.md`** — the production bootstrap runbook, the account model for
  players and testers (everyone registers themselves; playable characters are never an
  authorization mechanism), and the procedures deliberately not offered: no SQL role edit, no
  production seeder, no account-creating command, no configured administrator.

### Changed

- `docs/architecture/local-development.md` replaces the `tinker --execute` role-edit recipe with
  the command.
- `database/seeders/DatabaseSeeder.php` fixed against the current schema (`name` →
  `display_name`, plus the normalized column) and documented as local and test convenience that
  must never run in production. The seeded account is an ordinary player; seeding is named
  nowhere in the bootstrap runbook.

### Added — M2 (delivery label): authentication and access foundation

Delivered roadmap **M2 and M3** together. See `purrenade/docs/product/milestones.md` for the
delivery history and why the labels and the roadmap numbers do not line up one-for-one.

- **Auth core (roadmap M2)** — registration, login, email verification by 6-digit code with a
  42-second resend cooldown, password reset by link, the argon2id password policy with a breach
  check, two-dimensional rate limiting on every endpoint, and RFC 9457 Problem Details on every
  path of the host.
- **Two-factor, roles and sessions (roadmap M3)** — TOTP enrolment, challenge and disable; eight
  single-use recovery codes stored as keyed hashes; `player` and `admin` roles enforced by a
  database check constraint; mandatory admin two-factor bound to the credential generation; and
  session listing, per-session revocation and revoke-all over Sanctum token rows.

### Added — M1C (delivery label): runtime completion

- JSON root route, a readiness probe that checks PostgreSQL, systemd user services.

### Added — M1 (delivery label): repository bootstrap

- Laravel skeleton on PostgreSQL, Pint, PHPStan/Larastan, Pest, CI, `.env.example`. No product code.

### Changed — M0.6: decision normalization

**Newly APPROVED**
- **Audio Option C** — four persisted fields (`music_volume`, `music_muted`, `effects_volume`,
  `effects_muted`). **Mute is independent of volume**; unmuting restores the previous non-zero
  level, and mute is never stored as `volume = 0`.
- **Character unlock semantics** — Büşo from `best_score`, Ogito from accepted `run_count`
  (**no** duration filter), Sero from `lifetime_loli_activations` counting **actual**
  activations.
- **Leaderboard core rules** — Europe/Istanbul weekly boundary, total ordering, cursor
  pagination, banned-user visibility, opt-out support.
- **Display-name v1 baseline**; profanity and confusable screening deferred as future hardening.
- **Minimum admin capability set** — six moderation capabilities, with the out-of-scope list
  approved alongside.

**Fixed**
- Achievement verification counts corrected to **9 `DERIVED_PERSISTENT` / 7
  `DERIVED_TELEMETRY`**, and the classification split into **Verification Source** and
  **Progress Persistence**. Storing a cumulative total never reclassifies its evidence.
- `loli_activations` documented as **actual activations**, derived from validated telemetry and
  never inferred from the paw ledger — queued bonuses are run-scoped and can expire unstarted.
- Retired counters removed: `lifetime_score`, `max_loli_bonus_in_single_run`,
  `accepted_run_count_above_min_duration`.

**Notes**
- ADR-0006 gains **explicit retention latitude**: raw events need not be retained forever;
  validating at acceptance and persisting compact authoritative derived run facts is permitted
  and is the data-minimizing default.
- OpenAPI updated and lints clean: four audio fields, two achievement classification fields,
  `loli_activations` telemetry input, retired progression counters removed.
- Deleted-user retention/anonymization policy remains **OPEN**.
- Still no framework, dependency, migration, or application code. Bootstrap remains M1.

### Changed — M0.5: decision closure

**Newly APPROVED**
- **ADR-0005 accepted** — Nuxt BFF with server-managed session cookies for the browser, over a
  **token-capable** API. No endpoint may assume a browser, a cookie, or a same-site context; a
  future native client authenticates directly with a bearer flow. Laravel/Fortify/Sanctum
  remains the authentication authority.
- **Password reset never disables, resets, or bypasses 2FA** — a security invariant with its
  own regression gate (`S8`). 2FA recovery is a separate process.
- **Achievement progression authority** — client-reported summary counters alone are
  insufficient; progression is derived server-side from accepted, validated telemetry and
  authoritative persistent data. Makes telemetry retention required rather than optional
  (ANTI-5).
- **Loli Bonus queueing is run-scoped** — no `owed_loli_bonuses` column, no `Progression`
  field.

**Newly PROPOSED, awaiting review**
- Minimum v1 **admin capability set** — six moderation capabilities, explicitly not a back office.
- **Leaderboard rules** — Monday 00:00 Europe/Istanbul boundary, tie-break, cursor pagination,
  display-name rules, banned/deleted/opt-out visibility.
- **Character unlock semantics**, all server-derived.
- **Account deletion architecture**; retention and anonymization policy stays OPEN.

**Notes**
- Run telemetry fields added to the contract as **inputs to server-side derivation, never
  authoritative totals**. OpenAPI lints clean.
- Retained per-event gameplay data recorded as **behavioural personal data** with a retention
  obligation (SEC-5), interacting with the still-OPEN deletion policy.
- Still no framework, dependency, migration, or application code. Bootstrap remains M1.

### Added — M0: documentation foundation
- Repository hygiene files (`.gitignore`, `.gitattributes`, `.editorconfig`, CI stub, PR template).
- Documentation structure: `docs/architecture/`, `docs/api/`, `docs/security/`, `docs/testing/`, `docs/decisions/`.
- Architecture documentation: layering, domain boundaries, data model, queues, caching, observability, engineering standards, runtime/version research.
- API contract: conventions, OpenAPI draft, per-resource endpoint contracts, contract-change process.
- Security documentation: threat model, authentication, authorization and roles, two-factor, anti-cheat, rate limiting, data protection.
- Testing strategy and regression gates.
- ADR-0001, 0003, 0004, 0005, 0006, 0008.
- `LICENSE` placeholder recording that licensing remains undetermined.

### Notes
- No framework, dependency, migration, or application code exists yet. Bootstrap is M1.
- ADR-0005 (authentication transport) and ADR-0006 (run validation) remain **Proposed** and block implementation.
