# Authentication Architecture

How authentication actually works in this service, as implemented at **M2**.

**Status legend:** APPROVED / **IMPLEMENTED** / PROPOSED / OPEN.

The product rules live in [`../security/authentication.md`](../security/authentication.md),
[`../security/two-factor.md`](../security/two-factor.md) and
[`../security/authorization-and-roles.md`](../security/authorization-and-roles.md).
This document is about the mechanism.

---

## 1. The shape of it — IMPLEMENTED

```
  browser ──cookie──► Nuxt BFF ──Bearer token──► this API ──► PostgreSQL
              │            │
   HttpOnly,  │            │  server-side session holds the token;
   opaque,    │            │  the browser never receives it
   __Host-    │            │
              │            └─ CSRF boundary lives here
              │
  future native app ──Bearer token──► this API   (same endpoints, no BFF)
```

Three properties follow, and everything else in this document is in service of
them:

1. **This API is stateless and token-authenticated.** It accepts no cookie at
   all — Sanctum's stateful-domain list is empty on purpose (§4).
2. **The browser never holds a bearer token.** Its only credential is a cookie
   it cannot read, issued by the BFF.
3. **A native client is not a second implementation.** It calls these endpoints
   with the same scheme, bypassing the BFF.

---

## 2. Sanctum mode — IMPLEMENTED (resolves ADR-0005 q1)

**Token mode. Not SPA mode.**

| Setting | Value | Why |
| --- | --- | --- |
| `sanctum.stateful` | **empty** | A stateful domain re-enables cookie authentication against this service. SPA mode also requires the frontend and API to share a parent domain and exchange CSRF cookies cross-origin — constraints that exist to serve browsers, and that a native client would inherit for nothing. |
| `sanctum.guard` | **empty** | The default `['web']` makes the session guard the first thing tried on every request. A web session would then satisfy `auth:sanctum` with no token — and therefore with no record of whether a two-factor challenge was passed, which is the one question the admin gate depends on. |
| `sanctum.routes` | **false** | `GET /sanctum/csrf-cookie` serves nothing in token mode, but it is still a route that starts a session and sets a cookie on an API that has neither. |
| `sanctum.expiration` | **43200** (30 days) | The **absolute** session lifetime. The **idle** timeout lives in the BFF — see §5. |
| `sanctum.token_prefix` | `prrn_` | So a secret scanner can recognise a leaked Purrenade token in a commit or a log. |

### One token is one session

There is no second session concept. A row in `personal_access_tokens` **is** a
signed-in device:

| Session concept | Where it lives |
| --- | --- |
| Identity | `tokenable_id` |
| Device label | `name`, a coarse label derived server-side (§8) |
| Created / last active | `created_at` / `last_used_at`, which Sanctum maintains |
| Client-facing id | `public_id`, a UUID added by migration (§8) |
| How it authenticated | `abilities` (§3) |

That is what makes revocation immediate and real, as ADR-0005 §3 requires:
deleting the row destroys the credential, rather than asking a browser to forget
a cookie.

---

## 3. Abilities: how a session records *how* it authenticated — IMPLEMENTED

Every token carries `session`. A token minted by `POST /auth/2fa/challenge` also
carries `two-factor`, and **no endpoint adds that ability to an existing token**.

This is the mechanism behind mandatory admin 2FA, and the distinction it encodes
is the one that is easy to miss:

| Question | Answered by |
| --- | --- |
| Does this **account** have a second factor? | `users.two_factor_confirmed_at` |
| Did **this session** prove one? | the token's `two-factor` ability |

An admin gate that checked only the first would admit a token minted by a
password-only login — from before enrolment, or by any path that skipped the
challenge — on an account that merely *has* 2FA configured.

Tokens are **never** issued with the `*` wildcard, and
`PersonalAccessToken::satisfiedTwoFactor()` deliberately does not use
`tokenCan()`, which returns true for a wildcard. Nothing in this application
issues one; the local check makes that a guarantee rather than a convention.

---

## 3A. The ability is not enough on its own — IMPLEMENTED (M2 audit)

