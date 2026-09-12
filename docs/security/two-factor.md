# Two-Factor Authentication

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. Approved shape — APPROVED

From Claude Design v0.3 boards 05 and 20:

- **TOTP via an authenticator app** — "Doğrulama uygulamandaki 6 haneli kod"
- **Backup/recovery codes** — "Yedek kod kullan"
- **Recommended for all accounts**
- **Mandatory for the admin role**

---

## 2. Enrolment — APPROVED, IMPLEMENTED

| Step | Rule |
| --- | --- |
| 1. Begin | Generate a secret; return provisioning data for QR display |
| 2. Prove | The player submits a current code. **2FA is not active until possession is proven.** |
| 3. Issue | Recovery codes are generated and returned **exactly once** |
| 4. Store | The secret is **encrypted at rest**; recovery codes are **hashed** |

Step 2 exists because activating 2FA on an unproven secret locks the player out
of their own account — a self-inflicted denial of service that is entirely
preventable.

---

## 3. Verification — APPROVED, IMPLEMENTED

| Concern | Rule |
| --- | --- |
| Algorithm | Standard TOTP, 6 digits, 30-second step |
| Clock skew | One step either side — enough for real clock drift, small enough to stay tight |
| **Replay** | A code already used within its time step is **rejected** |
| Attempt limiting | Rate-limited per account; repeated failures are a signal |
| Comparison | Constant-time |

Replay rejection matters because a TOTP code is valid for a whole window: without
it, an intercepted code can be reused within that window.

---

## 4. Recovery codes — APPROVED

| Rule | Status |
| --- | --- |
| Returned **exactly once**, at enrolment | APPROVED |
| Stored **hashed**, never plaintext | APPROVED |
| **Single use**, enforced by a unique database constraint | APPROVED |
| Consumption is logged | APPROVED — rare, and worth noticing |
| Count | **RESOLVED at M2: 8.** Enough to survive several lost-authenticator events without the list feeling disposable, few enough that printing or storing them is realistic. Each one is a full bypass of the second factor, so more is not better. |
| Regeneration | **IMPLEMENTED.** Requires `current_password`. |
| Does regeneration invalidate the previous set? | **RESOLVED: yes, entirely.** A player asking for new codes is saying the old list is lost or exposed; leaving it valid would answer a security action by doubling the number of live bypasses. |
| Storage | **IMPLEMENTED** as one row per code, hashed with a keyed HMAC, in `two_factor_recovery_codes` — not Fortify's encrypted JSON column, which is reversible and enforces single use only in application code. Consumption is one atomic conditional `UPDATE`, so two concurrent requests cannot spend the same code. |

---

## 4A. 2FA recovery is separate from password reset — APPROVED INVARIANT

**A password reset must never silently disable, reset, or bypass 2FA.**

| Rule | Detail |
| --- | --- |
| Reset leaves enrolment, secret and recovery codes **untouched** | |
| The next login **still requires the 2FA challenge** | |
| Losing an authenticator is recovered through **2FA recovery**, never through password reset | |

Email already controls password reset. A reset that also cleared 2FA would make mailbox
compromise sufficient for full account takeover, and the second factor would defend against
nothing. See [authentication.md](authentication.md) §4.1 — the invariant and its regression
gate live there.

## 4B. A change to the second factor ends the other sessions — APPROVED (M2 audit)

Rotating a second factor is what somebody does when the old one is lost or
stolen. Before M2's audit, none of these transitions touched a session at all:

| Transition | Other sessions | The caller's own |
| --- | --- | --- |
| Begin enrolment (new secret) | revoked | kept |
| Confirm enrolment | revoked | kept |
| Disable | revoked | kept |
| Regenerate recovery codes | revoked | kept |

The caller's session survives, matching the rule an authenticated password change
already followed: a credential-affecting action ends every session except the one
performing it. Ejecting the caller mid-setup — right after showing them eight
recovery codes they may not have saved — would be worse than useless.

But surviving must not mean **keeping privileged trust**. The `two-factor`
ability cannot be withdrawn from a token that already exists, so the account also
carries a generation counter that each of these transitions advances, and a
session's satisfaction is only honoured against a matching generation. See
`purrenade-api/docs/architecture/auth-architecture.md` §3A. Without it,
disabling and re-enabling handed the surviving session its privileges back.

For an administrator this has a visible consequence, and it is the intended one:
re-enrolling a secret costs them the admin surface until they sign in again and
pass a challenge against the new one.

---

## 5. Mandatory for admin — APPROVED

| Rule | Detail |
| --- | --- |
| An admin **cannot disable 2FA** — `POST /auth/2fa/disable` returns `403` | |
| An admin without satisfied 2FA is **denied on every admin endpoint** | Enforced structurally, so new routes inherit it |
| Promoting a player to admin requires 2FA to be enabled first, or forces enrolment before any admin capability works | PROPOSED |

See [authorization-and-roles.md](authorization-and-roles.md) §5.

---

## 6. Challenge point — RESOLVED at M2 (ADR-0005 q4)

**At login.** One challenge per session, matching v0.3 board 05's placement.

Step-up before individual sensitive actions was considered and is not adopted for v1: it
needs a definition of "sensitive" that would drift as endpoints are added, and the risk it
actually addresses — a long-lived session being used by somebody else — is covered instead
by requiring `current_password` on every security-sensitive action. That is stateless,
identical for a browser and a native client, and impossible to leave half-implemented,
because there is no confirmation window that might still be open from an earlier action.

How "this session passed the challenge" is represented without a session store: the
`two-factor` ability is welded onto the token at the moment it is minted, and **no endpoint
adds it to an existing token**. See `../architecture/auth-architecture.md` §3.

---

## 7. What is not in v1 — APPROVED

| Not included | Why |
| --- | --- |
| **SMS 2FA** | Weakest common factor; adds cost and a phone-number personal-data surface |
| **WebAuthn / passkeys** | Stronger and worth a later look, but requires packages beyond the framework's built-in support and is not in the approved surface |
| **Email as a second factor** | Not a second factor when email already controls password reset |

Recorded so their absence is a decision, not an oversight. WebAuthn is the one
most likely to be revisited.

---

## 8. Failure modes to handle

| Situation | Handling |
| --- | --- |
| Player loses their authenticator | Recovery codes |
| Player loses authenticator **and** recovery codes | **OPEN (2FA-3)** — there must be *some* answer, and **password reset is not it** (§4A). Any support-mediated recovery is itself an attack path and must be designed deliberately, not improvised. |
| Admin loses both | **OPEN**, and worse — an admin lockout has no self-service answer that is also safe |
| Device clock drift | **IMPLEMENTED** — one time step of tolerance either side |
| A code intercepted inside its 30-second window | **IMPLEMENTED** — replay refused per account and durably, via the highest accepted time step on the user row. Fortify's own provider caches `md5(code)` globally instead, so two accounts emitting the same digits in one window interfere, and a cache flush forgets every used code. |
| A player who has spent every recovery code | **Surfaced at M2**: `/auth/me` reports the remaining count and the account screen warns when it reaches zero. Recovery beyond that is 2FA-3, still OPEN. |

---

## 9. Open questions

| Ref | Question |
| --- | --- |
| ~~2FA-1~~ | **Resolved at M2:** 8 codes, regeneration invalidates the previous set. §4. |
| ~~2FA-2~~ | **Resolved at M2:** at login. §6. |
| 2FA-3 | **Account recovery when both factors are lost** |
| 2FA-4 | Admin lockout recovery |
| 2FA-5 | Is WebAuthn planned beyond v1? |
