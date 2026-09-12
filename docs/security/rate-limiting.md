# Rate Limiting

Rate limiting is a **security control**, not a performance feature.

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. Principles — APPROVED

1. **Every authentication and submission endpoint is rate-limited.**
2. Limits apply **per identifier and per source**, not one or the other.
3. A limit is a **defence**, not a courtesy — exceeding it returns `429` with a
   retry signal, which clients honour rather than retrying in a loop.
4. Rate limiting **alone is never sufficient**. It bounds frequency, not
   falsehood.

### Why both dimensions — PROPOSED

| Dimension | Stops | Misses |
| --- | --- | --- |
| Per account/address | Brute force against one target | A distributed attack across many accounts |
| Per source (IP) | One host attacking many accounts | A distributed botnet |

Neither alone is adequate; together they cover the realistic attacks.

---

## 2. Classes and values — APPROVED, IMPLEMENTED (SEC-2 resolved at M2)

Every limiter returns **two** limits, both of which must be satisfied.

| Class | Endpoint | Per identifier | Per source |
| --- | --- | --- | --- |
| login | `POST /auth/login` | 5/min **and** 20/hour | 20/min |
| register | `POST /auth/register` | — (no account yet) | 10/hour |
| 2FA challenge | `POST /auth/2fa/challenge` | 5/min per challenge | 20/min |
| verification submit | `POST /auth/email/verify` | 10 / 10 min | 30 / 10 min |
| **verification resend** | `POST /auth/email/verify/resend` | 5/hour | 15/hour |
| **forgot password** | `POST /auth/password/forgot` | 5/hour | 15/hour |
| password reset | `POST /auth/password/reset` | — | 10/min |
| sensitive | 2FA changes, revoke-all, password change | 10/min | — |
| display name | `PATCH /profile` | 3/day | — |
| normal | Authenticated reads | 60/min | — |
| health | `GET /api/v1/health` | — (no identifier) | 60/min |
| submission | Run start and finish | **Not yet — M9.** Bounded by how fast runs can plausibly be played. |

Readiness was the one public route with no limiter, and it is the one public
route that queries the database — an unauthenticated cheap `GET` turning into
unbounded query load. Added at the M2 audit. One dimension only: a readiness
probe has no identifier, and a monitor is not a user. 60/min is well above any
sane probe (one per second uses a fifth of it) and far below anything worth using
as an amplifier.

The two email-sending classes are the strictest in the product: an unlimited
endpoint that mails a caller-supplied address is a spam relay, and the damage
lands on the product's own sending reputation.

**Registration is keyed on the source only.** Keying it on the submitted address
would let an attacker suppress registration for an address by burning its bucket.

**Identifier keys are hashed**, so the cache never holds a plaintext address.

Values live in `App\Support\RateLimits`; registration in
`App\Providers\RateLimitServiceProvider`. There is no lockout state anywhere —
see [authentication.md](authentication.md) §7.

---

## 3. Specific concerns

### Email endpoints — APPROVED

The strictest limits in the product. Registration, verification resend and
forgot-password all send mail to an address the caller supplies.

| Rule | |
| --- | --- |
| Limited per **target address** and per **source** | |
| The resend cooldown is itself a limit — v0.3 displays 0:42 | |
| The cooldown is returned to the client so it can render the countdown | Rather than surfacing an error |
| Exceeding the limit must not reveal whether the address exists | |

### Login — APPROVED

| Rule | |
| --- | --- |
| Limited per account **and** per source | |
| **Progressive delay is preferred to hard lockout** | A hard lockout converts credential stuffing into a denial-of-service against the targeted account |
| Repeated failures are logged as a signal | |

### Run submission — APPROVED

A player cannot legitimately finish runs faster than runs take to play. That makes
the limit both safe to set tightly and a **useful abuse signal**: a submission
rate above the plausible ceiling is evidence in its own right, feeding
[anti-cheat.md](anti-cheat.md).

### Admin endpoints — PROPOSED

Rate-limited despite being privileged. A compromised admin credential should not
be able to enumerate the player base at speed.

---

## 4. Implementation — PROPOSED

| Concern | Rule |
| --- | --- |
| Storage | Needs atomic increment with TTL, shared across instances. Redis is the natural fit; the database works at low volume but contends with real traffic. |
| Response headers | Remaining allowance and reset time on every limited endpoint |
| `429` | Carries a retry signal the client honours |
| Keying | Never key on a value the client controls and can vary freely |
| Storage | **IMPLEMENTED** on the database cache store. Adequate at this scale; Redis is CACHE-1, still OPEN. |
| Response | **IMPLEMENTED** — `429` as an RFC 9457 problem carrying `retry_after` both as a member and as a `Retry-After` header. The header is what proxies and HTTP libraries honour; the member is what a countdown in the UI needs. |
| Failure mode | **OPEN (RL-1).** Currently fails open, because the limiter store *is* the database — if it is unavailable the application is already down, so failing closed would add nothing. The decision belongs with the adoption of a dedicated limiter store (CACHE-1). |

---

## 5. Monitoring — APPROVED

Rate-limit triggers are logged and monitored. A rise in triggers is one of the
earliest available signals of credential stuffing, spam abuse, or a client bug —
often before anything else notices.

---

## 6. Open questions

| Ref | Question |
| --- | --- |
| ~~SEC-2~~ | **Resolved at M2.** §2. |
| RL-1 | Fail open or fail closed when the limiter is unavailable? §4. |
| RL-2 | Is Redis adopted for rate limiting, and at which milestone? (CACHE-1) |
| ~~AUTH-3~~ | **Resolved at M2:** progressive throttling, no lockout. |