The `two-factor` ability records that *a* challenge was passed. It does not
record **which secret** it was passed against, and Sanctum cannot withdraw an
ability from a token that already exists. So the two facts diverge the moment the
credential changes:

1. an attacker holds a 2FA-satisfied session;
2. the owner notices and rotates the second factor — disable, re-enrol, confirm,
   which is the textbook response to a stolen authenticator;
3. the account has confirmed 2FA again, and the attacker's token still carries
   `two-factor`.

At step 3 every account-level check passes, because each one is individually
true. What is false is their conjunction: that session never met *this* secret.
The admin gate would have re-admitted it.

**`users.two_factor_version`** closes it. A monotonic counter advanced by each of
the four changes that alter what the second factor *is*:

| Transition | Advances the generation | Revokes other sessions |
| --- | --- | --- |
| `beginEnrolment` (a new secret) | yes | yes |
| `confirmEnrolment` | yes | yes |
| `disable` | yes | yes |
| `replaceRecoveryCodes` | yes | yes |

A token records the generation it was challenged against
(`personal_access_tokens.two_factor_version`, stamped by `SessionIssuer` and
nowhere else), and `PersonalAccessToken::satisfiesTwoFactorFor()` requires the
ability **and** an equal generation. A token issued before the counter existed
holds `null`, which equals no generation — treated as unsatisfied, which is the
safe direction.

Two mechanisms, and both are needed. The eviction handles the sessions this
request can reach; the counter handles the one it cannot, because the caller's
own session survives by design. Without the counter, disable-then-re-enable
hands that surviving session its privileges back.

**The consequence is deliberate:** an administrator who rotates their secret
loses the admin surface until they sign in again and pass a challenge against the
new one. Re-enrolling is a credential change, and privileged trust should not
survive it.

---

## 3B. A password change is not the whole eviction — IMPLEMENTED (M2 audit)

Revoking tokens leaves a pending `two_factor_challenges` row behind. A challenge
is opened on the strength of a correct password and then lives in its own table
for five minutes, so whoever knew the **old** password kept a redeemable,
half-authenticated handle across the reset — and redeeming it minted a full
session *after* every existing one had been destroyed.

Both password paths now call `TwoFactorChallengeService::purgeForUser()`
alongside the token revocation. Covered by the `S9` tests, the companion of `S8`:
S8 says a reset must not weaken the second factor, S9 says it must not leave the
first factor's leftovers redeemable.

---

## 4. The Fortify / Sanctum responsibility split — IMPLEMENTED

Fortify ships two things, and this project takes one of them.

**Taken — the machinery:**

| From Fortify | Used for |
| --- | --- |
| `TwoFactorAuthenticationProvider` | Secret generation and the `otpauth://` URI |
| `pragmarx/google2fa` | TOTP verification arithmetic |
| `RecoveryCode::generate()` | Recovery-code format |
| `bacon/bacon-qr-code` | Server-rendered QR image |
| The `two_factor_*` column semantics | Encrypted secret, confirmation timestamp |

**Declined — the HTTP layer.** `Fortify::ignoreRoutes()` in
`App\Providers\FortifyServiceProvider`, and `features` enables only
`twoFactorAuthentication`.

The reason is ADR-0005 rather than taste. Fortify's routes park login state in
the HTTP session (`login.id`, between the password step and the two-factor step)
and answer with redirects. This API is stateless and token-authenticated
specifically so a native client can call the same endpoints — and a native client
has no session and follows no redirect. So routing is ours
(`App\Http\Controllers\Auth`) and calls Fortify's primitives underneath.

**What that buys:** no reimplementation of TOTP, recovery-code formats,
encrypted-secret storage or breach-checked password rules. **What it costs:** our
own login, challenge and logout endpoints — which are needed anyway, because
their responses are RFC 9457 and their credential is a token.

`laravel/passkeys` arrives as a Fortify dependency. Passkeys are explicitly out
of scope ([two-factor.md](../security/two-factor.md) §7), so its routes are
disabled too: an unused authentication surface is still an authentication
surface.

