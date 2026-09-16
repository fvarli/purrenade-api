#!/usr/bin/env bash
#
# Purrenade API production deployment.
#
#   deploy.sh --root DIR --sha SHA [--php PATH] [--composer PATH]
#             [--backup-helper PATH] [--health-url URL] [--dry-run]
#
# Runs ON the production host, against the production Git checkout. There is no
# build artifact: PHP needs no build step, and the proven model deploys an exact
# reviewed revision from the production clone.
#
# ---------------------------------------------------------------------------
# Failure boundaries — the reason this is a script and not a YAML block
# ---------------------------------------------------------------------------
#
# Each phase below states what is true if it fails, because "the deploy failed"
# is not an actionable sentence when a schema migration is involved.
#
#   PREFLIGHT  nothing has changed. Retry freely.
#   CHECKOUT   the working tree moved; no dependency or schema change yet.
#   DEPENDENCIES  code and vendor/ may disagree with the running FPM workers
#                 until a reload. Re-run, or check out the previous revision.
#   BACKUP     nothing has changed, and the deployment STOPS. Never migrate
#              without a verified backup — it is the only thing that makes
#              restoration possible at all.
#   MIGRATION  the schema may be partially changed. STOP. A human decides:
#              forward-fix, or a deliberately planned restore. This script
#              will never run `migrate:rollback` and never restores a database.
#   CACHES / SERVICES / HEALTH  the schema is already migrated. Rolling the
#              code back does NOT roll the database back. Forward-fix is
#              normally correct.
#
# There is deliberately no automatic rollback here. Application code and schema
# evolve together, so an automated "undo" would be a lie in exactly the cases
# where it matters most.

set -euo pipefail

readonly SHA_PATTERN='^[0-9a-f]{40}$'

die() { printf '\n  ERROR: %s\n\n' "$1" >&2; exit 1; }
info() { printf '  %s\n' "$1"; }
phase() { printf '\n==> %s\n' "$1"; }

# Where the deployment stopped, so the operator is told which boundary was hit
# rather than having to infer it from a stack of output.
BOUNDARY='PREFLIGHT'
on_failure() {
    local code=$?
    [[ $code -eq 0 ]] && return 0
    printf '\n  DEPLOYMENT FAILED at boundary: %s\n' "$BOUNDARY" >&2
    case "$BOUNDARY" in
        PREFLIGHT)    printf '  Nothing was changed. Safe to retry.\n\n' >&2 ;;
        CHECKOUT)     printf '  The checkout moved; no dependencies or schema changed.\n\n' >&2 ;;
        DEPENDENCIES) printf '  Code and vendor/ may disagree. Re-run, or restore the previous revision.\n\n' >&2 ;;
        BACKUP)       printf '  Stopped BEFORE migrating. The schema is untouched.\n\n' >&2 ;;
        MIGRATION)    printf '  The schema may be partially migrated. Do NOT rerun blindly.\n  A human decides: forward-fix, or a planned restore from the pre-migration backup.\n\n' >&2 ;;
        *)            printf '  The schema is already migrated. Code rollback does NOT undo it.\n  Forward-fix is normally correct.\n\n' >&2 ;;
    esac
    exit "$code"
}
trap on_failure EXIT

root=""
sha=""
php_bin="/usr/bin/php8.4"
composer_bin="/usr/local/bin/composer"
backup_helper="/usr/local/sbin/purrenade-backup"
health_url=""
dry_run=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --root)       root="${2:-}"; shift 2 ;;
        --sha)        sha="${2:-}"; shift 2 ;;
        --php)        php_bin="${2:-}"; shift 2 ;;
        --composer)   composer_bin="${2:-}"; shift 2 ;;
        --backup-helper) backup_helper="${2:-}"; shift 2 ;;
        --health-url) health_url="${2:-}"; shift 2 ;;
        --dry-run)    dry_run=1; shift ;;
        *) die "unknown argument: $1" ;;
    esac
done

# ---------------------------------------------------------------------------
phase "PREFLIGHT"
# ---------------------------------------------------------------------------
BOUNDARY='PREFLIGHT'

[[ -n "$root" ]] || die "missing --root"
[[ -n "$sha" ]] || die "missing --sha"
[[ "$sha" =~ $SHA_PATTERN ]] || die \
"unsafe revision: '$sha'
  A deployment revision must be a full 40-character lowercase Git commit SHA."
[[ -d "$root" ]] || die "deployment root does not exist: $root"
[[ -d "$root/.git" ]] || die "not a Git checkout: $root"

if [[ "$dry_run" -eq 0 ]]; then
    # PHP 8.4 by absolute path, never bare `php`. The host's generic PHP is the
    # 8.3 family for unrelated applications, and running Purrenade on it would
    # silently use a different runtime than the one it is deployed against.
    [[ -x "$php_bin" ]] || die "PHP 8.4 not found at $php_bin"
    [[ -f "$composer_bin" ]] || die "Composer not found at $composer_bin"

    actual_php="$("$php_bin" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    [[ "$actual_php" == "8.4" ]] || die \
"$php_bin reports PHP $actual_php, expected 8.4.
  Production runs PHP 8.4 side by side with the host default. Do not change the
  system alternative to work around this."
fi

