# ANTI-6 replay runtime — pinned bundle, process contract and pipeline

> **Status: IMPLEMENTED (ANTI-6 P3), 2026-10-03 — not deployed.** Production activation needs the
> P3 deploy and its privileged bootstrap ([`../production/ci-cd.md`](../production/ci-cd.md) §6A);
> until then no replay worker, scheduler, Node path or dump exclusion exists in production. Owner decisions: O1, O3, O8 and O9 (web
> `docs/product/anti-6-implementation-architecture.md` §19; register `open-decisions.md` §0AP),
> plus the Phase 0 decisions D1–D4 and refinements R1–R4 recorded in the P3 session. The web
> document is authoritative for semantics; this one records how the API implements them.
>
> **Post-acceptance evidence only (ADR-0006 Amendment A, ANTI-1).** Nothing here participates in,
> delays or changes run acceptance. Every failure, of every class, ends with the run's replay
> evidence **ABSENT** and the run, its status and its Loli evidence untouched.
>
> **Not in P3:** sending `replay_input` (P4, web), evaluating replay-dependent achievements (P5;
> O7 is OPEN), and run invalidation (M13).

## 1. What runs where

```
POST /game-runs/{id}/finish ─► acceptance transaction (unchanged classification)
     RUN → PROGRESSION → PAW_LEDGER → LB_ALL_TIME → LB_WEEKLY → (RUN update)
         → RUN_LOLI_EVIDENCE → RUN_REPLAY_INPUTS          accepted runs only
     └─ after commit: ReplayRunEvidence(run_id) on the `replay` queue
                                  │
          purrenade-replay-worker.service (queue:work database --queue=replay)
                                  │  no transaction held
          decrypt → verify the pin → one-shot Node 24 process → classify
                                  │
          replay commit transaction: RUN → PROGRESSION → RUN_REPLAY_INPUTS → RUN_REPLAY_EVIDENCE
```

| Piece | Where |
| --- | --- |
| Boundary parse (usable / unusable, never a 422) | `App\Services\Replay\ReplayInputParser`, via `FinishRunRequest::replayInput()` |
| Acceptance write and after-commit dispatch | `ReplayInputStore::record()`, called last in `RunLifecycleService::finishLocked()` |
| Job (`run_id` only, `replay` queue) | `App\Jobs\Runs\ReplayRunEvidence` |
| Processing and the commit transaction | `ReplayEvidenceService` |
| The Node process | `NodeReplayRunner` (interface `ReplayRunner`) |
| Result classification (pure) | `ReplayResultClassifier` |
| The pins | `ReplayBundles`, `resources/replay/domain-<v>/` |
| Sweeper | `php artisan replay:sweep`, scheduled every minute |
| Preflight | `php artisan replay:preflight`, and `deploy/bin/replay-gate.sh` |
| Review signal | `App\Support\RunReviewLog` |

## 2. The pinned bundle (O9)

The web repository owns the replay program (`game/replay/`); the API owns a **pinned copy** of
its build, like the web pins this API's OpenAPI. API deploys never build the web project and never
fetch a CI artifact. There is no shared Git history and no shared dependency management.

**Path.** `resources/replay/domain-<DOMAIN_VERSION>/`, one directory per domain version:

| File | What it is |
| --- | --- |
| `purrenade-replay.mjs` | byte copy of the web build's `.replay/purrenade-replay.mjs` (single-file ESM, esbuild, Node 24) |
| `golden.json` | byte copy of the web repository's `tests/support/replay-golden.json` at the same commit |
| `manifest.json` | the pin |

**Manifest format.** Exactly these members, in this order:

```json
{
  "domain_version": "1",
  "sha256": "<64 lowercase hex: SHA-256 of purrenade-replay.mjs>",
  "node_major": 24,
  "web_commit": "<40 lowercase hex: a clean, committed web revision>",
  "golden_sha256": "<64 lowercase hex: SHA-256 of golden.json>"
}
```