---

## 5. Sessions, timeouts and rotation — IMPLEMENTED (resolves ADR-0005 q2)

Two timeouts, each owned by the layer that can observe what it measures:

| Timeout | Value | Where | Why there |
| --- | --- | --- | --- |
| **Idle** | 7 days | BFF | The BFF is what sees browser activity. This is the timeout that matters for a shared or stolen device. |
| **Absolute** | 30 days | both | `sanctum.expiration` is the backstop if the BFF layer is ever bypassed, so a session cannot outlive its credential. |

**Rotation on every privilege change** — login, a passed two-factor challenge, a
password change. Enforced structurally: the BFF's `startSession()` always mints a
new identifier and is the only function that writes a session, so rotation cannot
be forgotten. That is the session-fixation defence.

**Session lifecycle by event:**

| Event | Other sessions | This session |
| --- | --- | --- |
| `POST /auth/logout` | untouched | ended |
| `DELETE /auth/sessions` | ended | kept (resolves **AUTH-2**) |
| `PUT /auth/password` (change) | ended | kept, rotated |
| `POST /auth/password/reset` | ended | ended |

The last two differ deliberately. A deliberate change by someone who can already
prove the old password is not the same event as a reset: a reset normally answers
a suspected compromise, so it evicts everyone including sessions the request
cannot identify.

---

## 6. CSRF — IMPLEMENTED (resolves ADR-0005 q3)

**Synchroniser token, at the BFF.** Not double-submit, and not here.

- **Not here**, because this API is not reached by ambient browser authority.
  The BFF is not a browser and attaches its credential explicitly, so there is
  nothing for a cross-site request to ride on.
- **Synchroniser, not double-submit**, because the BFF has a real server-side
  session to compare against. Double-submit compares a header to a cookie and
  trusts that only our own page could have set the cookie — which fails if any
  subdomain can write cookies for the parent domain. Having a session store makes
  the stronger pattern free.

Details are in the frontend's
`docs/architecture/bff-and-session.md`, which owns that boundary.

---

## 7. The two-factor challenge — IMPLEMENTED (resolves ADR-0005 q4)

**At login, not step-up.** One challenge per session, matching v0.3 board 05's
placement.

Step-up before individual sensitive actions was considered and is not adopted for
v1: it needs a definition of "sensitive" that would drift, and the actual risk it
addresses — a long-lived session being used by somebody else — is covered by
requiring `current_password` on every security-sensitive action (§9).

Between the password and the session sits a row in `two_factor_challenges`:

| Property | Value |
| --- | --- |
| Token | 32 random bytes, stored as a keyed HMAC (§10) |
| TTL | 5 minutes |
| Attempts | 5, then the challenge is destroyed |
| Confers | Nothing. It proves only that the first factor was satisfied. |

Fortify would keep this in the HTTP session; a row is what makes it work for a
client that has none.

**Replay is refused, per account and durably.** `two_factor_last_used_timestep`
on the user row holds the highest accepted TOTP step, and `verifyKeyNewer`
refuses anything at or below it. Fortify's own provider caches `md5(code)`
globally instead, which means two accounts whose authenticators emit the same six
digits in one window interfere with each other, and a cache flush forgets every
used code.

Clock skew: one step either side.

---

## 8. Session and device metadata — IMPLEMENTED (partially resolves AUTH-4)

| Recorded | Not recorded |
| --- | --- |
| Coarse device label, e.g. `Chrome on Android` | The raw User-Agent |
| `created_at`, `last_used_at` | IP address |
| Whether the session passed 2FA | Derived location |

The label comes from `App\Support\DeviceLabel`, which maps the User-Agent onto a
closed set of short strings. The raw header is never stored: it is long, it is
high-entropy enough to be a fingerprint on its own, and it is attacker-controlled
input that would otherwise be rendered back to the player.

**IP address and location remain OPEN (AUTH-4)** and are not recorded at all.
v0.3 board 20 shows an approximate location; delivering it needs a geo-IP source
and a retention policy decided together, and storing the address "for later"
would create the personal-data obligation before the decision that governs it.
The device label plus the timestamps deliver what the screen is *for* — letting a
player recognise their own devices and spot one they do not.

