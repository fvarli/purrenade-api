# Local Development

The API runs natively behind Nginx with trusted local HTTPS. The application is
not Dockerized; the sole local Docker dependency is Mailpit, which captures
development email on loopback.

```
  https://api.purrenade.test  →  native Nginx (TLS)  →  http://127.0.0.1:8410
```

## Requirements

- **PHP 8.4** — the system default may be older, so commands name the runtime explicitly.
- **Native PostgreSQL** — see the version note in `versions-and-runtime.md`.
- **Nginx** with an mkcert certificate for `api.purrenade.test`.
- Hostname resolving to loopback: `127.0.0.1 api.purrenade.test`.
- **Mailpit** for local application email capture (SMTP `127.0.0.1:2525`; web
  inbox `http://127.0.0.1:8025`).

## Two ways to run it

Pick one. **Never both at once** — they bind the same port.

### Persistent: a systemd user service

```bash
bin/install-service            # install, enable, start
bin/install-service --uninstall
```

A **user** service, not a system service: it runs as you, needs no root, and stops when your
desktop session ends. The script resolves the paths, installs the unit into
`~/.config/systemd/user/`, reloads the manager and enables it. The tracked template in
`deploy/systemd/` contains **no machine-specific paths**; the installed unit expresses
everything under your home directory with systemd's `%h` specifier.

```bash
systemctl --user status  purrenade-api
systemctl --user restart purrenade-api
systemctl --user stop    purrenade-api

journalctl --user -u purrenade-api -f
journalctl --user -u purrenade-api --since '10 min ago'
```

`Restart=on-failure` brings a crashed server back; a deliberate `systemctl --user stop` is left
alone. The unit passes `--host=127.0.0.1 --port=8410` explicitly rather than relying on `.env`:
`.env` is git-ignored and drifts, and if either value went missing the server would silently fall
back to `0.0.0.0:8000` — a port this project avoids, on an interface it does not expose.

**Lingering is deliberately off.** Without it a user manager starts at login and stops at logout,
which is exactly what is wanted here. Nothing enables it and nothing needs it.

### Foreground

```bash
/usr/bin/php8.4 /usr/local/bin/composer run serve
```

The frontend repository also provides a launcher that starts **both** dev servers together and
stops both on Ctrl+C — see its `local-development.md`. It locates this repository through an
environment variable set on the developer's machine, so **neither repository hardcodes a path
into the other**. If port 8410 is already serving — normally because the service above is
running — the launcher says so and starts nothing.

## What is always running, and what is not

**Nginx and PostgreSQL are persistent system services** — you never start or stop them per
session, and neither the unit nor the launcher touches them. Mailpit is separate
local Docker infrastructure; it does not Dockerize the application.

**The Laravel dev server is not persistent in the same way.** In the foreground it dies with your
terminal; as a user service it dies with your session.

**A 502 from `https://api.purrenade.test` means this dev server is not running.** That is the
expected response, not a broken configuration: Nginx is up and answering, but has nothing behind
it for that host.

**Port 8000 is deliberately not used** — it is a frequent collision and is already occupied on
some machines. `SERVER_HOST` and `SERVER_PORT` in `.env` are read by Laravel's `ServeCommand`, so
`artisan serve` **and** `artisan dev` both bind to `127.0.0.1:8410`. Nothing listens on
`0.0.0.0`.

## Reverse-proxy trust

TLS terminates at Nginx, which forwards a plain HTTP hop. Without trusted-proxy configuration the
application would report `isSecure() === false` and generate `http://` URLs for a request the
browser made over HTTPS.

`TRUSTED_PROXIES` (default `127.0.0.1`) is read in **`config/proxy.php`** and applied in
`AppServiceProvider::boot()`.

Two deliberate constraints:

- **Never `*`.** Trusting every proxy lets any client forge `X-Forwarded-For` and spoof both its
  address and the request scheme.
