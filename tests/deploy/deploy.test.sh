#!/usr/bin/env bash
#
# Tests for deploy/bin/deploy.sh and deploy/bin/backup.sh.
#
#   tests/deploy/deploy.test.sh
#
# These exercise everything that can be proven without a production host: input
# validation, refusal behaviour, phase ordering, and the guarantee that a dry
# run changes nothing.
#
# What is deliberately NOT simulated is a migration rollback. Pretending to
# rehearse that locally would produce false confidence in the one area where
# the real procedure is "stop and let a human decide".

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY="$ROOT_DIR/deploy/bin/deploy.sh"
HELPER="$ROOT_DIR/deploy/privileged/purrenade-backup"
DOCS="$ROOT_DIR/docs/production/ci-cd.md"

PASS=0
FAIL=0
pass() { printf '  ok   %s\n' "$1"; PASS=$((PASS + 1)); }
fail() { printf '  FAIL %s\n     %s\n' "$1" "${2:-}"; FAIL=$((FAIL + 1)); }

readonly SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'

# Executable lines only.
#
# Both scripts deliberately NAME the dangerous forms in their comments — the
# deploy script explains that it will never run `migrate:rollback`, and the
# backup script shows the `--file=` form that does not work and why. Grepping
# the raw file would flag that documentation as the very defect it prevents.
code_of() { grep -vE '^[[:space:]]*#' "$@"; }

# A directory that looks enough like a Git checkout for preflight.
make_checkout() {
    local d; d="$(mktemp -d)"; mkdir -p "$d/.git"; printf '%s' "$d"
}

# --- deploy.sh: revision validation ------------------------------------------
t_rejects_unsafe_revisions() {
    local root; root="$(make_checkout)"
    local bad rejected=1
    for bad in 'main' 'HEAD' '../etc' 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' \
               'aaaa' '$(id)' 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa;rm'; do
        if "$DEPLOY" --root "$root" --sha "$bad" \
             --dry-run >/dev/null 2>&1; then
            rejected=0; fail "unsafe revisions are rejected" "accepted: '$bad'"; break
        fi
    done
    [[ $rejected -eq 1 ]] && pass "unsafe revisions are rejected"
    rm -rf "$root"
}

t_requires_arguments() {
    local root; root="$(make_checkout)"
    local ok=1
    "$DEPLOY" --root "$root" --dry-run >/dev/null 2>&1 && ok=0          # no --sha
    "$DEPLOY" --sha "$SHA" --dry-run >/dev/null 2>&1 && ok=0             # no --root
    [[ $ok -eq 1 ]] \
        && pass "missing --root or --sha refuses" \
        || fail "missing --root or --sha refuses" "one was accepted"
    rm -rf "$root"
}

t_rejects_non_git_root() {
    local d; d="$(mktemp -d)"
    "$DEPLOY" --root "$d" --sha "$SHA" \
        --dry-run >/dev/null 2>&1
    [[ $? -ne 0 ]] \
        && pass "a root that is not a Git checkout is refused" \
        || fail "a root that is not a Git checkout is refused" "accepted"
    rm -rf "$d"
}

t_rejects_unknown_argument() {
    local root; root="$(make_checkout)"
    "$DEPLOY" --root "$root" --sha "$SHA" \
        --deploy-everything --dry-run >/dev/null 2>&1
    [[ $? -ne 0 ]] \
        && pass "an unknown argument is refused rather than ignored" \
        || fail "an unknown argument is refused rather than ignored" "accepted"
    rm -rf "$root"
}

