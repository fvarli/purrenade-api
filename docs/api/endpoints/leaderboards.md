# Endpoints — Leaderboards

**IMPLEMENTED (M10).** Product rules are owned by the frontend's `docs/product/leaderboards.md`;
the schema, ORDER and write path by [`../../architecture/data-model.md`](../../architecture/data-model.md) §5.

| Method | Path | Auth | Idempotent | Rate-limit class |
| --- | --- | --- | --- | --- |
| GET | `/leaderboards?window=weekly\|all_time[&cursor][&limit]` | authenticated **and verified** | read-only | normal (`api-normal`, 60/min per player) |

---

## Behavior — APPROVED

- Two windows: **weekly** and **all-time** (v0.3 board 15). No third window: previous-week
  viewing is **OPEN (LB-9)** — past weeks are retained, not viewable.
- Ranks by **best single accepted run**, not cumulative score. **Only `accepted` runs rank**;
  `flagged` and `rejected` runs are recorded and never ranked, and nothing promotes them.
- The response always includes **the requesting player's own entry with its true rank**, even
  when it is outside the returned page — the pinned "SEN" row — or `null` when the player has
  no accepted run in the window.
- Read-only. The client never writes ranking data and never computes a rank.

## Request

| Parameter | Rules | Errors (`422 validation_failed`, `errors.<field>[].code`) |
| --- | --- | --- |
| `window` | required; `weekly` or `all_time` | `required`, `type_invalid`, `value_not_allowed` |
| `limit` | optional; a plain decimal integer **1–100**, default **25** | `type_invalid`, `out_of_range`, `format_invalid` |
| `cursor` | optional; ≤ 512 chars of `[A-Za-z0-9_-]`; minted by this server for this window | `type_invalid`, `too_long`, `format_invalid`, `cursor_invalid` |

Each field reports its first failure only. Unknown query members are ignored.

## Response `200`

```json
{
  "window": "weekly",
  "period": { "starts_at": "2026-09-20T21:00:00.000Z", "ends_at": "2026-09-27T21:00:00.000Z" },
  "data": [ { "rank": 1, "display_name": "aysenur", "score": 5847, "is_self": true } ],
  "own_entry": { "rank": 1, "display_name": "aysenur", "score": 5847, "is_self": true },
  "meta": { "next_cursor": "…", "has_more": true }
}
```

- Not wrapped in a `data` envelope: `data` is the page's entries.
- `period` is the week's UTC bounds, `[starts_at, ends_at)`; `null` for `all_time`.
- An entry is **exactly** `{rank, display_name, score, is_self}`. No `users.id`, run id, email,
  `achieved_at`, `duration_ms`, validation or authentication data is ever returned (E6).
- `display_name` is read live from the player's account on every request.
- `meta.next_cursor` is `null` on the last page, and `has_more` is `false`.

Errors: `401 unauthenticated`; `403 email_not_verified`; `422 validation_failed`; `429
rate_limited` with `Retry-After`. All `application/problem+json`.

## Weekly boundary — APPROVED

**Monday 00:00 Europe/Istanbul**, attributed by the run's **server-recorded start**.

| Aspect | Rule |
| --- | --- |
| Timezone | **Europe/Istanbul as an IANA zone**, applied by PHP and PostgreSQL with its full rule history — never a fixed `+03:00`. Istanbul has been UTC+3 all year since 2016, so there is no ambiguous hour at the current rollover; older instants (Istanbul observed DST until 2016) are still computed correctly |
| Start of week | **Monday**, set explicitly, never taken from a locale |
| Storage | **UTC**; the response carries UTC instants |
| Attribution | **Run start**, not submission. A run started Sunday 23:58 and finished Monday 00:03 belongs to the week it started in |
| Late submission | Updates the week it started in — a past week, if the finish arrives later — and all-time; never the current week |
| Previous weeks | **Retained permanently.** Viewing them is **OPEN (LB-9)** |

## Ordering — APPROVED, one comparator (E1)

| # | Rule |
| --- | --- |
| 1 | Higher **score** |
| 2 | **Earlier** `achieved_at` — the run's **server-recorded finish** (D2) |
| 3 | **Shorter** `duration_ms` |
| 4 | Stable identifier — the entry's **representative run id** |

Each player appears once per window, represented by their first run in this order. The same
comparator chooses that run and orders the board, so the two can never disagree, and `run_id` is
unique per window, so the order is total. Neither id is exposed. Because tie priority is the
server acceptance time, holding a run open cannot buy an earlier `achieved_at`.

