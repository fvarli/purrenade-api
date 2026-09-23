# Versions and Runtime — Backend

**These are research findings, not permanent pins.**

Current stable versions and their **mutual compatibility** must be re-verified
immediately before M1 bootstrap. A package is not adopted merely because it is
the newest — the target is the **newest stable *compatible* stack**. Nothing was
installed or upgraded during M0.

---

## 1. Verification record

| Field | Value |
| --- | --- |
| Verified on | **2026-09-12** (M0 research), **re-verified 2026-09-12 immediately before M1 bootstrap** |
| Sources | Packagist, php.net, endoflife.date, Laravel documentation |
| Next verification | Before the next major upgrade, recorded here with its date |

## 1A. Installed at M1 bootstrap — actual, not aspirational

Versions **actually resolved into `composer.lock`**, using the real PHP 8.4 runtime.

| Component | Installed | Note |
| --- | --- | --- |
| PHP | **8.4.19** at `/usr/bin/php8.4` | Project baseline. The system default `php` remains 8.2 and is **not** changed. |
| Laravel | **13.31.0** | |
| Composer | 2.8.6, run as `/usr/bin/php8.4 /usr/local/bin/composer` | |
| Pest | **5.1.4** + `pest-plugin-laravel` 5.0.1 | |
| PHPUnit | **13.3.x** | See below |
| Pint | 1.32.x | |
| Larastan | 3.12.x | level 5 baseline |
| **PostgreSQL (local)** | **16.15** native | See the version gap below |
| **PostgreSQL (target)** | **18.x** | Unchanged, per ADR-0004 |

### PHP is pinned per project, not system-wide

```
composer  →  /usr/bin/php8.4 /usr/local/bin/composer …
artisan   →  /usr/bin/php8.4 artisan …
pest      →  /usr/bin/php8.4 vendor/bin/pest
pint      →  /usr/bin/php8.4 vendor/bin/pint
```

`.php-version` records `8.4`, and `composer.json` requires `"php": "^8.4"`.

**There is deliberately no `config.platform.php` pin.** The baseline is PHP 8.4, not one
specific patch release. Pinning the exact local patch would make the lockfile permanently
assert a machine state that is already changing; resolving under the **real** runtime is both
accurate and self-correcting as patches land.

### PHPUnit was raised from the skeleton's constraint

The `laravel/laravel` skeleton pins `phpunit/phpunit ^12.5.12`; **Pest 5.1.4 requires
`^13.3.2`**. That is not a conflict with Laravel itself — `laravel/framework` 13.31.0 declares
`^11.5.50 || ^12.5.8 || ^13.0.3`, so **PHPUnit 13 is officially supported**. The skeleton was
simply conservative. Raised to `^13.3.2`; PHPUnit 13 requires PHP ≥ 8.4.1, satisfied by 8.4.19.

### PostgreSQL: a known, intentional version gap

| | Version |
| --- | --- |
| **Local development (M1)** | **16.15**, the native cluster already running on `127.0.0.1:5432` |
| **Project / deployment target** | **18.x**, unchanged |
| **CI** | Whatever the runner provides — **detected at runtime, never assumed** |

This gap is acceptable **for bootstrap only**: M1 writes no product-domain migration and no
PostgreSQL-18-specific SQL or feature. The baseline is **not** redefined to 16, and **Docker is
not** introduced to eliminate the difference — Docker is not part of this project in any form.

A dedicated **PG18 compatibility gate** is added before database-sensitive product work (M9).
The local cluster may be upgraded natively before then; nothing in M1 depends on it.

**M9: the gate exists.** CI's `pg18` job installs PostgreSQL 18 natively from the PostgreSQL
project's apt repository, verifies the server reports `18.*`, and runs the migrations, the
whole suite — including the real-connection concurrency suite that exercises the
partial-index `ON CONFLICT` inference and READ COMMITTED re-select the run lifecycle relies
on — and a full rollback round-trip. The `app` job keeps running on the runner's default
version, so both 16-class and 18 behaviour are gated.

**M9 dev dependency:** `symfony/yaml ^8.1` (resolved 8.1.6, matching the installed Symfony 8.1
line; verified 2026-09-23). Test-only: the OpenAPI conformance tests parse
`docs/api/openapi.draft.yaml` with it. Chosen over a third-party OpenAPI-validator package
(C-9) because the conformance check needed is small and in-repo.

### Not installed at M1

| Package | Milestone |
| --- | --- |
| `laravel/fortify`, `laravel/sanctum` | **M2** — auth. `install:api` was deliberately **not** run, since it pulls Sanctum. |
| Redis / Valkey | **Not adopted.** CACHE-1 remains OPEN and evidence-driven; nothing in M1 needs it. |

