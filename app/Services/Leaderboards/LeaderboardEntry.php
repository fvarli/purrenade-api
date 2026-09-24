<?php

declare(strict_types=1);

namespace App\Services\Leaderboards;

/**
 * One public leaderboard row: exactly what a client may see, and nothing it
 * may not — no user id, no run id, no timestamps, no validation data.
 */
final readonly class LeaderboardEntry
{
    public function __construct(
        public int $rank,
        public string $displayName,
        public int $score,
        public bool $isSelf,
    ) {}
}
