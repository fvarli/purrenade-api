# API deployment

The manual procedure in use today. **There is no CI/CD yet** — automating this
is the next infrastructure milestone, and [§5](#5-what-future-cicd-must-preserve)
records what that automation has to keep.

Read [README.md](README.md) first for the environment these steps assume.

---

## 1. The model

Unlike the frontend, the API is deployed from a **production Git checkout**
rather than a transferred artifact: PHP needs no build step, and Composer can
install exactly what the lockfile names. What the two share is the rule that
the revision must be reviewed, exact and already pushed.

```
reviewed revision, already on origin/main
   │
   ├── update the production checkout to that exact SHA
   ├── Composer production install, explicit PHP 8.4
   ├── pre-migration PostgreSQL backup          ← never skipped
   ├── migrate --force --no-interaction
   ├── rebuild Laravel production caches
   ├── reload PHP 8.4 FPM
   ├── restart the queue worker
   └── health check → public smoke
```

**The production host is a deployment target, not a development workstation.**
The checkout could technically commit and push; doing so is exceptional hotfix
behaviour only. Source edited on the server is drift that the next deploy
silently reverts and that is invisible from the repository.

The first production deployment used backend revision
`8046fb5dcf900538b5b97ed137fed9fa133f9db1`.

## 2. Deploying

Every PHP invocation names the runtime explicitly. See
[README.md §3](README.md#3-runtime-isolation) — bare `php` on this host is not
8.4, and assuming otherwise runs the application on the wrong runtime.

```bash
# 1. The exact reviewed revision
git fetch origin
git checkout <release-sha>
git status --porcelain        # must be empty

# 2. Dependencies, production shape
/usr/bin/php8.4 /usr/local/bin/composer install \
    --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
```

`--no-dev` matters: Pint, PHPStan, Pest and Faker have no business on a
production host, and `--optimize-autoloader` is what `composer.json` already
requests through `config.optimize-autoloader`.

```bash
# 3. Back up the database BEFORE migrating — see database-and-queue.md §2
# 4. Migrate
/usr/bin/php8.4 artisan migrate --force --no-interaction
```

`--force` is required because Laravel refuses to migrate in production
interactively; `--no-interaction` makes the run safe from a script. **Never run
a seeder in production** — see [§4](#4-what-is-forbidden-in-production).

```bash
# 5. Production caches
/usr/bin/php8.4 artisan config:cache
/usr/bin/php8.4 artisan route:cache
/usr/bin/php8.4 artisan event:cache

# 6. Pick up the new code and configuration
sudo systemctl reload php8.4-fpm
sudo systemctl restart purrenade-queue.service
```

**`config:cache` stops `.env` being read at all.** Every `env()` call outside
`config/` returns null afterwards — which is why this repository reads `env()`
only inside `config/`, and why the cache must be rebuilt after any environment
change, not just after a code change. CI proves both caches build and clear
cleanly on every commit.

The queue worker must be **restarted, not reloaded**: a long-lived worker holds
the old code in memory and would keep running it. The unit's `--max-time=3600`
means it would eventually cycle on its own, but "eventually" is not a deployment
step.

## 3. Verifying

```bash
curl -fsS https://api.purrenade.ferzendervarli.com/api/v1/health
```

Expect 200 with `checks.application: "ok"` and `checks.database: "ok"`. A 503
means the application booted but PostgreSQL is unreachable — the deployment
reached the host, the database did not answer.

Then the end-to-end lifecycle, which is the real baseline because it exercises
the queue and SMTP as well: register a player through the public frontend,
receive the verification email, accept the code, sign in, sign out, sign in
again. The full sequence is in
[`purrenade/docs/production/operations.md`](https://github.com/fvarli/purrenade/blob/main/docs/production/operations.md).

If the application refuses to boot, read the message: `EnvironmentGuard` lists
**every** production-configuration violation at once, in plain language. That is
a correct refusal, not a deployment failure — fix the configuration.

## 4. What is forbidden in production

- **Seeders.** `DatabaseSeeder` is local and test convenience; it creates an
  account whose password is a constant in the repository. `db:seed` appears in
  no production procedure, deliberately.
- **Direct SQL role edits** to create an administrator. The supported path is
  `purrenade:admin:promote` — see [`../architecture/operations.md`](../architecture/operations.md).
- **`APP_KEY` regeneration** as part of a deploy. It protects encrypted state
  including 2FA secrets; rotation is a planned security operation.
- **Switching the system PHP alternative** to 8.4 to make a command work.
- **Editing source on the server** outside a declared hotfix.

## 5. Rollback

Backend rollback is **harder than frontend rollback**, and the difference is the
database. Application code and schema evolve together: an older release may not
understand the schema the newer one migrated to.

**`migrate:rollback` is not the standard recovery mechanism.** Some `down()`
methods are destructive — they drop columns and tables, and the data in them is
gone whether or not the rollback "succeeded". Reaching for it reflexively turns
a bad deploy into data loss.

The order of preference:

1. **Assess compatibility.** Did this release migrate? If not, checking out the
   previous revision, reinstalling dependencies and rebuilding caches is a
   complete rollback.
2. **Forward-fix.** Usually correct when a migration has run. Ship a small
   corrective release rather than reversing the schema.
3. **Restore from the pre-migration backup** — only when restoration is
   explicitly required and deliberately planned, accepting that data written
   since the backup is lost. See
   [database-and-queue.md §2](database-and-queue.md#2-backup-before-every-migration).
4. **Coordinate the two halves.** If the frontend release being withdrawn
   depended on this API change, roll both, in the order that leaves no window
   where a deployed frontend calls an endpoint that no longer exists.

This is why the pre-migration backup is not optional. It is the only thing that
makes option 3 available at all, and it has to exist *before* the migration that
might make it necessary.

## 6. What future CI/CD must preserve

**Must preserve**

- A reviewed, exact, pushed revision
- Explicit **PHP 8.4** for every invocation — never bare `php`
- A production Composer install: `--no-dev`, from the lockfile, optimized autoloader
- A **pre-migration PostgreSQL backup**, verified to exist before migrating
- Guarded production migrations — `--force --no-interaction`, never a seeder
- A config, route and event cache rebuild after code or environment changes
- A PHP-FPM reload and a queue worker **restart**
- An application health check, then a public smoke test
- Safe failure behaviour: a failed step stops the deployment rather than
  continuing to the next one

**Must not**

- Run seeders, or any command that writes application data
- Regenerate or print `APP_KEY`, or echo any environment value into job output
- Change the host's system PHP alternative or any shared runtime default
- Migrate without a verified backup
- Treat `migrate:rollback` as automated recovery
- Leave the queue worker running old code after a deploy

Do not create deployment GitHub Actions in this milestone. The existing CI
workflow formats, analyses, tests and checks contracts and documentation; it
does not deploy, and nothing here changes that.