## Pagination — APPROVED

**Keyset cursors** over ORDER; there is no offset. Cursors are opaque: an authenticated-encrypted
payload (APP_KEY) carrying a version, the window, the week (weekly only), and the ORDER position
of the last row served — no user id. A cursor issued before a Monday rollover **keeps paging the
week it was issued for**. A cursor that is tampered with, truncated, from the other window, of an
unknown version, or minted under a rotated APP_KEY is `422` with `errors.cursor[0].code =
cursor_invalid`; the client restarts from the first page.

## Consistency contract — APPROVED (M10)

1. **Per response:** the page, its ranks, `has_more` and `own_entry` are read from **one
   snapshot** (`REPEATABLE READ READ ONLY`), so they are mutually consistent.
2. **Across cursor requests the board is live, not frozen.** There is no snapshot or version
   pinning. Consequently:
   - (a) an entry that is new, or improves, and lands **above** the consumed cursor position is
     **not shown** in the rest of that traversal; it appears after a refresh from page 1;
   - (b) ranks are recomputed on every request, so rank numbers across pages are **strictly
     increasing but may skip** by the number of entries that moved above the cursor;
   - (c) **no entry is served twice in one traversal**, under invariant **M**: a row's ORDER key
     never moves later. A row already served lies before the cursor and stays there;
   - (d) an entry that was not served and stays below the cursor is served exactly once.
3. **M holds in M10** because the only writer is the monotone upsert. **Anything that lowers a
   row — M13 run invalidation, SEC-3 deletion or anonymisation — breaks M** and must either
   accept possible duplicates in open traversals or invalidate outstanding cursors.

The frontend therefore offers an explicit **refresh / back to top** action and does not pretend
ranks across pages are contiguous.

## Freshness — APPROVED

| Data | Freshness |
| --- | --- |
| The page of other players | Read from the projection, which an accepted finish updates in its own transaction. **No cache in M10** |
| **The player's own entry** | **Always read fresh**, with its true rank, in the same snapshot as the page |

## Visibility of banned, deleted and opted-out players

| Case | Behaviour | Status |
| --- | --- | --- |
| **Banned** | Entries hidden from public boards, retained internally for audit. | **APPROVED; enforced from M13.** No ban state exists before the M13 moderation console, so M10 filters nobody and claims no ban filtering |
| **Opted out** | Excluded from public pages; the player still sees their own rank privately. | **APPROVED in principle**; surface **OPEN (LB-8)** |
| **Deleted** | Must not appear publicly under a former identity. The projection stores no identity (names are joined live), and its foreign keys are `RESTRICT`, so deletion must handle it explicitly. | Policy **OPEN (LB-5)**, gating SEC-3 / M14 |

The reader has **one visibility predicate**, applied to the page and to both rank counts. It
admits every row in M10; M13 and LB-8 add to it there, so a hidden row can never still be
counted.

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

**Future hardening — PROPOSED, explicitly not a v1 blocker (LB-7):** automated profanity
screening in tr/en/es, and homoglyph/confusable impersonation detection.

## Performance

Served from maintained PostgreSQL tables (`leaderboard_all_time`, `leaderboard_weekly`), never a
sort over `runs`. The page is an index range scan on the `*_order` index (cost ∝ page size); each
rank is a count over the same index (cost ∝ rank); the own entry is a primary-key lookup. At most
four statements per request, whatever the page size. Plans at one million players are recorded
in `data-model.md` §5 and reproduced by `tests/Performance/leaderboard_explain.php`.

---

## Open questions

| Ref | Question |
| --- | --- |
| LB-5 | Retention and anonymization of deleted players' entries — gates SEC-3 / M14 (D1) |
| LB-6 | Is there any board other than weekly and all-time? Nothing suggests one |
| LB-7 | Profanity and confusable screening — source, locale coverage, tuning. **Future hardening, not a v1 blocker.** |
| LB-8 | Opt-out surface: where the setting lives and what an opted-out player sees |
| LB-9 | Previous-week viewing: its interaction and API contract |

**Resolved by M0.6:** the week boundary, tie-break, pagination, display-name v1 baseline and
opt-out support are **APPROVED**. **Resolved at M10 (2026-09-24):** D1 (M10 not blocked on
LB-5), D2 (`achieved_at` = server finish), D3 (M10/M12 scope), E1 (stable identifier = run id),
the consistency contract, and ban enforcement deferred to M13.
