# Domain Boundaries

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. The domains — PROPOSED

Five boundaries, drawn along where the data and the rules actually cohere.

```
┌──────────────┐   ┌──────────────┐   ┌──────────────┐
│   Identity   │   │     Runs     │   │ Progression  │
│ users, roles │──►│ run records  │──►│ paw ledger   │
│ sessions,2FA │   │ validation   │   │ achievements │
└──────┬───────┘   └──────┬───────┘   │ unlocks      │
       │                  │           └──────┬───────┘
       │                  ▼                  │
       │           ┌──────────────┐          │
       │           │ Leaderboards │◄─────────┘
       │           │  projection  │
       │           └──────────────┘
       ▼
┌──────────────┐
│    Admin     │  moderation, audit — capabilities OPEN
└──────────────┘
```

---

## 2. Identity — APPROVED scope

**Owns:** users, credentials, email verification state, password reset, roles
(`player`, `admin`), 2FA secrets and recovery codes, sessions/devices.

**Rules**
- Authentication is **required**; this is not guest-first.
- 2FA is **mandatory for admin**, enforced at the server boundary such that no
  admin route can bypass it.
- Session revocation takes effect **immediately**, per session and globally.

**Boundary:** no other domain reads credentials, secrets, or recovery codes.
Other domains receive an authenticated identity, nothing more.

---

## 3. Runs — APPROVED scope

**Owns:** the run record — start, finish, duration, score, `runPaws`, and any
validation metadata.

**Rules**
- **Score is server-authoritative.** The client proposes.
- Submission is **idempotent**.
- Validation lives here, per
  [ADR-0006](../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md).
- **Tutorial runs are not runs.** The tutorial submits nothing and touches no
  score, leaderboard, or progression data.

**Boundary:** Runs does not itself decide progression. It records what happened
and hands an accepted result to Progression **inside the same transaction**.

---

## 4. Progression — APPROVED scope

**Owns:** `lifetimePaws`, `loliCyclePaws`, `runPaws` attribution, achievement
definitions and progress, character unlock state, `tutorialCompletedAt`, best
score, run count, and the counters needed by unlock criteria.

**Rules**
- The three paw counters are **distinct concepts with distinct names**. There is
  no generic `paws` field.
- The 200 threshold triggers **one bonus per completed threshold**, consumes 200,
  and **preserves overflow**.
- Magnet-collected paws count normally.
- Achievement unlocks are **idempotent per `(player, achievement)`**.
- Unlock counters are **persisted from M9**, before the criteria semantics are
  decided — a counter that was never recorded cannot be reconstructed.

**Boundary:** Progression never accepts a value the client asserted. It derives
everything from an accepted run result.

---

## 5. Leaderboards — APPROVED scope

**Owns:** the ranking projection and its refresh, for the weekly and all-time
windows.

**Rules**
- The authoritative source is the runs table in PostgreSQL. A cache or projection
  is **never** the source of truth.
- Ordering must be a **total order**, so pagination cannot duplicate or skip.
- A player's own rank is always computed fresh.

**Boundary:** Leaderboards is **read-only** with respect to runs and progression.
It projects; it never writes back.

---

## 6. Admin — APPROVED in principle, capability set PROPOSED

**Owns:** the moderation console and the audit log.

**Rules**
- Mandatory 2FA, enforced server-side and **structurally**, so new routes inherit it.
- **Every admin action is audited**: actor, target, time, correlation ID.
- The admin interface is separate and plain; it does not adopt the game identity.

**PROPOSED minimum v1 capability set (SI-1)** — six capabilities, a moderation console rather
than a back office:

| # | Capability |
| --- | --- |
| 1 | User lookup (id / username / email), read-only, minimized fields |
| 2 | User status — suspend / unsuspend (**not** delete) |
| 3 | Run inspection, including validation metadata |
| 4 | Run invalidation (`accepted` → `rejected`) with a **mandatory reason** |
| 5 | Leaderboard moderation — force-rename a display name, hide an entry |
| 6 | Audit log inspection |

**Out of scope for v1:** content management, catalogue editing, arbitrary data editing,
granting progression, impersonation, bulk export.

Detail in [`../api/endpoints/admin.md`](../api/endpoints/admin.md). **Not implemented until
approved.**

---

## 7. Cross-domain rules — APPROVED

| Rule | Reason |
| --- | --- |
| A domain owns its tables; others go through its service | Prevents a schema change in one domain from silently breaking another |
| Run acceptance and progression update share **one transaction** | A partial application corrupts an account |
| No domain trusts client-supplied identity or score | The whole security model rests on this |
| Admin actions cross domains but always through their services, always audited | Otherwise moderation becomes an unlogged back door |

---

## 8. What deliberately does not exist — PROPOSED

| Not built | Why |
| --- | --- |
| A social/friends domain | Nothing in the approved surface needs one |
| A payments or economy domain | No monetization is in scope |
| A notification domain | Only transactional email exists, which belongs to Identity |
| A content-management domain | Achievements and characters are fixed catalogues, not editable content |

Recorded so their absence is a decision rather than an oversight.
