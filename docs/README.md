# Purrenade API — Documentation Index

This repository's technical documentation. All documentation is **English only**.

**The Product/Game Specification lives in the frontend repository**
(`purrenade/docs/product/`) and is **authoritative for product behavior**. This
repository references it and maintains backend-specific technical documentation
independently.

---

## Decision status legend

| Status | Meaning | Rules |
| --- | --- | --- |
| **APPROVED** | Already decided. | Authoritative. Implement as written. |
| **PROPOSED** | A recommendation, written so it can be reviewed. | **Not authoritative.** Must be reviewed before the milestone that depends on it closes. |
| **OPEN** | Unresolved; requires a decision. | **Must not be implemented or guessed.** |

**PROPOSED and OPEN are never silently promoted to APPROVED.**

### Where open questions are tracked

Each document carries its own **Open questions** table, and every one of those
questions is also indexed in the consolidated register in the frontend
repository: `purrenade/docs/product/open-decisions.md` (§6.2 covers this
repository). The register is the single live list across both repositories.

When you add an open question here, add it to that register in the same change.
Reference ids are unique across both repositories — backend-owned prefixes
include `API-`, `AUTH-`, `2FA-`, `ANTI-`, `CACHE-`, `DM-`, `BA-`, `OB-`, `QJ-`,
`RL-`, `GR-`, `AD-`, `PR-`, `CH-`.

---

## 🔴 One decision blocks implementation

| ADR | Decision | Blocks |
| --- | --- | --- |
| [ADR-0006](decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md) | Run validation / anti-cheat boundary | **M9, M10.** The largest architectural risk in v1. |

[ADR-0005](decisions/ADR-0005-authentication-and-2fa-strategy.md) — authentication
transport and 2FA — is **Accepted and implemented (M2)**, with all ten of its
open parameters resolved. See
[auth-architecture.md](architecture/auth-architecture.md).

---

## Architecture (`docs/architecture/`)

| Document | Contents |
| --- | --- |
| [auth-architecture.md](architecture/auth-architecture.md) | **★** How authentication works: Sanctum mode, abilities, sessions, secrets, rate limits, the error contract (M2) |
| [backend-architecture.md](architecture/backend-architecture.md) | Layering, request lifecycle, thin controllers |
| [domain-boundaries.md](architecture/domain-boundaries.md) | Auth · progression · runs · leaderboards · admin |
| [data-model.md](architecture/data-model.md) | **★** PostgreSQL schema shape, constraints, indexes |
| [queues-and-jobs.md](architecture/queues-and-jobs.md) | Justified queue usage only |
| [caching-and-redis.md](architecture/caching-and-redis.md) | Rate limiting, cache, leaderboard projection |
| [observability.md](architecture/observability.md) | Correlation IDs, structured logs, no-PII rule |
| [engineering-standards.md](architecture/engineering-standards.md) | **★** Per-milestone quality checklist |
| [operations.md](architecture/operations.md) | **★** Establishing the first administrator, how everyone else gets an account, procedures deliberately absent |
| [local-development.md](architecture/local-development.md) | **★** Local HTTPS, ports, reverse-proxy trust, the two ways to run the API, and the endpoints it answers today |
| [versions-and-runtime.md](architecture/versions-and-runtime.md) | Version research findings (not pins) |

## API (`docs/api/`)

| Document | Contents |
| --- | --- |
| [api-conventions.md](api/api-conventions.md) | **★** Versioning, error envelope, pagination, idempotency, rate limits |
| [openapi.draft.yaml](api/openapi.draft.yaml) | **★** The contract shape |
| [contract-change-process.md](api/contract-change-process.md) | How a contract change crosses two repositories |
| [endpoints/](api/endpoints/) | Per-resource contracts: [auth](api/endpoints/auth.md) · [game-runs](api/endpoints/game-runs.md) · [progression](api/endpoints/progression.md) · [leaderboards](api/endpoints/leaderboards.md) · [achievements](api/endpoints/achievements.md) · [characters](api/endpoints/characters.md) · [profile](api/endpoints/profile.md) · [admin](api/endpoints/admin.md) |

## Security (`docs/security/`)

| Document | Contents |
| --- | --- |
| [threat-model.md](security/threat-model.md) | **★** What is being defended, against whom |
| [authentication.md](security/authentication.md) | Registration, login, verification, reset |
| [authorization-and-roles.md](security/authorization-and-roles.md) | `player` and `admin`; server-side enforcement |
| [two-factor.md](security/two-factor.md) | TOTP, recovery codes, mandatory admin 2FA |
| [anti-cheat.md](security/anti-cheat.md) | **★** Run validation boundary |
| [rate-limiting.md](security/rate-limiting.md) | Limits per endpoint class |
| [data-protection.md](security/data-protection.md) | **★** KVKK/GDPR, PII, retention, private-source rule |

## Testing (`docs/testing/`)

[testing-strategy.md](testing/testing-strategy.md) · [regression-gates.md](testing/regression-gates.md)

## Decisions (`docs/decisions/`)

[ADR index](decisions/README.md) — ADR-0001, 0003, 0004, 0005, 0006, 0008.
Product-wide ADRs are mirrored from the frontend repository with the same
numbers and the same decisions.