# --- deploy.sh: a dry run changes nothing and orders phases correctly ---------
t_dry_run_is_inert_and_ordered() {
    local root; root="$(make_checkout)"
    printf 'untouched' > "$root/canary"
    local out
    out="$("$DEPLOY" --root "$root" --sha "$SHA" \
             --dry-run 2>&1)"
    local rc=$?

    [[ $rc -eq 0 ]] \
        && pass "a dry run completes" \
        || fail "a dry run completes" "exit $rc"

    [[ "$(cat "$root/canary")" == 'untouched' ]] \
        && pass "a dry run changes nothing on disk" \
        || fail "a dry run changes nothing on disk" "canary modified"

    # Backup must be printed before migration; that ordering is the safety
    # property, so it is asserted rather than assumed.
    local backup_line migrate_line
    backup_line="$(printf '%s\n' "$out" | grep -n '==> BACKUP' | cut -d: -f1)"
    migrate_line="$(printf '%s\n' "$out" | grep -n '==> MIGRATION' | cut -d: -f1)"
    if [[ -n "$backup_line" && -n "$migrate_line" && "$backup_line" -lt "$migrate_line" ]]; then
        pass "the backup phase runs before the migration phase"
    else
        fail "the backup phase runs before the migration phase" "backup=$backup_line migrate=$migrate_line"
    fi
    rm -rf "$root"
}

t_dry_run_uses_explicit_php_84() {
    local root; root="$(make_checkout)"
    local out
    out="$("$DEPLOY" --root "$root" --sha "$SHA" \
             --dry-run 2>&1)"
    printf '%s\n' "$out" | grep -q '/usr/bin/php8.4' \
        && pass "the dry run names PHP 8.4 explicitly" \
        || fail "the dry run names PHP 8.4 explicitly" "no php8.4 in output"
    printf '%s\n' "$out" | grep -qE '(^|[^.0-9])php (artisan|/)' \
        && fail "never invokes a bare php" "bare php found" \
        || pass "never invokes a bare php"
    rm -rf "$root"
}

t_never_seeds_or_rolls_back() {
    local forbidden found=0
    for forbidden in 'db:seed' 'migrate:rollback' 'migrate:fresh' 'migrate:reset' 'key:generate'; do
        if code_of "$DEPLOY" | grep -qF -- "$forbidden"; then
            found=1; fail "the deploy script contains no forbidden command" "found: $forbidden"; break
        fi
    done
    [[ $found -eq 0 ]] && pass "the deploy script contains no forbidden command"
}

t_reports_failure_boundary() {
    local d; d="$(mktemp -d)"
    local out
    out="$("$DEPLOY" --root "$d" --sha "$SHA" \
             --dry-run 2>&1)"
    printf '%s\n' "$out" | grep -q 'boundary: PREFLIGHT' \
        && pass "a failure names the boundary it stopped at" \
        || fail "a failure names the boundary it stopped at" "no boundary in output"
    rm -rf "$d"
}

# --- backup.sh ---------------------------------------------------------------




t_no_password_in_scripts() {
    local found=0 needle
    for needle in 'PGPASSWORD' 'DB_PASSWORD' '--password'; do
        if code_of "$HELPER" "$DEPLOY" | grep -qF -- "$needle"; then
            found=1; fail "no database credential appears in the scripts" "found: $needle"; break
        fi
    done
    [[ $found -eq 0 ]] && pass "no database credential appears in the scripts"
}


# --- the privileged backup contract (redteam F1/F2) --------------------------
t_helper_takes_no_arguments() {
    local ok=1 a
    for a in "--database other" "--dir /tmp" "-- --database other" "anything"; do
        # shellcheck disable=SC2086
        PURRENADE_BACKUP_DRY_RUN=1 "$HELPER" $a >/dev/null 2>&1 && { ok=0; fail "the privileged helper takes no arguments" "accepted: $a"; break; }
    done
    [[ $ok -eq 1 ]] && pass "the privileged helper takes no arguments"
}

t_helper_reads_database_and_dir_from_root_owned_config() {
    local conf; conf="$(mktemp)"
    printf 'PURRENADE_DB=purrenade\nPURRENADE_BACKUP_DIR=/var/backups/purrenade\n' > "$conf"
    local out
    out="$(PURRENADE_BACKUP_DRY_RUN=1 PURRENADE_BACKUP_CONF="$conf" "$HELPER" 2>/dev/null | tail -n 1)"
    [[ "$out" == /var/backups/purrenade/pre-migration-* ]] \
        && pass "the destination comes from configuration, not from the caller" \
        || fail "the destination comes from configuration, not from the caller" "got: $out"
    rm -f "$conf"
}

