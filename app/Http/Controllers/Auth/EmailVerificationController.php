<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\EmailVerificationService;
use App\Support\AuthLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /auth/email/verify and POST /auth/email/verify/resend
 *
 * Both are authenticated, which removes the enumeration surface these endpoints
 * would otherwise have: there is no address parameter to probe, and a caller can
 * only act on their own account. It also makes cross-account use structurally
 * impossible — the code is looked up by the authenticated user's id, so a code
 * issued for one account cannot verify another regardless of who holds it.
 */
final class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            // Idempotent-ish, but reported rather than silently succeeding: a
            // client submitting a code against a verified account has drifted
            // from the server's state and should refresh rather than continue.
            throw ApiProblem::of(
                ProblemCode::EmailAlreadyVerified,
                'This email address is already verified.'
            );
        }

        try {
            $this->verification->verify($user, (string) $request->string('code'));
        } catch (ApiProblem $problem) {
            AuthLog::forUser(AuthLog::EMAIL_VERIFICATION_FAILED, $user, $request, [
                'reason' => $problem->problemCode->value,
            ]);

            throw $problem;
        }

        AuthLog::forUser(AuthLog::EMAIL_VERIFIED, $user, $request);

        return AuthenticatedUserResource::make($user->refresh())->response();
    }

    /**
     * Send a new code, subject to the 42-second cooldown.
     *
     * The cooldown returns **429 with `retry_after`**, not a generic error: the
     * design renders a live countdown (v0.3 board 04 shows `0:42`), and it can
     * only do that if the server says how long is left. Treating impatience as a
     * failure would make the screen lie.
     */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            throw ApiProblem::of(
                ProblemCode::EmailAlreadyVerified,
                'This email address is already verified.'
            );
        }

        $remaining = $this->verification->cooldownRemaining($user);

        if ($remaining > 0) {
            throw ApiProblem::retryAfter(
                ProblemCode::VerificationResendCooldown,
                $remaining,
                'A code was sent recently. Wait before requesting another.'
            );
        }

        // Issuing invalidates the previous code, so the player's older mail
        // stops working the moment the new one is sent.
        $code = $this->verification->issue($user);

        $user->notify(new VerifyEmailNotification($code));

        AuthLog::forUser(AuthLog::EMAIL_VERIFICATION_SENT, $user, $request, ['trigger' => 'resend']);

        return response()->json([
            'meta' => [
                'email_verification' => [
                    'resend_available_in' => $this->verification->cooldownRemaining($user),
                    'expires_in' => EmailVerificationService::TTL_MINUTES * 60,
                ],
            ],
        ]);
    }
}
