<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * Mints the credential that represents one signed-in device.
 *
 * A Sanctum token, issued to the caller — the BFF for browser traffic, the app
 * itself for a future native client. Browser JavaScript never sees it: the BFF
 * keeps it in its own server-side session and attaches it upstream (ADR-0005 §1).
 *
 * Abilities are always an explicit list and never `['*']`. The wildcard would
 * make `tokenCan('two-factor')` answer true for a password-only login, which is
 * the one question the admin middleware depends on.
 */
final readonly class SessionIssuer
{
    public function __construct(private TwoFactorService $twoFactor) {}

    /**
     * @param  bool  $twoFactorSatisfied  True only on the path that actually verified
     *                                    a second factor. There is no endpoint that
     *                                    adds this to an existing token, so a session
     *                                    cannot acquire it after the fact.
     */
    public function issue(User $user, string $deviceLabel, bool $twoFactorSatisfied): NewAccessToken
    {
        $abilities = [PersonalAccessToken::ABILITY_SESSION];

        if ($twoFactorSatisfied) {
            $abilities[] = PersonalAccessToken::ABILITY_TWO_FACTOR;
        }

        // No `expires_at`: session lifetime is the BFF's concern for browser
        // traffic (idle and absolute timeouts live there, next to the cookie
        // they govern), and an unexpiring upstream credential paired with a
        // short-lived browser session would be the wrong way round. Sanctum's
        // global `expiration` config provides the absolute backstop, so the
        // value stays in one place rather than being duplicated per token.
        $token = $user->createToken($deviceLabel, $abilities);

        if ($twoFactorSatisfied) {
            // Stamp *which* second factor was proved, not merely that one was.
            // Without this the ability outlives the secret it attested to, and
            // a session challenged against a rotated-away secret stays
            // privileged. See PersonalAccessToken::satisfiesTwoFactorFor().
            $token->accessToken->forceFill([
                'two_factor_version' => $user->two_factor_version,
            ])->save();
        }

        return $token;
    }

    /**
     * Revoke one session, identified by its opaque public id.
     *
     * Scoped to the owner in the query itself rather than checked afterwards:
     * an ownership check written as a second step is a check someone can forget,
     * and forgetting it here means any player can sign out any other player.
     */
    public function revoke(User $user, string $publicId): bool
    {
        return $user->tokens()
            ->where('public_id', $publicId)
            ->delete() === 1;
    }

    /**
     * Revoke every session except the one making the request.
     *
     * Resolves AUTH-2: revoke-all keeps the current session. The action is
     * "sign out my other devices" — signing the caller out as well would mean
     * every use of it ends in an unexpected trip to the login screen, and
     * `POST /auth/logout` already exists for the other intent.
     *
     * @return int Number of sessions ended.
     */
    public function revokeAllExceptCurrent(User $user, ?int $currentTokenId): int
    {
        return $user->tokens()
            ->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))
            ->delete();
    }

    /**
     * Revoke every session, including the caller's own.
     *
     * Used after a password reset, where the premise is that the account may be
     * compromised: the point is to evict whoever else is holding a session, and
     * that has to include sessions this request cannot identify.
     */
    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }

    /**
     * Does the account still have a usable second factor?
     *
     * Read by the `/auth/me` projection so the client can warn a player who has
     * spent every recovery code — the state where losing an authenticator
     * becomes unrecoverable.
     */
    public function remainingRecoveryCodes(User $user): int
    {
        return $this->twoFactor->unusedRecoveryCodeCount($user);
    }
}
