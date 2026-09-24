<?php

declare(strict_types=1);

namespace App\Services\Leaderboards;

use App\Enums\LeaderboardWindow;
use Carbon\CarbonImmutable;

/**
 * One leaderboard response, read from one snapshot: the page, its ranks, the
 * next cursor and the player's own entry are mutually consistent.
 */
final readonly class LeaderboardPage
{
    /**
     * @param  list<LeaderboardEntry>  $entries
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $period  UTC `[starts_at, ends_at)` of the week; null for all-time
     */
    public function __construct(
        public LeaderboardWindow $window,
        public ?array $period,
        public array $entries,
        public ?LeaderboardEntry $ownEntry,
        public ?string $nextCursor,
        public bool $hasMore,
    ) {}
}