The first four members are the web build's own manifest, verbatim. `golden_sha256` is the API's
addition. A `web_commit` of `<sha>-dirty`, `unknown` or a short SHA is refused everywhere: the
manifest is then treated as not installed.

**Refresh procedure.** Only `bin/replay-pin` changes a pin:

```bash
bin/replay-pin <path-to-purrenade-web> <40-char web sha> --node <absolute Node 24> [--allow-unpublished]
```

1. The commit must exist and be reachable from the web `origin/main`. `--allow-unpublished` turns
   that into a warning, for a commit that is committed but not yet pushed, the same rule as the
   web's `contract:sync`.
2. The web repository is cloned into a temporary directory and checked out at exactly that commit.
   The web working tree and its `.git` are never touched, so local uncommitted changes can never
   reach a pin. P2's build marks a tree dirty only for `game/` and its own build script, so the
   clean clone is what makes the pin trustworthy.
3. With the given Node 24, the clone runs `npm ci`, `npm run replay:build` and
   `npm run replay:check`, which is the web's own golden check of the built bundle.
4. The web manifest must name exactly that commit. The bundle and golden are copied, the manifest
   is written, and both hashes are re-verified.
5. Nothing is staged or committed. Review the diff and commit it with the web commit in the message.

**Deploy skew rule.** The web must never ship a new `DOMAIN_VERSION` before the API has pinned
and deployed its bundle. A stream for a version with no pin is `version_unsupported`, and its
evidence is lost.

**Verification.**

- **CI, `docs` job:** "Replay bundles match their pins" checks the manifest shape, the directory,
  the clean commit, Node 24 and both hashes.
- **CI, `replay` job:** sets up Node 24 and runs the real process contract (`--group=replay-node`)
  and `replay:preflight`.
- **Runtime:** every replay re-hashes the bundle before running it. A mismatch is
  `version_unsupported` and fails closed.

Current pin: domain `1`, bundle `a1e6e00e…c070`, from web `b28e7758c2668eeea1e5728859cf1537a0dd107d`.

## 3. The process contract

| Aspect | Contract |
| --- | --- |
| Executable | `REPLAY_NODE_BINARY` (`config/replay.php`). It must be an **absolute** path to an executable file: the host's pinned Node 24, the dedicated installation the web service uses. It is never resolved through `PATH`; an empty or relative value means no replay runs |
| Command | `<node> --max-old-space-size=128 <bundle>`, the invocation the web's bundle check verifies |
| Environment | **Built from nothing.** Only `NodeReplayRunner::ENV_ALLOWLIST` is passed, and it is empty. No `APP_KEY`, no database credential, no token, no inherited `NODE_OPTIONS`, nothing from the host. Node 24 and the bundle need none (verified). A variable is added only with a proven runtime need on a supported host, recorded here |
| Working directory | the bundle's directory |
| stdin | one compact JSON document: `{protocol: 1, domain_version, seed, start_loli_cycle_paws, mode: "run", input: {format_version, domain_version, total_steps, events}}`. `seed`, the start cycle and the mode come from the `runs` row and never from the client |
| stdout | one line, at most 64 KiB. A longer answer is cut off and is `result_invalid` |
| stderr | drained and discarded. Only "was there any" may be logged |
| Timeout | hard `SIGKILL` after 15 s. This is transient (class A) |
| Exit code | `0` with an answer; anything else is a crash, which is transient (class A) |

## 4. Outcomes

`run_replay_inputs.outcome_code` is a closed set, enforced by a CHECK.

| Class | Outcome | When | Retried? |
| --- | --- | --- | --- |
| — | `established` | the replay reproduced the accepted `score`, `run_paws` and `duration_ms` exactly. Evidence: all three facts | — |
| B | `input_malformed` | unusable at the boundary (shape, caps, more steps than the server window holds), or the replay answered `invalid_input` | never |
| B | `version_unsupported` | unknown `format_version`; no pin for the `domain_version`; a pin whose bundle no longer matches; a run with no recorded start cycle | never |
| B | `result_invalid` | an answer that is not the documented shape, echoes another protocol or version, overflows, or describes an impossible run (`ended ≠ true`, stream not consumed exactly) | never |
| B | `input_expired` | past `input_expires_at`, before the replay or at commit; **also an input that no longer decrypts** (logged with reason `input_unreadable`) | never |
| B | `run_not_accepted` | the run is no longer `accepted` (M13 invalidation) | never |
| C | `replay_inconsistent` | a well-formed replay that disagrees with the accepted record. The **whole triple** is withheld; this is the review signal (§7) | never |
| A | `attempts_exhausted` | transient failures (spawn, missing Node, timeout, crash, database error) persisted through 3 tries (backoff 30 s, 120 s) | — |

