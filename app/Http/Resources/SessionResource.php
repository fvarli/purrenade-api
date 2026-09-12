<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One active session, as shown on the account-security screen.
 *
 * Three deliberate omissions:
 *
 *  - **The token.** Obviously — but worth stating, because the row this
 *    projects *is* the credential. `token` is the hashed value and never leaves
 *    the database; `public_id` is the unguessable handle the client addresses.
 *
 *  - **The database id.** A sequential integer would let a client name sessions
 *    it was never shown. The ownership check refuses them, but an identifier
 *    that cannot be guessed is better than one that can be guessed and refused.
 *
 *  - **IP address and location.** Not recorded at all in M2 (AUTH-4 stays
 *    OPEN). v0.3 board 20 shows an approximate location; that needs a geo-IP
 *    source and a retention policy decided together, and storing the address
 *    "for later" would create the personal-data obligation before the decision.
 *    The device label and the timestamps do the recognition job the screen
 *    exists for.
 *
 * @mixin PersonalAccessToken
 */
final class SessionResource extends JsonResource
{
    public function __construct(
        PersonalAccessToken $token,
        private readonly bool $isCurrent,
    ) {
        parent::__construct($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $token */
        $token = $this->resource;

        return [
            'id' => $token->public_id,
            'device' => $token->name,

            // So the UI can label one row "this device" and refuse to make
            // signing out of it look like signing out of somewhere else.
            'is_current' => $this->isCurrent,

            'two_factor_satisfied' => $token->satisfiedTwoFactor(),

            'created_at' => $token->created_at?->toIso8601String(),

            // Sanctum stamps this on use. Null means the session has not made a
            // request since it was created, which the UI renders as "just now"
            // rather than as "never".
            'last_active_at' => $token->last_used_at?->toIso8601String(),
        ];
    }
}
