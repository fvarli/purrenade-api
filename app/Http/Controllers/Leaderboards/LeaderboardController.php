<?php

declare(strict_types=1);

namespace App\Http\Controllers\Leaderboards;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaderboardRequest;
use App\Http\Resources\LeaderboardPageResource;
use App\Models\User;
use App\Services\Leaderboards\LeaderboardReader;
use App\Support\CorrelationId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * GET /leaderboards — one page of a window, plus the caller's own entry.
 *
 * Thin: shape in the form request, every read in `LeaderboardReader`. The
 * caller is the bearer of the token; nothing in the query names a player.
 */
final class LeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardReader $reader,
    ) {}

    public function __invoke(LeaderboardRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $started = hrtime(true);
        $page = $this->reader->page($user, $request->window(), $request->cursorPosition(), $request->limit());

        // The window and the timing only: no player, no cursor, no rows.
        Log::info('leaderboard.read', [
            'window' => $page->window->value,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'correlation_id' => CorrelationId::resolve($request->header(CorrelationId::HEADER)),
        ]);

        return (new LeaderboardPageResource($page))->response();
    }
}
