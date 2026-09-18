# CI/CD — API

How the API reaches production, what the pipeline may and may not do, and the
operator-managed contract it depends on.

Deploying by hand is [deployment.md](deployment.md); that procedure is what this
pipeline automates, and it remains the fallback when the pipeline is unavailable.

> The controlled production deployment path has been proven end-to-end.
> **OPS-3 is COMPLETE.** Deployment remains a controlled, manual operation: a
> failed run is not a successful deployment merely because the checkout advanced
> or health happens to be green. Use the reported failure boundary and complete
> the remaining sequence deliberately.

---

## 1. Two workflows, and why they are separate

| | `ci.yml` | `deploy.yml` |
| --- | --- | --- |
| Trigger | `push` / `pull_request` on `main` | **`workflow_dispatch` only** |
| Secrets | none | environment-scoped, deploy job only |
| Environment | none | `production` |
| Concurrency | per-ref, cancels stale runs | `purrenade-api-production`, **never cancels** |

**Deployment is manual on purpose.** Automatic deployment on every green push
would mean a documentation merge can restart a live service on a host that runs
unrelated applications, and it removes the moment where a person decides that
now is a good time to migrate a schema. The few minutes saved by automatic
deployment are not worth spending that control point.

Concurrency never cancels a deployment in flight. A run interrupted between
migration and cache rebuild is worse than a queued one.

## 2. What the pipeline proves before it touches anything

`deploy.yml` takes one input — a **full 40-character commit SHA** — and proves
three things about it in a job that holds **no secrets and has no environment**,
so untrusted input is never parsed in the presence of production credentials:

1. **It is a full lowercase SHA.** Not a ref, not a branch name. A branch name
   would let `workflow_dispatch` deploy an unreviewed branch, since the launcher
   chooses the ref a dispatch runs against.
2. **It is reachable from `origin/main`.** A commit on a fork, a PR head or a
   deleted branch is refused.
3. **CI concluded `success` for those exact bytes**, queried through the GitHub
   API with the read-only automatic token.

Only then does the deploy job start, and only that job sees a secret.

## 3. The deployment, phase by phase

`deploy/bin/deploy.sh` runs on the host. It is copied there from the reviewed
revision at deploy time and removed afterwards, so the script that executes is
never a stale copy that happened to be sitting on the server.

```
PREFLIGHT     arguments validated · PHP 8.4 confirmed by asking the binary
CHECKOUT      fetch · reachability re-checked · checkout exact SHA · clean-tree assert
DEPENDENCIES  composer install --no-dev --optimize-autoloader, explicit PHP 8.4
BACKUP        pg_dump --format=custom  →  pg_restore --list   ← proven readable
MIGRATION     artisan migrate --force --no-interaction
CACHES        config:cache · route:cache · event:cache
SERVICES      reload php8.4-fpm · restart purrenade-queue.service
HEALTH        GET /api/v1/health, retried
```

The validated `--root` is canonicalized and becomes the deployment process cwd
before the checkout phase. Therefore every repository-relative operation,
including Composer lifecycle scripts, is bound to the intended application
checkout rather than an SSH login directory or the transport script's location.

Then the workflow runs a public HTTPS smoke against the same endpoint.

### Failure boundaries

The script reports **which boundary it stopped at**, because "the deploy failed"
is not actionable when a schema migration is involved.

| Boundary | What is true | What to do |
| --- | --- | --- |
| `PREFLIGHT` | Nothing changed | Retry freely |
| `CHECKOUT` | Code moved; no dependency or schema change | Retry, or check out the previous revision |
| `DEPENDENCIES` | Code and `vendor/` may disagree | Re-run, or restore the previous revision |
| `BACKUP` | **Nothing changed. The deployment stopped before migrating.** | Fix the backup path or permissions, then retry |
| `MIGRATION` | The schema may be partially changed | **Stop.** A human chooses forward-fix or a planned restore |
| `CACHES` / `SERVICES` / `HEALTH` | The schema is already migrated | Forward-fix is normally correct |

