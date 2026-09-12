# ADR-0001 — Separate frontend and backend repositories

- **Status:** Accepted
- **Scope:** Product-wide
- **Date:** 2026-09-12
- **Decision owner:** Product owner

## Context

Purrenade is one product built from two codebases: a Nuxt/Vue/TypeScript/Phaser
browser client and a Laravel/PostgreSQL REST API. They differ in language,
runtime, package manager, test tooling, deployment target and release cadence.

The approved product direction states the requirement directly: they are **two
independent repositories developed as one product**, and each must have its own
Git history, dependencies, CI, test suite, README and technical documentation.

## Decision

Two independent repositories:

| Repository | Owns |
| --- | --- |
| `purrenade` | Frontend. **Also owns the Product/Game Specification.** |
| `purrenade-api` | Backend architecture, API contract, security documentation |

Explicitly prohibited:

- turning them into a monorepo,
- moving one repository into the other,
- creating shared Git state (submodules, nested repositories, shared hooks),
- merging package or dependency management,
- a commit that spans both.

They integrate through an **explicit API contract** and nothing else.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| **Monorepo** | Genuinely attractive for atomic cross-stack changes and one CI. Rejected because the approved direction requires independence, and because a PHP/Node monorepo needs tooling that neither ecosystem provides natively — the coordination cost is paid on every change, while the atomic-change benefit is realized only on contract changes. |
| **Backend nested inside the frontend** | Creates shared Git state, couples release cadence, and makes the backend's history unreadable on its own. |
| **Shared package for contract types** | A third repository to version and release. The OpenAPI document already serves this purpose without new infrastructure. |

## Consequences

**Easier**
- Independent deployment, versioning and rollback.
- Each repository's CI is simple and fast, with no cross-language matrix.
- Clear ownership; a backend change cannot accidentally break a frontend build.
- Security boundaries are structural: the browser bundle cannot contain server code.

**Harder**
- A contract change requires coordinated changes in two repositories, in the
  right order. This is the main cost, and it is mitigated by the
  contract-change process in `purrenade-api/docs/api/contract-change-process.md`.
- Some documentation is duplicated — notably product-wide ADRs. Duplication is
  accepted so each repository is independently readable; divergence is treated
  as a defect.
- Cross-repository refactors need discipline rather than a compiler.

**Now constrained**
- The API contract is the **only** integration point. Hidden coupling — shared
  assumptions about storage, ordering, or timing — is a bug.
- The frontend must never duplicate backend authority or security logic.
- The backend must never trust client-submitted score or progression data.
