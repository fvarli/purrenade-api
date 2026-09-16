#!/usr/bin/env bash
#
# Tests for deploy/bin/remote-exec.sh — the runner-to-production transport.
#
#   tests/deploy/remote-exec.test.sh
#
# These are the regression tests for the injection defects the redteam audit
# proved by execution: a repository variable breaking out of single quotes into
# the remote shell, and a secret beginning with '-' becoming an ssh option.
#
# Nothing here connects anywhere. PURRENADE_SSH_BIN / PURRENADE_SCP_BIN point at
# a recorder that writes its argv to a file, so the tests assert what the script
# ACTUALLY passes rather than what its source appears to say. That distinction
# is the point: an assertion that greps the implementation would pass even if
# the implementation were wrong in exactly the way the audit found.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REMOTE="$ROOT_DIR/deploy/bin/remote-exec.sh"

PASS=0; FAIL=0
pass() { printf '  ok   %s\n' "$1"; PASS=$((PASS + 1)); }
fail() { printf '  FAIL %s\n     %s\n' "$1" "${2:-}"; FAIL=$((FAIL + 1)); }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# A recorder standing in for ssh/scp: one argv element per line.
# One argv element per line for the option assertions, PLUS the final argument
# written verbatim to a second file. The remote program is multi-line, so a
# line-oriented log alone cannot round-trip it.
cat > "$WORK/recorder" <<'REC'
#!/usr/bin/env bash
: > "$ARGV_LOG"
for a in "$@"; do printf '%s\n' "$a" >> "$ARGV_LOG"; done
printf '%s' "${!#}" > "$ARGV_LOG.last"
REC
chmod +x "$WORK/recorder"
printf 'key' > "$WORK/key"
printf 'echo hi\n' > "$WORK/payload.sh"

export PURRENADE_SSH_BIN="$WORK/recorder"
export PURRENADE_SCP_BIN="$WORK/recorder"
export ARGV_LOG="$WORK/argv"

run_script() {
    "$REMOTE" --host example.test --user deploy --port 22 --key "$WORK/key" \
        --script "$WORK/payload.sh" -- "$@"
}

# The remote program is the last argv element the recorder captured, read back
# whole rather than line-by-line.
remote_program() { cat "$ARGV_LOG.last"; }

# --- hostile values must travel as literal data -----------------------------
t_hostile_values_are_literal() {
    local hostile=(
        "plain"
        "with'single'quotes"
        'with"double"quotes'
        'semi;colon; id'
        'dollar$(id)and`backtick`'
        '/var/www/x'"'"'; id; '"'"''
        '-leading-dash'
        'white space	tab'
        'glob*?[a-z]'
        'trailing\backslash\'
    )
    local bad=0 v
    for v in "${hostile[@]}"; do
        run_script "$v" >/dev/null 2>&1 || { bad=1; fail "hostile values transported literally" "invocation failed for: $v"; break; }
        local prog; prog="$(remote_program)"
        # The remote program must contain ONLY base64 between the quotes, so no
        # fragment of the hostile value may appear in the shell source at all.
        if printf '%s' "$prog" | grep -qF -- "$v"; then
            bad=1; fail "hostile values transported literally" "raw value leaked into remote source: $v"; break
        fi
        # And it must decode back to exactly the original.
        local encoded decoded
        encoded="$(printf '%s' "$prog" | grep -oE "printf %s '[A-Za-z0-9+/=]*'" | head -1 | sed "s/.*'\(.*\)'/\1/")"
        decoded="$(printf '%s' "$encoded" | base64 -d 2>/dev/null)"
        if [[ "$decoded" != "$v" ]]; then
            bad=1; fail "hostile values transported literally" "round-trip mismatch for: $v"; break
        fi
    done
    [[ $bad -eq 0 ]] && pass "hostile values transported literally (10 payloads)"
}

t_remote_source_is_metacharacter_free() {
    run_script "/var/www/x'; rm -rf /; '" >/dev/null 2>&1
    local prog; prog="$(remote_program)"
    # Everything inside the single quotes must be base64 only.
    if printf '%s' "$prog" | grep -qE "printf %s '[A-Za-z0-9+/=]*'"; then
        pass "the interpolated region is base64 only"
    else
        fail "the interpolated region is base64 only" "unexpected remote program: $prog"
    fi
}

# --- option injection --------------------------------------------------------
t_leading_dash_user_rejected() {
    "$REMOTE" --host example.test --user '-oProxyCommand=id' --port 22 \
        --key "$WORK/key" --script "$WORK/payload.sh" -- x >/dev/null 2>&1
    [[ $? -ne 0 ]] && pass "a '-'-leading SSH user is rejected before ssh runs" \
        || fail "a '-'-leading SSH user is rejected before ssh runs" "accepted"
}

