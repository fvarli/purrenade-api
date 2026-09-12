# ADR-0005 — Authentication and 2FA strategy

- **Status:** **Accepted** (direction). Implementation details re-researched at M2.
- **Scope:** Product-wide
- **Date:** 2026-09-12
- **Decision owner:** Product owner + backend

> Supersedes the M0 draft, which recorded this as *Proposed — decision required*.
> No package has been selected and no flow has been implemented. This ADR fixes the
> **architecture**; the concrete package and parameter choices are made at M2 after
> re-researching current best practice.

---

## Context

### Approved requirements

Authentication is **required**; this is not a guest-first product. The approved surface is:
registration · email/password login · **email verification by 6-digit code** with a resend
cooldown · **password reset by emailed link** · **2FA (TOTP with backup codes)**, recommended
for all and **mandatory for admin** · role-aware access (`player`, `admin`) ·
**server-side authorization** with no trust in frontend authorization · active session/device
management with per-session and global revocation.

### The constraint that shapes the decision

**Future Android/iOS distribution must remain possible.** A native shell **cannot rely on
same-site browser cookies**. A browser-only cookie design would require building a second
authentication path later — for the same flows, the same 2FA, and the same session management.

### The second constraint

Frontend and backend are separate repositories and will very likely be separate hosts.
Cookie-based SPA authentication requires them to share a **parent domain**, or requires the
Nuxt server to proxy the API so the browser only ever talks to one origin.

This makes ADR-0005 and the Nuxt rendering/BFF question **one decision, not two**.

### Ecosystem research — 2026-09-12

| Finding | Detail |
| --- | --- |
| Mainstream Laravel pattern | **Fortify** provides the flows (registration, login, email verification, password reset, 2FA); **Sanctum** provides SPA session authentication **or** API tokens. Complementary, not alternatives. |
| Sanctum SPA mode | Same-top-level-domain session cookies with a CSRF cookie endpoint |
| Sanctum token mode | Bearer tokens, suitable for a native client |
| 2FA scope | TOTP only out of the box; no WebAuthn/passkeys without additional packages |
| Versions at verification | `laravel/fortify` 1.39.0, `laravel/sanctum` 4.3.3, `laravel/passport` 13.8.0 |

**Re-research this section before implementation begins at M2.** These findings are dated.

---

## Decision

**A Nuxt BFF-oriented architecture with secure, server-managed session/cookie authentication
for the browser product, over a token-capable Laravel API.**

1. **The browser never holds a persistent bearer token.** No access or refresh token is stored
   in `localStorage` or `sessionStorage`. The browser's only credential is an opaque session
   cookie issued by the Nuxt server.
2. **Nuxt acts as a BFF.** The browser talks only to the Nuxt origin. Nuxt holds the
   server-side session and attaches the appropriate credential when calling Laravel.
3. **Laravel + Fortify + Sanctum remains the authentication authority.** The BFF is a client of
   it, never a second source of truth. Every authentication decision, every authorization
   decision, and all 2FA state live in Laravel.
4. **The Laravel API stays token-capable**, so a future native client authenticates directly
   against it with an appropriate bearer-token flow.
5. **The native flow is not implemented in v1 web development.** It is a preserved capability,
   not a deliverable.

### 1 — Browser / BFF / session-cookie flow

```
  Browser ──(1) credentials ──► Nuxt BFF ──(2) authenticate ──► Laravel + Fortify
     ▲                              │                                  │
     │                              │◄──(3) authenticated principal ───┘
     │                              │
     │◄─(4) Set-Cookie: session ────┘   BFF stores the API credential server-side
     │
  Browser ──(5) request + cookie ─► Nuxt BFF ──(6) request + API credential ─► Laravel
```

1. The browser posts credentials to the Nuxt BFF over TLS.
2. The BFF forwards them to Laravel, which is the sole authority on whether they are valid and
   on whether a 2FA challenge is required.
3. Laravel returns the authenticated principal, or a pending-2FA state.
4. The BFF establishes its **own server-side session** and sets an opaque session cookie on the
   browser. **The API credential never reaches the browser.**
5. Subsequent browser requests carry only that cookie.
6. The BFF resolves the session and attaches the API credential when calling Laravel.

A 2FA challenge suspends the flow at step 3: the BFF holds a short-lived pending state and
issues no full session cookie until Laravel confirms the challenge. Logout revokes the BFF
session **and** the upstream credential — a BFF session that outlives its API credential is a
bug, not a convenience.

### 2 — CSRF boundary

Cookies are attached by the browser automatically, so cookie authentication is inherently
CSRF-exposed. **The BFF hop does not remove that** — it relocates it. The BFF becomes the
CSRF boundary:

| Rule | Detail |
| --- | --- |
| Every state-changing request to the BFF carries a CSRF token | Double-submit or synchroniser pattern, decided at M2 |
| The token is **not** in an `HttpOnly` cookie | It must be readable by the client to be sent; the session cookie is the `HttpOnly` one |
| Safe methods carry no token | `GET`/`HEAD` must have no side effects, per the API conventions |
| `SameSite` is defence in depth, not the control | It reduces exposure; it is not a substitute for a token |
| **Laravel→BFF is not CSRF-exposed** | The BFF is not a browser and attaches credentials explicitly, so no ambient authority exists on that hop |
| The BFF validates its own origin | Requests failing the origin check are rejected before the session is resolved |