**The BFF forwards the browser's User-Agent** so this service can derive the
label at all; without it the API sees the BFF's own agent and every session reads
"Unknown device". The forwarded value is length-capped and character-filtered at
the BFF and never stored raw here.

`public_id` (UUID) is what clients address. The primary key is a sequential
integer, and handing those out invites `DELETE /auth/sessions/41` against
somebody else's session — which the ownership check refuses, but which should not
be expressible.

---

## 9. Re-authentication — IMPLEMENTED

**`current_password` on the sensitive request itself.** Not a confirmation
window.

Required by: begin 2FA enrolment · disable 2FA · regenerate recovery codes ·
change password · sign out other devices.

Fortify offers a window: confirm once, and sensitive actions unlock for a few
hours. That design needs an HTTP session to remember the confirmation, and this
API has none. Per-request re-authentication is also impossible to leave
half-implemented — there is no window that might still be open from an earlier
action — and it is identical for a browser and a native caller.

`current_password` is a framework validation rule, so nothing is compared by
hand.

Not required by `POST /auth/2fa/confirm`: the caller already re-authenticated to
*start* enrolment, and that step is itself a possession proof. Asking again would
add friction to the one screen where a player is already juggling two devices.

---

## 10. Secret storage — IMPLEMENTED

Three kinds of secret, three treatments, and using the wrong one for any of them
is a real defect:

| Secret | Storage | Why |
| --- | --- | --- |
| **Password** | argon2id, 64 MiB / 4 passes | Memory-hard, as `authentication.md` §2 requires — and no silent truncation, which bcrypt would inflict at 72 bytes on a 128-character policy. |
| **TOTP secret** | Encrypted (`encrypted` cast) | Reversible by necessity: verification needs the secret back to compute the expected code. |
| **Email verification code** | argon2id, looked up by `user_id` | Six digits is a keyspace of one million. A *fast* hash of it is reversible by exhaustive search in microseconds, so the cost of hashing is the whole defence against a leaked table. |
| **Recovery code / challenge token** | HMAC-SHA256 keyed with `APP_KEY` | ~100 bits of entropy, so exhaustive search is not a threat and a fast hash loses nothing — and being deterministic it can be **indexed**, which is what allows recovery-code consumption to be one atomic conditional `UPDATE` rather than a read-then-write race. |

The asymmetry between the last two is the point: low-entropy secrets need a slow
hash, high-entropy secrets need an indexable one. See `App\Support\KeyedHash`.

**Rotating `APP_KEY` invalidates every recovery code and pending challenge.**
That is the accepted cost of keying the digest: the alternative is an unkeyed
hash that a stolen table makes verifiable offline.

Recovery codes live in their own table, one row per code, hashed — not in
Fortify's encrypted JSON column. `two-factor.md` §4 makes "stored hashed" and
"single use, enforced by the database" APPROVED rules, and an encrypted blob is
reversible while an array rewrite is enforced only by application code.

---

## 11. Password policy — IMPLEMENTED (resolves SEC-1)

| Rule | Value |
| --- | --- |
| Minimum length | **12** |
| Maximum length | **128**, as a validation error — never a silent trim |
| Composition rules | **None** |
| Breach check | **On**, via Pwned Passwords k-anonymity |
| Confirmation | Required on register, reset and change |

Length is the property that resists guessing; mandatory character classes mostly
produce `Purrenade1!`. Twelve rather than eight because a public leaderboard
gives every account a reason to be attacked and player 2FA is optional.

The maximum exists because hashing cost is otherwise attacker-controlled: a
one-megabyte password is a denial-of-service request. It is a refusal, not a
truncation — a password quietly shortened to fit is one the player cannot
reproduce.

The breach check is a separate rule (`App\Rules\NotCompromised`) rather than
`Password::uncompromised()`, because Laravel's `Password` rule aggregates every
check into one failed-rule name. A client would then get the same code for "too
short" and for "this password has been published" — two problems needing opposite
advice. Split, each failure carries its own stable code.

