<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateDisplayNameRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Support\AuthLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /profile — the account identity a player can change.
 *
 * Display name only in M2. Email changes need their own verification flow
 * (a code to the new address, and the old one kept until it succeeds), which is
 * a feature rather than a field, and belongs with profile work.
 *
 * Rate-limited two ways, because the leaderboard shows this name to everybody:
 * a named limiter caps changes per day, and `display_name_changed_at` on the row
 * gives the client a concrete "available at" instead of a bare refusal. The
 * column matters because a limiter bucket disappears when the cache is flushed,
 * and a rename cooldown that a cache restart clears is not a cooldown.
 */
final class ProfileController extends Controller
{
    /** Hours between display-name changes. */
    public const RENAME_COOLDOWN_HOURS = 24;

    public function updateDisplayName(UpdateDisplayNameRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $requested = (string) $request->string('display_name');

        // Re-submitting the same name — including only a casing change — is not
        // a rename and does not spend the cooldown.
        $unchanged = $user->display_name === $requested;

        if (! $unchanged && $user->display_name_changed_at !== null) {
            $availableAt = $user->display_name_changed_at->copy()->addHours(self::RENAME_COOLDOWN_HOURS);

            if ($availableAt->isFuture()) {
                throw ApiProblem::retryAfter(
                    ProblemCode::DisplayNameChangeCooldown,
                    (int) Carbon::now()->diffInSeconds($availableAt, absolute: true),
                    'You changed your display name recently. Try again later.'
                );
            }
        }

        try {
            if ($unchanged) {
                $user->setDisplayName($requested);
                $user->save();
            } else {
                // Claim the cooldown and write the name as one transaction.
                //
                // The claim is a conditional UPDATE whose affected-row count is
                // the authority — the same shape the unique index already
                // provides for the name itself. The check further up reads a
                // snapshot, so without this two concurrent renames both see an
                // expired cooldown and both commit, and one escapes the 24 hours
                // entirely. A cooldown enforced only in PHP holds only when
                // nobody is trying to break it.
                //
                // One transaction, because the two writes have to fail together:
                // claiming the cooldown and then losing the name to the unique
                // index would spend a day's allowance on a rename that did not
                // happen.
                DB::transaction(function () use ($user, $requested): void {
                    $claimed = $user->newQuery()
                        ->whereKey($user->getKey())
                        ->where(fn ($query) => $query
                            ->whereNull('display_name_changed_at')
                            ->orWhere('display_name_changed_at', '<=', Carbon::now()->subHours(self::RENAME_COOLDOWN_HOURS)))
                        ->update(['display_name_changed_at' => Carbon::now()]);

                    if ($claimed !== 1) {
                        throw ApiProblem::retryAfter(
                            ProblemCode::DisplayNameChangeCooldown,
                            self::RENAME_COOLDOWN_HOURS * 3600,
                            'You changed your display name recently. Try again later.'
                        );
                    }

                    $user->refresh();
                    $user->setDisplayName($requested);
                    $user->save();
                });
            }
        } catch (UniqueConstraintViolationException) {
            // The validator checked availability; the index is the guarantee.
            // Two players claiming one name at the same moment both pass
            // validation and only one commits.
            throw ApiProblem::of(
                ProblemCode::Conflict,
                'That display name was just taken. Choose another.'
            );
        }

        if (! $unchanged) {
            AuthLog::forUser(AuthLog::DISPLAY_NAME_CHANGED, $user, $request);
        }

        return AuthenticatedUserResource::make($user->refresh())->response();
    }
}