A duplicate delivery after an outcome is a no-op. A replay's `loli_activations` that differs from
the frozen P1 evidence is an operational warning, `run.replay.loli_divergence`. **Loli evidence is
never changed.**

## 5. Retention, encryption and the size bounds (O3)

- **Logical boundary.** `input_expires_at = received_at + 24 h`, exactly. From that instant the
  input is unusable. That is checked before a replay and again inside the commit transaction, so
  expired input never establishes evidence.
- **Physical deletion.**
  - Every terminal outcome sets `input = NULL` in the statement that records it.
  - `replay:sweep`, driven by the every-minute scheduler, clears every expired row promptly and
    without relying on traffic.
  - A scheduler cannot guarantee byte deletion at exactly 24:00:00 through a timer, service or
    host outage. So the 24 hours is a hard **logical** bound, and physical purge is prompt and
    best-effort.
- **At rest.** The envelope of `Crypt::encryptString()` (`APP_KEY`; `APP_PREVIOUS_KEYS` honoured)
  is stored as bytes. Only `ReplayInputStore::decrypt()` reads it back, and only for the replay
  job. There is no admin, export or API read path.
- **Stored size, measured.** The largest canonical input the boundary admits is 142 657 bytes:
  - 20 000 events whose gaps maximise digits subject to Σgap ≤ 432 000 (20 000 two-digit gaps,
    plus 2 577 upgraded to three digits);
  - an 8-digit `domain_version`.

  For AES-CBC the envelope is `126 + 4·ceil(16·(⌊n/16⌋+1)/3)` bytes, which gives **190 358**,
  measured with the real encrypter. AES-GCM stores less (190 290).
  - The CHECK `octet_length(input) <= 190358` is that exact maximum. The representation has no
    variable part, so headroom would only admit bytes the boundary cannot produce.
  - `ReplayStorageBoundTest` keeps the constant, the CHECK and the measurement equal.
- **Request body.** The finish route's authoritative limit is **256 KiB** (`LimitFinishBody`;
  `413 payload_too_large`).
  - The largest conforming body is ~220.2 KB: a compact log at the caps is at most 220 073 bytes
    (20 000 × `[gap≤6 digits,code],` + 74), and the telemetry adds ~110.
  - nginx's ceiling is set **above** it, at `client_max_body_size 288k`. Both count entity-body
    bytes, so every body in (256 KiB, 288k] reaches Laravel and gets the canonical problem
    response; only materially larger ones stop at nginx.
  - A conforming client omits the log rather than exceed the bound.

## 6. Worker, scheduler, backups (D2, O8, O3)

- **`purrenade-replay-worker.service`:** `queue:work database --queue=replay --tries=3
  --timeout=60`. The existing `purrenade-queue.service` serves only `default`, which carries the
  verification and reset mail, and never runs a replay.
- **`purrenade-scheduler.{service,timer}`:** `schedule:run` every minute. Its only entry is
  `replay:sweep`.
  - The sweep's re-dispatch half re-queues a pending row that has been quiet for 10 minutes: a
    crash between commit and dispatch, or a worker killed without `failed()`.
  - Duplicates are no-ops, and `ShouldBeUnique` suppresses them while one is queued.
- **Backups:** the privileged helper dumps with `--exclude-table-data='*.run_replay_inputs'` and
  refuses an archive whose listing shows that table's data.
  - A restore has the table, empty: pending replays end ABSENT.
  - `tests/deploy/backup-exclusion.test.sh` proves this against a real PostgreSQL.

