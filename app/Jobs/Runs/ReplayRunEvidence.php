<?php

declare(strict_types=1);

namespace App\Jobs\Runs;

use App\Services\Replay\ReplayEvidenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Replay one accepted run's canonical input and establish its replay evidence
 * (ANTI-6 P3). Post-acceptance evidence only — it can never change the run.
 *
 * - **Payload:** the run id, and nothing else (queues §4). The input stays
 *   encrypted in `run_replay_inputs`; it never enters `jobs` or `failed_jobs`.
 * - **Dispatch:** only after the acceptance transaction commits (`afterCommit()`
 *   at the call site; the connections default to `after_commit => false`).
 * - **Queue:** the dedicated `replay` queue, served by its own worker, so a
 *   replay can never delay an authentication or email job.
 * - **Retries:** class A (transient) rethrows, within `tries` and `backoff`;
 *   `failed()` records `attempts_exhausted`. Classes B and C are terminal at once
 *   and never retried.
 * - **Idempotent:** the work row's state is compared under `FOR UPDATE`; a
 *   duplicate is a no-op. `ShouldBeUnique` is hygiene against piling duplicates
 *   while one is queued, not the correctness guarantee.
 * - **Correlation:** Laravel's `Context`, which carries `correlation_id`, is
 *   dehydrated with the job automatically.
 */
final class ReplayRunEvidence implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** Below the database connection's `retry_after` (90 s). */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $runId,
    ) {
        $this->onQueue((string) config('replay.queue'));
    }

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function handle(ReplayEvidenceService $replays): void
    {
        $replays->process($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ReplayEvidenceService::class)->exhausted($this->runId);
    }
}
