# Production — API

How the Purrenade API runs in production. Start here, then go to
[deployment.md](deployment.md) to ship a release, or
[database-and-queue.md](database-and-queue.md) for PostgreSQL, the queue worker
and transactional mail.

The browser-facing half of this documentation lives in the other repository, at
[`purrenade/docs/production/`](https://github.com/fvarli/purrenade/tree/main/docs/production).
The two are written to be read together: that one owns everything up to the BFF,
this one owns everything past it.

> **Public repository.** This documents the system design and the operating
> procedure, not the host. Machine identity, addresses, capacity, firewall state
> and every credential are deliberately absent — see
> [§8](#8-what-never-goes-in-this-repository). Host-specific values appear as
> `<placeholder>` and are read from the server, never from Git.

**Operating the application** — establishing the first administrator, and how
accounts work — is [`../architecture/operations.md`](../architecture/operations.md).
That document covers procedures *inside* the application; this directory covers
the environment it runs in.

---

## 1. What runs in production

| Component | What it is | Exposure |
| --- | --- | --- |
| nginx | TLS termination, **shared with unrelated applications** | Public, 80 → 443 |
| PHP 8.4 FPM | The Purrenade PHP runtime, its own service and socket | Local socket only |
| Laravel API | API-only; document root is the application's `public/` | Public, its own HTTPS origin |
| `purrenade-queue.service` | `queue:work database`, one worker | No listener |
| PostgreSQL | Application database and role, dedicated to Purrenade | **Not publicly exposed** |

Production is **API-only**. There is no server-rendered UI here; the browser
never talks to this service directly as a token-bearing client. Every
browser-originated request arrives from the Nuxt BFF, server to server.

### Public endpoints

| | |
| --- | --- |
| API | `https://api.purrenade.ferzendervarli.com` |
| Frontend | `https://purrenade.ferzendervarli.com` |

There is deliberately no `www` hostname. DNS is managed through Cloudflare with
the Purrenade records **DNS-only, not proxied** — no Cloudflare edge is part of
the current traffic architecture.

## 2. The request path

```
Browser → nginx (frontend vhost) → Nitro/BFF
                                      │  HTTPS, server-to-server, bearer token
                                      ▼
                          nginx (API vhost)        TLS terminates here
                                      │  FastCGI over a local socket
                                      ▼
                          PHP 8.4 FPM → Laravel
                                      │
                          ┌───────────┴───────────┐
                          ▼                       ▼
                    PostgreSQL            database queue
                                                  │
                                    purrenade-queue.service (PHP 8.4)
                                                  │
                                              SMTP → mailbox
```

The bearer token is held by the BFF, never by the browser. The authoritative
design is
[`purrenade/docs/architecture/bff-and-session.md`](https://github.com/fvarli/purrenade/blob/main/docs/architecture/bff-and-session.md)
and, on this side, [`../architecture/auth-architecture.md`](../architecture/auth-architecture.md).

## 3. Runtime isolation

**The host is shared, and Purrenade must not disturb it.** This is the single
most important operational constraint, and two version choices follow from it.

| | Purrenade | The host's generic default |
| --- | --- | --- |
| PHP | **8.4** (8.4.25 observed at first deployment) | the 8.3 family, for other applications |
| Composer | 2.9.5 | — |
| Laravel | 13.31.0 | — |

The API's nginx virtual host routes PHP explicitly to the **PHP 8.4 FPM
socket**, so Purrenade gets 8.4 without anything else on the machine changing.

**Never switch the system PHP alternative to 8.4** to make a command work.
Name the runtime instead — every production PHP invocation is explicit:

```bash
/usr/bin/php8.4 artisan ...
/usr/bin/php8.4 /usr/local/bin/composer ...
```

Assuming bare `php` means 8.4 is the mistake this convention exists to prevent:
it silently runs a different runtime than the one the application is deployed
against. The repository already follows this convention everywhere, including
`.php-version` and the `^8.4` constraint in `composer.json`.

## 4. Production configuration

The environment file lives on the host, outside Git, readable by the runtime
identity and the web server group and nobody else. The repository carries
`.env.example` with non-secret examples; that file is a template, never a record
of production values.

Safe, non-secret semantics:

```ini
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

### The application refuses to boot on a development configuration

`App\Support\EnvironmentGuard` runs as the first statement of
`AppServiceProvider::boot()` and throws when `APP_ENV=production` while any of
these hold:

| Rejected | Why it is dangerous rather than merely wrong |
| --- | --- |
| `APP_DEBUG=true` | Returns framework internals — including configuration — to clients |
| `MAIL_MAILER=log` | Writes verification codes to a file and **reports success**; mail silently never arrives |
| `QUEUE_CONNECTION=sync` | Runs queued mail inside the request, so a slow provider becomes a slow signup |
| A `.test` / `.local` / `localhost` `APP_URL` or `FRONTEND_URL` | Password-reset links point at a host that does not exist for the player |

It reports **all** violations at once rather than the first, and it refuses
rather than warning. Every one of these is safe locally and dangerous in
production, which is exactly the combination that survives a deployment
unnoticed: nothing fails, nothing warns, and the first symptom is an incident.

### `APP_KEY`

`APP_KEY` protects encrypted application state, **including two-factor
secrets**. Losing it does not merely invalidate sessions — it makes enrolled
second factors unreadable.

It is not a value to regenerate as part of a deploy. Rotation is a dedicated
security operation: plan it with the framework's supported previous-key
strategy, and plan the migration and recovery of already-encrypted state
explicitly before starting.

## 5. Health

```bash
curl -fsS https://api.purrenade.ferzendervarli.com/api/v1/health
```

`GET /api/v1/health` is **readiness, not liveness**. It runs `select 1` against
PostgreSQL, because every meaningful response this API produces comes out of the
database.

| Result | Status | Body |
| --- | --- | --- |
| Healthy | **200** | `status: "ok"`, `checks.application: "ok"`, `checks.database: "ok"` |
| Database unreachable | **503** | `status: "degraded"`, `checks.database: "error"` |

503 rather than 500 on purpose: it tells a load balancer or uptime monitor to
take the instance out of rotation instead of paging a human. The reason for the
failure is logged, never returned — a driver message carries the host, port,
database name and role.

The endpoint is rate limited. Laravel's `/up` was **removed** at the M2 audit
and is not a Purrenade health endpoint.

## 6. Known deviations and technical debt

| # | Current state | Position |
| --- | --- | --- |
| 1 | **PostgreSQL 16.15 in production; the project target is 18.x** | A known, accepted deviation. The target is **not** rewritten to 16. A dedicated PG18 compatibility gate lands before database-sensitive product work at M9. |
| 2 | Backend deployment is in-place rather than release-directory based; the controlled pipeline has been exercised but has not completed an end-to-end deployment | Preserve the documented failure-boundary and recovery model. **OPS-3 remains OPEN**; manual deployment remains the fallback. |
| 3 | Reboot-survival not yet verified | Units are enabled; a controlled restart drill is outstanding (OPS-5) |
| 4 | One queue worker, database driver | Correct at this volume; revisit with queue depth (QJ-*) |

CI also runs against a PostgreSQL older than the target and records the gap
explicitly rather than hiding it.

## 7. Future observability and product analytics

Deliberately not designed in this milestone. Three separate concerns, which must
not be collapsed into one vendor decision: **operational observability** (logs,
health, queue depth — what exists today), **product analytics** (registrations,
verified registrations, logins, retention), and **gameplay telemetry** (runs,
score distribution, Paw Tokens, Loli and SLAYYY activations).

**Server-authoritative gameplay facts are not client analytics events.** Scores
that decide a leaderboard cannot share a channel with UI events; the anti-cheat
trust boundary — [ADR-0006](../decisions/ADR-0006-run-validation-and-anti-cheat-boundary.md),
the largest architectural risk in v1 — has to be settled first. Privacy,
consent, retention, minimization, aggregation and admin visibility come before
implementation, and no vendor is chosen here. Tracked as **OPS-4**.

## 8. What never goes in this repository

- Production `.env` files or any part of their contents
- `APP_KEY`, the database password, the SMTP App Password, bearer tokens
- Database dumps, or any backup that carries application data
- Session contents, recovery codes, private keys
- Host identity, addresses, capacity, firewall or Fail2ban state, SSH details
- Developer-specific absolute local paths

CI enforces part of this: no environment file other than `.env.example` may be
tracked, and a gate proves no credential-shaped value reaches any log call.

---

| Document | Contents |
| --- | --- |
| [deployment.md](deployment.md) | **★** Deploying a release, caches, FPM, rollback principles, CI/CD invariants |
| [database-and-queue.md](database-and-queue.md) | **★** PostgreSQL, backup and restore, migrations, the queue worker, SMTP |
| [../architecture/operations.md](../architecture/operations.md) | **★** Administrator bootstrap and the account model |
