<?php

declare(strict_types=1);

namespace App\Services\Leaderboards;

use App\Enums\LeaderboardWindow;
use Carbon\CarbonImmutable;

/**
 * A point in ORDER — `(score, achieved_at, duration_ms, run_id)` — within one
 * window, and for the weekly window one week.
 *
 * What a cursor carries: the ORDER key of the last row served, plus the page's
 * window and week, so a cursor taken before a Monday rollover keeps paging the
 * week it was issued for. Never exposed except encrypted inside a cursor.
 */
final readonly class LeaderboardPosition
{
    public function __construct(
        public LeaderboardWindow $window,
        public ?string $weekStart,
        public int $score,
        public int $achievedAtMs,
        public int $durationMs,
        public string $runId,
    ) {}

    /** `achieved_at` in the stored `timestamp(3)` form, UTC. */
    public function achievedAt(): string
    {
        return CarbonImmutable::createFromTimestampMs($this->achievedAtMs, 'UTC')->format('Y-m-d H:i:s.v');
    }
}
