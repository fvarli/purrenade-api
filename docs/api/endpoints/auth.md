# Endpoints — Auth

**Contract shape only. No implementation exists.**

**Transport is APPROVED (M0.5):** a Nuxt BFF with server-managed session cookies for the
browser, over a token-capable API. Parameter choices (session lifetime, CSRF pattern, 2FA
challenge point) are made at M2. See
[ADR-0005](../../decisions/ADR-0005-authentication-and-2fa-strategy.md).

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## Summary

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| POST | `/auth/register` | public | no | strict |
| POST | `/auth/login` | public | no | **strict** |
| POST | `/auth/logout` | authenticated | yes | normal |
| POST | `/auth/email/verify` | authenticated | no | strict |
| POST | `/auth/email/verify/resend` | authenticated | no | **very strict** |
| POST | `/auth/password/forgot` | public | no | **very strict** |
| POST | `/auth/password/reset` | public | no | strict |
| POST | `/auth/2fa/challenge` | challenge token | no | **strict** |
| POST | `/auth/2fa/enable` | authenticated | no | normal |
| POST | `/auth/2fa/confirm` | authenticated | no | strict |
| POST | `/auth/2fa/disable` | authenticated, **never admin** | no | strict |
| GET | `/auth/sessions` | authenticated | — | normal |
| DELETE | `/auth/sessions/{id}` | authenticated | yes | normal |
| DELETE | `/auth/sessions` | authenticated | yes | normal |

---

## Registration — APPROVED

Creates an unverified account and dispatches a **6-digit verification code**.

**Validation intent:** username present and within rules (**OPEN, LB-3**); email
syntactically valid and not already registered; password meets policy
(**OPEN, SEC-1**).

**Enumeration:** registration inherently reveals that an address is taken. Mitigate
with rate limiting and a generic error rather than a distinguishing message.

**Side effects:** queued verification email. Dispatched **after commit**.

## Login — APPROVED

Returns either an authenticated player or a **two-factor challenge requirement**.

**Rules**
- **The same generic error for unknown email and wrong password.** Different
  errors are an enumeration oracle.
- Constant-time-comparable behavior: an unknown email still performs a hash
  comparison, so response timing does not leak existence.
- Failure count is tracked per account **and** per source. Lockout policy is
  **OPEN** (ADR-0005 question 10).
- An unverified account may sign in but is routed to verification. Which
  endpoints an unverified player may reach is **PROPOSED**: profile and
  verification only.

## Email verification — APPROVED

**A 6-digit code, not a magic link** (v0.3 board 04).

| Rule | Status |
| --- | --- |
| Codes are **hashed at rest**, never stored in plaintext | APPROVED |
| Single-use; consumed on success | APPROVED |
| Attempt limit per code, then invalidation | PROPOSED |
| Code TTL | **OPEN (SEC-2)** |
| Resend cooldown — v0.3 displays 0:42 | **OPEN (SEC-2)** |

**Resend** returns the remaining cooldown rather than an error, so the client can
render the countdown the design shows.

## Password reset — APPROVED

**An emailed link, not a code** (v0.3 board 06) — deliberately different from
verification.

| Rule | Status |
| --- | --- |
| `forgot` returns the **same response whether or not the address exists** | APPROVED — prevents enumeration |
| Tokens are hashed at rest and single-use | APPROVED |
| Token TTL | **OPEN (SEC-2)** |
| A successful reset **revokes all existing sessions** | PROPOSED — a reset usually means a compromise |
| **A reset never disables, resets, or bypasses 2FA** | **APPROVED — security invariant** |

#### The 2FA invariant — APPROVED

A completed password reset leaves **2FA enrolment**, the **TOTP secret** and **recovery codes**
entirely untouched, and **the next login still requires the 2FA challenge**.

Email already controls password reset. A reset that also cleared 2FA would make mailbox
compromise sufficient for complete account takeover, and the second factor would protect
against nothing. A player who has lost their authenticator uses **2FA recovery**, which is a
separate, deliberately-designed process.

Tested by regression gate `S8`, not asserted in prose. See
[`../../security/authentication.md`](../../security/authentication.md) §4.1.

## Two-factor — APPROVED

TOTP via an authenticator app, with single-use recovery codes (v0.3 board 05).

| Rule | Status |
| --- | --- |
| Recovery codes returned **exactly once**, at confirmation | APPROVED |
| Recovery codes stored **hashed**; single use enforced by a unique constraint | APPROVED |
| **2FA cannot be disabled by an admin** — `disable` returns `403` for the admin role | APPROVED |
| Enrolment requires proving possession before activation | APPROVED |
| Code replay within the same time step is rejected | PROPOSED |
| Clock-skew tolerance window | PROPOSED — one step either side |
| Challenge at login, or step-up before sensitive actions | **OPEN** (ADR-0005 q4) |
| **2FA is never cleared by a password reset** | **APPROVED** — see the reset section above |
| Recovery-code count and regeneration | **OPEN** (ADR-0005 q6) |

See [`../../security/two-factor.md`](../../security/two-factor.md).

## Sessions — APPROVED

Lists active sessions with device label, approximate location, relative last-seen
time, and a flag for the current session (v0.3 board 20).

| Rule | Status |
| --- | --- |
| Revocation takes effect **immediately** | APPROVED |
| Revoking a session the caller does not own returns `404`, not `403` | PROPOSED — existence is not disclosed |
| Revoke-all may or may not include the current session | **OPEN** — v0.3's "sign out of all devices" is ambiguous |
| How a device is labelled and how location is derived | **OPEN** — and both are personal data (SEC-3) |

---

## Open questions

| Ref | Question |
| --- | --- |
| SEC-1 | Password policy |
| SEC-2 | Code/token TTLs and the resend cooldown |
| AUTH-1 | What an unverified player may access |
| AUTH-2 | Does revoke-all include the current session? |
| AUTH-3 | Lockout policy on repeated failures |
| AUTH-4 | Device labelling and location derivation, and their privacy implications |
