# Database, queue and mail in production

PostgreSQL, the backup and migration procedure, the queue worker, and
transactional email. Deploying is [deployment.md](deployment.md).

Host-specific values appear as `<placeholder>`.

---

## 1. PostgreSQL

| | |
| --- | --- |
| Production version | **16.15** |
| Project target | **18.x** |
| Exposure | **Not publicly exposed** for this deployment |
| Database | dedicated to Purrenade |
| Role | dedicated to Purrenade, deliberately non-privileged |

**The version gap is a known, accepted deviation — not an error to correct by
rewriting the target.** The project targets 18.x; production currently runs
16.15. Since M9, CI runs a dedicated PostgreSQL 18 compatibility job alongside
the default one, so the product's database-sensitive behaviour is gated on both.
CI records the gap explicitly rather than hiding it.

The application role is least-privilege by construction:

```
LOGIN · NOSUPERUSER · NOCREATEDB · NOCREATEROLE · NOINHERIT · NOREPLICATION
```

`PUBLIC CONNECT` was revoked on the database and the application role granted
the access it needs explicitly. The role cannot create databases — the same
property the local and CI setups rely on, which is why the test suite isolates
itself in a **schema** rather than a second database.

Nothing in the application needs more than this. A migration that required
`SUPERUSER` would be a design problem, not a permissions problem.

## 2. Backup before every migration

