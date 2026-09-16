#!/usr/bin/env bash
#
# Purrenade API health assertion.
#
#   health-check.sh --url URL [--attempts N] [--interval SECONDS]
#
# Used by deploy.sh on the host and by the workflow against the public origin,
# so "healthy" means the same thing in both places.
#
# `GET /api/v1/health` is readiness, not liveness: it runs `select 1` against
# PostgreSQL. Three assertions, because a bare `curl -f` is too weak — it treats
# a 301 as success, and it cannot tell a working API from nginx answering for
# some other site on this shared host:
#
#   1. the status is exactly 200 (503 means degraded, and is NOT healthy);
#   2. the body reports status "ok";
#   3. the body reports the database check "ok" — the application can boot with
#      an unreachable database and still answer, which is the case this exists
#      to catch.
#
# Polling every two seconds stays far below the endpoint's 60/minute limit.

set -euo pipefail

die() { printf '\n  ERROR: %s\n\n' "$1" >&2; exit 1; }

url=""; attempts=20; interval=2

while [[ $# -gt 0 ]]; do
    case "$1" in
        --url)      url="${2:-}"; shift 2 ;;
        --attempts) attempts="${2:-}"; shift 2 ;;
        --interval) interval="${2:-}"; shift 2 ;;
        *) die "unknown argument: $1" ;;
    esac
done

[[ -n "$url" ]] || die "missing --url"
[[ "$url" =~ ^https?://[A-Za-z0-9._~:/?#@!$\&()*+,\;=%-]+$ ]] || die "unsafe or malformed URL: ${url}"
[[ "$attempts" =~ ^[0-9]+$ && "$attempts" -ge 1 && "$attempts" -le 120 ]] || die "--attempts must be 1-120"
[[ "$interval" =~ ^[0-9]+$ && "$interval" -ge 1 && "$interval" -le 30 ]] || die "--interval must be 1-30"

body="$(mktemp)"
trap 'rm -f "$body"' EXIT

last_status="none"
for _ in $(seq 1 "$attempts"); do
    # No -f and no -L: the status is read explicitly, so a redirect is a visible
    # failure rather than a silent pass.
    last_status="$(curl -sS -o "$body" -w '%{http_code}' --max-time 10 "$url" 2>/dev/null || echo '000')"

    if [[ "$last_status" == "200" ]] \
       && grep -q '"status":"ok"' "$body" \
       && grep -q '"database":"ok"' "$body"; then
        printf '  healthy: %s reports status ok and database ok\n' "$url"
        exit 0
    fi
    sleep "$interval"
done

case "$last_status" in
    200) die "unhealthy: ${url} returned 200 but did not report status ok AND database ok.
  A 200 whose database check is \"error\" means the application booted and PostgreSQL did not answer." ;;
    503) die "unhealthy: ${url} returned 503 (degraded) — PostgreSQL is unreachable from the application." ;;
    *)   die "unhealthy: ${url} last returned HTTP ${last_status}, expected exactly 200.
  A 3xx is NOT healthy — it means something other than this deployment answered." ;;
esac
