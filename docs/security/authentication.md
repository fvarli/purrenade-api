# Authentication

**Status legend:** APPROVED / PROPOSED / OPEN.
**Transport is APPROVED (M0.5)** — a Nuxt BFF with server-managed session cookies over a
token-capable Laravel API. See
[ADR-0005](../decisions/ADR-0005-authentication-and-2fa-strategy.md).

---

## 1. Approved surface — APPROVED

Authentication is **required**; Purrenade is not guest-first.

- registration
- email/password login
- **email verification by 6-digit code** with a resend cooldown
- **password reset by emailed link**
- **2FA (TOTP + backup codes)** — recommended for all, **mandatory for admin**
- roles: `player`, `admin`
- **server-side authorization**, no trust in frontend authorization
- active session/device management with per-session and global revocation

---

## 2. Credentials — PROPOSED

| Concern | Rule |
| --- | --- |
| Password hashing | A modern memory-hard algorithm with tuned cost parameters, reviewed as hardware changes |
| Storage | Only the hash. Never the password, never a reversible form. |
| Comparison | Constant-time |
| Rehashing | Transparent on login when parameters change |

### Password policy — APPROVED, IMPLEMENTED (SEC-1 resolved at M2)

| Rule | Value |
| --- | --- |
| Minimum length | **12 characters** |
| Maximum length | **128**, as a validation error — never a silent trim |
| Composition requirements | **None** |
| Breach-list checking | **On**, via Pwned Passwords k-anonymity |
| Confirmation | Required on register, reset and change |
| Hashing | **argon2id**, 64 MiB / 4 passes / 1 thread |

Length is the property that resists guessing; mandatory character classes mostly
produce `Purrenade1!`. Twelve rather than eight because a public leaderboard
gives every account a reason to be attacked and player 2FA is optional.

The maximum exists because hashing cost is otherwise attacker-controlled — a
one-megabyte password is a denial-of-service request. It is a **refusal**, not a
truncation: a password quietly shortened to fit is one the player cannot
reproduce. That is also why the hasher is argon2id and not bcrypt, which ignores
everything past 72 bytes.

The breach check is its own validation rule rather than `Password::uncompromised()`,
because Laravel's `Password` rule aggregates every check into one failed-rule
name — a client would then receive the same code for "too short" and for "this
password has been published", two problems needing opposite advice.

v0.3 board 07's strength meter is **copy, not policy**, as this section always
said. It never blocks a submit, and it cannot: the server checks things no client
heuristic can know, the breach corpus above being the obvious one.

---

## 3. Email verification — APPROVED

**A 6-digit code, not a magic link** (v0.3 board 04).

| Rule | Status |
| --- | --- |
| Codes are **hashed at rest** | APPROVED |
| Single-use, consumed on success | APPROVED |
| Attempt-limited per code, then invalidated | **IMPLEMENTED — 5 attempts** |
| TTL | **IMPLEMENTED — 10 minutes** (SEC-2 resolved at M2) |
| Resend cooldown — v0.3 displays 0:42 | **IMPLEMENTED — 42 seconds** (SEC-2) |
| Issuing a new code invalidates the previous one | **IMPLEMENTED** — without it, every resend would *widen* an attacker's window rather than refreshing it |
| A code cannot verify another account | **IMPLEMENTED, structurally** — the endpoint is authenticated and the code is looked up by the caller's own id |
| Resend is rate-limited per address **and** per source | APPROVED |
| The code is dispatched by a **queued job after commit** | APPROVED |

A 6-digit code has a small keyspace, so **its security comes entirely from TTL,
attempt limits and rate limiting** — not from the code itself. That is why those
three are not optional details.

---

## 4. Password reset — APPROVED

**An emailed link, not a code** (v0.3 board 06).

