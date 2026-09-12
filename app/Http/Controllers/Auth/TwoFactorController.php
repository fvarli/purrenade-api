<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\SensitiveActionRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\AuthLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor enrolment and management.
 *
 * Enrolment is two steps on purpose: `enable` hands out a secret, `confirm`
 * proves the player can produce a code from it. 2FA is off between them.
 * Switching it on in one step against a secret the player never successfully
 * scanned locks them out of their own account, with no self-service way back.
 *
 * Recovery codes are returned by `confirm` and by `recoveryCodes`, and by
 * nothing else — they are shown exactly once and stored hashed, so there is no
 * endpoint that can show them again.
 */
final class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * POST /auth/2fa/enable — begin enrolment.
     *
     * Requires `current_password`: this call starts a change to the account's
     * defences, and a borrowed open session should not be able to begin it.
     *
     * Returns the secret three ways — a QR image, the `otpauth://` URI, and the
     * bare secret — because a player on the same device as their authenticator
     * cannot scan their own screen, and one on a locked-down phone may have to
     * type it.
     */
    public function enable(SensitiveActionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $enrolment = $this->twoFactor->beginEnrolment($user);

        return response()->json([
            'data' => [
                'secret' => $enrolment['secret'],
                'otpauth_uri' => $enrolment['otpauth_uri'],
                // A `data:` URI for an <img>, not raw SVG: the frontend never
                // needs v-html, so this screen adds no XSS sink.
                'qr_code' => $enrolment['qr_code'],
            ],
            'status' => 'two_factor_pending',
            'meta' => [
                'detail' => 'Scan the code, then confirm with a code from your authenticator app.',
            ],
        ]);
    }

    /**
     * POST /auth/2fa/confirm — prove possession and switch 2FA on.
     *
     * The recovery codes in this response are the only time they are ever
     * transmitted.
     */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $codes = $this->twoFactor->confirmEnrolment($user, (string) $request->string('code'));

        AuthLog::forUser(AuthLog::TWO_FACTOR_ENABLED, $user, $request);

        return AuthenticatedUserResource::make($user->refresh())
            ->additional([
                'status' => 'two_factor_enabled',
                'meta' => [
                    'recovery_codes' => $codes,
                    'detail' => 'Save these recovery codes now. They will not be shown again.',
                ],
            ])
            ->response();
    }

    /**
     * POST /auth/2fa/recovery-codes — issue a new set.
     *
     * The previous set stops working. A player asking for new codes is saying
     * the old list is lost or exposed; leaving it valid would answer a security
     * action by doubling the number of live bypasses.
     */
    public function recoveryCodes(SensitiveActionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $codes = $this->twoFactor->replaceRecoveryCodes($user);

        AuthLog::forUser(AuthLog::TWO_FACTOR_RECOVERY_CODES_REGENERATED, $user, $request);

        return response()->json([
            'status' => 'recovery_codes_regenerated',
            'meta' => [
                'recovery_codes' => $codes,
                'detail' => 'Your previous recovery codes no longer work. Save these.',
            ],
        ]);
    }

    /**
     * POST /auth/2fa/disable
     *
     * Refused for administrators — 2FA is mandatory for that role and an admin
     * cannot opt out (docs/security/two-factor.md §5). The refusal lives in the
     * service, not here: it guards the destructive operation itself, so no
     * caller can reach it by another route. This method deliberately does not
     * repeat the check — an earlier version of this comment claimed both did,
     * which would have made a reader think there were two.
     */
    public function disable(SensitiveActionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->twoFactor->disable($user);

        AuthLog::forUser(AuthLog::TWO_FACTOR_DISABLED, $user, $request);

        return response()->noContent();
    }

    /**
     * GET /auth/2fa — the account's current two-factor state.
     *
     * A state read, not a secret read: whether 2FA is on, whether an enrolment
     * is half-finished, and how many recovery codes remain. Never the secret,
     * never a code.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending' => $user->hasTwoFactorPending(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => $this->twoFactor->unusedRecoveryCodeCount($user),
                'mandatory' => $user->role->requiresTwoFactor(),
            ],
        ]);
    }
}
