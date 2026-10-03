#!/usr/bin/env bash
#
# Tests for deploy/bin/deploy.sh, deploy/bin/replay-gate.sh and the privileged
# backup helper.
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
GATE="$ROOT_DIR/deploy/bin/replay-gate.sh"
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
    local d; d="$(mktemp -d)"; mkdir -p "$d/.git"; printf '{}' > "$d/composer.json"; printf '#!/bin/sh\n' > "$d/artisan"; printf '%s' "$d"
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

t_rejects_root_without_composer_manifest() {
    local d; d="$(mktemp -d)"; mkdir -p "$d/.git"
    "$DEPLOY" --root "$d" --sha "$SHA" --dry-run >/dev/null 2>&1
    [[ $? -ne 0 ]] \
        && pass "a Git root without composer.json is refused" \
        || fail "a Git root without composer.json is refused" "accepted"
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

    # The ANTI-6 hard gate comes before anything moves, and the application's
    # own preflight before the backup.
    local gate_line checkout_line preflight_line
    gate_line="$(printf '%s\n' "$out" | grep -n '==> REPLAY PREFLIGHT' | cut -d: -f1)"
    checkout_line="$(printf '%s\n' "$out" | grep -n '==> CHECKOUT' | cut -d: -f1)"
    preflight_line="$(printf '%s\n' "$out" | grep -n 'artisan replay:preflight' | cut -d: -f1)"
    if [[ -n "$gate_line" && -n "$checkout_line" && "$gate_line" -lt "$checkout_line" ]]; then
        pass "the replay gate runs before the checkout"
    else
        fail "the replay gate runs before the checkout" "gate=$gate_line checkout=$checkout_line"
    fi
    if [[ -n "$preflight_line" && -n "$backup_line" && "$preflight_line" -lt "$backup_line" ]]; then
        pass "the replay preflight runs before the backup"
    else
        fail "the replay preflight runs before the backup" "preflight=$preflight_line backup=$backup_line"
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

# This reproduces the production failure shape: deploy.sh is invoked from an
# unrelated SSH-login-like directory, while --root names a separate checkout.
# The stubs record their cwd, so this checks observable process behavior rather
# than merely asserting that the source contains a cd command.
t_repository_commands_run_from_validated_root() {
    local work fixture caller log php composer
    work="$(mktemp -d)"
    fixture="$work/application root; \$(not-executed) [literal]"
    caller="$work/ssh login home"
    log="$work/cwds"
    mkdir -p "$fixture/.git" "$caller" "$work/bin"
    printf '{}' > "$fixture/composer.json"
    printf '#!/bin/sh\n' > "$fixture/artisan"

    php="$work/php8.4"
    composer="$work/composer"
    printf '%s\n' '#!/usr/bin/env bash' 'if [[ "$1" == "-r" ]]; then printf 8.4; exit 0; fi' 'printf "php:%s:%s\n" "${2##*/}" "$PWD" >> "$DEPLOY_CWD_LOG"' > "$php"
    printf '# composer fixture\n' > "$composer"
    chmod +x "$php"

    printf '%s\n' '#!/usr/bin/env bash' 'case "$1" in -C) shift 2;; esac' 'operation="$1"' 'printf "git:%s:%s\n" "$operation" "$PWD" >> "$DEPLOY_CWD_LOG"' 'case "$operation" in' '    fetch) [[ "$2" == "--quiet" && "$3" == "origin" ]];;' '    cat-file) [[ "$2" == "-e" && "$3" == "${DEPLOY_TEST_SHA}^{commit}" ]];;' '    merge-base) [[ "$2" == "--is-ancestor" && "$3" == "$DEPLOY_TEST_SHA" && "$4" == "origin/main" ]];;' '    archive) [[ "$2" == "$DEPLOY_TEST_SHA" && "$3" == "deploy" && "$4" == "resources/replay" ]] && cat "$DEPLOY_TEST_GATE_TAR";;' '    checkout) [[ "$2" == "--quiet" && "$3" == "--force" && "$4" == "$DEPLOY_TEST_SHA" ]];;' '    status) [[ "$2" == "--porcelain" ]];;' '    *) exit 1;;' 'esac' > "$work/bin/git"
    printf '%s\n' '#!/usr/bin/env bash' 'printf "sudo:%s\n" "$PWD" >> "$DEPLOY_CWD_LOG"' 'printf "/verified/backup.dump\n"' > "$work/bin/sudo"
    printf '%s\n' '#!/usr/bin/env bash' 'printf "curl:%s\n" "$PWD" >> "$DEPLOY_CWD_LOG"' > "$work/bin/curl"
    chmod +x "$work/bin/git" "$work/bin/sudo" "$work/bin/curl"
    make_gate_tar "$work" 0

    (
        cd "$caller"
        HOME="$caller" PATH="$work/bin:$PATH" DEPLOY_CWD_LOG="$log" DEPLOY_TEST_SHA="$SHA" DEPLOY_TEST_GATE_TAR="$work/gate.tar" \
            "$DEPLOY" --root "$fixture" --sha "$SHA" --php "$php" \
            --composer "$composer" --backup-helper "$work/backup helper" \
            --health-url https://health.test >/dev/null
    )
    local rc=$?
    local expected bad_cwd=0 record
    expected="$(cd "$fixture" && pwd -P)"
    while IFS= read -r record; do
        [[ "$record" == *":$expected" ]] || bad_cwd=1
    done < "$log"

    local expected_record records_ok=1
    for expected_record in \
        "git:fetch:$expected" "git:cat-file:$expected" \
        "git:merge-base:$expected" "git:archive:$expected" "gate:$expected" \
        "git:checkout:$expected" "git:status:$expected" \
        "php:install:$expected" "php:replay:preflight:$expected" "php:migrate:$expected" \
        "php:config:cache:$expected" "php:route:cache:$expected" \
        "php:event:cache:$expected" "sudo:$expected" "curl:$expected"; do
        grep -qxF -- "$expected_record" "$log" || records_ok=0
    done
    [[ "$(grep -cxF -- "sudo:$expected" "$log")" -eq 4 ]] || records_ok=0

    # The gate ran before the working tree moved.
    local gate_at checkout_at
    gate_at="$(grep -nxF -- "gate:$expected" "$log" | cut -d: -f1)"
    checkout_at="$(grep -nxF -- "git:checkout:$expected" "$log" | cut -d: -f1)"
    [[ -n "$gate_at" && -n "$checkout_at" && "$gate_at" -lt "$checkout_at" ]] || records_ok=0

    if [[ $rc -eq 0 && -s "$log" && $bad_cwd -eq 0 && $records_ok -eq 1 ]]; then
        pass "repository deployment commands use --root from another cwd and HOME"
    else
        fail "repository deployment commands use --root from another cwd and HOME" "exit=$rc; $(tr '\n' ' ' < "$log" 2>/dev/null)"
    fi
    rm -rf "$work"
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

# --- the ANTI-6 replay gate ----------------------------------------------------

# A target-revision archive whose replay-gate.sh is a stub that records its cwd
# and exits with the given status.
make_gate_tar() {
    local work="$1" status="$2"
    mkdir -p "$work/gate/deploy/bin" "$work/gate/resources/replay"
    printf '%s\n' '#!/usr/bin/env bash' 'printf "gate:%s\n" "$PWD" >> "$DEPLOY_CWD_LOG"' "exit $status" > "$work/gate/deploy/bin/replay-gate.sh"
    tar -C "$work/gate" -cf "$work/gate.tar" deploy resources
}

t_replay_gate_refusal_changes_nothing() {
    local work fixture log php
    work="$(mktemp -d)"; fixture="$work/root"; log="$work/log"
    mkdir -p "$fixture/.git" "$work/bin"
    printf '{}' > "$fixture/composer.json"; printf '#!/bin/sh\n' > "$fixture/artisan"
    php="$work/php8.4"
    printf '%s\n' '#!/usr/bin/env bash' 'if [[ "$1" == "-r" ]]; then printf 8.4; exit 0; fi' 'printf "php:%s\n" "${2##*/}" >> "$DEPLOY_CWD_LOG"' > "$php"; chmod +x "$php"
    printf '%s\n' '#!/usr/bin/env bash' 'case "$1" in -C) shift 2;; esac' 'printf "git:%s\n" "$1" >> "$DEPLOY_CWD_LOG"' 'case "$1" in archive) cat "$DEPLOY_TEST_GATE_TAR";; checkout|status) exit 0;; *) exit 0;; esac' > "$work/bin/git"
    printf '%s\n' '#!/usr/bin/env bash' 'printf "sudo\n" >> "$DEPLOY_CWD_LOG"' > "$work/bin/sudo"
    chmod +x "$work/bin/git" "$work/bin/sudo"
    printf '# composer fixture\n' > "$work/composer"
    : > "$log"
    make_gate_tar "$work" 1

    local out rc
    out="$(PATH="$work/bin:$PATH" DEPLOY_CWD_LOG="$log" DEPLOY_TEST_GATE_TAR="$work/gate.tar" \
        "$DEPLOY" --root "$fixture" --sha "$SHA" --php "$php" --composer "$work/composer" 2>&1)"
    rc=$?

    if [[ $rc -ne 0 ]] && grep -qx 'gate:.*' "$log" && ! grep -qE '^(git:checkout|php:|sudo)' "$log" \
        && printf '%s\n' "$out" | grep -q 'boundary: REPLAY_PREFLIGHT'; then
        pass "a replay gate refusal stops the deploy before checkout, dependencies, backup or migration"
    else
        fail "a replay gate refusal stops the deploy before checkout, dependencies, backup or migration" "rc=$rc; $(tr '\n' ' ' < "$log")"
    fi
    rm -rf "$work"
}

t_restarts_the_replay_worker() {
    code_of "$DEPLOY" | grep -qF 'sudo -n systemctl restart purrenade-replay-worker.service' \
        && pass "the deploy restarts the dedicated replay worker" \
        || fail "the deploy restarts the dedicated replay worker" "no restart line"
}

t_docs_cover_the_replay_worker_restart() {
    grep -qE '^<deploy-user> ALL=\(root\) NOPASSWD: /usr/bin/systemctl restart purrenade-replay-worker.service$' "$DOCS" \
        && pass "the sudoers contract names the replay worker restart" \
        || fail "the sudoers contract names the replay worker restart" "rule missing from $DOCS"
}

t_helper_excludes_replay_input_data() {
    code_of "$HELPER" | grep -qF -- 'pg_dump --format=custom --exclude-table-data="$EXCLUDED_DATA"' \
        && grep -qF "readonly EXCLUDED_DATA='*.run_replay_inputs'" "$HELPER" \
        && pass "the helper's dump excludes run_replay_inputs data" \
        || fail "the helper's dump excludes run_replay_inputs data" "flag missing"
}

# The real gate script, against this repository's own tree and pinned bundles.
# Needs a Node 24 at REPLAY_NODE_BINARY (or the local nvm install); without one
# the positive case is reported as a failure, never skipped.
gate_fixture() {
    local work="$1" node="$2"
    mkdir -p "$work/bin"
    printf '%s\n' '#!/usr/bin/env bash' '[[ "$1" == "is-enabled" ]] && { printf "%s\n" "${GATE_UNITS_STATE:-enabled}"; exit 0; }' 'exit 1' > "$work/bin/systemctl"
    chmod +x "$work/bin/systemctl"
    printf 'APP_ENV=production\nREPLAY_NODE_BINARY=%s\n' "$node" > "$work/env"
    cp "$HELPER" "$work/helper"
}

t_replay_gate_script() {
    local node="${REPLAY_NODE_BINARY:-$HOME/.nvm/versions/node/v24.21.0/bin/node}"
    local work; work="$(mktemp -d)"
    gate_fixture "$work" "$node"

    PATH="$work/bin:$PATH" bash "$GATE" --tree "$ROOT_DIR" --env "$work/env" --backup-helper "$work/helper" >/dev/null 2>&1 \
        && pass "the replay gate passes for the reviewed helper, enabled units, Node 24 and intact pins" \
        || fail "the replay gate passes for the reviewed helper, enabled units, Node 24 and intact pins" "node=$node"

    printf '# drift\n' >> "$work/helper"
    PATH="$work/bin:$PATH" bash "$GATE" --tree "$ROOT_DIR" --env "$work/env" --backup-helper "$work/helper" >/dev/null 2>&1 \
        && fail "the replay gate refuses an installed helper that differs from the template" "accepted" \
        || pass "the replay gate refuses an installed helper that differs from the template"
    cp "$HELPER" "$work/helper"

    GATE_UNITS_STATE=disabled PATH="$work/bin:$PATH" bash "$GATE" --tree "$ROOT_DIR" --env "$work/env" --backup-helper "$work/helper" >/dev/null 2>&1 \
        && fail "the replay gate refuses when the replay worker or timer is not enabled" "accepted" \
        || pass "the replay gate refuses when the replay worker or timer is not enabled"

    printf 'REPLAY_NODE_BINARY=node\n' > "$work/env"
    PATH="$work/bin:$PATH" bash "$GATE" --tree "$ROOT_DIR" --env "$work/env" --backup-helper "$work/helper" >/dev/null 2>&1 \
        && fail "the replay gate refuses a Node that is not an absolute path" "accepted" \
        || pass "the replay gate refuses a Node that is not an absolute path"

    # A tampered bundle copy fails its pin.
    local tree="$work/tree"
    mkdir -p "$tree"
    cp -r "$ROOT_DIR/deploy" "$ROOT_DIR/resources" "$tree/"
    printf '\n// tampered\n' >> "$tree/resources/replay/domain-1/purrenade-replay.mjs"
    gate_fixture "$work" "$node"
    PATH="$work/bin:$PATH" bash "$GATE" --tree "$tree" --env "$work/env" --backup-helper "$work/helper" >/dev/null 2>&1 \
        && fail "the replay gate refuses a bundle that does not match its pin" "accepted" \
        || pass "the replay gate refuses a bundle that does not match its pin"
    rm -rf "$work"
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
t_rejects_root_without_composer_manifest
t_rejects_unknown_argument
t_dry_run_is_inert_and_ordered
t_dry_run_uses_explicit_php_84
t_repository_commands_run_from_validated_root
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
t_replay_gate_refusal_changes_nothing
t_restarts_the_replay_worker
t_docs_cover_the_replay_worker_restart
t_helper_excludes_replay_input_data
t_replay_gate_script

printf '\n  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
