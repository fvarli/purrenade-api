<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The tutorial, as much as a client is allowed to know: whether it is done.
 *
 * The stored fact is `tutorial_completed_at`, a nullable timestamp, and it
 * stays stored — `docs/architecture/data-model.md` §4 owns it and M9 will move
 * it to `player_progression` unchanged. What crosses the wire is the boolean
 * derived from it, because **when** a player finished is not something any
 * client has a use for.
 *
 * That is a narrower reading of this API's "facts rather than conclusions"
 * habit than `AuthenticatedUserResource` applies elsewhere, and deliberately
 * so. The habit exists to stop the server sending a *state name* the client
 * then has to trust — "you are locked out", "you are eligible" — where the
 * client could have judged for itself. Completion is not that: it is a single
 * bit the server owns outright, the timestamp adds no decision the client could
 * make differently, and sending it would put a date in the browser purely
 * because the database happened to have one.
 *
 * @mixin User
 */
final class TutorialStateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'tutorial_completed' => $user->tutorial_completed_at !== null,
        ];
    }
}