**There is no automatic rollback, deliberately.** Checking out the previous
revision does not undo a migration, and automating `migrate:rollback` runs
destructive `down()` methods — turning a bad deploy into data loss. The pipeline
never restores a database either; restoration stays an explicit, planned
operator procedure ([database-and-queue.md §2](database-and-queue.md#2-backup-before-every-migration)).

A backup that fails stops the deployment **before** the migration. That ordering
is the single most important property in this file.

## 4. Security model

**Workflow permissions** are explicit and minimal: `contents: read` at workflow
level, with `actions: read` added only to the validation job for its CI query.

**Every external action is pinned to a full commit SHA** with the release in a
trailing comment. A tag is a moving pointer — whoever can move `v2` can run
their code in a job. The comment is for humans; the SHA is the contract.

**Host verification is real.** `PROD_SSH_KNOWN_HOSTS` is provisioned
out-of-band and written to `known_hosts` before any connection.
`StrictHostKeyChecking=yes` is explicit on every `ssh` and `scp`.
**`ssh-keyscan` is never run at deploy time** — trusting whatever key answers
turns the first connection into an unguarded opportunity to impersonate the
host.

**No caller value is ever remote shell source.** Every `ssh` and `scp` goes
through `deploy/bin/remote-exec.sh`, the only place in this repository that
builds a remote command. ssh has no argv — whatever it is given is joined into
one string and handed to a shell on the far side — so passing a value "as an
environment variable" does not make it safe once it is embedded in that string.
Each argument is therefore base64-encoded on the runner and decoded on the host;
the base64 alphabet carries no quote, `$`, `;`, newline or leading `-`, so the
interpolated text cannot change how anything parses. An earlier revision
interpolated repository variables into single-quoted strings, and
`/var/www/x'; id; '` was proven to execute on the host.

**Host, user and port are ssh's own arguments**, not remote source, and are
validated against strict grammars *and* passed after `--`. A user beginning with
`-` would otherwise be read as an option — `-oProxyCommand=…` executes on the
runner, where the private key is. ssh and scp get independent option arrays;
deriving one from the other rewrote every element and corrupted identity paths
containing `-p`.

**No secret is printed.** No `set -x` around credentials, no environment dump,
no echoing of values. Database credentials are never handled at all: the backup
runs as the `postgres` OS user over the local socket under peer authentication,
so there is no password to pass.

**Injection defences.** The SHA input is pattern-tested before use and rejected
unless it is 40 lowercase hex characters, so it cannot carry a path traversal, a
quote or a command substitution into a path or a remote command. The same test
is repeated inside the script, so running it by hand is equally safe.

**Health assertions are specific.** `deploy/bin/health-check.sh` requires
**exactly 200** — a 503 is degraded and a 3xx means something else answered —
plus `"status":"ok"` and `"database":"ok"` in the body. A `curl -f` alone accepts
a redirect and cannot tell this API from another site on the shared host.

**Artifact attestations are deferred.** The backend transfers no build artifact
— it deploys a Git revision the host fetches itself, verified by reachability
from `main`. There is nothing an attestation would add here. Revisit if the
backend ever gains an artifact.

## 5. Deployment identity and the privileged backup helper

A **dedicated unprivileged account**, not root, with write access only to the
API deployment directory.

### The sudoers contract

Three rules, each an exact command with no wildcard and no caller-supplied
argument:

```
# /etc/sudoers.d/purrenade-deploy   (operator installs this; CI/CD never writes it)
<deploy-user> ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
<deploy-user> ALL=(root) NOPASSWD: /usr/bin/systemctl restart purrenade-queue.service
<deploy-user> ALL=(root) NOPASSWD: /usr/local/sbin/purrenade-backup
```

That is the complete list — three operations, three rules.

### What this replaced, and why

An earlier draft documented two further rules:

```
ALL=(postgres) NOPASSWD: /usr/bin/pg_dump
ALL=(root)     NOPASSWD: /usr/bin/tee /<backup-dir>/*
```

An independent audit rejected both, correctly.

The first restricts no arguments, so the deployment account could dump **any
database on a host that runs unrelated applications** and could write files as
`postgres`. The second is a root-owned arbitrary-file-write primitive; sudo's
own manual warns that wildcards in command arguments are not a security
boundary. Between them they turned a backup step into cross-tenant data
disclosure plus a route to root.

The fix is not a tighter wildcard. **It is to remove the arguments.**

### The helper

`/usr/local/sbin/purrenade-backup` is root-owned, mode 0755, and **takes no
arguments at all**. The database name and the backup directory come from
`/etc/purrenade/backup.conf` — root-owned, mode 0640, which the deployment
account cannot write. The filename is generated inside the helper. It runs
`pg_dump` through `runuser`, refuses an empty dump, proves the archive with
`pg_restore --list`, and prints exactly one line: the path of a verified backup.

The deployment account therefore receives the **outcome** of a backup and never
the capability to take one against a target of its choosing.

**sudo must never execute a file the deployment account can write.** The
canonical source lives in this repository at
`deploy/privileged/purrenade-backup`, but bootstrap installs a **root-owned copy
at `/usr/local/sbin/`**, outside every deploy-writable directory, and the
sudoers rule names that installed copy. Granting sudo to a path inside the
deployment checkout would be a root shell with extra steps.

Every `sudo` in the deployment code uses **`-n`**. A missing rule then fails
immediately instead of blocking on a password prompt that no CI session can
answer — which would otherwise hold the deployment lock until the job timed out.

nginx is **never** restarted by the pipeline: it serves unrelated applications.

## 6. Production bootstrap and reconciliation

The initial production bootstrap is operator-managed. The pipeline cannot
bootstrap itself; do not blindly reapply these steps to a running host. They are
the contract to reconcile deliberately when the deployment identity, privileged
helper, service units, or GitHub environment configuration changes.

1. **Create the deployment account** on the host — unprivileged, owning the API
   deployment directory.
2. **Install the sudoers contract** from §5, narrowed to the real paths.
3. **Install the privileged backup helper.** Copy
   `deploy/privileged/purrenade-backup` to `/usr/local/sbin/purrenade-backup`,
   `chown root:root`, `chmod 0755`. Do **not** point sudo at the repository
   copy.
4. **Create `/etc/purrenade/backup.conf`** — `root:root`, `0640`, containing
   `PURRENADE_DB=` and `PURRENADE_BACKUP_DIR=`. Create the backup directory
   root-restricted and outside Git. Verify with
   `sudo -u <deploy-user> sudo -n /usr/local/sbin/purrenade-backup`.
5. **Reconcile the queue unit — do not blindly replace it.**
   `deploy/systemd/purrenade-queue.service` is a template reconstructed from
   this repository's documented runtime contract; **nobody has read the running
   production unit.** Diff it against the live unit first
   (`systemctl cat purrenade-queue.service`) and reconcile the differences
   deliberately. The sandboxing directives it carries commented out are
   **PROPOSED and unverified** — enable them separately, never as a side effect
   of adopting CI/CD.
6. **Generate a deployment SSH keypair** on a trusted machine, add the public
   key to the deployment account's `authorized_keys`, and keep the private key
   out of chat, email and the repository. Prefer a key dedicated to this
   pipeline so it can be rotated without touching human access.
7. **Capture the host key out-of-band** (`ssh-keyscan` run once, by a human, on
   a trusted network, and verified against the host's own
   `/etc/ssh/ssh_host_*_key.pub`).
8. **Create the `production` GitHub Environment** with required reviewers and a
   deployment branch policy limited to `main`.
9. **Add the environment secrets and variables** in §7.
10. **Deploy once by hand** following [deployment.md](deployment.md), to confirm
   the account and sudoers contract work before the pipeline depends on them.

## 7. Environment configuration

Names only. **No value belongs in this repository.**

**Secrets** (environment-scoped to `production`)

| Name | What it is |
| --- | --- |
| `PROD_HOST` | Deployment host address |
| `PROD_USER` | Deployment account name |
| `PROD_SSH_PRIVATE_KEY` | Private key for the deployment account |
| `PROD_SSH_KNOWN_HOSTS` | Trusted host key line, captured out-of-band |

**Variables** (non-secret, environment-scoped)

| Name | What it is |
| --- | --- |
| `PROD_SSH_PORT` | SSH port; defaults to 22 when unset |
| `PROD_API_ROOT` | API deployment directory on the host |
| `PROD_API_HEALTH_URL` | `https://api.purrenade.ferzendervarli.com/api/v1/health` |
| `PROD_API_PUBLIC_URL` | `https://api.purrenade.ferzendervarli.com` |

There is deliberately **no variable for the database name or the backup
directory**. Those live in the root-owned helper configuration, so the workflow
cannot name what gets dumped or where it is written even if a repository
variable were altered.

The host address and account name are secrets rather than variables — not
because they are cryptographic, but because this repository is public and they
are reconnaissance an attacker would otherwise be handed.

## 8. Testing the deployment logic

`tests/deploy/deploy.test.sh` drives the real scripts against temporary
directories: revision validation, refusal of unsafe input, the guarantee that a
dry run changes nothing, that the backup phase precedes the migration phase,
that PHP 8.4 is always named explicitly, that no forbidden command
(`db:seed`, `migrate:rollback`, `migrate:fresh`, `key:generate`) appears in
executable lines, and that no database credential appears in either script.

```bash
tests/deploy/deploy.test.sh
bash -n deploy/bin/deploy.sh deploy/bin/backup.sh
```

A production migration rollback is **not** simulated. Rehearsing it locally
would manufacture confidence in the one procedure whose real answer is "stop and
let a human decide".

## 9. Accepted residual risk: the in-place transition window

The API deploys **in place**, from the production Git checkout, rather than into
immutable release directories the way the frontend does. That is the proven
model this pipeline automates, and it is not redesigned here.

It has a consequence worth stating plainly rather than discovering during an
incident. Between `git checkout` and the PHP-FPM reload, live traffic can be
served by a **mixed state**: new source files with an old `vendor/` while
Composer is still working, or new code against caches that have not been
rebuilt. The window is seconds, but it is real, and it exists in the manual
procedure too.

What follows from it:

- **Migrations should stay backward-compatible** with the immediately previous
  revision wherever the change allows it. Additive first, destructive later, in
  a separate release — a column dropped in the same deploy that stops using it
  is a column the old code is still selecting during the window.
- **Every in-place backend deployment has a transition window**, so the
  documented failure boundaries and recovery principle remain operationally
  significant even after prior successful runs.
- **Automatic rollback does not solve this and is not offered.** Once the
  migration phase has run the schema has changed, and checking out the previous
  revision does not undo it.

Immutable backend releases, or a brief maintenance window, are the obvious
future answers. Neither is in this generation of the pipeline.

## 10. Troubleshooting

| Symptom | Cause |
| --- | --- |
| Validation refuses a SHA that is on main | No **push-event** CI run concluded success for that exact commit |
| `Host key verification failed` | `PROD_SSH_KNOWN_HOSTS` is missing, stale, or the host key changed. **Investigate before updating it.** |
| `sudo: a password is required` | The sudoers contract in §5 is missing or does not match the command exactly |
| Stops at `BACKUP` | The backup directory is missing, or the `tee` sudo rule does not cover it. The schema is untouched. |
| Stops at `MIGRATION` | A migration failed. Do not rerun blindly — read the boundary guidance in §3. |
| Boots then fails health | `EnvironmentGuard` refused a development configuration; it lists every violation at once |
