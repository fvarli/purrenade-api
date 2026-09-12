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

## 2. Format — PROPOSED

| Concern | Rule |
| --- | --- |
| Content type | `application/json` for requests and successful responses |
| Field naming | **`snake_case`**, consistently. The frontend maps at its boundary; mixing conventions inside one API is worse than either choice. |
| Dates and times | **ISO 8601, UTC, with an explicit offset** |
| Durations | Integer **milliseconds**, suffixed `_ms` |
| Money | Does not exist in this product |
| Scores, paw counts | **Integers.** Never floating point. |
| Booleans | Real booleans; never `0`/`1`/`"true"` |
| Nulls | `null` means "absent". An empty list is `[]`, never `null` |
| Enumerations | Lowercase snake_case strings, documented exhaustively in the contract |

---

## 3. Error envelope — PROPOSED

**One envelope for every error response.** The client maps from `code`, never
from `message`.

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "correlation_id": "01J9Z...",
    "details": {
      "email": [{ "code": "email_taken", "message": "This email is already registered." }]
    }
  }
}
```

| Field | Rule |
| --- | --- |
| `code` | **Stable, machine-readable, never localized.** Adding a code is additive; changing one is breaking. |
| `message` | Human-readable, **localized to the request locale**. For display and logs only — never for logic. |
| `correlation_id` | Always present. The player can quote it; the frontend surfaces it on unexpected failures. |
| `details` | Field-level errors, each with its own stable `code` |

**Never included:** stack traces, SQL, internal class names, file paths,
dependency versions.

**OPEN (API-1):** whether to adopt RFC 9457 `application/problem+json` instead.
It is the standard and interoperates well; the envelope above is simpler for a
single first-party client. Decide once, before the first endpoint exists.

### 3.1 Status codes — PROPOSED

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
| Required on | **`POST /game-runs/{run}/finish`**, and anywhere else double-application would corrupt state |
| Retention | Keys expire; the window is documented |

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
| API-1 | RFC 9457 `problem+json` or the envelope in §3? |
| API-2 | `snake_case` confirmation (§2) |
| API-3 | Idempotency key retention window |
| API-4 | Default and maximum pagination limits |
| API-5 | Is idempotency generalized beyond run submission? |
| API-6 | Whether the API exposes a distinct bearer scheme now, or only when the native client is built |
