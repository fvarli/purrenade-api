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

### Password policy — OPEN (SEC-1)

Nothing in the references specifies one. What must be decided:

| Question | PROPOSED direction |
| --- | --- |
| Minimum length | Length is the property that matters; favour a meaningful minimum over composition rules |
| Composition requirements | Modern guidance is against mandatory character-class rules — they produce predictable passwords |
| **Breach-list checking** | Recommended. Credential stuffing is the realistic attack, and rejecting known-breached passwords addresses it directly. |
| Maximum length | High, but bounded, so hashing cost cannot be weaponized |
| Feedback | v0.3 board 07 shows a strength meter — that is **copy**, not policy. Policy is server-side. |

---

## 3. Email verification — APPROVED

**A 6-digit code, not a magic link** (v0.3 board 04).

| Rule | Status |
| --- | --- |
| Codes are **hashed at rest** | APPROVED |
| Single-use, consumed on success | APPROVED |
| Attempt-limited per code, then invalidated | PROPOSED |
| TTL | **OPEN (SEC-2)** |
| Resend cooldown — v0.3 displays 0:42 | **OPEN (SEC-2)** |
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
| A successful reset **revokes all existing sessions** | PROPOSED — a reset usually means a suspected compromise |
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

**Tested, not asserted.** This is a named regression gate (`S8`), verified by a test that
completes a reset and then asserts that enrolment, secret and recovery codes are unchanged and
that the next login is still challenged. See
[`../testing/regression-gates.md`](../testing/regression-gates.md).

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
| Session lifetime, idle timeout, absolute timeout | **OPEN** (ADR-0005 q3) |
| Does revoke-all include the current session? | **OPEN (AUTH-2)** |

---

## 7. Rate limiting and lockout — APPROVED in principle

Every authentication endpoint is rate-limited per identifier **and** per source.
See [rate-limiting.md](rate-limiting.md).

**OPEN (AUTH-3):** lockout policy. The tension is real — a hard lockout on failed
attempts converts credential stuffing into a denial-of-service against the
targeted account. Progressive delay is usually preferable to lockout.

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
| ADR-0005 | *(Direction Accepted.)* Remaining at M2: Sanctum mode upstream, session store, timeouts, CSRF pattern |
| SEC-1 | Password policy, including breach-list checking |
| SEC-2 | Code and token TTLs; resend cooldown |
| AUTH-1 | What an unverified player may access |
| AUTH-2 | Does revoke-all include the current session? |
| AUTH-3 | Lockout policy |
| AUTH-4 | Device labelling and location derivation |
