# API Conventions

Rules that every endpoint follows. **Consistency is a contract**, not a style
preference — the frontend generates its types from this contract, so an
inconsistent endpoint is a defect.

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. Versioning — APPROVED

All endpoints live under **`/api/v1`**. Versioned from the first endpoint, never
retrofitted.

| Rule | Detail |
| --- | --- |
| Additive changes are not breaking | New optional fields, new endpoints |
| **Breaking changes require a new version** | Removing or renaming a field, changing a type, changing semantics |
| A version is supported until its clients are gone | This matters if a native shell ships later and lags the web client |

---

## 2. Format — APPROVED (API-2 resolved at M2)

| Concern | Rule |
| --- | --- |
| Content type | `application/json` for requests and successful responses |
| Field naming | **`snake_case`**, consistently — **decided (API-2)**. The frontend maps at its boundary; mixing conventions inside one API is worse than either choice. |
| Dates and times | **ISO 8601, UTC, with an explicit offset** |
| Durations | Integer **milliseconds**, suffixed `_ms` |
| Money | Does not exist in this product |
| Scores, paw counts | **Integers.** Never floating point. |
| Booleans | Real booleans; never `0`/`1`/`"true"` |
| Nulls | `null` means "absent". An empty list is `[]`, never `null` |
| Enumerations | Lowercase snake_case strings, documented exhaustively in the contract |

---

## 3. Error envelope — APPROVED, IMPLEMENTED (API-1 resolved at M2)

**RFC 9457 Problem Details**, served as `application/problem+json`, for **every**
error response. One shape, no exceptions.

"Every" means every path on the host, not every declared route. The renderer was
originally scoped to `api/*` and `/`, so anything else — a typo, a probe, a route
the framework had registered — fell through to the framework's default handler and
answered with `{"exception", "file", "line", "trace"}`. The scope condition is
gone (M2 audit), and so are the two routes nobody had declared.

```json
{
  "type": "urn:purrenade:error:validation_failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The given data was invalid.",
  "instance": "/api/v1/auth/register",
  "code": "validation_failed",
  "correlation_id": "01K5R8Q2M3N4P5Q6R7S8T9V0W1",
  "errors": {
    "email": [{ "code": "taken", "message": "This email is already registered." }]
  }
}
```

### Why RFC 9457 rather than the bespoke envelope

The envelope this section previously proposed was simpler to write and offered
nothing the standard does not. RFC 9457 is what HTTP tooling, client generators
and proxies already understand, and the requirement it has to satisfy is that a
client needs **one** parser for the whole surface — which a standard shape
delivers by construction.

### Members

| Field | Rule |
| --- | --- |
| `type` | A **URN**: `urn:purrenade:error:{code}`. RFC 9457 does not require the type to be dereferenceable, and no published documentation site exists — inventing an `https://` URL that 404s would be worse than being honest. |
| `title` | Short, stable, **English**, describing the problem *type* rather than this occurrence. Not localised. |
| `status` | The HTTP status, repeated in the body as the RFC specifies. |
| `detail` | Human-readable, about **this** occurrence. For display and logs only — **never branch on it**. |
| `instance` | The path that produced the problem. |
| **`code`** | **Extension.** The value clients branch on: stable, machine-readable, never localised. Always the tail of `type`, so the two cannot disagree. Adding a code is additive; changing one is breaking. |
| **`correlation_id`** | **Extension.** Always present, and echoed in the `X-Correlation-Id` header. The player can quote it. |
| **`errors`** | **Extension.** Field name → list of `{code, message}`. The per-field code is what lets a client render "already taken" in Turkish without parsing English. |
| **`retry_after`** | **Extension**, on `429`. Seconds. Also sent as a `Retry-After` header — the header is what proxies and HTTP libraries honour, the member is what a countdown in the UI needs. |

### Two rules that follow

**`status` is a top-level member wherever a response has one**, never nested
inside `meta`. On `POST /auth/login` it decides the response *shape*, and an
OpenAPI discriminator cannot read a property inside another object — so it sits
at the top of every envelope that has one, consistently rather than only where a
union needs it.

**Never included:** stack traces, SQL, internal class names, file paths,
dependency versions. That holds with `APP_DEBUG=true` as well: a response shape
that changes between environments is one nobody can test against.

The one documented exception to "every error is problem+json" is the readiness
probe's `503`, which carries the same `HealthReport` schema as its `200`. That is
deliberate — a monitor branches on `status`, and a body that changed shape at the
moment the service degraded would be a body the monitor could not parse. It is a
status report in a degraded state, not an error description. A CI gate enforces
the rule and names that exemption.

### 3.1 Status codes — APPROVED, IMPLEMENTED

| Code | Used for |
| --- | --- |
| `200` | Successful read or update |
| `201` | Resource created |
| `202` | Accepted for asynchronous processing |
| `204` | Successful delete with no body |
| `400` | Malformed request |
| `401` | Not authenticated, or credentials expired |
| `403` | Authenticated but not permitted — **including 2FA required for admin** |
| `404` | Not found, **or found but not visible to this caller** |
| `409` | Conflict — a stale write, or a duplicate that is not idempotent-safe |
| `422` | Validation failed |
| `429` | Rate limited |
| `500` | Unexpected failure |

