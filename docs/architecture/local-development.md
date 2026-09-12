# Local Development

The API runs natively behind Nginx with trusted local HTTPS. **No Docker.**

```
  https://api.purrenade.test  →  native Nginx (TLS)  →  http://127.0.0.1:8410
```

## Requirements

- **PHP 8.4** — the system default may be older, so commands name the runtime explicitly.
- **Native PostgreSQL** — see the version note in `versions-and-runtime.md`.
- **Nginx** with an mkcert certificate for `api.purrenade.test`.
- Hostname resolving to loopback: `127.0.0.1 api.purrenade.test`.

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
session, and neither the unit nor the launcher touches them.

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
of paging a human. Laravel's own `/up` remains the liveness probe.

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
