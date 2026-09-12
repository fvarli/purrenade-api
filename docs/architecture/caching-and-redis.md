# Caching and Redis

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. The hard rule — APPROVED

**A cache is never the source of truth.** PostgreSQL is authoritative for all
persistent product data — [ADR-0004](../decisions/ADR-0004-postgresql-primary-database.md).

This is stated first because the tempting exception is exactly the one that must
be refused: Redis sorted sets are an excellent fit for ranking, and a leaderboard
built on them alone would be fast, simple, and unable to survive durability
requirements, moderation, account deletion under KVKK, or an audit.

---

## 2. Where Redis is justified — PROPOSED

| Use | Justification |
| --- | --- |
| **Rate limiting** | Needs a fast, shared, atomic counter across application instances. This is the strongest case. |
| **Cache** | Leaderboard pages, achievement and character catalogues — read-heavy, rarely changing, cheap to invalidate |
| **Queue driver** | If queues are adopted; see [queues-and-jobs.md](queues-and-jobs.md) |
| **Leaderboard projection** | Acceptable as a **projection over** the authoritative table, refreshed from it and rebuildable from it at any time |

## 3. Where Redis is NOT used — APPROVED

| Not used for | Reason |
| --- | --- |
| Source of truth for ranking | Not durable, not auditable, not deletable under KVKK in a defensible way |
| Source of truth for progression | The paw ledger must be transactional with run acceptance |
| Anything that must survive a flush | A cache is expected to be lost |
| Authorization decisions | Never cached |

---

## 4. Cache policy — PROPOSED

| Data | Strategy | Invalidation |
| --- | --- | --- |
| Leaderboard top page | Short TTL | On projection refresh |
| **A player's own rank** | **Not cached** | Always fresh — a stale rank shown to the player it belongs to is the one staleness nobody forgives |
| Achievement catalogue | Long TTL | On deploy |
| Character catalogue | Long TTL | On deploy, and when `artwork_available` changes |
| Player profile / progression | Not cached, or very short TTL | Written on every run |
| Anything security-relevant | **Never cached** | — |

### 4.1 Rules — PROPOSED

- Every cached value is **rebuildable from PostgreSQL**. A cold cache is slow,
  never wrong.
- Cache keys are versioned so a shape change cannot serve a stale structure to
  new code.
- Cache failure **degrades to the database**, it does not fail the request.
- No personal data in a cache key.

---

## 5. Rate limiting — APPROVED

Rate limiting is a security control, not a performance feature. Limits and their
rationale are in [`../security/rate-limiting.md`](../security/rate-limiting.md).

Storage requirements: atomic increment, TTL, shared across instances. Redis is
the natural fit; the database is a workable fallback at low volume but contends
with real traffic.

---

## 6. Licensing note — PROPOSED

Redis's licensing changed to RSALv2/SSPL, which is not OSI-approved. **Valkey**
is the BSD-licensed fork maintained under the Linux Foundation and is
protocol-compatible.

This is worth an explicit decision rather than a default, especially given that
the product's own licensing model is still OPEN
(`purrenade/docs/product/licensing-and-rights.md`). Either choice is technically
fine; the decision should be recorded rather than inherited from a tutorial.

---

## 7. Is Redis needed at all? — OPEN

Honestly assessed at the expected scale:

| Need | Without Redis |
| --- | --- |
| Rate limiting | Database-backed limiter; correct, slower, contends with real traffic |
| Caching | Often unnecessary at low volume if indexes are right |
| Queues | Database queue driver is adequate |
| Leaderboard projection | A materialized view in PostgreSQL |

**PROPOSED:** adopt Redis (or Valkey) **for rate limiting first**, and extend to
cache and queue only when a measurement justifies it. Adding a stateful
infrastructure component is an operational commitment, not a free optimization.

---

## 8. Open questions

| Ref | Question |
| --- | --- |
| CACHE-1 | Is Redis adopted at all, and at which milestone? |
| CACHE-2 | Redis or Valkey? |
| CACHE-3 | Is the leaderboard projection in PostgreSQL or in Redis? |
| CACHE-4 | Cache TTLs, once real traffic shapes are known |