#### The failure policy — APPROVED (M2 audit)

Only the first five characters of the SHA-1 leave the server. Never the password,
never the whole hash; `Add-Padding` is sent, so response size does not identify
the bucket either. Five hex characters cover roughly one password in a million,
which is not an identification.

**Fail open, and never silently.** A password confirmed breached is refused; a
provider that cannot be reached refuses nothing. Breach checking is defence in
depth behind a 12-character minimum, argon2id, two-dimensional throttling and the
second factor — failing closed would let a third party's outage take
registration, password reset and password change down together, including for an
administrator trying to recover an account mid-incident.

The framework's version of this was fail-open too, but **invisibly**: it caught
its own transport errors and returned an empty result set, which reads exactly
like "this password appears in no breach". An outage and a clean answer were the
same event. `App\Support\BreachCheck` keeps the mechanism and the policy and
records every skipped check on the security channel as
`breach_check_unavailable`, carrying the reason and no password material of any
kind.

**Bounded.** The framework default is a 30-second in-band timeout on three
endpoints reachable without authentication — a denial-of-service amplifier
pointed at our own workers through a service we do not control. It is
`auth.breach_check.timeout`, and it defaults to **3 seconds**. There is no retry:
a retry in the request path multiplies exactly what the timeout bounds.

---

## 12. Email verification — IMPLEMENTED (resolves SEC-2)

**A six-digit code, not a magic link** (v0.3 board 04), while keeping Laravel's
verified-email semantics: success sets `email_verified_at` and fires `Verified`,
so `MustVerifyEmail` and anything built on it keeps working.

| Rule | Value |
| --- | --- |
| Code | 6 digits from `random_int`, zero-padded — so `000042` is valid and the keyspace is the full million |
| TTL | **10 minutes** |
| Attempts per code | **5**, then the code is destroyed |
| Resend cooldown | **42 seconds** — the number v0.3 renders as `(0:42)` |
| Resend limit | 5/hour per account, 15/hour per source |
| Submission limit | 10 per 10 minutes per account, 30 per source |
| At rest | argon2id hash |
| Replacement | Issuing a new code **deletes the previous one** |
| On success | Consumed |

Six digits is not much, so the security of this flow is entirely in those rules
rather than in the code. In particular, replacement matters: without it every
resend would *widen* an attacker's window instead of refreshing it.

**Cross-account use is structurally impossible.** The endpoint is authenticated
and the code is looked up by the caller's own id, so a code issued for one
account cannot verify another regardless of who holds it. A challenge also
records the address it was issued for, so an account that changes its email
mid-flow cannot be verified by the old code.

The cooldown answers **429 with `retry_after`**, not a generic error — the design
renders a live countdown, and it can only do that if the server says how long is
left.

Mail is a queued notification dispatched `afterCommit`: a code for a row a
rollback removes is worse than a slightly later mail. Locally
`QUEUE_CONNECTION=sync` runs it inline, so there is no worker to start.

---

## 13. Rate limiting and lockout — IMPLEMENTED (resolves AUTH-3)

**Progressive throttling. No lockout, ever.** A hard lockout on failed attempts
converts credential stuffing into a reliable denial-of-service against any
account whose address an attacker knows — the attack becomes *easier*. There is
no lockout state anywhere in this system.

Every limiter is **two-dimensional**: per identifier and per source, both of
which must be satisfied. Per-account alone misses one host attacking a thousand
accounts; per-IP alone misses a botnet attacking one.

| Class | Endpoint | Per identifier | Per source |
| --- | --- | --- | --- |
| login | `POST /auth/login` | 5/min **and** 20/hour | 20/min |
| register | `POST /auth/register` | — (no account yet) | 10/hour |
| 2FA challenge | `POST /auth/2fa/challenge` | 5/min per challenge | 20/min |
| verify | `POST /auth/email/verify` | 10 / 10 min | 30 / 10 min |
| **resend** | `POST /auth/email/verify/resend` | 5/hour | 15/hour |
| **forgot** | `POST /auth/password/forgot` | 5/hour | 15/hour |
| reset | `POST /auth/password/reset` | — | 10/min |
| sensitive | 2FA changes, revoke-all, password change | 10/min | — |
| display name | `PATCH /profile` | 3/day | — |
| normal | authenticated reads | 60/min | — |