- **`env()` is read only inside `config/`.** After `php artisan config:cache` the `.env` file is
  no longer consulted, so a stray `env()` call elsewhere would silently return `null`, empty the
  trusted list, and break HTTPS detection **in production only**. It is also why this is not
  configured in `bootstrap/app.php` — that closure runs before the config repository is bound.

Production defines its own proxy topology through the same variable; nothing here assumes it
terminates TLS the same way.

## Mail, locally

Authentication sends two things: the six-digit verification code and the
password-reset link. Both are **queued notifications dispatched after commit** —
a code for a row a rollback removes is worse than a slightly later mail.

`QUEUE_CONNECTION=sync` locally, so they run inline and there is **no worker to
start**. The jobs are still queue-shaped, which is the APPROVED contract
(`docs/security/authentication.md` §3); production flips one environment variable
and runs a worker, with no code change.

> **`QUEUE_CONNECTION=database` without a running worker is a silent failure.**
> Registration succeeds, the code is written to the `jobs` table, and the player
> waits for a mail that will never arrive. The test suite does not catch it —
> `phpunit.xml` sets `sync` — so it only shows up in a real browser. It is
> called out in `.env.example` for that reason.

Mailpit is the canonical local mail workflow. `.env.example` configures Laravel
to send SMTP mail to `127.0.0.1:2525` with `MAIL_MAILER=smtp`,
`MAIL_SCHEME=null`, and null credentials. Mailpit captures that mail in its web
inbox at `http://127.0.0.1:8025`; it is local development infrastructure, not a
production delivery service. `MAIL_MAILER=log` is no longer the canonical local
setup.

The existing container is named `purrenade-mailpit`. Starting it is idempotent:

```bash
docker start purrenade-mailpit
```

If it has not been created on a machine yet, create it once with loopback-only
bindings:

```bash
docker run -d \
  --name purrenade-mailpit \
  --restart unless-stopped \
  -p 127.0.0.1:2525:1025 \
  -p 127.0.0.1:8025:8025 \
  axllent/mailpit
```

### Inspecting application email

Open `http://127.0.0.1:8025` to inspect the six-digit registration-verification
code and password-reset link, as well as other application email. Registration
verification has been verified end-to-end through this setup. Because
`QUEUE_CONNECTION=sync`, these local notifications need no separate queue worker.

## Security events

Authentication events go to a channel of their own,
`storage/logs/security-*.log`, written through `App\Support\AuthLog` and nothing
else. Separate from the application log because retention and alerting differ —
and because one writer means the field set is fixed and no credential can reach
it.

```bash
tail -f storage/logs/security-$(date +%Y-%m-%d).log
```

Logged: logins (success, failure, throttled), registration, verification,
password reset and change, every two-factor transition, recovery-code use,
session revocation, and admin access granted or denied with the reason.

**Never logged:** passwords, TOTP secrets, recovery codes, verification codes,
reset tokens, session cookies, bearer tokens, `APP_KEY`, database credentials. A
CI gate greps for a credential being passed to `AuthLog`.

## Tests run in their own schema

The suite runs in the `purrenade_test` **schema**, inside the same dedicated
`purrenade` database (`DB_SEARCH_PATH` in `phpunit.xml`). `RefreshDatabase` drops
and rebuilds everything in the search path, so sharing `public` with local
development would destroy the developer's data on every run — including the
account used for manual acceptance.

A schema rather than a second database because the application role owns its
database and may create schemas in it, but deliberately has **no `CREATEDB`
privilege**. So the isolation needs no superuser and no sudo:
`tests/TestCase.php` creates the schema if it is missing, and a new developer has
nothing to set up.

```bash
php artisan test                                        # the suite, in its own schema
DB_SEARCH_PATH=purrenade_test php artisan migrate:fresh # rebuild just the test schema
php artisan migrate                                     # the dev schema, incrementally
```

