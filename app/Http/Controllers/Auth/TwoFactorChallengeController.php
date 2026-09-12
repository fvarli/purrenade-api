<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\Auth\SessionIssuer;
use App\Services\Auth\TwoFactorChallengeService;
use App\Services\Auth\TwoFactorService;
use App\Support\AuthLog;
use Illuminate\Http\JsonResponse;

/**
 * POST /auth/2fa/challenge
 *
 * Second factor. The only endpoint that mints a session carrying the
 * `two-factor` ability — which is what every admin-scoped route checks. There is
 * deliberately no way to add that ability to an existing token, so a session
 * either passed a challenge when it was created or never will.
 *
 * Accepts a TOTP code or a recovery code. Both consume the challenge on success.
 */
final class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorChallengeService $challenges,
        private readonly TwoFactorService $twoFactor,
        private readonly SessionIssuer $sessions,
    ) {}

    public function __invoke(TwoFactorChallengeRequest $request): JsonResponse
    {
        $challenge = $this->challenges->resolve((string) $request->string('challenge_token'));

        /** @var User $user */
        $user = $challenge->user;

        // The account could have had 2FA turned off between login and this
        // request. Issuing a `two-factor` session against an account with no
        // second factor would be a privilege the account cannot back up.
        if (! $user->hasTwoFactorEnabled()) {
            $this->challenges->consume($challenge);

            throw ApiProblem::of(
                ProblemCode::TwoFactorNotEnabled,
                'Two-factor authentication is no longer enabled on this account. Sign in again.'
            );
        }

        $recoveryCode = $request->recoveryCode();
        $totpCode = $request->totpCode();

        $usedRecoveryCode = false;

        if ($recoveryCode !== null) {
            $usedRecoveryCode = $this->twoFactor->consumeRecoveryCode($user, $recoveryCode);

            if (! $usedRecoveryCode) {
                $this->fail($challenge, $user, $request, 'recovery_code');
            }
        } elseif ($totpCode === null || ! $this->twoFactor->verifyTotp($user, $totpCode)) {
            $this->fail($challenge, $user, $request, 'totp');
        }

        $this->challenges->consume($challenge);

        $token = $this->sessions->issue(
            $user,
            $challenge->device_label ?? 'Unknown device',
            twoFactorSatisfied: true,
        );

        if ($usedRecoveryCode) {
            // Rare and worth noticing: a consumed recovery code means somebody
            // lost an authenticator, or somebody is using a stolen printout.
            AuthLog::forUser(AuthLog::TWO_FACTOR_RECOVERY_CODE_USED, $user, $request, [
                'remaining' => $this->twoFactor->unusedRecoveryCodeCount($user),
            ]);
        }

        AuthLog::forUser(AuthLog::LOGIN_SUCCEEDED, $user, $request, [
            'two_factor' => true,
            'method' => $usedRecoveryCode ? 'recovery_code' : 'totp',
        ]);

        // Describe the session this response created — see LoginController.
        $user->withAccessToken($token->accessToken);

        return AuthenticatedUserResource::make($user)
            ->additional([
                'status' => 'authenticated',
                'meta' => [
                    'token' => $token->plainTextToken,
                    'used_recovery_code' => $usedRecoveryCode,
                    'recovery_codes_remaining' => $this->twoFactor->unusedRecoveryCodeCount($user),
                ],
            ])
            ->response();
    }

    /**
     * Count the failure and refuse.
     *
     * One error code for a wrong TOTP code and a wrong recovery code: which
     * kind of secret the caller guessed wrongly is not information they need.
     * The attempt is charged to the challenge, so the cap in
     * TwoFactorChallenge::MAX_ATTEMPTS applies across both.
     */
    private function fail(
        TwoFactorChallenge $challenge,
        User $user,
        TwoFactorChallengeRequest $request,
        string $method,
    ): never {
        $this->challenges->registerFailure($challenge);

        AuthLog::forUser(AuthLog::TWO_FACTOR_CHALLENGE_FAILED, $user, $request, ['method' => $method]);

        throw ApiProblem::of(
            ProblemCode::TwoFactorCodeInvalid,
            'That code is not correct.'
        );
    }
}
