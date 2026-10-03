<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReplayOutcome;
use App\Jobs\Runs\ReplayRunEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ANTI-6 replay-input sweeper (O3, O8) — the scheduler's first and only
 * consumer. Every minute:
 *
 * 1. **Expire.** Every pending row past `input_expires_at` becomes terminal
 *    `input_expired`, and its input bytes are cleared in that same statement.
 *    The 24-hour boundary itself is logical and is enforced by the replay path
 *    at use and at commit; this is the prompt **physical** purge, independent
 *    of traffic. It is best-effort only in the sense that no scheduler can run
 *    through a host outage.
 *
 *    One `UPDATE`, taking only work-row locks. A replay commit holding one of
 *    those rows makes the update wait, re-check the row under READ COMMITTED,
 *    and skip it once it is terminal — so the two never both record an outcome.
 *
 * 2. **Re-dispatch.** A pending row untouched for `REDISPATCH_AFTER_MINUTES`
 *    has probably lost its job (a crash between the acceptance commit and the
 *    dispatch, or a worker killed without `failed()`). It is touched and
 *    dispatched again. A duplicate is harmless: the job's state check makes it
 *    a no-op, and `ShouldBeUnique` suppresses it while one is still queued.
 *
 * It logs counts only.
 */
final class ReplaySweepCommand extends Command
{
    public const REDISPATCH_AFTER_MINUTES = 10;

    public const REDISPATCH_BATCH = 100;

    protected $signature = 'replay:sweep';

    protected $description = 'Purge expired ANTI-6 replay input and re-dispatch orphaned replays';

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $stamp = $now->format('Y-m-d H:i:s.v');

        $expired = DB::update(
            "UPDATE run_replay_inputs
             SET state = 'terminal', outcome_code = ?, input = NULL, updated_at = ?
             WHERE state = 'pending' AND input_expires_at <= ?",
            [ReplayOutcome::InputExpired->value, $stamp, $stamp],
        );

        /** @var list<string> $orphans */
        $orphans = DB::table('run_replay_inputs')
            ->where('state', 'pending')
            ->where('input_expires_at', '>', $stamp)
            ->where('updated_at', '<', $now->subMinutes(self::REDISPATCH_AFTER_MINUTES)->format('Y-m-d H:i:s.v'))
            ->orderBy('updated_at')
            ->limit(self::REDISPATCH_BATCH)
            ->pluck('run_id')
            ->all();

        $redispatched = 0;

        foreach ($orphans as $runId) {
            $touched = DB::update(
                "UPDATE run_replay_inputs SET updated_at = ? WHERE run_id = ? AND state = 'pending'",
                [$stamp, $runId],
            );

            if ($touched === 1) {
                ReplayRunEvidence::dispatch($runId);
                $redispatched++;
            }
        }

        if ($expired > 0 || $redispatched > 0) {
            Log::info('run.replay.swept', ['expired' => $expired, 'redispatched' => $redispatched]);
        }

        $this->line("expired {$expired} redispatched {$redispatched}");

        return self::SUCCESS;
    }
}