**Authorization returns `404`, not `403`, when revealing existence would leak
information.** `403` is for resources the caller may know exist.

---

## 4. Authentication — APPROVED

Transport is decided by
[ADR-0005](../decisions/ADR-0005-authentication-and-2fa-strategy.md): a **Nuxt BFF with
server-managed session cookies** for the browser, over a **token-capable** API.

| Caller | Credential |
| --- | --- |
| **Browser** | Talks to the **Nuxt BFF**, which holds the session and attaches the upstream credential. The browser itself carries only an `HttpOnly` session cookie set by the BFF, and **never** a bearer token. |
| **Future native client** | Authenticates **directly against this API** with a bearer credential, **bypassing the BFF**. Not implemented in v1. |

**Implemented at M2:** one scheme, `sessionToken` — a Sanctum personal access
token presented as `Authorization: Bearer`. This API accepts **no cookie at all**;
Sanctum's stateful-domain list is empty on purpose, which is what keeps it
origin-agnostic and makes the native path the same path rather than a parallel
one. Every token carries a `session` ability; only a token minted by the
two-factor challenge also carries `two-factor`, which is how admin-scoped routes
distinguish "this account has 2FA" from "this session proved it". See
`../architecture/auth-architecture.md`.

Two rules follow for every endpoint in this contract:

1. **No endpoint may assume a browser, a cookie, or a same-site context.** The API must remain
   usable by a native client, which is what keeps that path open.
2. **The BFF is a client, not an authority.** It carries credentials; **this API decides**
   every authentication and authorization question.

**Every endpoint's requirement is stated explicitly in the contract** — public / authenticated
/ authenticated + verified / admin. An endpoint without a documented requirement is a bug.

**CSRF** is enforced at the BFF for browser traffic, not here: this API is not reached by
ambient browser authority. See ADR-0005 §2.

---

## 5. Idempotency — APPROVED

Operations that must not double-apply accept an idempotency key.

| Rule | Detail |
| --- | --- |
| Header | `Idempotency-Key` — a client-generated unique value |
| Scope | Unique **per user per endpoint** |
| Behavior | A repeat with the same key returns the **original response** and performs no further work |
| Conflict | The same key with a **different** request body is an error, not a silent overwrite |
| Required on | **`POST /game-runs/{runId}/finish`**, and anywhere else double-application would corrupt state |
| Format | A UUID; case-insensitive, stored lower-case |
| Retention | **For run submission, keys never expire** (GR-3): the identity lives on the run record for as long as the run does, so a late retry can never apply progression twice. A future generalisation to other endpoints (API-5) would decide its own retention. |
| Replay equality | **Semantic**: the same status and the same decoded `data`. Not byte-identical — the stored result is `jsonb`, which does not keep key order. |

Enforced by a **unique database constraint**, not by an application check.

---

## 6. Pagination — PROPOSED

**Cursor-based**, not offset-based. Offsets over a live ranking skip and repeat
rows as scores change underneath the reader.

```json
{ "data": [ ... ], "meta": { "next_cursor": "...", "has_more": true } }
```

| Rule | Detail |
| --- | --- |
| `limit` | Client-supplied, with a documented maximum |
| `next_cursor` | Opaque. Clients never construct or parse one. |
| Ordering | Always a **total order**, so a cursor is unambiguous |

---

## 7. Rate limiting — APPROVED

Every endpoint belongs to a rate-limit class. Limits and rationale are in
[`../security/rate-limiting.md`](../security/rate-limiting.md).

Responses carry the remaining allowance and reset time; `429` carries a retry
signal. The client honours it and **never retries in a tight loop**.

---

## 8. Localization — APPROVED

| Rule | Detail |
| --- | --- |
| The client sends its locale on every request | Via a standard language header |
| `message` fields are localized | `code` fields never are |
| Emails use the player's **profile locale** | Not the requesting device's |

---

## 9. Correlation — APPROVED

Every request accepts a correlation ID and every response echoes one. It appears
in logs, in queued jobs, and in the audit log. See
[`../architecture/observability.md`](../architecture/observability.md).

---

## 10. General rules — APPROVED

| Rule | Reason |
| --- | --- |
| **Authorization on every request**, never inferred from a previous one | Statelessness |
| **Never trust a client-supplied identity** | Resolve the actor from the credential, never from the body |
| **Never trust a client-supplied score or progression value** | The public leaderboard makes this exploitable by design |
| Reads never mutate | |
| A `GET` is safe and cacheable, or it is not a `GET` | |
| Responses contain only what the caller may see | Field-level authorization, not just endpoint-level |
| **Every endpoint exists in the OpenAPI document** | An undocumented endpoint breaks the only integration point between the repositories |

---

## 11. Open questions

| Ref | Question |
| --- | --- |
| ~~API-1~~ | **Resolved at M2: RFC 9457 `problem+json`.** See §3. |
| ~~API-2~~ | **Resolved at M2: `snake_case`.** See §2. |
| API-3 | Idempotency key retention window |
| API-4 | Default and maximum pagination limits |
| API-5 | Is idempotency generalized beyond run submission? |
| API-6 | Whether the API exposes a distinct bearer scheme now, or only when the native client is built |
