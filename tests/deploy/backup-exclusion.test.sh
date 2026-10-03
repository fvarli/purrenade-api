#!/usr/bin/env bash
#
# Proves the backup helper's dump semantics against a real PostgreSQL (O3):
#
#   tests/deploy/backup-exclusion.test.sh
#
# The helper itself runs only as root on the production host, so this test runs
# the SAME pg_dump exclusion and the SAME listing check, read out of the helper
# template, against a scratch schema:
#
#   - the dump keeps run_replay_inputs' definition but none of its rows;
#   - other tables' rows are kept;
#   - the helper's listing check sees no data entry for the table;
#   - a restore recreates the table, with its CHECK constraints, empty;
#   - a database without the table yet dumps without error (the first P3
#     deploy's pre-migration backup).
#
# Connection: the PG* environment variables, or else DB_* from .env. The role
# needs no CREATEDB: a scratch schema in the existing database is used, and
# dropped afterwards.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="$ROOT_DIR/deploy/privileged/purrenade-backup"

PASS=0
FAIL=0
pass() { printf '  ok   %s\n' "$1"; PASS=$((PASS + 1)); }
fail() { printf '  FAIL %s\n     %s\n' "$1" "${2:-}"; FAIL=$((FAIL + 1)); }

env_value() { grep -E "^$1=" "$ROOT_DIR/.env" 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"'"'"; }

export PGHOST="${PGHOST:-$(env_value DB_HOST)}"
export PGPORT="${PGPORT:-$(env_value DB_PORT)}"
export PGUSER="${PGUSER:-$(env_value DB_USERNAME)}"
export PGDATABASE="${PGDATABASE:-$(env_value DB_DATABASE)}"
export PGPASSWORD="${PGPASSWORD:-$(env_value DB_PASSWORD)}"
export PGOPTIONS="-c client_min_messages=warning"

# The helper's exact values, never a copy typed here.
excluded="$(sed -nE "s/^readonly EXCLUDED_DATA='([^']+)'$/\1/p" "$HELPER")"
listing_regex="$(sed -nE "s/.*grep -qE '(TABLE DATA [^']+)'.*/\1/p" "$HELPER")"

[[ "$excluded" == '*.run_replay_inputs' ]] \
    && pass "the helper excludes run_replay_inputs data in any schema" \
    || fail "the helper excludes run_replay_inputs data in any schema" "got: '$excluded'"

grep -qF -- '--exclude-table-data="$EXCLUDED_DATA"' "$HELPER" \
    && pass "the helper's pg_dump passes the exclusion" \
    || fail "the helper's pg_dump passes the exclusion" "flag not found on the pg_dump line"

[[ -n "$listing_regex" ]] \
    && pass "the helper verifies the exclusion in the archive listing" \
    || fail "the helper verifies the exclusion in the archive listing" "no listing check found"

schema="purrenade_dumpcheck_$$"
work="$(mktemp -d)"
cleanup() {
    psql -X -q -v ON_ERROR_STOP=1 -c "DROP SCHEMA IF EXISTS ${schema} CASCADE" >/dev/null 2>&1
    rm -rf "$work"
}
trap cleanup EXIT

sql() { psql -X -q -At -v ON_ERROR_STOP=1 -c "$1"; }

if ! sql "SELECT 1" >/dev/null 2>&1; then
    fail "PostgreSQL is reachable" "cannot connect as $PGUSER@$PGHOST/$PGDATABASE"
    printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
    exit 1
fi

# --- a database that does not have the table yet ------------------------------
sql "CREATE SCHEMA ${schema}; CREATE TABLE ${schema}.control (id int PRIMARY KEY); INSERT INTO ${schema}.control VALUES (1), (2);"

if pg_dump --format=custom --exclude-table-data="$excluded" -n "$schema" > "$work/before.dump" 2>"$work/err"; then
    pass "a dump before the table exists succeeds (pattern matches nothing)"
else
    fail "a dump before the table exists succeeds (pattern matches nothing)" "$(cat "$work/err")"
fi

# --- the table, with rows ----------------------------------------------------
sql "CREATE TABLE ${schema}.run_replay_inputs (
        run_id uuid PRIMARY KEY,
        state varchar(16) NOT NULL,
        outcome_code varchar(32),
        input bytea,
        CONSTRAINT run_replay_inputs_state_check CHECK (
            (state = 'pending' AND outcome_code IS NULL AND input IS NOT NULL)
            OR (state = 'terminal' AND outcome_code IS NOT NULL AND input IS NULL))
     );
     INSERT INTO ${schema}.run_replay_inputs VALUES
        ('00000000-0000-7000-8000-000000000001', 'pending', NULL, '\\xdeadbeef'),
        ('00000000-0000-7000-8000-000000000002', 'terminal', 'established', NULL);"

pg_dump --format=custom --exclude-table-data="$excluded" -n "$schema" > "$work/after.dump" \
    && pass "the dump with the table succeeds" \
    || fail "the dump with the table succeeds"

listing="$(pg_restore --list "$work/after.dump")"

printf '%s\n' "$listing" | grep -qE "TABLE ${schema} run_replay_inputs " \
    && pass "the archive keeps the table definition" \
    || fail "the archive keeps the table definition"

printf '%s\n' "$listing" | grep -qE "$listing_regex" \
    && fail "the archive holds no run_replay_inputs data" "a TABLE DATA entry is present" \
    || pass "the archive holds no run_replay_inputs data"

printf '%s\n' "$listing" | grep -qE "TABLE DATA ${schema} control " \
    && pass "other tables' data is kept" \
    || fail "other tables' data is kept"

# The listing check would catch a dump taken WITHOUT the exclusion.
pg_dump --format=custom -n "$schema" > "$work/unfiltered.dump"
pg_restore --list "$work/unfiltered.dump" | grep -qE "$listing_regex" \
    && pass "the helper's listing check detects unexcluded data" \
    || fail "the helper's listing check detects unexcluded data"

# The bytes themselves are not in the archive.
if LC_ALL=C grep -qa $'\xde\xad\xbe\xef' "$work/after.dump"; then
    fail "the input bytes are absent from the archive" "found"
else
    pass "the input bytes are absent from the archive"
fi

# --- restore ------------------------------------------------------------------
sql "DROP SCHEMA ${schema} CASCADE"

if pg_restore --no-owner -d "$PGDATABASE" "$work/after.dump" 2>"$work/err"; then
    pass "the archive restores"
else
    fail "the archive restores" "$(cat "$work/err")"
fi

[[ "$(sql "SELECT count(*) FROM ${schema}.run_replay_inputs")" == "0" ]] \
    && pass "the restored table exists and is empty" \
    || fail "the restored table exists and is empty"

[[ "$(sql "SELECT count(*) FROM pg_constraint WHERE conname = 'run_replay_inputs_state_check' AND connamespace = '${schema}'::regnamespace")" == "1" ]] \
    && pass "the restored table keeps its constraints" \
    || fail "the restored table keeps its constraints"

[[ "$(sql "SELECT count(*) FROM ${schema}.control")" == "2" ]] \
    && pass "the restored control rows are intact" \
    || fail "the restored control rows are intact"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[[ $FAIL -eq 0 ]]
