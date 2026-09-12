# Observability

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. Requirements — APPROVED

- **Structured logging with request/correlation IDs.**
- **No sensitive data in logs.**

Both are non-negotiable, and the second is the one that gets violated by
accident — usually by logging a whole request payload during debugging.

---

## 2. Correlation — APPROVED

| Rule | Detail |
| --- | --- |
| Every request has a correlation ID | Accepted from the client if present, generated otherwise |
| It is attached to the logging context for the whole request | Every log line from that request carries it |
| It is echoed in the response | The player can quote it; the frontend surfaces it on an unexpected failure |
| It propagates into queued jobs | Otherwise the deferred half of a request is untraceable |
| It appears in the audit log | An admin action is traceable to the request that caused it |

This is what makes "it failed for me at about 14:30" into a searchable question.

---

## 3. Structured logging — PROPOSED

Logs are **machine-parseable records**, not prose. Every entry carries:

| Field | Notes |
| --- | --- |
| `timestamp` | UTC |
| `level` | |
| `message` | A stable, low-cardinality string — not an interpolated sentence |
| `correlation_id` | |
| `user_id` | **The identifier only.** Never the email, username, or IP. |
| `route`, `method`, `status`, `duration_ms` | |
| `context` | Domain fields relevant to the event |

### 3.1 What must never be logged — APPROVED

- Passwords, password hashes, or anything password-shaped
- Tokens, session identifiers, API keys
- 2FA secrets, TOTP codes, recovery codes
- Email verification codes or password reset tokens
- Email addresses, IP addresses, precise locations
- Full request or response bodies for authenticated endpoints

**Redaction is applied at the logger**, not at each call site. A rule that
depends on every developer remembering it is not a rule.

---

## 4. What is worth logging — PROPOSED

| Event | Why |
| --- | --- |
| Authentication success and failure | The primary abuse signal |
| 2FA challenge outcomes, recovery-code use | Recovery-code use is rare and worth noticing |
| Authorization denials | Repeated denials for one user is a signal |
| **Run submission: accepted, flagged, rejected** | The anti-cheat feedback loop lives here |
| Achievement progression derived from telemetry | Rate and distribution shifts are an early tampering signal |
| Idempotent replay hits | A sudden rise means a client bug or an attack |
| Rate-limit triggers | |
| **Every admin action** | To the audit log, always |
| Job failures | |
| Slow queries above a threshold | The leaderboard is the likely offender |

---

## 5. Metrics — PROPOSED

| Metric | Why it matters here |
| --- | --- |
| Request rate, error rate, latency percentiles per endpoint class | Baseline health |
| **Run submission rate, and the accepted/flagged/rejected split** | The clearest early signal that something is being exploited |
| Authentication failure rate | Credential stuffing |
| Rate-limit trigger rate | |
| Queue depth, failure rate, oldest-job age | |
| Database connection saturation and slow-query count | |
| Leaderboard projection refresh duration | Grows with the table; worth watching early |

---

## 6. Audit log — APPROVED

Distinct from application logs: **append-only, never updated, never deleted**,
retained on its own schedule.

Every admin action records actor, action, target type and id, correlation id,
timestamp, and metadata. An admin capability that is not audited is a back door,
which is why auditing is specified before the capabilities themselves are
(SI-1 remains OPEN).

---

## 7. Health and readiness — PROPOSED

| Endpoint | Reports | Status |
| --- | --- | --- |
| `GET /api/v1/health` | **Readiness.** The process is up **and** the default PostgreSQL connection answers. | **Implemented (M1).** |
| `GET /up` | **Liveness.** The framework's own probe: the process boots and dispatches. No dependency checks. | Framework default. |
| `GET /` | Not a probe. The API-only signpost — see `docs/api/openapi.draft.yaml`. | **Implemented (M1).** |

`/api/v1/health` returns **503** when a dependency is unreachable, not 500 and not a
misleading 200. 503 is what tells a load balancer to take the instance out of rotation
rather than page a human.

**Liveness and readiness are separate endpoints on purpose.** A liveness probe that fails
on a transient database blip causes restarts instead of reporting the blip; a readiness
probe that ignores the database reports green from a process that cannot serve a single
request. `/up` is the former, `/api/v1/health` the latter.

**Readiness checks exactly the dependencies this service relies on — today, one.** Cache,
queue, mail and Redis are not adopted by this project (see `caching-and-redis.md` and
`queues-and-jobs.md`); adding probes for them would turn a health check into an
observability system. A dependency gets a check in the same change that adopts it.

**Neither endpoint exposes version details, internal hostnames, dependency configuration,
filesystem paths, SQL, or stack traces to unauthenticated callers.** A failing check
reports `"error"` and nothing more — the driver message, which carries the host, port,
database name and role, is logged server-side under §3.1.

`/api/v1/health` carries **no `version` field**, because there is no source of truth for
one: `composer.json` declares no version and the project has no releases. The only number
available would be the framework's, which is not this service's version and which the rule
above forbids disclosing anyway. It is added — here, in the response, and in the OpenAPI
contract — in the change that introduces releases.

---

## 8. Errors — PROPOSED

- Unhandled exceptions are captured with the correlation ID and **scrubbed of
  personal data before transmission** if an external error tracker is used.
- The client receives the documented error envelope and the correlation ID —
  **never a stack trace or an internal detail**.
- An error that a player can trigger by normal use is a bug in validation, not an
  error to monitor.

---

## 9. Open questions

| Ref | Question |
| --- | --- |
| OB-1 | Log aggregation destination and retention |
| OB-2 | Is an external error tracker used, and is it acceptable under KVKK? |
| OB-3 | Audit log retention (SEC-3) |
| OB-4 | Alerting thresholds, particularly on the run-rejection rate |