info "revision:  $sha"
info "root:      $root"
info "php:       $php_bin"
[[ "$dry_run" -eq 1 ]] && info "MODE:      dry run — nothing will be changed"

# ---------------------------------------------------------------------------
phase "CHECKOUT"
# ---------------------------------------------------------------------------
BOUNDARY='CHECKOUT'

run() {
    if [[ "$dry_run" -eq 1 ]]; then
        printf '  DRY RUN: %s\n' "$*"
        return 0
    fi
    "$@"
}

run git -C "$root" fetch --quiet origin
if [[ "$dry_run" -eq 0 ]]; then
    git -C "$root" cat-file -e "${sha}^{commit}" 2>/dev/null \
        || die "revision not found after fetch: $sha"
    # Only revisions reachable from origin/main deploy. The workflow checks this
    # too; repeating it here means the script is safe to run by hand.
    git -C "$root" merge-base --is-ancestor "$sha" origin/main \
        || die "revision is not reachable from origin/main: $sha"
fi
run git -C "$root" checkout --quiet --force "$sha"

if [[ "$dry_run" -eq 0 ]]; then
    [[ -z "$(git -C "$root" status --porcelain)" ]] || die \
"the production checkout is dirty after checkout.
  Production is a deployment target, not a workstation. Investigate local edits."
fi
info "checked out $sha"

# ---------------------------------------------------------------------------
phase "DEPENDENCIES"
# ---------------------------------------------------------------------------
BOUNDARY='DEPENDENCIES'

# --no-dev: Pint, PHPStan, Pest and Faker have no business on a production host.
run "$php_bin" "$composer_bin" install \
    --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
info "production dependencies installed"

# ---------------------------------------------------------------------------
phase "BACKUP"
# ---------------------------------------------------------------------------
BOUNDARY='BACKUP'

# The backup is taken by a ROOT-OWNED privileged helper that accepts NO
# arguments. The database and the destination come from a root-owned
# configuration file this account cannot write, and the filename is generated
# inside the helper.
#
# That shape is deliberate. An earlier draft granted this account
# `sudo -u postgres pg_dump` with no argument restriction and a wildcard root
# `tee`, which together are a superuser read of every database on a shared host
# plus an arbitrary root file write. Removing the arguments removes the reach.
#
# `sudo -n` is the boundary, not this variable: only the installed helper path
# carries a NOPASSWD rule, so pointing --backup-helper elsewhere simply fails to
# obtain privilege rather than escalating.
if [[ "$dry_run" -eq 1 ]]; then
    info "DRY RUN: would run  sudo -n ${backup_helper}"
    backup_path="(dry run)"
else
    # If this fails the trap fires and the deployment stops here, before the
    # schema is touched. That ordering is the whole point.
    backup_path="$(sudo -n "$backup_helper" | tail -n 1)"
    [[ -n "$backup_path" ]] || die "the backup helper reported no path"
fi
info "backup: $backup_path"

# ---------------------------------------------------------------------------
phase "MIGRATION"
# ---------------------------------------------------------------------------
BOUNDARY='MIGRATION'

# --force because Laravel refuses to migrate in production interactively;
# --no-interaction so it is safe from a non-tty. Seeders are never run: the
# seeder creates an account whose password is a constant in the repository.
run "$php_bin" "$root/artisan" migrate --force --no-interaction
info "migrations applied"

# ---------------------------------------------------------------------------
phase "CACHES"
# ---------------------------------------------------------------------------
BOUNDARY='CACHES'

# config:cache stops .env being read at all, so the caches must be rebuilt after
# any environment change, not only after a code change.
run "$php_bin" "$root/artisan" config:cache
run "$php_bin" "$root/artisan" route:cache
run "$php_bin" "$root/artisan" event:cache
info "production caches rebuilt"

# ---------------------------------------------------------------------------
phase "SERVICES"
# ---------------------------------------------------------------------------
BOUNDARY='SERVICES'

# Exactly two named units, each covered by the narrow sudoers contract in
# docs/production/ci-cd.md. Nothing global is restarted: nginx serves unrelated
# applications and is never touched here.
#
# The worker is RESTARTED, not reloaded: a long-lived worker holds the old code
# in memory. --max-time would cycle it eventually, but "eventually" is not a
# deployment step.
run sudo -n systemctl reload php8.4-fpm
run sudo -n systemctl restart purrenade-queue.service
info "PHP-FPM reloaded, queue worker restarted"

# ---------------------------------------------------------------------------
phase "HEALTH"
# ---------------------------------------------------------------------------
BOUNDARY='HEALTH'

if [[ -n "$health_url" && "$dry_run" -eq 0 ]]; then
    # Readiness, not liveness: the endpoint runs `select 1` against PostgreSQL.
    # Polling every two seconds stays far below the endpoint's 60/minute limit.
    healthy=0
    for _ in $(seq 1 20); do
        if curl -fsS -o /dev/null "$health_url"; then healthy=1; break; fi
        sleep 2
    done
    [[ "$healthy" -eq 1 ]] || die \
"health check never returned success: $health_url
  The schema is already migrated. Code rollback does NOT undo that.
  Read the application log and EnvironmentGuard output before acting."
    info "health check passed"
else
    info "health check skipped"
fi

BOUNDARY='COMPLETE'
trap - EXIT
printf '\n  Deployed %s\n\n' "$sha"
