# Purrenade API — Working Agreement

Read this before making any change in this repository.

Before changing local infrastructure or developer configuration, read
`docs/architecture/local-development.md`.

## What this repository is

The Laravel/PostgreSQL REST API for Purrenade. It is the **authoritative
security and data boundary** for the product: authentication, authorization,
score integrity and personal data are decided here.

`purrenade` (Nuxt/Vue/TypeScript/Phaser) is a **separate, independent
repository**. Never merge them, nest them, share Git state, or unify dependency
management. Commits in the two repositories are always separate.

**The Product/Game Specification lives in the frontend repository**
(`purrenade/docs/product/`) and is authoritative for product behavior. This
repository maintains its own technical documentation independently.

## Source-of-truth priority

1. Written Product/Game Specification — behavior and game rules.
2. Claude Design v0.3 — approved UX/UI structure and visual decisions.
3. Historical v0.2.1 decisions as represented in v0.3 — secondary only.
4. ChatGPT Art Direction Board — aspirational mood only; not mechanics.
5. Private source references — local-only, never committed or served.

**If a conflict cannot be resolved, stop and report it instead of inventing a
decision.** Record it in `purrenade/docs/product/design-reference-conflicts.md`.

## Decision status discipline

Every documented decision carries **APPROVED**, **PROPOSED**, or **OPEN**.
Never silently promote PROPOSED or OPEN to APPROVED.

**Two ADRs block implementation and must be decided, not assumed:**
- ADR-0005 — authentication transport and 2FA strategy (blocks M2/M3)
- ADR-0006 — run validation / anti-cheat boundary (blocks M9/M10)

## Non-negotiable engineering rules

- **Authorization is enforced server-side on every request.** A frontend check is
  never a control.
- **Never trust client-submitted score or progression data.**
- **Thin controllers.** Business logic lives in domain/service layers.
- **Explicit validation** at the boundary.
- **Database constraints in addition to** application validation.
- **Transactions** wherever integrity requires them.
- **Race-safe** progression and run completion; **idempotent** submission.
- **Indexes follow real query patterns**, verified against realistic volumes.
- **Queues only where they provide real value.**
- **Structured logging with correlation IDs; no sensitive data in logs.**
- **Consistent, documented API errors** with stable machine-readable codes.
- **OpenAPI stays in sync with the implementation.** A contract change updates
  the implementation, the document, and the frontend together.
- **Migrations reversible where practical.**
- **PostgreSQL only. MySQL is not used.**

## Versions

Version findings in `docs/architecture/versions-and-runtime.md` are **research,
not pins**. Re-verify current stable versions and mutual compatibility
immediately before installing anything. Prefer the newest stable **compatible**
stack, not the newest version number. Never hardcode old versions.

## Git identity and attribution

- Use the existing Git identity. Never change `user.name` / `user.email`.
- Never use "Claude", "Anthropic", "AI", "Assistant", "Bot" or similar as author
  or committer.
- **No AI attribution anywhere**: no `Co-authored-by` trailers, no generated-by
  banners, footers, signatures, comments, or metadata in source, docs,
  changelogs, pull-request text, or commit messages.
- Commit messages describe the change only. Conventional-commit style:
  `feat(auth): add email verification flow`.
- Never commit or push unless the current milestone explicitly authorizes it.

## Hard prohibitions

- Do not commit secrets, credentials, keys, or real player data.
- Do not implement an OPEN decision.
- Do not log personal data, tokens, 2FA secrets, or recovery codes.
- Do not let a cache become the source of truth for ranking or progression.
- Do not add an endpoint that is not in the API contract.
