# Security Policy — Purrenade API

## Reporting a vulnerability

Report suspected vulnerabilities privately to the repository owner. Do not open
a public issue, and do not include working exploit code in the initial report.

**OPEN:** the published security contact address and disclosure timeline are not
yet decided. See `purrenade/docs/product/open-decisions.md` (SEC-4).

## Scope

This repository is the **authoritative security boundary** for Purrenade.
Authentication, authorization, score integrity and personal data are decided
here. The browser client is untrusted by design.

## Standing rules

1. **Authorization is enforced server-side on every request.** A frontend check
   is never a control.
2. **The client is never trusted for score or progression.** Submitted run
   results are validated — see
   [`docs/security/anti-cheat.md`](docs/security/anti-cheat.md) and
   [ADR-0006](docs/decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md).
3. **2FA is mandatory for the admin role**, enforced at the server boundary such
   that no admin route can bypass it.
4. **No sensitive data in logs** — no credentials, tokens, 2FA secrets, recovery
   codes, or personal data. Correlation IDs, not identities.
5. **Database constraints back application validation.** Integrity is not left to
   application code alone.
6. **Rate limiting on every authentication and submission endpoint**, per
   identifier and per source.
7. **Secrets never enter the repository.** `.env.example` documents shape, never
   values.

## Known unresolved security decisions

| Ref | Decision |
| --- | --- |
| ADR-0005 | Authentication transport, token lifetime, 2FA enforcement point |
| ADR-0006 | Run validation / anti-cheat model |
| SEC-1 | Password policy — length, composition, breach-list checking |
| SEC-3 | KVKK/GDPR flows — deletion, export, consent, retention |
| SEC-4 | Published security contact and disclosure timeline |

## Dependencies

Dependency and supply-chain policy is defined at M1, when dependencies first
exist. Until then this repository has no dependencies.