---

## 2. Findings — 2026-09-12

| Component | Latest at verification | Notes |
| --- | --- | --- |
| **Laravel** | **13.31.0** (2026-09-08) | Released Mar 2026. Declares `php ^8.3`, but **Symfony 8 dependencies effectively require PHP 8.4**. **No LTS since Laravel 6:** bug fixes ≈ Q3 2027, security ≈ Q1 2028 — plan a steady annual upgrade cadence. |
| **PHP** | 8.5.10 current; **8.4.25 recommended** | PHP 8.4 EOL 2028-12-31. PHP 8.3 EOL 2027-12-31. **PHP 8.2 EOLs 2026-12-31.** |
| **PostgreSQL** | **18.6** (EOL 2030-11-14) | Target 18. PG 17 is a fallback if hosting lags. |
| Redis | 8.10.x | Licensing is RSALv2/SSPL; **Valkey** is the BSD-licensed alternative. See [caching-and-redis.md](caching-and-redis.md) §6. |
| `laravel/sanctum` | 4.3.3 | SPA session cookies **or** API tokens |
| `laravel/fortify` | 1.39.0 | Registration, login, email verification, password reset, **2FA** |
| `laravel/passport` | 13.8.0 | OAuth2 — not needed; no third-party clients exist |
| `laravel/horizon` | 5.49.0 | Only if Redis queues are adopted |
| `pestphp/pest` | 5.1.4 | **Requires `php ^8.4`** — reinforces the PHP 8.4 target |
| `laravel/pint` | 1.32.1 | Formatting gate |
| `larastan/larastan` | 3.12.0 | Static analysis gate |
| `spatie/laravel-permission` | 8.3.0 | Candidate for roles — likely **unnecessary** for a two-role model |
| `dedoc/scramble` | 0.13.43 | OpenAPI generation candidate |
| `darkaonline/l5-swagger` | 11.1.0 | OpenAPI generation candidate |

### 2.1 Local environment at verification

| Tool | Local | Assessment |
| --- | --- | --- |
| PHP | **8.2.30** | Below Laravel 13's requirement. A **local-environment gap, not an architectural constraint** — upgrade to 8.4 before bootstrap, **not during M0**. |
| Composer | 2.8.6 | Fine |
| psql client | 16.15 | Target is 18; align via Docker at M1 |
| Docker | 29.1.3 | Available — the simplest path to PostgreSQL 18 and PHP 8.4 |

---

## 3. Target stack — PROPOSED

| Component | Target |
| --- | --- |
| PHP | **8.4** |
| Laravel | **13.x** |
| PostgreSQL | **18** |
| Redis / Valkey | **8.x**, only where justified |
| Testing | Pest 5 (requires PHP 8.4) |
| Static analysis | Larastan 3.x |
| Formatting | Pint 1.x |

All PROPOSED, all subject to M1 re-verification.

---

## 4. Auth package research — PROPOSED, decision belongs to ADR-0005

The mainstream 2026 pattern is **Fortify for the flows + Sanctum for session or
token issuance**. Sanctum's 2FA story is TOTP-only; WebAuthn/passkeys would need
an additional package. Passport is not needed — there are no third-party clients.

**No package is selected here.** See
[ADR-0005](../decisions/ADR-0005-authentication-and-2fa-strategy.md).

---

## 5. OpenAPI tooling — PROPOSED

The contract is **authored first** (`docs/api/openapi.draft.yaml`) so the
frontend can build against it before the backend exists. Generation tooling
(Scramble or l5-swagger) is then used to **verify the implementation has not
drifted** from the document — not to replace it.

**OPEN:** which generator, decided at M2 when real endpoints exist.

---

## 6. Version policy — APPROVED

1. **Never hardcode old versions.** Research before bootstrapping.
2. **Never install a package merely because it is newest.** Compatibility decides.
3. **Avoid alphas and betas** for anything load-bearing.
4. **Record what was actually installed, and when it was verified**, in this file.
5. **Re-verify at every bootstrap or major upgrade.**
6. **MySQL is not used**, at any version.

---

## 7. Risks carried into M1

| Risk | Mitigation |
| --- | --- |
| Local PHP is 8.2; Laravel 13 needs 8.3+ and Pest 5 needs 8.4 | Upgrade to PHP 8.4 as the first M1 step |
| Local PostgreSQL client is 16, target is 18 | Run PostgreSQL 18 in Docker; align the client |
| Laravel 13 has no LTS | Plan an annual upgrade cadence; do not assume long-term stasis |
| Laravel's declared `php ^8.3` understates the real requirement | Target 8.4, which the Pest requirement forces anyway |
| Redis licensing | Decide Redis vs Valkey deliberately (CACHE-2) |
