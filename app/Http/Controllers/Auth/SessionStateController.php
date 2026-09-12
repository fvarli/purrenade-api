<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Support\AuthLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /auth/me and POST /auth/logout.
 *
 * `me` is how the frontend restores state after a page refresh: the browser
 * holds only an opaque BFF cookie, so on every fresh load the BFF asks this
 * endpoint who the session belongs to. It is therefore one of the four endpoints
 * an unverified account may reach — a player who cannot ask "am I verified?"
 * cannot be told to verify.
 */
final class SessionStateController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $meta = [];

        // Only while it is relevant. A verified account has no use for a resend
        // countdown, and sending one invites the client to render a stale timer.
        if ($user->email_verified_at === null) {
            $meta['email_verification'] = [
                'resend_available_in' => $this->verification->cooldownRemaining($user),
            ];
        }

        return AuthenticatedUserResource::make($user)
            ->additional($meta === [] ? [] : ['meta' => $meta])
            ->response();
    }

    /**
     * End this session, and only this session.
     *
     * The token row is deleted, so the credential itself stops existing —
     * revocation is real rather than a cookie being forgotten (ADR-0005 §3).
     * Other devices are untouched; `DELETE /auth/sessions` is the endpoint for
     * that intent.
     */
    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        AuthLog::forUser(AuthLog::LOGOUT, $user, $request);

        return response()->noContent();
    }
}
