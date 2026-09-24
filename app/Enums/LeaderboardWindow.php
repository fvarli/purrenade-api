<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two leaderboard windows board 15 shows (LB-1, APPROVED). There is no
 * third: previous-week viewing is OPEN (LB-9).
 */
enum LeaderboardWindow: string
{
    case Weekly = 'weekly';
    case AllTime = 'all_time';

    /** The projection table holding this window. */
    public function table(): string
    {
        return match ($this) {
            self::Weekly => 'leaderboard_weekly',
            self::AllTime => 'leaderboard_all_time',
        };
    }
}