| Rule | Status |
| --- | --- |
| Tokens are hashed at rest, single-use, time-limited | APPROVED |
| The `forgot` response is **identical whether or not the address exists** | APPROVED — enumeration resistance |
| A successful reset **revokes all existing sessions** | **IMPLEMENTED** — including any the caller holds. A reset normally answers a suspected compromise, so the point is to evict whoever else is signed in, which has to include sessions the request cannot identify. |
| **Reset never disables, resets, or bypasses 2FA** | **APPROVED — a security invariant.** See §4.1 |

### 4.1 Password reset never touches 2FA — APPROVED INVARIANT

**A password reset must never silently disable, reset, or bypass two-factor authentication.**

| Rule | Detail |
| --- | --- |
| A completed reset leaves **2FA enrolment** untouched | The account remains 2FA-enabled |
| It leaves the **TOTP secret** untouched | No re-enrolment is triggered |
| It leaves **recovery codes** untouched | None are consumed, invalidated, or regenerated |
| The **next login still requires the 2FA challenge** | Reset changes one factor, not both |
| **2FA recovery is a separate process** | With its own security-sensitive design — see [two-factor.md](two-factor.md) §8 |

**Why this is an invariant and not a preference.** Email already controls password reset. If a
reset also cleared 2FA, then compromising a mailbox would compromise the entire account in one
step, and the second factor would provide no protection against precisely the attack it exists
to stop. A player who has lost their authenticator must go through 2FA recovery — deliberately,
and on its own terms.

**Tested, not asserted.** Regression gate `S8`, **implemented at M2** as five separate
tests in `tests/Feature/Auth/PasswordResetTest.php` — one per property, so a partial
regression cannot hide behind a passing neighbour: enrolment still enabled, secret
unchanged, no recovery code consumed or regenerated, the next login still challenged, and
no challenge pre-marked as satisfied. Also exercised end to end in the manual acceptance
pass. See [`../testing/regression-gates.md`](../testing/regression-gates.md).

Mechanically, the reset callback writes **exactly two columns** — `password` and
`remember_token`. Nothing two-factor is named in it, so the invariant cannot be broken by
editing a value, only by adding a field.

---

## 4.2 What each password path invalidates — APPROVED (M2 audit)

Stated as one table, because "the reset revokes sessions" turned out not to be
the whole answer.

| | Authenticated change (`PUT /auth/password`) | Reset by link (`POST /auth/password/reset`) |
| --- | --- | --- |
| Requires `current_password` | yes | no — the emailed token is the proof |
| The caller's own session | **kept** | **revoked** |
| Every other session | **revoked** | **revoked** |
| Pending two-factor challenges | **purged** | **purged** |
| Two-factor enrolment, secret, recovery codes | untouched | untouched (§4.1) |
| `remember_token` | untouched | rotated |

The asymmetry on the caller's own session is the point. A deliberate change by
someone who can already prove the old password is not the same event as a reset:
signing them out of the screen they just used is friction with no security value.
A reset is normally a response to suspected compromise, so it evicts everyone
including sessions this request cannot identify.

**The purge is the part that was missing.** Revoking tokens left a pending
`two_factor_challenges` row behind — a half-authenticated handle opened on the
strength of the *old* password, redeemable for the rest of its five minutes, and
redeeming it minted a full session after every existing one had been destroyed.
Both paths now purge. Covered by the `S9` tests.

---

## 5. Enumeration resistance — APPROVED

| Surface | Rule |
| --- | --- |
| Login | The same generic error for unknown email and wrong password |
| Login timing | An unknown email still performs a hash comparison, so timing does not distinguish |
| Forgot password | The same response regardless of existence |
| Registration | Inherently reveals that an address is taken; mitigated by rate limiting and a generic message |

---

## 5A. Session transport — APPROVED

Per [ADR-0005](../decisions/ADR-0005-authentication-and-2fa-strategy.md):

