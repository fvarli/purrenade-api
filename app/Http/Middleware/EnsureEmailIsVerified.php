<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The verified-email gate (AUTH-1).
 *
 * An authenticated but unverified player may reach exactly four endpoints:
 * `GET /auth/me`, `POST /auth/email/verify`, `POST /auth/email/verify/resend`
 * and `POST /auth/logout` — enough to learn that verification is required, to
 * complete it, and to leave. Everything else in the API sits behind this
 * middleware.
 *
 * That list is the *whole* allowance, including two-factor enrolment and session
 * management. An unverified account is an account whose owner has not been shown
 * to control the address, and the cost of waiting is one code.
 *
 * Applied to the route **group**, not per route, so an endpoint added later is
 * gated by default. The alternative — remembering `->middleware('verified')` on
 * each new route — fails silently the first time someone forgets.
 *
 * Laravel ships its own `EnsureEmailIsVerified`. This replaces it only so the
 * refusal is an RFC 9457 problem with a stable `code` the client can branch on,
 * rather than a bare `{"message": ...}` that would need special-casing.
 */
final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->email_verified_at === null) {
            throw ApiProblem::of(
                ProblemCode::EmailNotVerified,
                'Verify your email address before using this endpoint.'
            );
        }

        return $next($request);
    }
}