t_helper_rejects_unsafe_config() {
    local conf; conf="$(mktemp)" ok=1
    for pair in "purrenade;id|/var/backups/x" "purrenade|relative/dir" "purrenade|/var/\$(id)" "|/var/backups/x"; do
        printf 'PURRENADE_DB=%s\nPURRENADE_BACKUP_DIR=%s\n' "${pair%%|*}" "${pair##*|}" > "$conf"
        PURRENADE_BACKUP_DRY_RUN=1 PURRENADE_BACKUP_CONF="$conf" "$HELPER" >/dev/null 2>&1 \
            && { ok=0; fail "unsafe configuration is rejected" "accepted: $pair"; break; }
    done
    [[ $ok -eq 1 ]] && pass "unsafe configuration is rejected"
    rm -f "$conf"
}

t_helper_never_sources_its_config() {
    # Sourcing would execute the file. It is data and must be parsed.
    code_of "$HELPER" | grep -qE '^\s*(\.|source)\s' \
        && fail "the helper parses its configuration rather than sourcing it" "found a source/. directive" \
        || pass "the helper parses its configuration rather than sourcing it"
}

t_deploy_cannot_select_database_or_destination() {
    local ok=1
    for opt in --database --backup-dir --dir; do
        code_of "$DEPLOY" | grep -qF -- "$opt)" && { ok=0; fail "the deploy script exposes no database or destination option" "still accepts $opt"; break; }
    done
    [[ $ok -eq 1 ]] && pass "the deploy script exposes no database or destination option"
}

# --- the documented sudoers contract ----------------------------------------
t_docs_have_no_unrestricted_pg_dump_rule() {
    grep -E '^<deploy-user>.*NOPASSWD' "$DOCS" | grep -q 'pg_dump' \
        && fail "no unrestricted pg_dump sudo rule is documented" "a pg_dump rule is still present" \
        || pass "no unrestricted pg_dump sudo rule is documented"
}

t_docs_have_no_wildcard_sudo_rule() {
    grep -E '^<deploy-user>.*NOPASSWD.*\*' "$DOCS" \
        && fail "no wildcard sudo rule is documented" "a wildcard rule is still present" \
        || pass "no wildcard sudo rule is documented"
}

t_docs_sudo_rule_is_outside_the_repository_checkout() {
    # sudo must never execute a file the deployment account can write.
    local rules; rules="$(grep -E '^<deploy-user>.*NOPASSWD' "$DOCS" || true)"
    if printf '%s' "$rules" | grep -qE '(deploy/bin|deploy/privileged|/var/www)'; then
        fail "no sudo rule points into a deploy-writable checkout" "$rules"
    else
        pass "no sudo rule points into a deploy-writable checkout"
    fi
}

t_docs_sudo_rules_preserve_no_environment() {
    grep -qE '^<deploy-user>.*env_keep' "$DOCS" \
        && fail "no sudo rule preserves the caller environment" "env_keep present" \
        || pass "no sudo rule preserves the caller environment"
}

t_every_sudo_is_noninteractive() {
    local offenders
    offenders="$(code_of "$DEPLOY" "$HELPER" | grep -oE 'sudo +[-/A-Za-z0-9_${}\"]+' | grep -vE '^sudo +-n' || true)"
    [[ -z "$offenders" ]] \
        && pass "every sudo invocation is non-interactive (-n)" \
        || fail "every sudo invocation is non-interactive (-n)" "$offenders"
}

printf '\ndeploy.sh / privileged backup helper\n'
t_rejects_unsafe_revisions
t_requires_arguments
t_rejects_non_git_root
t_rejects_unknown_argument
t_dry_run_is_inert_and_ordered
t_dry_run_uses_explicit_php_84
t_never_seeds_or_rolls_back
t_reports_failure_boundary
t_no_password_in_scripts
t_helper_takes_no_arguments
t_helper_reads_database_and_dir_from_root_owned_config
t_helper_rejects_unsafe_config
t_helper_never_sources_its_config
t_deploy_cannot_select_database_or_destination
t_docs_have_no_unrestricted_pg_dump_rule
t_docs_have_no_wildcard_sudo_rule
t_docs_sudo_rule_is_outside_the_repository_checkout
t_docs_sudo_rules_preserve_no_environment
t_every_sudo_is_noninteractive

printf '\n  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
