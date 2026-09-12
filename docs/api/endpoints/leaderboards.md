# Endpoints — Leaderboards

**Contract shape only.** The week boundary and moderation/privacy rules now carry
**recommendations awaiting approval** — see the frontend's `docs/product/leaderboards.md`.

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| GET | `/leaderboards?window=weekly\|all_time` | authenticated | — | normal |

---

## Behavior — APPROVED

- Two windows: **weekly** and **all-time** (v0.3 board 15).
- Ranks by **best single-run score**, not cumulative score.
- The response always includes **the requesting player's own row with its true
  rank**, even when outside the returned page — the pinned "SEN" row.
- Read-only. The client never writes ranking data and never computes a rank
  locally; a locally computed rank is wrong the moment another player scores.

---

## Weekly boundary — APPROVED

**Monday 00:00 Europe/Istanbul**, attributed by **server-recorded run start**.

| Aspect | Recommendation | Why |
| --- | --- | --- |
| Timezone | **Europe/Istanbul** | Türkiye-first launch, and — decisively — **Türkiye has been UTC+3 year-round since 2016**, so there is no DST-ambiguous or duplicated hour at rollover. A weekly reset that fires twice, or not at all, on two days a year is a defect class this avoids entirely. |
| Start of week | **Monday** | Conventional in Türkiye and most of Europe |
| Storage | **UTC**, converted for display | Never store a local timestamp |
| Attribution | **Run start**, not submission | A run cannot be held open across the boundary to choose its week |
| Previous week | Archived and viewable | Discarding it destroys the only record of a player's best week |

**UTC was considered** and rejected as weaker: equally DST-free, but it places the reset at
03:00 local for the primary audience, mid-tail of Sunday-evening sessions.

## Ordering — APPROVED

Ordering must be a **total order**, or cursor pagination can duplicate or skip rows.

| # | Rule |
| --- | --- |
| 1 | Higher **score** |
| 2 | **Earlier** `achieved_at` |
| 3 | **Shorter** `duration_ms` |
| 4 | Stable identifier — guarantees the total order |

## Pagination — APPROVED

**Cursor-based.** Offsets over a live ranking skip and repeat rows as scores change underneath
the reader. Cursors are opaque; clients never construct or parse one.

| Parameter | Recommendation |
| --- | --- |
| Default page size | 25 |
| Maximum page size | 100 |
| Own row | Always returned with the page, carrying its true rank |

## Visibility of banned, deleted and opted-out players

| Case | Behaviour | Status |
| --- | --- | --- |
| **Banned** | Entries hidden from public boards, **retained internally** for audit. A ban is moderation, not erasure. | **APPROVED** |
| **Opted out** | Excluded from public pages; the player **still sees their own rank privately**. Reversible. | **APPROVED in principle** — surface details LB-8 |
| **Deleted** | Identity removed or anonymized; the player **must not appear publicly under their former identity**. **Projections must be rebuilt** — a stale projection keeps serving a deleted identity. | Architecture PROPOSED; **retention/anonymization policy OPEN** (LB-5, SEC-3) |

## Display names — v1 baseline APPROVED

| Rule | Value |
| --- | --- |
| Length | 3–20 characters |
| Character set | Unicode letters and digits, plus `_`, `.`, `-` |
| Must contain | At least one letter |
| Must not | Begin or end with punctuation |
| Uniqueness | Case-insensitive |
| Changes | Rate-limited |
| Moderation | Admin **force-rename** |

**Future hardening — PROPOSED, explicitly not a v1 blocker:** automated profanity screening in
tr/en/es, and homoglyph/confusable impersonation detection. Both need a real user base to tune
against; **admin force-rename is the v1 answer** to an abusive or impersonating name.

## Freshness — APPROVED

| Data | Freshness |
| --- | --- |
| The page of other players | May be served from a short-TTL cache or projection |
| **The player's own row** | **Always computed fresh** — a stale rank shown to the player it belongs to is the one staleness nobody forgives |

## Performance — PROPOSED

Served from a **projection** over the authoritative `runs` table — a materialized
view or a maintained ranking table — never a live sort over everything. Redis may
cache or project; it is **never** the source of truth.

---

## Visibility — APPROVED

Only `accepted` runs appear. `flagged` and `rejected` runs are excluded from
ranking while remaining recorded.

---

## Open questions

| Ref | Question |
| --- | --- |
| LB-7 | Profanity and confusable screening — source, locale coverage, tuning. **Future hardening, not a v1 blocker.** |
| LB-8 | Opt-out surface: where the setting lives and what an opted-out player sees |

**Resolved by M0.6:** the week boundary, tie-break, pagination, display-name v1 baseline and
opt-out support are **APPROVED**.
| LB-5 | What happens to a banned or deleted player's entries (interacts with KVKK) |
| LB-6 | Is there any board other than weekly and all-time? Nothing suggests one |