Argon2id cost is lowered in `phpunit.xml` only. `config/hashing.php` keeps the
real values, and no test asserts anything about the cost.

## Creating an administrator locally

There is no self-service route to the admin role, deliberately. Register through
the application, verify the address, then promote that existing account:

```bash
php artisan purrenade:admin:promote you@example.test
```

The command only ever grants the role. It will not create an account, will not
set or reset a password, and will not mark an address verified — so it refuses
an address it cannot find and refuses an account that has not verified. Running
it against an account that is already an administrator reports that and changes
nothing.

Promotion signs the account out everywhere and discards any pending two-factor
challenge. That is the point rather than a side effect: a session that proved
possession as a player must not silently become an administrative session, so
the new privilege begins at a sign-in performed after the change. Add `--force`
to skip the confirmation prompt when scripting.

The same command is the production procedure; the runbook is
[operations.md](operations.md).

The admin surface then stays refused until all four conditions hold — role,
verified address, enrolled second factor, and a session that actually passed a
challenge. Each refusal carries its own code, so the response says which gate
stopped you. Enrol through the account-security screen and sign in again; the
`two-factor` ability is attached when the session is minted and no endpoint adds
it later.

## The two endpoints this service answers today

Both are operational, not product surface. Both are in `docs/api/openapi.draft.yaml`, because
every route that exists must exist in the contract.

### `GET /` — the API-only signpost

```json
{
  "success": false,
  "message": "This is an API-only application. No web access allowed.",
  "service": "Purrenade API",
  "environment": "local",
  "api_base": "/api/v1",
  "health": "/api/v1/health",
  "documentation": null
}
```

There is no browser-facing surface here: no Blade, no view, no HTML error page. Without this
route the web root returns a bare framework 404, which tells an operator nothing and reads like a
misconfigured deployment. `success` is `false` because the caller asked for something this
service does not serve; the status is **200** because the route is not an error — it answers
exactly as designed, and 404-ing a deliberate signpost only hides it from monitoring.

`documentation` is `null` and stays null until documentation is actually published. A plausible
link that 404s is worse than admitting there is none.

It is registered from `routes/root.php` via `withRouting(then:)` in `bootstrap/app.php` — it sits
above the `/api` prefix, and this application has no `web` middleware group for it to join.

### `GET /api/v1/health` — readiness

```json
{
  "success": true,
  "status": "ok",
  "service": "Purrenade API",
  "environment": "local",
  "timestamp": "2026-01-15T09:24:11+00:00",
  "checks": { "application": "ok", "database": "ok" }
}
```

**This is readiness, not liveness.** Every meaningful response this API produces comes out of
PostgreSQL, so it really connects: `select 1` on the default connection. When that fails the
endpoint answers **503** with `status: "degraded"` and `checks.database: "error"` — 503 rather
than 500, because it is what tells a load balancer to take the instance out of rotation instead
of paging a human. It is the only health endpoint: Laravel's `/up` was removed at
the M2 audit for serving HTML from a JSON-only service (observability.md §7).

**The reason for a failure is logged, never returned.** A driver message carries the host, port,
database name and role; the caller gets `"error"` and nothing else.

**Scope is exactly one dependency.** Cache, queue, mail and Redis are not adopted by this
project, and probing things the service does not use turns a health check into an observability
system. A dependency gets a check in the change that adopts it.

**There is no `version` field**, deliberately. `composer.json` declares no version and the
project has no releases, so there is no source of truth to read; the only number available would
be the framework's, which is not this service's version and which
[observability.md §7](observability.md) forbids disclosing to unauthenticated callers anyway. It
is added — here, in the response, and in the contract — in the change that introduces releases.
`config/service.php` records where it will go.

## Verifying

```bash
curl -s https://api.purrenade.test/                   # signpost, 200, no -k needed
curl -s https://api.purrenade.test/api/v1/health      # readiness, 200 / 503
curl -I https://api.purrenade.test/api/v1/health
```