| Concern | Rule |
| --- | --- |
| Browser credential | An **`HttpOnly`, `Secure`, `SameSite=Lax`** session cookie set by the Nuxt BFF |
| Bearer tokens in the browser | **Never.** Not in `localStorage`, not in `sessionStorage`, not anywhere script-readable. |
| Upstream credential | Held **server-side in the BFF session**; it never reaches the browser |
| CSRF | Enforced at the **BFF** on every state-changing request. The BFF hop relocates CSRF exposure; it does not remove it. |
| Laravel→BFF hop | Not CSRF-exposed — the BFF is not a browser and attaches credentials explicitly |
| Origin model | The browser talks to **one origin**; no CORS, no cross-origin credentialed requests |
| Cookie rotation | On every privilege change: login, 2FA pass, password change |
| Logout | Revokes the BFF session **and** the upstream credential |
| Native clients | Authenticate **directly against this API** with a bearer flow, bypassing the BFF. Not implemented in v1. |

**Laravel remains the authentication authority.** The BFF is a client of it and never makes an
authorization decision.

## 6. Sessions — APPROVED

| Rule | Status |
| --- | --- |
| Revocation is **immediate**, per session and globally | APPROVED |
| Sessions record device label, approximate location, last-seen | APPROVED (v0.3 board 20) |
| Device label and location are **personal data** and fall under retention policy | APPROVED |
| Session lifetime, idle timeout, absolute timeout | **IMPLEMENTED** — idle 7 days at the BFF, absolute 30 days at both layers |
| Does revoke-all include the current session? | **RESOLVED (AUTH-2): no.** `DELETE /auth/sessions` keeps the caller's own session. The action is "get everyone else out", and `POST /auth/logout` already exists for the other intent — ending the caller's session here would make every use of the control finish at the login screen. |
| A session **is** a Sanctum token row | **IMPLEMENTED** — so revocation deletes the credential itself rather than asking a browser to forget a cookie |

---

## 7. Rate limiting and lockout — APPROVED in principle

Every authentication endpoint is rate-limited per identifier **and** per source.
See [rate-limiting.md](rate-limiting.md).

**RESOLVED (AUTH-3) at M2: progressive throttling, no lockout, ever.** A hard lockout on
failed attempts converts credential stuffing into a reliable denial-of-service against any
account whose address an attacker knows — it makes the attack *easier*. There is no lockout
state anywhere in this system.

Every limiter is two-dimensional (per identifier **and** per source, both of which must be
satisfied), because per-account alone misses one host attacking a thousand accounts and
per-IP alone misses a botnet attacking one. Concrete values are in
[rate-limiting.md](rate-limiting.md) §2 and in `App\Support\RateLimits`.

---

## 8. Rules that hold regardless of the transport decision — APPROVED

1. The actor is resolved **from the credential**, never from the request body.
2. Authorization is checked on **every** request, never inherited.
3. No credential, code, or token is logged, ever.
4. No credential, code, or token is stored in plaintext.
5. TLS everywhere, in every environment.
6. The transport lives behind **one swappable module** on the client, so the
   ADR-0005 decision does not ripple through the frontend.

---

## 9. Open questions

| Ref | Question |
| --- | --- |
| ~~ADR-0005~~ | **All ten M2 questions resolved.** See `../architecture/auth-architecture.md`. |
| ~~SEC-1~~ | **Resolved at M2.** §2. |
| ~~SEC-2~~ | **Resolved at M2.** §3, and rate-limiting.md §2. |
| ~~AUTH-1~~ | **Resolved at M2:** four endpoints. See `authorization-and-roles.md` §7. |
| ~~AUTH-2~~ | **Resolved at M2:** revoke-all keeps the current session. §6. |
| ~~AUTH-3~~ | **Resolved at M2:** progressive throttling, no lockout. §7. |
| AUTH-4 | Device labelling **resolved**; location and its retention remain OPEN. `../architecture/auth-architecture.md` §8. |
| AUTH-4 | Device labelling and location derivation |
