# ADR-0003 — Laravel REST API backend

- **Status:** Accepted
- **Scope:** Product-wide
- **Date:** 2026-09-12
- **Decision owner:** Product owner

## Context

The approved product surface requires a server that owns authentication with
2FA, role-aware authorization, durable progression, leaderboards, achievements,
character unlocks, session/device management and an admin panel — with
**server-side authorization** and **no trust in frontend authorization**.

The approved technology direction names Laravel, REST and OpenAPI.

## Decision

**Laravel, exposing a versioned REST API documented by OpenAPI.**

1. **REST over `/api/v1`.** Versioned from the first endpoint, not retrofitted.
2. **OpenAPI is the contract.** Authored in `docs/api/openapi.draft.yaml` before
   implementation; kept in sync with the implementation thereafter; frontend
   types are generated from it.
3. **Thin controllers.** Business logic lives in domain/service layers, not in
   controllers.
4. **Explicit validation** at the boundary, and **authorization enforced
   server-side** on every request.
5. **Database constraints in addition to application validation** — integrity is
   not left to application code alone.
6. **Consistent, documented error responses** with stable machine-readable codes.
7. **Queues only where they provide real value** — transactional email is the
   clear case; leaderboard projection refresh is a candidate.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| **GraphQL** | The client's data needs are few, fixed and well known — 21 screens with stable shapes. GraphQL's flexibility would buy little while adding query-cost control, caching and authorization-per-field problems to a product whose main risk is already score integrity. |
| **Laravel with server-rendered views** | The frontend is a separate Nuxt application ([ADR-0001](ADR-0001-separate-frontend-backend-repositories.md)); the backend serves data, not HTML. |
| **A different backend framework or language** | Contradicts the approved technology direction. Laravel also ships mature, audited answers for exactly the flows this product needs (registration, verification, reset, 2FA, queues, rate limiting). |
| **Serverless functions** | Would fragment the transactional integrity that run submission and progression require, for no benefit at this scale. |
| **Generated-only OpenAPI** (write code first, generate docs) | Rejected for v1's starting point: the contract must exist **before** implementation so the frontend can be built against it. Generation is then used to verify the implementation has not drifted. |

## Consequences

**Easier**
- The frontend can be built against a contract before the backend exists.
- Contract drift becomes a build-time type error in the frontend.
- Laravel's ecosystem covers the auth surface without bespoke cryptography.
- Versioning from day one means v2 does not require breaking v1 clients — which
  matters if a native shell ships later and lags behind the web client.

**Harder**
- The contract must be maintained in two places at once (document and
  implementation), which is why `docs/api/contract-change-process.md` exists.
- Laravel has no LTS designation since Laravel 6: a steady annual upgrade cadence
  must be planned rather than assumed.

**Now constrained**
- No business logic in controllers.
- No endpoint that trusts the client for score, progression, or authorization.
- Every contract change updates the implementation, the OpenAPI document, and the
  frontend's generated types together.
