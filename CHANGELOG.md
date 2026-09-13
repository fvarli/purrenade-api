# Changelog

All notable changes to this repository are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project does not yet have released versions.

## [Unreleased]

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