The two email-sending classes carry the strictest limits in the product: an
unlimited endpoint that mails a caller-supplied address is a spam relay, and the
damage lands on the product's own sending reputation.

Registration is keyed on the **source only**. Keying it on the submitted address
would let an attacker suppress registration for an address by burning its bucket.

Identifier keys are hashed, so the cache never holds a plaintext address.

Values are in `App\Support\RateLimits`; registration in
`App\Providers\RateLimitServiceProvider`.

**Failure mode (RL-1) remains OPEN.** The limiter currently fails open, because
the cache store is the database and its unavailability means the application is
already down. Deciding fail-open versus fail-closed properly belongs with the
adoption of a dedicated limiter store (CACHE-1).

---

## 14. Roles and the admin gate — IMPLEMENTED

Two roles, a `users.role` column, a PHP enum, and a **database check
constraint**. No RBAC package: there are two roles, neither is created at
runtime, and no permission is assigned independently of a role, so a generalised
schema would add tables and a cache layer to express a boolean.

The check constraint is there because a role column is a privilege column. A
stray write of `administrator` or `''` must fail loudly rather than produce an
account whose privileges depend on how some comparison happens to behave.

`App\Http\Middleware\EnsureAdministrator` requires **four** things, each refused
with its own stable code:

| Condition | Code on failure |
| --- | --- |
| role is `admin` | `admin_role_required` |
| address verified | `email_not_verified` |
| second factor enrolled | `admin_two_factor_required` |
| **this session passed a challenge** | `admin_two_factor_required` |

Applied to the route **group**, so a route added to the admin surface later
inherits all four. `authorization-and-roles.md` §5 requires exactly that: a
per-route annotation is one forgotten line away from a privileged bypass. A test
enumerates the registered routes and asserts the middleware is present on every
one.

`POST /auth/2fa/disable` returns **403 `admin_two_factor_mandatory`** for an
administrator, regardless of their current enrolment state. The role check comes
first deliberately: the answer is always no, and "there is nothing to disable"
would imply that disabling becomes allowed once there is.

---

## 15. The verified-email gate — IMPLEMENTED (resolves AUTH-1)

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

Enforced by `verified` middleware on the route group, so a new endpoint is gated
by default. A test enumerates the authenticated routes and asserts that exactly
those four lack the gate, so the allowance cannot widen unnoticed.

---

## 16. The error contract — IMPLEMENTED (resolves API-1, API-2)

**RFC 9457 Problem Details**, `application/problem+json`, for every error.

```json
{
  "type": "urn:purrenade:error:validation_failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The given data was invalid.",
  "instance": "/api/v1/auth/register",
  "code": "validation_failed",
  "correlation_id": "01K5R8Q2M3N4P5Q6R7S8T9V0W1",
  "errors": { "email": [{ "code": "taken", "message": "…" }] }
}
```

Chosen over the bespoke envelope that `api-conventions.md` §3 proposed, because
the standard shape offers everything the envelope did plus tooling that already
understands it, and the requirement is that a client needs **one** parser.

**Every path on the host, not every route.** The renderer was originally scoped
to `api/*` and `/`, which left the rest of the host to Laravel's default handler:
`/anything-else` answered with `{"exception", "file", "line", "trace"}` — a stack
trace and absolute filesystem paths under `APP_DEBUG`, and a bare `{"message"}`
otherwise. The scope condition is gone, and two routes nobody had declared went
with it (§19). The whole host is the API; there is no second surface to exclude,
so an exclusion could only ever be a hole.

The gate that should have caught this could not: it lints the OpenAPI document,
and the paths where it happened are not in the document. There is now a second
gate that boots the application and asks it.

