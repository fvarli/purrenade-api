# Endpoints — Admin

**Capabilities are OPEN.** Nothing here is implemented until specified.

| Method | Path | Auth | Rate-limit class |
| --- | --- | --- | --- |
| GET | `/admin/players` | **admin + 2FA satisfied** | normal |

The path above is a **placeholder** so the admin surface is visible in the
contract. It is not a specification.

---

## What is approved — APPROVED

Only two things, both from v0.3 board 20:

1. **2FA is mandatory for the admin role.**
2. **The admin panel is a separate, plain interface** that does not adopt the
   game's visual identity.

---

## Minimum v1 capability set — APPROVED (SI-1)

Six capabilities. **A moderation console, not a back office.** Every one of them exists to
answer a question the product will actually face in its first months — an abusive display name,
a suspicious score, a player asking why their run was rejected.

| # | Capability | Scope | Notes |
| --- | --- | --- | --- |
| 1 | **User lookup** — by id, username, or email | Read-only | Minimized fields; audited. Reads personal data, so it returns what the task needs, not the whole record. |
| 2 | **User status** — suspend / unsuspend | Write | **Not delete.** Deletion is the player's own right, exercised through their account, not an admin action. |
| 3 | **Run inspection** — list and detail, including validation metadata | Read-only | The only way to answer "why was my run flagged?" |
| 4 | **Run invalidation** — `accepted` → `rejected` | Write | **Mandatory reason.** Audited. Directly changes ranking, so it is never a silent action. |
| 5 | **Leaderboard moderation** — force-rename a display name, hide an entry | Write | Audited; the player is notified of a forced rename and must choose a new name |
| 6 | **Audit log inspection** | Read-only | Append-only store. An admin reads the record of admin actions, including their own. |

### Explicitly out of scope for v1 — APPROVED

| Not built | Why |
| --- | --- |
| Content management of any kind | There is no editable content; achievements and characters are fixed catalogues |
| Editing the achievement or character catalogue | Catalogue changes are deployments, reviewed as code |
| Arbitrary data editing | An admin who can edit anything is an unauditable authority |
| Granting progression, paws, or scores | Destroys the integrity the whole anti-cheat design exists to protect |
| User impersonation | The single most dangerous admin feature; nothing in the product needs it |
| Bulk export | A data-exfiltration surface with no v1 use case |

**This set is approved; nothing beyond it is.** An admin endpoint outside the six capabilities
above is a privileged surface nobody has reviewed, and is not built.

---

## Rules that apply to every admin endpoint — APPROVED

| Rule | Detail |
| --- | --- |
| **Admin role required**, checked server-side on every request | Never inferred from a previous request |
| **2FA must be satisfied** | Enforced such that **no admin route can bypass it**, including new ones added later |
| `403`, not `404`, for a non-admin | An authenticated player may know the admin surface exists |
| **Every action is audited** | Actor, action, target type and id, correlation id, timestamp — append-only |
| Reads are minimized | An admin sees what the capability requires, not the whole record |
| No admin endpoint bypasses validation or integrity constraints | An admin write is still a write |
| Admin actions never delete audit entries | |

### Enforcement is structural, not per-route — APPROVED

Mandatory admin 2FA must be enforced at a level that **new routes inherit
automatically**. A per-route check is a rule that is one forgotten annotation away
from a privileged bypass, and the test for it is correspondingly explicit:
*an admin without satisfied 2FA is denied on every admin endpoint, by any route.*

---

## Open questions

| Ref | Question |
| --- | --- |
| AD-5 | Per-capability request/response shapes, to be contracted at M13 |

**Resolved by M0.6:** SI-1 — the minimum capability set is **APPROVED**.
| AD-1 | Is the admin surface part of this application or a separate one? (BA-2) |
| AD-2 | Is there more than one admin level? Nothing suggests one |
| AD-3 | Audit log retention (SEC-3, OB-3) |
| AD-4 | Is admin access restricted by network or device in addition to 2FA? |
