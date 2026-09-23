<?php

declare(strict_types=1);

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Http\Resources\TutorialStateResource;
use App\Models\User;
use App\Services\Progression\ProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /progression/tutorial — the player has finished the first-run tutorial.
 *
 * The narrowest endpoint in the API: no body, no parameters, one fact. The
 * actor is the bearer of the token and nothing else, so there is no id to
 * validate, no ownership to check and no way to aim it at another account —
 * `docs/security/authorization-and-roles.md` §3's "authorization is decided
 * against the resource" is satisfied by there being exactly one resource the
 * caller could possibly mean.
 *
 * ## Idempotent, structurally
 *
 * Completion is a **one-time fact**. Replaying the tutorial from Settings, or
 * double-submitting because a network retry fired twice, must not move the
 * timestamp — so every write is a conditional `UPDATE ... WHERE
 * tutorial_completed_at IS NULL`. Reading the column and then writing it would
 * be the same bug the display-name cooldown had: two concurrent requests both
 * see null and both stamp, and the second silently overwrites the first.
 *
 * That also means **skipping and finishing are the same call.** The product
 * treats a skipped tutorial as completed for routing, and the server is
 * deliberately not told which happened.
 *
 * ## Where it is stored (M9)
 *
 * On `player_progression`, with `users.tutorial_completed_at` still written as
 * a legacy mirror until the contract deployment drops it — see
 * `ProgressionService::completeTutorial()` and `data-model.md` §4.1. The wire
 * stays the boolean `tutorial_completed`.
 *
 * ## What it does not do
 *
 * No score, no paw, no run: `domain-boundaries.md` §3 — *tutorial runs are not
 * runs*. Nothing here writes to the security log either.
 */
final class TutorialController extends Controller
{
    public function __construct(
        private readonly ProgressionService $progression,
    ) {}

    public function complete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->progression->completeTutorial($user);

        $user->refresh();

        return TutorialStateResource::make($user)->response();
    }
}
