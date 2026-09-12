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

## 2. Enrolment — PROPOSED

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

## 3. Verification — PROPOSED

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
| Count, and whether they can be regenerated | **OPEN** (ADR-0005 q6) |
| Does regeneration invalidate the previous set? | **OPEN** — it should |

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

## 5. Mandatory for admin — APPROVED

| Rule | Detail |
| --- | --- |
| An admin **cannot disable 2FA** — `POST /auth/2fa/disable` returns `403` | |
| An admin without satisfied 2FA is **denied on every admin endpoint** | Enforced structurally, so new routes inherit it |
| Promoting a player to admin requires 2FA to be enabled first, or forces enrolment before any admin capability works | PROPOSED |

See [authorization-and-roles.md](authorization-and-roles.md) §5.

---

## 6. Challenge point — OPEN (ADR-0005 q4)

| Model | Trade-off |
| --- | --- |
| **At login** | Simple, matches v0.3 board 05's placement in the login flow, one challenge per session |
| **Step-up before sensitive actions** | Stronger for long-lived sessions; more friction; needs a definition of "sensitive" |

**PROPOSED:** challenge at login, with step-up considered later for admin actions
specifically — admins have the most to lose and the fewest sessions.

---

## 7. What is not in v1 — PROPOSED

| Not included | Why |
| --- | --- |
| **SMS 2FA** | Weakest common factor; adds cost and a phone-number personal-data surface |
| **WebAuthn / passkeys** | Stronger and worth a later look, but requires packages beyond the framework's built-in support and is not in the approved surface |
| **Email as a second factor** | Not a second factor when email already controls password reset |

Recorded so their absence is a decision, not an oversight. WebAuthn is the one
most likely to be revisited.

---

## 8. Failure modes to handle — PROPOSED

| Situation | Handling |
| --- | --- |
| Player loses their authenticator | Recovery codes |
| Player loses authenticator **and** recovery codes | **OPEN (2FA-3)** — there must be *some* answer, and **password reset is not it** (§4A). Any support-mediated recovery is itself an attack path and must be designed deliberately, not improvised. |
| Admin loses both | **OPEN**, and worse — an admin lockout has no self-service answer that is also safe |
| Device clock drift | Skew tolerance (§3) |

---

## 9. Open questions

| Ref | Question |
| --- | --- |
| 2FA-1 | Recovery-code count and regeneration policy |
| 2FA-2 | Challenge at login or step-up |
| 2FA-3 | **Account recovery when both factors are lost** |
| 2FA-4 | Admin lockout recovery |
| 2FA-5 | Is WebAuthn planned beyond v1? |