t_leading_dash_host_rejected() {
    "$REMOTE" --host '-oProxyCommand=id' --user deploy --port 22 \
        --key "$WORK/key" --script "$WORK/payload.sh" -- x >/dev/null 2>&1
    [[ $? -ne 0 ]] && pass "a '-'-leading SSH host is rejected before ssh runs" \
        || fail "a '-'-leading SSH host is rejected before ssh runs" "accepted"
}

t_port_grammar() {
    local ok=1
    for bad in '-1' '0' '65536' '22;id' 'abc' '2 2'; do
        "$REMOTE" --host example.test --user deploy --port "$bad" --key "$WORK/key" \
            --script "$WORK/payload.sh" -- x >/dev/null 2>&1 && { ok=0; fail "port grammar" "accepted: $bad"; break; }
    done
    [[ $ok -eq 1 ]] && pass "port must be numeric and within 1-65535"
}

t_destination_after_double_dash() {
    run_script ok >/dev/null 2>&1
    local dashdash dest
    dashdash="$(grep -nxF -- '--' "$ARGV_LOG" | head -1 | cut -d: -f1)"
    dest="$(grep -nxF -- 'deploy@example.test' "$ARGV_LOG" | head -1 | cut -d: -f1)"
    if [[ -n "$dashdash" && -n "$dest" && "$dashdash" -lt "$dest" ]]; then
        pass "'--' precedes the ssh destination"
    else
        fail "'--' precedes the ssh destination" "-- at ${dashdash:-none}, dest at ${dest:-none}"
    fi
}

# --- ssh/scp option separation ----------------------------------------------
t_ssh_uses_lowercase_p() {
    run_script ok >/dev/null 2>&1
    grep -qxF -- '-p' "$ARGV_LOG" && ! grep -qxF -- '-P' "$ARGV_LOG" \
        && pass "ssh receives -p and not -P" \
        || fail "ssh receives -p and not -P" "$(tr '\n' ' ' < "$ARGV_LOG")"
}

t_scp_uses_uppercase_p() {
    "$REMOTE" --host example.test --user deploy --port 22 --key "$WORK/key" \
        --copy "$WORK/payload.sh" --dest /tmp/x >/dev/null 2>&1
    grep -qxF -- '-P' "$ARGV_LOG" && ! grep -qxF -- '-p' "$ARGV_LOG" \
        && pass "scp receives -P and not -p" \
        || fail "scp receives -P and not -p" "$(tr '\n' ' ' < "$ARGV_LOG")"
}

t_identity_path_containing_dash_p_is_intact() {
    # The exact corruption the old ${ssh_opts[@]/-p/-P} substitution caused.
    local d="$WORK/runner-pool"; mkdir -p "$d"; printf 'k' > "$d/deploy_key"
    "$REMOTE" --host example.test --user deploy --port 22 --key "$d/deploy_key" \
        --copy "$WORK/payload.sh" --dest /tmp/x >/dev/null 2>&1
    grep -qxF -- "$d/deploy_key" "$ARGV_LOG" \
        && pass "an identity path containing '-p' survives scp option building" \
        || fail "an identity path containing '-p' survives scp option building" "$(grep runner "$ARGV_LOG" || echo 'not found')"
}

t_strict_host_key_checking_always_on() {
    run_script ok >/dev/null 2>&1
    grep -qxF -- 'StrictHostKeyChecking=yes' "$ARGV_LOG" \
        && pass "StrictHostKeyChecking=yes is always passed" \
        || fail "StrictHostKeyChecking=yes is always passed" "absent"
}

t_scp_destination_grammar() {
    local ok=1
    for bad in 'relative/path' '/tmp/x;id' '/tmp/$(id)' '-/tmp/x'; do
        "$REMOTE" --host example.test --user deploy --port 22 --key "$WORK/key" \
            --copy "$WORK/payload.sh" --dest "$bad" >/dev/null 2>&1 && { ok=0; fail "scp destination grammar" "accepted: $bad"; break; }
    done
    [[ $ok -eq 1 ]] && pass "scp destinations must be conservative absolute paths"
}

t_missing_key_refused() {
    "$REMOTE" --host example.test --user deploy --port 22 --key "$WORK/nope" \
        --script "$WORK/payload.sh" -- x >/dev/null 2>&1
    [[ $? -ne 0 ]] && pass "a missing identity file is refused" \
        || fail "a missing identity file is refused" "accepted"
}

printf '\nremote-exec.sh\n'
t_hostile_values_are_literal
t_remote_source_is_metacharacter_free
t_leading_dash_user_rejected
t_leading_dash_host_rejected
t_port_grammar
t_destination_after_double_dash
t_ssh_uses_lowercase_p
t_scp_uses_uppercase_p
t_identity_path_containing_dash_p_is_intact
t_strict_host_key_checking_always_on
t_scp_destination_grammar
t_missing_key_refused

printf '\n  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
