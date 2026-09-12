<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Services\Auth\SessionIssuer;
use App\Services\Auth\TwoFactorChallengeService;
use App\Support\AuthLog;
use App\Support\DeviceLabel;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\JsonResponse;

/**
 * POST /auth/login
 *
 * First factor. Ends in one of two states, and the response says which:
 *
 *   `authenticated`       — a session token is issued.
 *   `two_factor_required` — a short-lived challenge token is issued instead.
 *
 * Both are 200: the password was correct in both cases, and a discriminated
 * `status` is easier for a client to branch on than a status code that means
 * "correct, but not finished".
 *
 * ### Enumeration resistance
 *
 * Two rules, and both matter (docs/security/authentication.md §5):
 *
 *  1. **One error for everything.** Unknown address and wrong password both
 *     return `invalid_credentials`. Two codes would be an account-existence
 *     oracle, and a registration form already tells an attacker that much only
 *     for addresses they can test one at a time under a rate limit.
 *
 *  2. **An unknown address still costs a hash comparison.** Without the dummy
 *     verify below, "no such user" returns in a fraction of the time a real
 *     password check takes, and the timing difference is an oracle that needs no
 *     error message at all.
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly SessionIssuer $sessions,
        private readonly TwoFactorChallengeService $challenges,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $email = (string) $request->string('email');
        $password = (string) $request->string('password');
        $deviceLabel = DeviceLabel::fromRequest($request);

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! $this->hasher->check($password, $user->password)) {
            $this->equaliseTiming($user, $password);

            AuthLog::forAttempt(AuthLog::LOGIN_FAILED, $email, $request, [
                // Whether the account exists is recorded in the log — it is a
                // genuinely useful signal for telling a fat-fingered player from
                // a credential-stuffing run — but it is never in the response.
                'account_exists' => $user instanceof User,
            ]);

            throw ApiProblem::of(
                ProblemCode::InvalidCredentials,
                'Those credentials do not match our records.'
            );
        }

        // Transparent rehash when the cost parameters change, so tightening
        // argon2id settings upgrades accounts as their owners sign in rather
        // than leaving old hashes in place forever.
        if ($this->hasher->needsRehash($user->password)) {
            $user->forceFill(['password' => $this->hasher->make($password)])->save();
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->requireSecondFactor($user, $deviceLabel, $request);
        }

        return $this->issueSession($user, $deviceLabel, $request);
    }

    private function requireSecondFactor(User $user, string $deviceLabel, LoginRequest $request): JsonResponse
    {
        $challenge = $this->challenges->start($user->id, $deviceLabel);

        AuthLog::forUser(AuthLog::TWO_FACTOR_CHALLENGED, $user, $request);

        return response()->json([
            // Top-level, because this is the member that decides the response
            // shape. See RegisterController for the convention.
            'status' => 'two_factor_required',
            'meta' => [
                'challenge_token' => $challenge['token'],
                'challenge_expires_at' => $challenge['expires_at']->toIso8601String(),
                // So the challenge screen can offer the recovery-code path only
                // when one is actually available.
                'recovery_codes_available' => $this->sessions->remainingRecoveryCodes($user) > 0,
            ],
        ]);
    }

    private function issueSession(User $user, string $deviceLabel, LoginRequest $request): JsonResponse
    {
        $token = $this->sessions->issue($user, $deviceLabel, twoFactorSatisfied: false);

        AuthLog::forUser(AuthLog::LOGIN_SUCCEEDED, $user, $request, ['two_factor' => false]);

        // Attach the token that was just minted, so the projection describes the
        // session this response created. Without it `session` comes back null on
        // the one response where the client most needs it: this request was not
        // authenticated *by* that token, so the model has no current one.
        $user->withAccessToken($token->accessToken);

        return AuthenticatedUserResource::make($user)
            ->additional([
                'status' => 'authenticated',
                'meta' => ['token' => $token->plainTextToken],
            ])
            ->response();
    }

    /**
     * Spend the same work on a failed lookup as on a real password check.
     *
     * When the account exists the real `check()` has already run, so nothing
     * more is needed. When it does not, hash the submitted password against a
     * discarded hash of itself: argon2id's cost is paid either way, so the two
     * paths take comparable time and the response no longer says which happened.
     */
    private function equaliseTiming(?User $user, string $password): void
    {
        if ($user instanceof User) {
            return;
        }

        $this->hasher->check($password, $this->hasher->make($password));
    }
}
