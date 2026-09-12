# Authorization and Roles

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. The rule — APPROVED

**Authorization is enforced server-side, on every request, on the specific
resource.** No trust in frontend authorization. A route guard in the client is a
convenience; removing it must expose nothing.

---

## 2. Roles — APPROVED

| Role | Description |
| --- | --- |
| `player` | The default. Owns their account, runs, and progression. |
| `admin` | Privileged. **2FA mandatory.** Capabilities OPEN (SI-1). |

There is no third role in the approved surface. **OPEN (AD-2):** whether more
than one admin level is ever needed — nothing suggests it.

---

## 3. Role is not enough — APPROVED

The distinction that matters most in this product:

| Check | Answers | Sufficient? |
| --- | --- | --- |
| **Role check** | "Is this caller an admin?" | Only for admin-only surfaces |
| **Ownership check** | "Does this run/session/profile belong to this caller?" | **Required for every player resource** |

A role check on `/auth/sessions/{id}` would let any player revoke any other
player's session. Almost every endpoint in this API is an ownership check, not a
role check, and that is where authorization bugs will come from.

**Rule:** authorization is decided against the **resource**, not against the route.

---

## 4. Implementation — APPROVED, IMPLEMENTED

| Rule | Detail |
| --- | --- |
| Policies live in one place per resource | Not scattered through controllers |
| **Deny by default** | An endpoint with no explicit policy is denied, not allowed |
| Authorization runs **after** validation and **before** any side effect | |
| Field-level filtering | A response contains only what the caller may see, not merely a permitted endpoint |
| No authorization decision is cached | |

---

## 5. Mandatory admin 2FA — APPROVED

**2FA is mandatory for the admin role**, enforced server-side.

### It must be structural — APPROVED

Enforcement happens at a level that **new routes inherit automatically**. A
per-route annotation is one forgotten line away from a privileged bypass.

| Rule | Detail |
| --- | --- |
| An admin whose 2FA is not satisfied is denied on **every** admin-scoped route | Including routes added later |
| `POST /auth/2fa/disable` returns `403` for admins | An admin cannot opt out |
| The test is explicit and exhaustive | *An admin without satisfied 2FA is denied on every admin endpoint, by any route* |

---

## 6. Status codes — APPROVED, IMPLEMENTED

| Situation | Code |
| --- | --- |
| Not authenticated | `401` |
| Authenticated, lacks permission, **and may know the resource exists** | `403` |
| Authenticated, lacks permission, **and must not learn it exists** | `404` |
| Admin route, non-admin caller | `403` — the admin surface is not a secret |
| Admin route, admin without satisfied 2FA | `403` with a distinct, stable error code |

The `403`-versus-`404` distinction is an information-disclosure decision, not a
style one: another player's run id must return `404`, or the endpoint becomes an
existence oracle.

---

## 7. Verified-email gate — APPROVED, IMPLEMENTED (AUTH-1 resolved at M2)

An authenticated but **unverified** account may reach exactly four endpoints:

```
GET  /auth/me
POST /auth/email/verify
POST /auth/email/verify/resend
POST /auth/logout
```

Enough to learn that verification is required, to complete it, and to leave.
Everything else waits — **including two-factor enrolment and session
management**. An unverified account is one whose owner has not been shown to
control the address, and the cost of waiting is one code.

Enforced by `verified` middleware on the route **group**, so an endpoint added
later is gated by default. A test enumerates the authenticated routes and asserts
that exactly those four lack the gate, so the allowance cannot widen unnoticed.

---

## 8. Testing — APPROVED

Authorization is tested with **negative cases**, which are the ones that matter:

| Test | Assertion |
| --- | --- |
| Cross-account access | Player A cannot read or modify Player B's run, session, or profile |
| Role escalation | A player cannot reach any admin endpoint |
| **Admin without 2FA** | Denied on **every** admin endpoint |
| Unauthenticated access | Every protected endpoint returns `401` |
| Unverified access | Gated endpoints are refused |
| Existence disclosure | Another player's resource returns `404`, not `403` |
| Deny-by-default | A route without a policy is denied |

---

## 9. Open questions

| Ref | Question |
| --- | --- |
| AD-5 | Per-capability request/response shapes for the approved six-capability admin console, to be contracted at M13 |
| ~~AUTH-1~~ | **Resolved at M2:** four endpoints. §7. |
| AD-2 | Is more than one admin level needed? |
| AD-4 | Is admin access restricted by network or device in addition to 2FA? |
