# Purrenade API — Backend

REST API for **Purrenade**, a browser-first, mobile-first casual endless
score-attack runner. Laravel · PostgreSQL.

> **Status: bootstrapped (M1).** Laravel, the toolchain and quality gates are in place.
> **No product features exist yet** — authentication begins at M2.

## Local setup

Requires **PHP 8.4** and a **native PostgreSQL** server. **No Docker** — this project does not
use containers in local development.

The system default `php` on a developer machine may be an older version, so **every command
names the runtime explicitly**:

```bash
/usr/bin/php8.4 /usr/local/bin/composer install
cp .env.example .env          # then fill in DB_PASSWORD locally
/usr/bin/php8.4 artisan key:generate
/usr/bin/php8.4 artisan migrate
```

| Command | What it does |
| --- | --- |
| `/usr/bin/php8.4 vendor/bin/pest` | Tests |
| `/usr/bin/php8.4 vendor/bin/pint --test` | Formatting check |
| `/usr/bin/php8.4 vendor/bin/pint` | Format |
| `/usr/bin/php8.4 vendor/bin/phpstan analyse` | Static analysis |

`.php-version` records `8.4`. If your `php` already *is* 8.4, the prefix is unnecessary.

### Database

PostgreSQL only — **MySQL is not used** (ADR-0004). Local development uses a dedicated
least-privilege role; **Laravel never connects as the `postgres` superuser**, and the real
password lives only in the git-ignored `.env`.

**Version gap, intentional and temporary:** local development currently runs **PostgreSQL 16.x**
while the project target is **18.x**. This is acceptable while no product-domain migration and
no PG18-specific SQL exist. See `docs/architecture/versions-and-runtime.md`.

## Scope

This repository owns:

- authentication, 2FA, roles and authorization,
- the player profile and progression system,
- run lifecycle, validation and score authority,
- leaderboards, achievements and character unlocks,
- session/device management,
- the admin surface,
- the API contract and its OpenAPI document.

**The Product/Game Specification lives in the frontend repository**
(`purrenade/docs/product/`) and is authoritative for product behavior. This
repository references it and maintains its own technical documentation
independently.

## Repository independence

This is one of **two independent repositories**. They are developed as one
product but are never merged, nested, or given shared Git state. Commits in the
two repositories are always separate.

| Repository | Purpose |
| --- | --- |
| `purrenade` | Nuxt/Vue/TypeScript/Phaser frontend. Owns the Product/Game Specification. |
| `purrenade-api` (this one) | Laravel REST API, PostgreSQL. |

They integrate through an **explicit API contract** and nothing else.

## Documentation

```
docs/
  architecture/   layering, domain boundaries, data model, queues, caching, observability
  api/            ★ conventions, OpenAPI draft, per-resource endpoint contracts
  security/       ★ threat model, auth, authorization, 2FA, anti-cheat, rate limiting, data protection
  testing/        testing strategy and regression gates
  decisions/      architecture decision records
```

Start at [`docs/README.md`](docs/README.md).

## Two decisions block implementation

| ADR | Decision | Blocks |
| --- | --- | --- |
| [ADR-0005](docs/decisions/ADR-0005-authentication-and-2fa-strategy.md) | Authentication transport and 2FA strategy | M2, M3 — and constrains the approved "Android/iOS must remain possible" requirement |
| [ADR-0006](docs/decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md) | Run validation / anti-cheat boundary | M9, M10 — the largest architectural risk in v1 |

## Target stack — PROPOSED

PHP 8.4 · Laravel 13.x · PostgreSQL 18 · Redis 8.x (where justified).

**These are research findings, not pins.** Re-verify current stable versions and
mutual compatibility immediately before bootstrap. See
[`docs/architecture/versions-and-runtime.md`](docs/architecture/versions-and-runtime.md).

**MySQL is not used.**

## Development status

The current milestone is **M0 — documentation foundation**. Framework bootstrap
is M1 and requires explicit approval. See
`purrenade/docs/product/milestones.md`.

## License

**Not yet determined.** See [`LICENSE`](LICENSE).