A migration that has run cannot be assumed reversible — see
[deployment.md §5](deployment.md#5-rollback). The backup is what makes recovery
possible at all, so it is taken **before** the migration, every time.

Backups are written to a **root-restricted directory outside Git**, and they
contain application data: they are secret-bearing artifacts, never repository
files, never pasted into a diagnostic.

### The command, and why it is shaped this way

The obvious form does not work:

```bash
# WRONG — fails
sudo -u postgres pg_dump --file=<backup-dir>/<name>.dump purrenade
```

`pg_dump` runs as the `postgres` OS user, which **cannot traverse a root-only
directory**, so it cannot create the file. The fix is not to loosen the
directory. Let `postgres` write to stdout and let the privileged shell perform
the redirect:

```bash
sudo -u postgres pg_dump --format=custom <database-name> \
  | sudo tee <backup-dir>/pre-migration-$(date -u +%Y%m%dT%H%M%SZ).dump >/dev/null
```

The `postgres` user produces the bytes; root places them. Neither needs a
permission it should not have.

`--format=custom` rather than plain SQL, because a custom-format dump can be
restored selectively and validated without being executed:

```bash
sudo pg_restore --list <backup-dir>/<file>.dump | head
```

**A backup that has not been listed is not a backup.** `pg_restore --list` is
the cheapest possible proof that the file is a readable archive rather than a
truncated transfer or an empty file from a command that failed quietly.

Timestamped names are a convention, not a fixed filename — the first production
backup's name is historical evidence, not a value to hardcode into a script.

### Restoring

Restoration is deliberate, planned and explicitly authorised. It loses
everything written since the backup, so it is the last option, not the first.
Confirm the archive with `pg_restore --list`, stop the queue worker so no job
writes during the restore, restore, then rebuild caches and re-verify health and
the full sign-in lifecycle.

## 3. Migrations

```bash
/usr/bin/php8.4 artisan migrate --force --no-interaction
```

`--force` because Laravel refuses to migrate in production without it.
Explicit PHP 8.4 because bare `php` on this host is not 8.4.

**Never run a seeder in production.** `DatabaseSeeder` is local and test
convenience, documented as such in its own docblock: it creates an account whose
password is a constant in this repository. On a real database that is a
published credential on a verified account. `db:seed` appears in no production
procedure.

## 4. The queue worker

| | |
| --- | --- |
| Driver | `database` — no Redis in this deployment |
| Unit | `purrenade-queue.service`, enabled, restarts automatically |
| Identity | an unprivileged deployment account, with the web-server group |
| Runtime | explicit **PHP 8.4** |

```
queue:work database --sleep=3 --tries=3 --timeout=90 --max-time=3600
```

`--max-time=3600` cycles the process hourly so a long-lived PHP worker cannot
accumulate leaked state. It does **not** replace restarting the worker on
deploy: a running worker holds the old code in memory until it cycles.

```bash
sudo systemctl status  purrenade-queue.service
sudo systemctl restart purrenade-queue.service
sudo journalctl -u purrenade-queue.service -f
```

### What is queued, and why it matters

Only transactional mail: `VerifyEmailNotification` and
`ResetPasswordNotification`, both `ShouldQueue` and both dispatched
`afterCommit` so a code for a row a rollback removes is never sent.

This makes the worker **load-bearing for registration.** With
`QUEUE_CONNECTION=database` and no worker running, verification codes are
written to the `jobs` table and never delivered: registration appears to
succeed and the player waits for mail that will not arrive. Nothing errors.
That silence is why the worker is a service rather than something started by
hand, and it is why the smoke test insists on a real delivered email.

### Monitoring

```sql
SELECT count(*) FROM jobs;          -- depth; should drain, not grow
SELECT count(*) FROM failed_jobs;   -- should be zero
```

Depth, failure count and oldest-job age are the three signals that matter. A
growing `jobs` table with a healthy worker means jobs are failing and retrying;
`failed_jobs` is where they land after `--tries=3`.

### How to smoke-test the queue, and how not to

**Do it with the real notification** — trigger a registration through the public
frontend and confirm the email arrives. That exercises the whole chain: Laravel →
the database queue → the PHP 8.4 worker → SMTP → a real mailbox. It is the
verified production path.

Two methods that do **not** work here, both learned the hard way:

- **A queued Closure evaluated in Tinker.** It fails serialization because a
  Closure typed at a REPL has no source file to serialize from. That is a test
  method failing, not the queue — and it wastes an incident's worth of attention
  looking for an infrastructure fault that is not there.
- **A marker file in the host's `/tmp`.** The worker unit runs with
  `PrivateTmp`, so its `/tmp` is not the host's. The job writes the file, the
  operator looks in the wrong `/tmp`, and a working queue looks broken.

## 5. Transactional email

| | |
| --- | --- |
| Provider | Zoho Mail, **EU region** |
| Host | `smtp.zoho.eu` |
| Port | `587`, TLS |
| Sender | `Purrenade <hello@uselunexa.com>` |
| Authentication | a Zoho **App Password** — never documented, never committed |

**The region matters.** `smtp.zoho.com` returns **SMTP 535** for this account;
the EU endpoint `smtp.zoho.eu` is the verified one. A 535 here reads as bad
credentials and sends an operator hunting for a wrong password, when the
password is fine and the endpoint is wrong.

Delivery was verified end to end at the first production deployment, both
directly and through the queued verification notification.

`MAIL_MAILER=log` is refused in production by `EnvironmentGuard` — it does not
error, it writes the verification code to a log file and reports success, which
is the most dangerous failure shape in this whole system.

### When mail does not arrive

1. Is the worker running? `systemctl status purrenade-queue.service`
2. Is anything queued or failed? `jobs`, `failed_jobs`
3. The worker journal — SMTP errors surface there
4. Is the endpoint the EU one, and the port 587 with TLS?
5. Laravel's own logs under the application's `storage/logs`

**Never** log or paste verification codes, recovery codes, tokens, passwords or
personal data while debugging. A diagnostic paste is the usual way that
protection gets bypassed.

## 6. Logs

| Concern | Where |
| --- | --- |
| Application | Laravel logs under the application's `storage/logs` |
| PHP | the PHP 8.4 FPM service and its journal |
| HTTP | the dedicated Purrenade API nginx access and error logs |
| Queue | `journalctl -u purrenade-queue.service` |
| Security events | the dedicated security channel, retained per `LOG_SECURITY_DAYS` |

Structured application logging is built to carry no secrets and no PII — a CI
gate proves no credential-shaped value reaches any logging call, and that every
writer to the security channel is a known one. Do not defeat it by hand.
