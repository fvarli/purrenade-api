# Architecture Decision Records — Backend

## Index

| ADR | Title | Status | Scope |
| --- | --- | --- | --- |
| [0001](ADR-0001-separate-frontend-backend-repositories.md) | Separate frontend and backend repositories | **Accepted** | Product-wide |
| 0002 | Nuxt + Phaser frontend architecture | Accepted | **Frontend only** — see `purrenade/docs/decisions/` |
| [0003](ADR-0003-laravel-rest-api-backend.md) | Laravel REST API backend | **Accepted** | Product-wide |
| [0004](ADR-0004-postgresql-primary-database.md) | PostgreSQL as the primary database | **Accepted** | Product-wide |
| [0005](ADR-0005-authentication-and-2fa-strategy.md) | Authentication and 2FA strategy | **Accepted and implemented** | Product-wide |
| [0006](ADR-0006-run-validation-and-anti-cheat-boundary.md) | Run validation and anti-cheat boundary | **🔴 Proposed — decision required** | Product-wide |
| 0007 | Localization strategy | Accepted | Product-wide — mirrored in the frontend; the backend's obligations are summarized below |
| [0008](ADR-0008-source-of-truth-and-design-reference-hierarchy.md) | Source-of-truth and design-reference hierarchy | **Accepted** | Product-wide |

---

## 🔴 The two that block implementation

| ADR | Blocks | Why it cannot wait |
| --- | --- | --- |
| **0005** | M2, M3 | Determines CORS, CSRF, cookie flags, token lifetime, and whether a native shell later needs a **second authentication path**. Choosing cookie-only forecloses the approved "Android/iOS must remain possible" requirement. |
| **0006** | M9, M10 | A public leaderboard ships in v1 and no approved reference addresses score integrity. Retrofitting validation after runs are recorded means deciding what to do with a table of unverifiable history. |

---

## ADR-0007 — the backend's share of localization

The full ADR lives in the frontend repository. The obligations that fall on this
repository:

1. Every request carries the client's locale; player-facing `message` fields are
   localized to it.
2. **Transactional emails use the player's profile locale**, not the requesting
   device's.
3. **Error `code` fields are stable and never localized.** Clients branch on
   codes, never on prose.
4. Turkish is the source locale; `tr`, `en`, `es` are the supported set.

---

## Numbering and cross-repository sync — PROPOSED

The two repositories share **one ADR numbering space**, because the decisions are
shared even though the repositories are independent.

| Rule | Detail |
| --- | --- |
| Scope header | Every ADR declares `Scope: Product-wide`, `Scope: Frontend`, or `Scope: Backend` |
| Product-wide ADRs | Stored in **both** repositories with the **same number and the same decision**, so each is independently readable |
| Repository-specific ADRs | Stored only where they apply; the number is still reserved globally |
| Divergence | Two copies that disagree are a **defect**, not a variant |
| Superseding | Never edit an ADR into a different decision. Write a new one and mark the old `Superseded by ADR-XXXX`. |

---

## Format

```markdown
# ADR-XXXX — Title

- **Status:** Proposed | Accepted | Superseded by ADR-YYYY | Deprecated
- **Scope:** Product-wide | Frontend | Backend
- **Date:** YYYY-MM-DD
- **Decision owner:** role

## Context
## Decision
## Alternatives considered
## Consequences
```

## When to write one

When a decision is **hard to reverse**, **crosses a repository boundary**,
**affects security or data integrity**, or **would otherwise be rediscovered by
archaeology six months later**.
