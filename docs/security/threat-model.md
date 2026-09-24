# Threat Model

What is being defended, against whom, and where the real risk is.

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. Assets — APPROVED

| Asset | Why it matters |
| --- | --- |
| **Player accounts** | Email, password hash, 2FA secrets, recovery codes, session records |
| **Personal data** | Email, username, approximate location, device labels, play history. **KVKK applies** — the primary audience is Turkish. |
| **Leaderboard integrity** | The product's competitive core. A ranking nobody believes is worthless. |
| **Progression** | Paw ledger, achievements, unlocks — the retention loop |
| **Admin capability** | Whatever it turns out to be (SI-1), it is privileged by definition |
| **Availability** | A casual game that is down is a game nobody returns to |

---

## 2. Adversaries — PROPOSED

| Adversary | Motivation | Capability |
| --- | --- | --- |
| **The curious player** | Sees their own score in a console variable and changes it | Browser devtools. **The most numerous by far.** |
| **The determined cheater** | Wants the top of the leaderboard | Can read the bundle, script the API, forge plausible submissions |
| **The credential attacker** | Wants accounts, or reuses leaked passwords | Automated credential stuffing |
| **The spammer** | Wants the product's email infrastructure to send their mail | Automated registration and resend abuse |
| **The abusive user** | Wants an offensive display name seen by everyone | An ordinary account and the leaderboard |
| **The opportunist** | Scans for known vulnerabilities | Automated scanners |

Note that the first row — not the sophisticated attacker — is the one the
architecture is primarily shaped by. A public ranking plus a client-side score
makes casual tampering the default behavior, not an edge case.

---

## 3. The central threat — APPROVED

**The game runs entirely on hardware the player controls.**

Everything that produces a score — simulation, timers, collision, the score
accumulator — executes in the player's browser and can be modified. A public
leaderboard turns that into an incentive.

| Mitigation | Status |
| --- | --- |
| Score is **server-authoritative**; the client proposes | **APPROVED** |
| Submission is **idempotent** | **APPROVED** |
| Structural validation — protocol, identity, lifecycle, tuning-independent impossibilities | **APPROVED** (ADR-0006, Layer 1) — **may reject** |
| Plausibility bounds derived from tuning values | **APPROVED** (ADR-0006, Layer 1) — **flag only**, while the tuning they derive from is still PROPOSED |
| **Server-owned run identity and a server-issued seed** | **APPROVED** (ADR-0006, Layer 2). A run is created server-side, `active` and bound to the authenticated actor, before gameplay. **One active run per user**, enforced in the database. |
| A separate browser-held **run token** | **Not adopted.** It adds no property beyond *authenticated actor + opaque `run_id` + server-side ownership*, and §4 already defends XSS on the grounds that the browser holds no portable credential. |
| Telemetry replay against the pure domain | **Deferred beyond v1**; the domain is kept portable for exactly this. Its absence is what leaves **ANTI-6** open. |
| **Client-side obfuscation** | **Explicitly not a control** |

See [anti-cheat.md](anti-cheat.md) and
[ADR-0006](../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md).

---

## 4. Threats by surface — PROPOSED

### Authentication

| Threat | Mitigation |
| --- | --- |
| Credential stuffing | Rate limiting per account and per source; **breach-list checking via Pwned Passwords k-anonymity** (SEC-1 resolved at M2) |
| Account enumeration | Identical responses for unknown and wrong credentials; identical forgot-password response regardless of existence; timing that does not distinguish |
| Verification-code brute force | Codes hashed at rest, attempt-limited, TTL-bounded, rate-limited |
| Reset-token reuse or theft | Hashed, single-use, short-lived; a successful reset revokes sessions (PROPOSED) |
| 2FA bypass | Challenge enforced server-side; recovery codes single-use by unique constraint; replay within a time step rejected |
| **2FA bypass via password reset** | **Invariant: a reset never disables, resets, or bypasses 2FA.** Otherwise mailbox compromise alone yields full account takeover and the second factor defends against nothing. Tested by gate `S8` — see [authentication.md](authentication.md) §4.1 |
| **Session cookie theft via XSS** | The browser holds **no bearer token**; its only credential is an `HttpOnly` cookie it cannot read. A script injection cannot exfiltrate a portable credential. |
| **CSRF against the BFF** | Cookie auth is inherently CSRF-exposed; the **BFF enforces a CSRF token** on every state-changing request. `SameSite` is defence in depth, not the control. |
| **Admin without 2FA** | Enforced **structurally**, so new routes inherit it — not per-route |
| Session fixation / stale sessions | Revocation is immediate, per session and globally |

### Submission and progression

