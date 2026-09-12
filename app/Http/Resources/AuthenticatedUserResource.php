<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a caller is told about their own account.
 *
 * This projection drives the frontend's entire authentication state machine, so
 * it reports facts rather than conclusions: "email is verified", "2FA is
 * enabled", "this session passed a challenge". The client derives its state from
 * those; the server does not send a state name the client then has to trust.
 *
 * Field-level authorization applies here as everywhere: the password hash, the
 * TOTP secret, recovery codes and `remember_token` are absent. Recovery codes
 * are reported as a **count**, never as values — they are shown exactly once, at
 * the moment they are generated, and are not retrievable afterwards.
 *
 * @mixin User
 */
final class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $token = $user->currentAccessToken();

        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role->value,

            'email_verified' => $user->email_verified_at !== null,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),

            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'two_factor_pending' => $user->hasTwoFactorPending(),

            // A count, so the client can warn a player who has spent nearly all
            // of them — the state in which losing an authenticator stops being
            // recoverable.
            'two_factor_recovery_codes_remaining' => app(TwoFactorService::class)
                ->unusedRecoveryCodeCount($user),

            // True for an administrator who has not enrolled. A real, reachable
            // state: an operator can promote a player who has no second factor.
            // Reporting it lets the UI say what to do instead of showing an
            // account that simply appears broken.
            'requires_two_factor_enrolment' => $user->requiresTwoFactorEnrolment(),

            'created_at' => $user->created_at?->toIso8601String(),

            'session' => $token instanceof PersonalAccessToken ? [
                'id' => $token->public_id,
                'device' => $token->name,
                'two_factor_satisfied' => $token->satisfiedTwoFactor(),
                'created_at' => $token->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}
