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

## 2. Classes — PROPOSED

| Class | Applies to | Character |
| --- | --- | --- |
| **very strict** | Email-sending endpoints: verification resend, forgot password | Few per hour. These endpoints send mail on demand — an unlimited one is a spam relay. |
| **strict** | Login, 2FA challenge, verification submission, password reset, account deletion | Few per minute, with progressive delay |
| **submission** | Run start and finish | Bounded by how fast runs can plausibly be played |
| **normal** | Authenticated reads | Generous; protects against scraping, not against use |

Concrete values are **OPEN (SEC-2)** and set at M2 against real behavior.

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
| Failure mode | **OPEN (RL-1):** if the limiter is unavailable, fail open (available but unprotected) or fail closed (protected but down)? For authentication endpoints, failing closed is defensible. |

---

## 5. Monitoring — APPROVED

Rate-limit triggers are logged and monitored. A rise in triggers is one of the
earliest available signals of credential stuffing, spam abuse, or a client bug —
often before anything else notices.

---

## 6. Open questions

| Ref | Question |
| --- | --- |
| SEC-2 | Concrete limits per class |
| RL-1 | Fail open or fail closed when the limiter is unavailable? |
| RL-2 | Is Redis adopted for rate limiting, and at which milestone? (CACHE-1) |
| AUTH-3 | Progressive delay versus lockout |