Two application extensions:

- **`code`** — the value clients branch on. Stable, machine-readable, never
  localised, and always equal to the tail of `type`, so the two cannot disagree.
  RFC 9457 makes `type` the identifier; the project's existing contract makes
  `code` the ergonomic discriminator. Carrying both satisfies each.
- **`errors`** — field name to a list of `{code, message}`. The per-field code is
  what lets a client say "already taken" in Turkish without parsing English.

`type` is a **URN**, not an `https://` URL: RFC 9457 does not require the type to
be dereferenceable, and no published documentation site exists to dereference.
Inventing one that 404s would be worse than being honest.

**`status` is a top-level member** wherever a response has one, not nested in
`meta`. On `POST /auth/login` it decides the response *shape*, and a
discriminator cannot be declared inside another object — so it lives at the top
of every envelope that has one, consistently.

**Naming is `snake_case`** throughout — requests, responses, extensions,
contract (resolves API-2).

Built in one place (`App\Support\ProblemResponse`), and the only way application
code signals an error is by throwing `App\Exceptions\ApiProblem`. Nothing
constructs an error response by hand, which is what keeps the contract from
drifting endpoint by endpoint.

**Never present:** stack traces, SQL, class names, file paths, dependency
versions. That holds with `APP_DEBUG=true` as well — a developer gets the detail
from the log, and a response shape that changes between environments is one
nobody can test.

---

## 16A. Where the database is the authority — IMPLEMENTED (M2 audit)

Several single-use and once-only guarantees were enforced in PHP against a value
read earlier in the request. Each one passed its sequential test — which is what
a read-then-write does when nobody else is looking — and lost under a second
caller holding the same snapshot.

The shape that works was already in the codebase, in `consumeRecoveryCode`: a
**conditional UPDATE whose affected-row count is the authority**. The rest now
match it.

| Guarantee | Enforced by |
| --- | --- |
| A recovery code is spent once | `UPDATE … WHERE used_at IS NULL`, affected rows = 1 |
| A TOTP code cannot be replayed within its step | `UPDATE … WHERE last_used_timestep IS NULL OR < ?`, affected rows = 1 |
| A verification attempt is counted once | `increment … WHERE attempts < 5`, affected rows = 1 |
| A challenge attempt is counted once | `increment … WHERE attempts < 5`, affected rows = 1 |
| One live verification code per account | `unique(user_id)` |
| One live two-factor challenge per account | `unique(user_id)` + a transaction |
| One live recovery-code set | `lockForUpdate()` on the user row, inside the existing transaction |
| A display name is unique | `unique(display_name_normalized)` |
| A rename spends the cooldown once | `UPDATE … WHERE changed_at IS NULL OR <= ?`, affected rows = 1, in a transaction with the rename |

Three of these were genuine holes rather than tidiness:

- **The TOTP replay guard** compared in PHP against a hydrated model and then
  wrote unconditionally, so two concurrent submissions of one intercepted code
  both compared against the same old step and both succeeded — the guard failing
  under exactly the condition it exists for.
- **Attempt caps** read a snapshot and checked `attempts < 5` in PHP, so N
  parallel requests each got a free guess against a six-digit code. The cap was
  worth as many attempts as an attacker could open connections.
- **Recovery-code regeneration** could leave **sixteen** live codes: two
  transactions each deleted the set the other had not yet inserted, then each
  inserted eight. Regenerating is a security action, and it was capable of
  doubling the number of live bypasses.

The rename cooldown's claim and the rename itself share one transaction, so a
name lost to the unique index does not spend a day's allowance on a rename that
did not happen.

`tests/Feature/Auth/ConcurrencyTest.php` reproduces the interleaving for each —
two callers that both read before either writes — because true parallelism needs
separate connections that a transactional test database cannot provide, and the
interleaving is the condition the guard actually has to survive.

---

## 17. Correlation — IMPLEMENTED

Header: **`X-Correlation-Id`**.

One value follows a request from the browser, through the BFF, into this service,
into its logs, and back out in the response — so a player quoting an id from an
error screen gives support a single key to search on.

