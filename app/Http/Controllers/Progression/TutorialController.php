<?php

declare(strict_types=1);

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Http\Resources\TutorialStateResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * POST /progression/tutorial — the player has finished the first-run tutorial.
 *
 * The narrowest endpoint in the API: no body, no parameters, one column. The
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
 * timestamp — so the write is a conditional `UPDATE ... WHERE
 * tutorial_completed_at IS NULL` and the affected-row count is the authority.
 * Reading the column and then writing it would be the same bug the display-name
 * cooldown had: two concurrent requests both see null and both stamp, and the
 * second silently overwrites the first.
 *
 * That also means **skipping and finishing are the same call.** The product
 * treats a skipped tutorial as completed for routing, and the server is
 * deliberately not told which happened — it is not a fact the API has any use
 * for, and collecting it would be the tutorial telemetry this milestone
 * explicitly does not want.
 *
 * ## What it does not do
 *
 * No score, no paw, no progression side effect: `domain-boundaries.md` §3 —
 * *tutorial runs are not runs*. Nothing here writes to the security log either;
 * finishing a tutorial is not a security event, and the CI log gate keeps that
 * channel to the two classes that own it.
 */
final class TutorialController extends Controller
{
    public function complete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /*
         * One statement, and its row count is the answer.
         *
         * No transaction: this is a single conditional UPDATE, which PostgreSQL
         * already applies atomically. Wrapping it would suggest there were two
         * writes that had to agree, and there is only ever one.
         */
        $claimed = $user->newQuery()
            ->whereKey($user->getKey())
            ->whereNull('tutorial_completed_at')
            ->update(['tutorial_completed_at' => Carbon::now()]);

        // `$claimed === 0` is the ordinary replay case, not a failure: the
        // tutorial was already completed and the stored timestamp stands.
        if ($claimed === 1) {
            $user->refresh();
        }

        return TutorialStateResource::make($user)->response();
    }
}
