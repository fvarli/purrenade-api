# Queues and Jobs

**Queues only where they provide real value.**

**Status legend:** APPROVED / PROPOSED / OPEN.

---

## 1. The rule — APPROVED

A queue is added when deferring work **measurably improves** request latency or
reliability, not because queues are available. Every job is a piece of work that
can fail invisibly, retry unexpectedly, and run out of order — that cost is paid
for a reason or not at all.

---

## 2. Justified now — PROPOSED

### 2.1 Transactional email

| Job | Why it is queued |
| --- | --- |
| Email verification code | An SMTP round trip inside a registration request makes registration as slow and as fragile as the mail provider |
| Password reset link | Same |
| Security notifications (new device, password changed), if adopted | Same |

**Rules**
- Dispatched **after** the transaction commits, never inside it. A job that runs
  before commit can observe — or email about — a state that was rolled back.
- Retries with backoff; a permanent failure is logged and surfaced to operations,
  never silently swallowed.
- **The email body never contains a secret in a recoverable form beyond what the
  flow requires**, and the job payload never contains a plaintext code that is
  stored hashed.

### 2.2 Leaderboard projection refresh — **inline, no job (M10)**

Refreshing the whole ranking inside the submission transaction would make every
run submission pay for the whole leaderboard — so nothing is "refreshed". An
accepted finish upserts **only the affected rows**, the player's all-time row
and their weekly row, inside its own transaction (BA-3 and QJ-2 resolved). That
costs two single-row index writes, keeps the board exact at commit, and needs no
job, no worker and no scheduler — production has neither a scheduler nor a use
for one here. See `data-model.md` §5.

### 2.3 ANTI-6 replay evidence — **queued, dedicated queue (P3)** — APPROVED

Owner decisions O9 and the P3 §16.2 rows (2026-10-03); D2 (dedicated worker). Details in
[replay-runtime.md](replay-runtime.md).

| Job | Why it is queued |
| --- | --- |
| `ReplayRunEvidence(run_id)` | Deterministic replay of an accepted run's canonical input is CPU work of up to seconds. It establishes **post-acceptance evidence only** and never changes the run, so it is not transactional with acceptance — the line §3 draws |

- **After commit.** Dispatched with an explicit `afterCommit()` from inside the
  acceptance transaction; the connections default to `after_commit => false`.
- **Its own queue and worker.** The `replay` queue is served only by
  `purrenade-replay-worker.service`; `purrenade-queue.service` keeps `default`
  (the transactional mail), so a replay can never delay an authentication or
  email job.
- **§4 rules.** Identifiers only (`run_id`; the input stays encrypted in its work
  row), the correlation ID via `Context`, 3 tries with 30 s / 120 s backoff and a
  60 s job timeout below `retry_after` (90 s), `failed()` recording
  `attempts_exhausted`, no transaction held while replaying, idempotent under the
  work row's `FOR UPDATE` state check.

---

## 3. Deliberately NOT queued — APPROVED

| Work | Why it stays synchronous |
| --- | --- |
| **Run acceptance and validation** | The player must be told immediately whether their run counted. A queued verdict means a score that changes after the fact. |
| **Paw ledger and threshold evaluation** | Must be atomic with run acceptance. A queued update is a window in which the account is inconsistent. |
| **Achievement unlock evaluation** | Same transaction as the run result; the unlock is part of the response |
| **Character unlock evaluation** | Same |
| **Any authorization decision** | Never deferred |

The line: **anything that must be transactional with run acceptance is not
queueable.** Making it a job would trade correctness for latency.

*Note 2026-10-03:* the ANTI-6 replay (§2.3) is queued because it is **not**
transactional with acceptance and can change no acceptance outcome. Where
replay-dependent achievement evaluation sits is ANTI-6 **O7** (C4), still OPEN;
P3 evaluates no achievement and queues no evaluation.

---

## 4. Job design rules — PROPOSED

| Rule | Reason |
| --- | --- |
| Jobs are **idempotent** | At-least-once delivery means every job will eventually run twice |
| Jobs carry **identifiers, not entity snapshots** | A snapshot is stale by the time the job runs |
| Jobs carry the **correlation ID** | Otherwise the deferred half of a request is untraceable |
| Jobs have an explicit **retry policy and a maximum attempt count** | An infinitely retried job is an outage amplifier |
| Failed jobs land somewhere **visible** | A silent failed-job table is a silent outage |
| **No sensitive data in job payloads** | Payloads are persisted, logged, and inspected |
| Jobs never open long transactions | They hold locks against live traffic |

---

## 5. Infrastructure — PROPOSED

| Question | Recommendation |
| --- | --- |
| Queue driver | Redis, if Redis is already justified for rate limiting and caching. Otherwise the database driver is sufficient at this volume and removes a dependency. *Production:* the database driver with one worker (`default`, mail); the P3 deploy adds the second, `replay` (§2.3), through its bootstrap. |
| Worker supervision | Required in every environment where jobs are dispatched |
| Monitoring | Queue depth, failure rate and oldest-job age are the three signals that matter |
| Scheduled tasks | **One: `replay:sweep`, every minute** (OB-6 decided by ANTI-6 O8; active in production once the P3 bootstrap installs the timer), driven by the `purrenade-scheduler.timer` systemd timer running `schedule:run`. It purges expired replay input and re-dispatches orphaned replays. Nothing else is scheduled until its own decision says so. The leaderboard projection is still maintained on write (§2.2) |

See [caching-and-redis.md](caching-and-redis.md) for whether Redis is adopted at
all.

---

## 6. Open questions

| Ref | Question |
| --- | --- |
| QJ-1 | Is Redis adopted, and therefore used as the queue driver? (CACHE-1) |
| ~~QJ-2~~ | **Resolved at M10:** inline, affected rows only, inside the finish transaction (§2.2). |
| QJ-3 | Which security notifications are sent, if any? Nothing in the approved surface requires them |
| QJ-4 | Retention and alerting policy for failed jobs |