**An inbound value is accepted only if it matches `^[A-Za-z0-9_-]{8,64}$`.** That
is not cosmetic: the value is echoed in a response header and written into log
lines, so accepting arbitrary bytes would permit header injection through CR/LF
and log forging through newlines. The allowed alphabet contains neither.
Otherwise a ULID is generated — sortable, so a log ordered by correlation id is
also ordered by arrival.

---

## 18. Security logging — IMPLEMENTED

A dedicated `security` channel, written through `App\Support\AuthLog` and nothing
else. Separate from the application log because retention differs, alerting
differs, and — most usefully — a single writer means the field set is fixed and
no credential can reach it.

Logged: login success and failure, throttle events, registration, verification
sent/succeeded/failed, password reset requested and completed, password changed,
2FA challenged/failed/enabled/disabled, recovery code used, recovery codes
regenerated, session revoked, sign-out-all, admin access granted and denied with
the reason.

**Never logged**, and structurally so — no method accepts one: passwords, TOTP
secrets, recovery codes, verification codes, reset tokens, session cookies,
bearer tokens, `APP_KEY`, database credentials. A CI gate greps for a credential
being passed to `AuthLog`.

Email addresses appear only on events where no `user_id` exists yet — a failed
login against an unknown address is uninvestigable without one — and never
alongside a `user_id`, which already identifies the account.

---

## 19. What is deliberately not here

| Not implemented | Why |
| --- | --- |
| Native bearer login flow | ADR-0005 §5: the *capability* is preserved, the client is not built. These endpoints already serve it. |
| Passkeys / WebAuthn | Out of scope (two-factor.md §7). Routes disabled. |
| SMS second factor | The weakest common factor, and a phone-number personal-data surface. |
| Email as a second factor | Not a second factor when email already controls password reset. |
| Step-up re-authentication | §7. Per-request `current_password` covers the risk. |
| Redis for rate limiting | CACHE-1 is OPEN. The database limiter is adequate at this scale. |
| IP address / location on sessions | AUTH-4 is OPEN. §8. |
| Account deletion | SEC-3 is OPEN. |
| Admin capabilities | AD-5, M13. `GET /admin/overview` exists only to make the access-control decisions testable. |

Two routes were here without anyone deciding they should be, and both are now
gone (M2 audit):

| Removed | What it was | Why it went |
| --- | --- | --- |
| `GET /up` | Laravel's built-in health route, from `withRouting(health: …)` | It renders a **Blade view** — HTML, from a service whose root route says "no web access allowed" — and it was in no contract. `GET /api/v1/health` is the readiness endpoint and reports the database too. |
| `GET`/`PUT storage/{path}` | Registered by `filesystems.disks.local.serve` | Signed-URL gated, so not exploitable — but this service stores and serves no files, and one of the two was an **upload** endpoint. Unreviewed surface kept alive by a default nobody chose. `serve => false`. |

Neither was visible to the routes-in-contract gate, because that gate read route
*files* and these were registered by the framework. It reads
`php artisan route:list` now.

---

## 20. Open questions

| Ref | Question |
| --- | --- |
| AUTH-4 | Device location: source, precision, and retention policy, decided together |
| RL-1 | Fail open or fail closed when the limiter store is unavailable (§13) |
| CACHE-1 | Whether Redis is adopted, and therefore used for rate limiting and the cache |
| 2FA-3 | Account recovery when both factors are lost. **Password reset is not the answer** (§10 of two-factor.md). |
| 2FA-4 | Admin lockout recovery |
| SEC-3 | Account deletion and data export. **Note for whoever implements it:** `email_verification_codes`, `two_factor_recovery_codes` and `two_factor_challenges` all cascade on the user, but `personal_access_tokens` is Sanctum's polymorphic table and has no foreign key — deleting a user leaves their sessions behind. Nothing in M2 deletes a user, so no orphan is reachable today, but the deletion path must call `$user->tokens()->delete()` explicitly. |
| AD-5 | Per-capability admin contracts, at M13 |
