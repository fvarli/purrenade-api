#!/usr/bin/env bash
#
# Structural tests for .github/workflows/*.yml.
#
#   tests/deploy/workflow.test.sh
#
# GitHub Actions cannot be executed locally, so these parse the workflows and
# assert PROPERTIES of the resulting structure — which step gates which, where
# secrets are reachable, how actions are pinned.
#
# They deliberately do not compare the YAML against a copy of itself. An
# assertion that greps for the expected text would pass for an implementation
# that is wrong in exactly the way the redteam audit found: the rollback gating
# defect was a correct-looking `if:` referring to a step that did too much.
# These tests reason about the parsed graph instead.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PASS=0; FAIL=0
pass() { printf '  ok   %s\n' "$1"; PASS=$((PASS + 1)); }
fail() { printf '  FAIL %s\n     %s\n' "$1" "${2:-}"; FAIL=$((FAIL + 1)); }

check() {  # check <name> <python-expression-file>
    local name="$1"; shift
    local out
    if out="$(python3 - "$ROOT_DIR" <<<"$1" 2>&1)"; then
        [[ -z "$out" ]] && pass "$name" || pass "$name ($out)"
    else
        fail "$name" "$out"
    fi
}

read -r -d '' PRELUDE <<'PY' || true
import sys, glob, os, re, yaml
root = sys.argv[1]
WF = {os.path.basename(p): yaml.safe_load(open(p)) for p in glob.glob(os.path.join(root, '.github/workflows/*.yml'))}
RAW = {os.path.basename(p): open(p).read() for p in glob.glob(os.path.join(root, '.github/workflows/*.yml'))}
def fail(m):
    print(m); sys.exit(1)
PY

printf '\nworkflow structure\n'

check "every action is pinned to a full 40-hex commit SHA" "$PRELUDE
bad=[u for raw in RAW.values() for u in re.findall(r'uses:\s*(\S+)', raw) if not re.search(r'@[0-9a-f]{40}\$', u)]
if bad: fail('unpinned: ' + ', '.join(bad))
print(str(len({u for raw in RAW.values() for u in re.findall(r'uses:\s*(\S+)', raw)})) + ' distinct pins')"

check "every action pin carries a human-readable version comment" "$PRELUDE
for name, raw in RAW.items():
    for line in raw.splitlines():
        if 'uses:' in line and '@' in line and '#' not in line.split('uses:')[1]:
            fail(name + ': pin without a version comment -> ' + line.strip())"

check "every job declares permissions and a timeout" "$PRELUDE
for name, d in WF.items():
    top = 'permissions' in d
    for jn, job in d['jobs'].items():
        if not (top or 'permissions' in job): fail(name + '::' + jn + ' has no permissions')
        if 'timeout-minutes' not in job: fail(name + '::' + jn + ' has no timeout-minutes')"

check "ci.yml references no secret" "$PRELUDE
if 'secrets.' in RAW.get('ci.yml',''): fail('ci.yml references a secret')"

check "secrets are reachable only from a job bound to the production environment" "$PRELUDE
d = WF['deploy.yml']
for jn, job in d['jobs'].items():
    if 'secrets.' in yaml.dump(job):
        env = job.get('environment')
        env = env.get('name') if isinstance(env, dict) else env
        if env != 'production': fail(jn + ' touches secrets without the production environment')"

check "the validation job holds no secret and no environment" "$PRELUDE
v = WF['deploy.yml']['jobs']['validate']
if 'secrets.' in yaml.dump(v): fail('validate job references a secret')
if 'environment' in v: fail('validate job is bound to an environment')"

check "deployment is workflow_dispatch only" "$PRELUDE
on = WF['deploy.yml'].get(True, WF['deploy.yml'].get('on'))
if list(on) != ['workflow_dispatch']: fail('triggers: ' + str(list(on)))"

check "production deploys never cancel each other" "$PRELUDE
c = WF['deploy.yml'].get('concurrency')
if not c or c.get('cancel-in-progress') is not False: fail('concurrency: ' + str(c))"

check "the CI gate constrains workflow, sha, push event and success" "$PRELUDE
raw = RAW['deploy.yml']
for needle in ['workflows/ci.yml/runs', 'head_sha=', 'event=push', '--paginate', 'conclusion == \"success\"']:
    if needle not in raw: fail('CI gate is missing: ' + needle)"

check "the dispatch input is pattern-tested before use" "$PRELUDE
if '^[0-9a-f]{40}\$' not in RAW['deploy.yml']: fail('no 40-hex validation of the dispatch input')"

check "host key checking is never disabled and never scanned at deploy time" "$PRELUDE
for name, raw in RAW.items():
    if re.search(r'StrictHostKeyChecking\s*=\s*no', raw): fail(name + ' disables host key checking')
    if 'ssh-keyscan' in raw: fail(name + ' runs ssh-keyscan')"