## 7. Preflight and the deploy gate (D3)

`deploy.sh` runs the target revision's `deploy/bin/replay-gate.sh`, extracted with `git archive`,
**before the checkout moves**. It checks, and changes nothing:

1. the installed backup helper is byte-identical to the template;
2. the replay worker and the scheduler timer are enabled;
3. `REPLAY_NODE_BINARY` in `.env` is an absolute Node 24;
4. every pin matches its hashes, from a clean commit, for Node 24;
5. a golden self-replay of every case.

`php artisan replay:preflight` repeats checks 3–5 through the application's own code path after
the dependencies install and before the backup. A failure at either point stops the deploy with
the schema untouched. Neither ever touches a run.

## 8. Logs and signals

- **Never logged** (`docs/security/data-protection.md` §5): the input in any form, the replay
  document, and the process's stdin, stdout or stderr. A CI scan forbids those names in logging
  calls, and `ReplayPrivacyTest` pushes a marked stream through the real path and searches every
  log, job payload, failure record, response and stored run for it.
- **Operational:** `run.replay.completed` carries `{run_id, outcome, reason?, attempts,
  replay_ms?, stderr_present?, correlation_id}`. Also `run.replay.loli_divergence`, and
  `run.replay.swept` with counts only.
- **Review signal:** `run.replay_inconsistent` on the `security` channel, with
  `{event, run_id, user_id, reasons ⊂ {score, run_paws, duration}, correlation_id}`. It never
  carries values. It changes no status. SL-3, SL-4 and ADR-0012 stay OPEN.

## 9. Obligations left to later phases

- **M13 invalidation** takes RUN → PROGRESSION first. Its transaction should also make a pending
  work row terminal and clear its input (architecture §9.1 case A; data minimisation). Until then,
  the replay commit's re-check records `run_not_accepted`.
- **P5** evaluates replay-dependent achievements inside the replay commit transaction, at the
  progression lock the commit already takes. O7 decides it.
- **P4** sends the stream from the BFF under the same O3 policy, and must handle `413`
  deliberately. Today `isFinalRefusal` does not include it.

## 10. Owner dispositions of implementation deviations — APPROVED (2026-10-03)

| Deviation | Disposition |
| --- | --- |
| Undecryptable input | Terminal `input_expired`, operational reason `input_unreadable`; no new outcome code |
| Run without `start_loli_cycle_paws` | Terminal `version_unsupported`; evidence ABSENT |
| Body-limit placement | The **authenticated** finish route owns the canonical 256 KiB / `413 payload_too_large` boundary (route middleware, after authentication); an unauthenticated oversized request may receive `401` first |
| Deploy gate | Runs **before the checkout** (`deploy/bin/replay-gate.sh`), stricter than planned; accepted |
| Zero headroom on the stored bound | Accepted **because** the envelope length is invariant for a fixed plaintext length under the configured `AES-256-CBC` (§5): the IV is always 16 bytes (24 base64 chars), the ciphertext is the PKCS#7-padded plaintext (a function of length only), the MAC is 64 hex chars, the tag is empty, and base64 output needs no JSON escaping under `JSON_UNESCAPED_SLASHES`. Measured: 1 000 / 1 000 encryptions of the maximal 142 657-byte plaintext stored exactly 190 358 bytes. A cipher change that grew the envelope would break `ReplayStorageBoundTest` before it reached production |

## 11. Local development

`QUEUE_CONNECTION=sync` runs the replay job inline, after the finish commits. Without a valid
`REPLAY_NODE_BINARY` that inline job fails, and the sync driver surfaces the failure as a `500`
on the already-committed finish — the acceptance is **not** rolled back, and a retry with the same
key returns the stored result (proven with a real commit by `ReplayConcurrencyTest`, "keeps a
committed acceptance when the after-commit replay fails synchronously"). This is a local-only
artefact: production queues the job, and acceptance is never affected. Set
`REPLAY_NODE_BINARY` to a local Node 24 to replay locally.
