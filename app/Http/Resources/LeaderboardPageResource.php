<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Leaderboards\LeaderboardEntry;
use App\Services\Leaderboards\LeaderboardPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `LeaderboardPage` schema. Unwrapped: the page's own `data` is the list
 * of entries, next to `window`, `period`, `own_entry` and `meta`.
 *
 * Each entry is exactly `{rank, display_name, score, is_self}`. No user id,
 * run id, email, timestamp or validation data leaves the server; `period` is
 * the week's UTC bounds, the only time in the response.
 *
 * @mixin LeaderboardPage
 */
final class LeaderboardPageResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LeaderboardPage $page */
        $page = $this->resource;

        return [
            'window' => $page->window->value,
            'period' => $page->period === null ? null : [
                'starts_at' => $page->period[0]->format('Y-m-d\TH:i:s.v\Z'),
                'ends_at' => $page->period[1]->format('Y-m-d\TH:i:s.v\Z'),
            ],
            'data' => array_map(self::entry(...), $page->entries),
            'own_entry' => $page->ownEntry === null ? null : self::entry($page->ownEntry),
            'meta' => [
                'next_cursor' => $page->nextCursor,
                'has_more' => $page->hasMore,
            ],
        ];
    }

    /**
     * @return array{rank: int, display_name: string, score: int, is_self: bool}
     */
    private static function entry(LeaderboardEntry $entry): array
    {
        return [
            'rank' => $entry->rank,
            'display_name' => $entry->displayName,
            'score' => $entry->score,
            'is_self' => $entry->isSelf,
        ];
    }
}