check "no raw ssh or scp is invoked outside the audited transport" "$PRELUDE
raw = RAW['deploy.yml']
for line in raw.splitlines():
    st = line.strip()
    if st.startswith('#'): continue
    if re.match(r'^(ssh|scp)\s', st): fail('raw remote command in deploy.yml: ' + st)"

# --- frontend-only: the rollback gating defect the audit found ---------------
if [[ -f "$ROOT_DIR/deploy/bin/release.sh" ]]; then
check "activation, restart and verification are separate steps" "$PRELUDE
ids = [s.get('id') for s in WF['deploy.yml']['jobs']['deploy']['steps'] if s.get('id')]
for need in ['activate', 'restart', 'verify_loopback', 'verify_public']:
    if need not in ids: fail('missing step id: ' + need + ' (have ' + str(ids) + ')')"

check "rollback is gated on activation alone, so a failed restart still rolls back" "$PRELUDE
steps = WF['deploy.yml']['jobs']['deploy']['steps']
rb = [s for s in steps if 'Roll back' in s.get('name', '')]
if not rb: fail('no rollback step')
cond = rb[0].get('if', '')
if 'steps.activate.outcome' not in cond: fail('rollback not gated on activation: ' + cond)
for other in ['restart', 'verify_loopback', 'verify_public']:
    if 'steps.' + other + '.outcome' in cond:
        fail('rollback gating also depends on ' + other + ', which would skip rollback when it fails')"

check "rollback re-verifies health and reports loudly if it fails" "$PRELUDE
steps = WF['deploy.yml']['jobs']['deploy']['steps']
rb = [s for s in steps if 'Roll back' in s.get('name', '')][0]['run']
if 'health-check.sh' not in rb: fail('rollback does not re-verify health')
if '::error::' not in rb: fail('rollback does notreport failure loudly')"
fi

# --- PHP CI lifecycle -------------------------------------------------------
#
# Regression coverage for the two defects that broke the push CI at fb9d1b7.
# Guarded on composer.json so the frontend copy of this file stays a valid
# no-op rather than drifting into a second implementation.
#
# Both assertions compare step POSITIONS inside the parsed workflow, not
# command text. Renaming a step or reordering a job cannot make them pass
# vacuously, and neither can a comment that merely mentions composer.
if [[ -f "$ROOT_DIR/composer.json" ]]; then

check "every job that runs artisan installs dependencies first" "$PRELUDE
# DEFECT 2: the OpenAPI job ran 'php artisan route:list' from a bare checkout.
# GitHub jobs are isolated, so it had no vendor/ and died on
# 'vendor/autoload.php: No such file or directory'.
for name, d in WF.items():
    for jn, job in d['jobs'].items():
        steps = job['steps']
        artisan = [i for i, s in enumerate(steps) if 'artisan' in (s.get('run') or '')]
        if not artisan: continue
        install = [i for i, s in enumerate(steps) if 'composer install' in (s.get('run') or '')]
        if not install:
            fail(name + '::' + jn + ' runs artisan at step ' + str(artisan[0]) + ' but never installs dependencies')
        if min(install) > min(artisan):
            fail(name + '::' + jn + ' runs artisan at step ' + str(min(artisan)) +
                 ' before composer install at step ' + str(min(install)))"

check "composer install always has an explicit non-production APP_ENV" "$PRELUDE
# DEFECT 1: composer install triggers post-autoload-dump -> artisan
# package:discover, so Laravel BOOTS during installation. With no .env yet,
# config/app.php defaults APP_ENV to 'production' and EnvironmentGuard
# correctly refuses to boot. The environment must be named, not implied.
for name, d in WF.items():
    for jn, job in d['jobs'].items():
        if not any('composer install' in (s.get('run') or '') for s in job['steps']): continue
        env = dict(d.get('env') or {}); env.update(job.get('env') or {})
        value = env.get('APP_ENV')
        if value is None:
            fail(name + '::' + jn + ' runs composer install with no explicit APP_ENV')
        if value == 'production':
            fail(name + '::' + jn + ' runs composer install under APP_ENV=production')"

check "the OpenAPI contract job bootstraps before its route gate" "$PRELUDE
steps = WF['ci.yml']['jobs']['contract']['steps']
def first(needle):
    for i, s in enumerate(steps):
        if needle in (s.get('run') or ''): return i
    return None
install, routes = first('composer install'), first('route:list')
if install is None: fail('the contract job never installs dependencies')
if routes is None: fail('the contract job no longer runs route:list — has the gate been removed?')
if install > routes: fail('composer install (step ' + str(install) + ') comes after route:list (step ' + str(routes) + ')')"

check "CI never carries a production environment or a secret" "$PRELUDE
raw = RAW['ci.yml']
if 'secrets.' in raw: fail('ci.yml references a secret')
for jn, job in WF['ci.yml']['jobs'].items():
    if 'environment' in job: fail('ci.yml::' + jn + ' is attached to a deployment environment')
if re.search(r'APP_ENV:\s*.?production', raw): fail('ci.yml sets APP_ENV=production')"

fi

printf '\n  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