### 3 — Cookie security

| Attribute | Value | Reason |
| --- | --- | --- |
| `HttpOnly` | **yes** | The session cookie must be unreachable from JavaScript; this is the reason for the whole design |
| `Secure` | **yes** | TLS everywhere, in every environment |
| `SameSite` | `Lax` | `Strict` breaks return-from-email flows (verification, password reset); `None` needlessly widens exposure on a same-origin design |
| `Path` | `/` | |
| Name | host-prefixed | Prevents a subdomain from overwriting it |
| Lifetime | idle timeout + absolute timeout | Values at M2 |
| Rotation | on privilege change — login, 2FA pass, password change | Prevents session fixation |
| Server-side store | the session is a **server-side record**, the cookie only a reference | Revocation must be immediate and real |

### 4 — Origin assumptions

| Assumption | Consequence |
| --- | --- |
| The browser talks to **one origin**: the Nuxt application | **No CORS for the browser.** No cross-origin credentialed requests. |
| The Laravel API need not share a parent domain with the frontend | Deployment is not constrained to a single DNS parent |
| The API may be network-restricted to the BFF for browser traffic | While staying publicly reachable for future native clients |
| The BFF is a **stateful, security-relevant component** | It is inside the security boundary, is deployed with the frontend, and must be operated accordingly |
| The API remains **origin-agnostic and token-capable** | Which is what keeps the native path open |

The last two are the real cost of this decision and are recorded as such in the frontend
`SECURITY.md`.

### 5 — Future native extension

A native Android/iOS client is a **direct client of the Laravel API**, bypassing the BFF:

```
  Native app ──credentials──► Laravel + Fortify ──bearer token──► native secure storage
  Native app ──Authorization: Bearer …──► Laravel API
```

| Requirement carried now | Why |
| --- | --- |
| The API accepts a bearer credential in addition to whatever the BFF uses | Otherwise the native path needs new endpoints |
| No endpoint assumes a browser, a cookie, or a same-site context | Otherwise the native path needs new logic |
| 2FA, verification and reset flows are transport-agnostic | Otherwise the whole auth surface is duplicated |
| Tokens are stored in platform secure storage, never in web storage | Applies to the native client when it is built |

**None of this is implemented in v1.** The obligation on v1 is only that nothing forecloses it.

### 6 — Why the alternatives were rejected

#### Cookie-only direct SPA (Sanctum SPA against Laravel, no BFF) — rejected

- Requires the frontend and API to **share a parent domain**, constraining deployment for a
  reason unrelated to the product.
- Requires cross-origin credentialed requests and a CSRF cookie endpoint, which is precisely
  the configuration browsers keep tightening.
- **Forecloses the approved native requirement**: same-site cookies do not work from a native
  shell, so a second authentication path would have to be built later.
- Its main advantage — no token in JavaScript — is fully retained by the BFF design.

#### Bearer-only in the browser — rejected

- The token must live somewhere the browser can read, and every such location is reachable by
  XSS. A single script-injection defect becomes complete, exportable account takeover.
- Requires refresh/rotation machinery in the client, which is more code in the least trusted
  place.
- Its main advantage — origin flexibility and one path for all clients — is retained: the API
  stays token-capable for native, while the browser simply does not use that path.

#### OAuth2 / Passport — rejected

No third-party clients exist. Passport's complexity buys nothing a first-party product needs
and adds a large surface to secure.

---

## Consequences

**Easier**
- No token is exposed to JavaScript; XSS cannot exfiltrate a portable credential.
- No CORS and no cross-origin cookie behaviour for the browser.
- Deployment is not constrained to a shared DNS parent.
- The native path is a client of the same API, not a second auth system.
- Revocation is real: the BFF session is a server-side record.

**Harder**
- **The Nuxt server is now stateful and security-relevant.** It must be deployed, monitored,
  and patched as part of the security boundary. The frontend `SECURITY.md` is corrected accordingly.
- One extra network hop per request.
- Session storage and its lifecycle are an operational concern the frontend did not previously have.
- CSRF must be implemented at the BFF; the hop relocates the problem rather than removing it.

**Now constrained**
- No browser code may store a bearer token.
- No API endpoint may assume a cookie, a browser, or a same-site context.
- The BFF may never become an authorization authority — Laravel decides, always.
- Frontend transport stays behind **one swappable module**
  (`purrenade/docs/architecture/api-client.md` §2).

---

## Decided at M2, after re-research

| # | Question |
| --- | --- |
| 1 | Which Sanctum mode the BFF uses upstream, and the exact Fortify configuration |
| 2 | Session store, idle timeout, absolute timeout, rotation triggers |
| 3 | CSRF pattern: double-submit or synchroniser |
| 4 | 2FA challenge point: at login, or step-up before sensitive actions |
| 5 | Where mandatory-admin-2FA is enforced so **no admin route can bypass it** |
| 6 | Recovery-code count, storage, single-use semantics, regeneration |
| 7 | Session/device model: device identification, location derivation, revocation semantics |
| 8 | Password policy — length, composition, breach-list checking (SEC-1) |
| 9 | Email verification code TTL, resend cooldown (v0.3 shows 0:42), rate limits |
| 10 | Lockout and throttling policy on repeated failures |
