#!/usr/bin/env bash
#
# ANTI-6 replay deploy gate (owner decision D3: a hard gate).
#
#   replay-gate.sh --tree DIR --env FILE --backup-helper PATH
#
# Run by deploy.sh BEFORE the checkout moves, so a failure leaves production
# exactly as it was. DIR is an extracted copy of the TARGET revision's `deploy/`
# and `resources/replay/` (git archive), so the gate checks what is about to be
# deployed, with this script as reviewed in that revision.
#
# It verifies, and changes nothing:
#
#   1. the installed root-owned backup helper is byte-identical to the target
#      revision's template — so the dump excludes run_replay_inputs data (O3)
#      before any revision that can store replay input goes live;
#   2. the dedicated replay worker and the scheduler timer are installed and
#      enabled (D2, O8);
#   3. REPLAY_NODE_BINARY in the production .env is an absolute path to an
#      executable reporting Node 24 (O9) — never a PATH lookup;
#   4. every pinned bundle and golden matches its manifest's SHA-256, from a
#      clean web commit, for Node 24;
#   5. golden self-replay: every golden case, run through the pinned bundle with
#      the production process contract (empty environment, bundle directory as
#      cwd, --max-old-space-size=128, 15 s kill), answers exactly its expected
#      document.
#
# A failure here never touches a run: at runtime the same conditions end a
# replay as ABSENT evidence, and run acceptance does not depend on replay.

set -euo pipefail

die() { printf '\n  REPLAY GATE FAILED: %s\n\n' "$1" >&2; exit 1; }
info() { printf '  %s\n' "$1"; }

tree=""
env_file=""
backup_helper=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --tree)          tree="${2:-}"; shift 2 ;;
        --env)           env_file="${2:-}"; shift 2 ;;
        --backup-helper) backup_helper="${2:-}"; shift 2 ;;
        *) die "unknown argument: $1" ;;
    esac
done

[[ -d "$tree/resources/replay" ]] || die "the target revision has no resources/replay"
[[ -f "$env_file" ]] || die "no environment file at $env_file"

# 1. Backup helper — the installed copy must be the reviewed template.
template="$tree/deploy/privileged/purrenade-backup"
[[ -f "$template" ]] || die "the target revision has no backup helper template"
[[ -f "$backup_helper" ]] || die "no installed backup helper at $backup_helper"
installed_sha="$(sha256sum "$backup_helper" | cut -d' ' -f1)"
template_sha="$(sha256sum "$template" | cut -d' ' -f1)"
[[ "$installed_sha" == "$template_sha" ]] || die \
"the installed backup helper differs from this revision's template.
  Reinstall it (docs/production/ci-cd.md §6) so pre-deploy dumps exclude
  run_replay_inputs data, then retry."
info "backup helper matches the template ($template_sha)"

# 2. The replay worker and the scheduler timer.
for unit in purrenade-replay-worker.service purrenade-scheduler.timer; do
    [[ "$(systemctl is-enabled "$unit" 2>/dev/null || true)" == "enabled" ]] \
        || die "$unit is not installed and enabled (docs/production/ci-cd.md §6)"
done
info "replay worker and scheduler timer are enabled"

# 3. Node — from the production .env, parsed (never sourced).
node_bin="$(grep -E '^REPLAY_NODE_BINARY=' "$env_file" | tail -n 1 | cut -d= -f2- | tr -d '"'"'" || true)"
[[ "$node_bin" == /* ]] || die "REPLAY_NODE_BINARY in $env_file is not an absolute path"
[[ -f "$node_bin" && -x "$node_bin" ]] || die "REPLAY_NODE_BINARY is not an executable file: $node_bin"
node_version="$(env -i "$node_bin" --version)"
[[ "$node_version" =~ ^v24\.[0-9]+\.[0-9]+$ ]] || die "REPLAY_NODE_BINARY reports $node_version, expected Node 24"
info "node $node_version at $node_bin"

# 4 + 5. Pins and golden self-replay, checked by the pinned Node itself.
env -i "$node_bin" -e '
const { readFileSync, readdirSync, existsSync } = require("node:fs")
const { createHash } = require("node:crypto")
const { spawnSync } = require("node:child_process")
const { join } = require("node:path")

const root = process.argv[1]
const sha256 = (path) => createHash("sha256").update(readFileSync(path)).digest("hex")
const fail = (message) => { process.stderr.write(`  ${message}\n`); process.exit(1) }
const dirs = readdirSync(root).filter((name) => /^domain-[0-9]{1,8}$/.test(name)).sort()

if (dirs.length === 0) fail("no pinned replay bundle")

for (const name of dirs) {
  const dir = join(root, name)
  const manifestPath = join(dir, "manifest.json")
  if (!existsSync(manifestPath)) fail(`${name}: no manifest`)
  const m = JSON.parse(readFileSync(manifestPath, "utf8"))
  const keys = Object.keys(m).join(",")
  if (keys !== "domain_version,sha256,node_major,web_commit,golden_sha256") fail(`${name}: manifest shape`)
  if (`domain-${m.domain_version}` !== name) fail(`${name}: domain_version`)
  if (!/^[0-9a-f]{40}$/.test(m.web_commit)) fail(`${name}: web_commit is not a clean commit`)
  if (m.node_major !== 24) fail(`${name}: node_major`)
  const bundle = join(dir, "purrenade-replay.mjs")
  const golden = join(dir, "golden.json")
  if (sha256(bundle) !== m.sha256) fail(`${name}: bundle sha256 mismatch`)
  if (sha256(golden) !== m.golden_sha256) fail(`${name}: golden sha256 mismatch`)

  const cases = JSON.parse(readFileSync(golden, "utf8"))
  if (!Array.isArray(cases) || cases.length === 0) fail(`${name}: empty golden`)

  for (const c of cases) {
    const r = spawnSync(process.execPath, ["--max-old-space-size=128", bundle], {
      input: JSON.stringify(c.document), env: {}, cwd: dir, encoding: "utf8", timeout: 15000,
    })
    if (r.status !== 0 || r.stderr !== "") fail(`${name}: golden replay failed`)
    if (JSON.stringify(JSON.parse(r.stdout)) !== JSON.stringify(c.expected)) fail(`${name}: golden mismatch`)
  }

  process.stdout.write(`  ${name}: sha256 ${m.sha256}, web ${m.web_commit}, golden ${cases.length}/${cases.length}\n`)
}
' "$tree/resources/replay" || die "a pinned replay bundle failed verification"

info "replay gate passed"
