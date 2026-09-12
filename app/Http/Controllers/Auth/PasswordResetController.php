<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use App\Services\Auth\SessionIssuer;
use App\Services\Auth\TwoFactorChallengeService;
use App\Support\AuthLog;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password reset and password change.
 *
 * Reset is an emailed **link**, deliberately unlike email verification's code
 * (v0.3 board 06). Laravel's password broker owns the token: hashed at rest,
 * single use, time-limited, and verified against the address as a pair. None of
 * that is reimplemented here.
 *
 * ### The invariant this file must not break
 *
 * **A completed reset never touches two-factor authentication.** Not the
 * enrolment flag, not the TOTP secret, not one recovery code, and it does not
 * mark any challenge as satisfied. Email already controls password reset; if a
 * reset also cleared 2FA, then compromising a mailbox would compromise the whole
 * account in one step and the second factor would defend against exactly nothing.
 *
 * The code below therefore writes **only** `password` and `remember_token`. It
 * is an APPROVED invariant (docs/security/authentication.md §4.1) with a named
 * regression gate, `S8`, and a test that asserts each of those four properties
 * individually rather than trusting this comment.
 */
final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly SessionIssuer $sessions,
        private readonly TwoFactorChallengeService $challenges,
    ) {}

    /**
     * POST /auth/password/forgot
     *
     * One response, always. An unknown address, a known address, a send failure
     * — all 202. Anything that distinguishes them turns this endpoint into an
     * account-existence oracle that needs no credentials to query, and the
     * design's own copy ("we have sent a link if the address is registered")
     * depends on the server actually behaving that way.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = (string) $request->string('email');

        // The broker's return value is deliberately discarded. Branching on it
        // is the mistake this endpoint exists to avoid.
        Password::broker()->sendResetLink(['email' => $email]);

        AuthLog::forAttempt(AuthLog::PASSWORD_RESET_REQUESTED, $email, $request);

        return response()->json([
            'status' => 'accepted',
            'meta' => [
                'detail' => 'If that address belongs to an account, a reset link is on its way.',
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * POST /auth/password/reset
     *
     * Completes the reset and ends every existing session.
     *
     * Every session, including any the caller currently holds: a reset is
     * normally a response to suspected compromise, so the point is to evict
     * whoever else is signed in — and that has to include sessions this request
     * cannot identify. The player signs in again afterwards, and if 2FA is
     * enabled they are challenged for it, because the reset changed one factor
     * and not the other.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            [
                'email' => (string) $request->string('email'),
                'password' => (string) $request->string('password'),
                'password_confirmation' => (string) $request->string('password_confirmation'),
                'token' => (string) $request->string('token'),
            ],
            function (User $user, string $password): void {
                // Exactly two columns. Nothing two-factor is named here, which
                // is the mechanical form of the invariant above — the invariant
                // cannot be broken by editing a value, only by adding a field.
                $user->forceFill([
                    'password' => $this->hasher->make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $this->sessions->revokeAll($user);

                // Revoking tokens is not the whole eviction. A pending
                // two-factor challenge is a half-authenticated handle, opened on
                // the strength of the *old* password, and it survives in its own
                // table for the rest of its five minutes — so whoever knew that
                // password could redeem it after the reset and be issued a full
                // session, minted after every existing one had been destroyed.
                $this->challenges->purgeForUser($user->id);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One code for an unknown address, a wrong token and an expired
            // token. Distinguishing them would let a caller holding a guessed
            // token learn whether the address exists.
            throw ApiProblem::of(
                ProblemCode::ResetTokenInvalid,
                'This reset link is invalid or has expired. Request a new one.'
            );
        }

        AuthLog::forAttempt(AuthLog::PASSWORD_RESET_COMPLETED, (string) $request->string('email'), $request);

        return response()->json([
            'status' => 'password_reset',
            'meta' => [
                'detail' => 'Your password has been changed. Sign in with your new password.',
            ],
        ]);
    }

    /**
     * PUT /auth/password
     *
     * Changing a password from inside a session. `current_password` is required
     * — an open session is not proof that the owner is at the keyboard.
     *
     * Other sessions are ended; this one survives. A deliberate change by
     * someone who can already prove the old password is not the same event as a
     * reset, and signing the player out of the screen they just used would be
     * friction with no security value. Two-factor state is untouched, for the
     * same reason as in `reset()`.
     */
    public function update(UpdatePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'password' => $this->hasher->make((string) $request->string('password')),
        ])->save();

        $current = $user->currentAccessToken();

        $revoked = $this->sessions->revokeAllExceptCurrent(
            $user,
            $current === null ? null : (int) $current->getKey(),
        );

        // For the same reason as in `reset()`: a challenge opened against the
        // password that has just been replaced must not still be redeemable.
        $this->challenges->purgeForUser($user->id);

        AuthLog::forUser(AuthLog::PASSWORD_CHANGED, $user, $request, ['sessions_revoked' => $revoked]);

        return response()->noContent();
    }
}
