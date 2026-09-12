# Contributing — Purrenade API

## Ground rules

1. **Two independent repositories.** Never merge this repository with
   `purrenade`, never nest one inside the other, never share Git state, and never
   couple dependency management. Backend and frontend commits stay separate.
2. **The written Product/Game Specification wins for behavior.** It lives in
   `purrenade/docs/product/`. Approved behavior is never changed silently.
3. **The API contract is binding.** A contract change updates the implementation,
   the OpenAPI document, and the frontend together — see
   [`docs/api/contract-change-process.md`](docs/api/contract-change-process.md).
4. **Decision status is explicit.** Every documented decision is tagged
   `APPROVED`, `PROPOSED`, or `OPEN`. PROPOSED and OPEN are never silently
   promoted to APPROVED.

## Working sequence for any milestone

1. Read the current specification.
2. Inspect both repositories.
3. Inspect the relevant design references.
4. Research current recommended technical practices.
5. Document findings that materially affect implementation.
6. Identify conflicts with approved product decisions.
7. If no meaningful conflict exists, implement.
8. If a meaningful conflict exists, stop and report before changing behavior.
9. Add/update tests.
10. Run the relevant regression gates ([`docs/testing/regression-gates.md`](docs/testing/regression-gates.md)).
11. Update the technical documentation and the OpenAPI document.
12. Review the final diff for scope creep and regressions.

## Git identity and commit attribution

- Use the repository/user's existing Git identity. Do not change global or local
  `user.name` / `user.email`.
- Do not create tool-specific or automation identities, and do not use
  "Claude", "Anthropic", "AI", "Assistant", "Bot" or similar as author or committer.
- Do not add generated-by banners, signatures, footers, trailers, comments, or
  metadata identifying an assistant anywhere.
- Before the first commit, verify: `git config user.name`, `git config user.email`,
  `git status`, `git remote -v`, `git branch --show-current`. If identity is
  missing, stop and report rather than inventing one.

## Commit messages

Conventional-commit style, describing the change only:

```
feat(auth): add email verification flow
fix(runs): prevent duplicate run submission
docs(api): document game run contract
test(auth): cover mandatory admin 2FA
```

## Code expectations

See [`docs/architecture/engineering-standards.md`](docs/architecture/engineering-standards.md).
The short version:

- Idiomatic Laravel; **thin controllers**; no business logic buried in them.
- Explicit validation; **authorization enforced server-side** on every request.
- **Database constraints in addition to** application validation.
- Transactions wherever data integrity requires them.
- Race-safe score and progression updates; idempotency where relevant.
- Indexes driven by real query patterns, verified against realistic volumes.
- Queues only where they provide real value.
- Structured logging with correlation IDs; **no sensitive data in logs**.
- Consistent, documented API responses and errors.
- **OpenAPI kept in sync with the implementation.**
- Migrations reversible where practical.
- Tests for authorization, validation, edge cases and **abuse cases**.

## Never commit

- Secrets, `.env` files, credentials, keys, tokens.
- Real player data or production dumps.
- `vendor/`, build output, or local database volumes.
