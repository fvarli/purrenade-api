<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * The ANTI-6 review signal: a replay that did not reproduce its accepted run
 * (class C, `replay_inconsistent`).
 *
 * A review/security signal **only** (architecture §13). It changes no run
 * status, establishes no partial evidence and leaves Loli evidence untouched;
 * any action against the run is a later explicit, audited admin invalidation
 * (M13). It reuses the existing `security` channel — SL-3, SL-4 and ADR-0012
 * stay OPEN and are not decided here.
 *
 * The field set is fixed and structurally free of gameplay data: the run, the
 * player, which accepted fields disagreed (codes, never values), and the
 * correlation id. No method accepts the input or the replay's output.
 */
final class RunReviewLog
{
    public const REPLAY_INCONSISTENT = 'run.replay_inconsistent';

    /**
     * @param  list<string>  $reasons  Codes from {score, run_paws, duration}.
     */
    public static function replayInconsistent(string $runId, int $userId, array $reasons): void
    {
        Log::channel(AuthLog::CHANNEL)->warning(self::REPLAY_INCONSISTENT, [
            'event' => self::REPLAY_INCONSISTENT,
            'run_id' => $runId,
            'user_id' => $userId,
            'reasons' => array_values(array_intersect($reasons, ['score', 'run_paws', 'duration'])),
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }
}