| Threat | Mitigation |
| --- | --- |
| Forged score | Server-authoritative score + validation (ADR-0006) |
| Replayed submission | Idempotency key with a unique constraint |
| Double-counted paws | Atomic ledger update inside the transaction |
| Duplicate achievement unlock | Unique `(player, achievement)` |
| Two concurrent runs | **At most one active run per user**, enforced by a partial unique index, not an application check (ADR-0006, GR-4). Starting again resumes the existing run rather than creating a second. |
| Fabricated achievement | **Every achievement is `DERIVED_PERSISTENT` or `DERIVED_TELEMETRY`.** Client-reported summary counters are **never** adopted as authoritative progression — see [anti-cheat.md](anti-cheat.md) §2.1. Because nothing in v1 can establish a `DERIVED_TELEMETRY` fact, none is persisted or returned at M9 — **ANTI-6**. |
| A stale retry re-applying progression | The idempotency identity lives with the durable run history; **there is no expiry window** an old retry could outlive (GR-3) |

### Leaderboard

| Threat | Mitigation |
| --- | --- |
| Inflated entries | Only `accepted` runs appear: the projection is written only on the accepted branch of the finish transaction, the backfill filters on `status = 'accepted'`, and the release-B migration asserts it against `runs` (M10) |
| A client-supplied rank or score | There is no write endpoint. Scores come only from accepted `runs.score`; every rank is computed by the server per request |
| Tie gaming by holding a run open | Tie priority is `achieved_at` = the **server-recorded finish** (D2), so holding a run open only ever loses ties. Residual: tie rule 3 uses the claimed `duration_ms` — APPROVED, and bounded by the `duration_below_minimum` flag rule |
| Abusive display names | APPROVED v1 baseline plus **admin force-rename** (M13). Automated screening is future hardening (LB-7) |
| Enumeration of the player base | Entries carry only `rank`, `display_name`, `score` and `is_self` — no user id, run id, email or timestamp. Verified accounts only, 60 reads a minute. Cursors are encrypted and carry no user id |
| Cursor forgery or tampering | Authenticated encryption under APP_KEY, plus version, window and schema checks → `422 cursor_invalid`. A rotated key is a harmless restart from page 1 |
| Pathological paging | `limit` ≤ 100; cursor ≤ 512 characters of a strict charset; keyset only, no OFFSET; every query an index range scan |
| Banned or opted-out players shown | **Not enforced in M10** — no ban or opt-out state exists yet. The reader keeps one visibility predicate, applied to the page and both rank counts, for M13 and LB-8 |

### Email

| Threat | Mitigation |
| --- | --- |
| Using the product as a spam relay | Strict rate limits on registration, resend and forgot-password; per-address and per-source |
| Reputation damage from bounces | Bounce handling (OPEN) |

### Personal data

| Threat | Mitigation |
| --- | --- |
| Leakage through logs | **Structured logging with redaction at the logger**, not at each call site |
| Leakage through error responses | Documented envelope; never a stack trace or internal detail |
| Leakage through third parties | **Self-hosted fonts** rather than a font CDN; error-tracker acceptability is OPEN (OB-2) |
| Over-retention | Retention policy — **OPEN (SEC-3)** |

---

## 5. Explicitly out of scope — APPROVED

| Not defended against | Why |
| --- | --- |
| A player modifying their own client | Impossible to prevent; **the server's job is to make it not matter** |
| A player sharing their own account | Their data, their choice |
| Nation-state adversaries | Not a proportionate threat model for a casual game |
| Physical device compromise | Outside the product boundary |

Naming these matters: it prevents effort being spent on client hardening that
cannot work, instead of on server validation that can.

---

## 6. Assumptions — APPROVED

1. **The browser client is untrusted.** Always. Every frontend check is a
   convenience.
2. **TLS everywhere.** No plaintext transport, in any environment.
3. **The database is not directly reachable** from the public internet.
4. **Secrets live in the environment**, never in the repository.
5. **An admin is trusted but audited.** Every admin action is recorded
   append-only.

---

## 7. Open questions

| Ref | Question |
| --- | --- |
| ADR-0005 | *(Direction Accepted.)* Session lifetime, CSRF pattern and 2FA enforcement point remain, at M2 |
| ~~ADR-0006~~ | **Accepted 2026-09-22.** Layer 1 + Layer 2 in v1; Layer 3 deferred with the domain kept portable; no separate run token. |
| **ANTI-6** | **How the four `DERIVED_TELEMETRY` run facts are established.** Until something can, they are neither persisted nor returned. **Blocks M11.** |
| ~~SEC-1~~ | **Resolved at M2.** Password policy and breach-list checking — [authentication.md](authentication.md) §2. |
| SEC-3 | KVKK/GDPR: deletion, export, consent, retention |
| SEC-4 | Published security contact and disclosure timeline |
| LB-7 | Automated profanity and confusable screening — **future hardening**, not a v1 blocker. Admin force-rename is the approved v1 answer to an abusive or impersonating name. |
| OB-2 | Is an external error tracker acceptable under KVKK? |
| ~~ANTI-5~~ | **Resolved by ADR-0006.** Data minimization: no raw per-event gameplay history is retained, and no `run_events` table is created. The surface SEC-3 must cover is correspondingly smaller. |
