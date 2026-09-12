<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\AuthLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin gate: role, verified address, enrolled second factor, and a session
 * that actually passed a two-factor challenge.
 *
 * All four, every request. Mandatory admin 2FA is an APPROVED requirement
 * (docs/security/authorization-and-roles.md §5) and that document is explicit
 * that it must be **structural**: enforced where new routes inherit it, because
 * a per-route annotation is one forgotten line away from a privileged bypass.
 * So this is applied to the admin route group and nothing inside that group
 * repeats the check.
 *
 * The fourth condition is the one that is easy to get wrong. "Has 2FA enabled"
 * is a property of the account; "passed 2FA" is a property of *this session*.
 * Checking only the former would let a token minted by a password-only login —
 * from before enrolment, or by a path that skipped the challenge — reach the
 * admin surface on an account that merely *has* 2FA configured. The ability is
 * welded onto the credential by the challenge endpoint and by nothing else, so
 * the distinction is not something this middleware has to trust the caller for.
 *
 * It also has to be the *current* factor. Both properties can be true of
 * different secrets: an ability cannot be withdrawn from a token that already
 * exists, so a session that passed a challenge before the account rotated its
 * authenticator still carries the ability afterwards. Each condition then reads
 * as satisfied while their conjunction is false. The token records which
 * generation of the second factor it was challenged against, and this compares
 * it — so rotating a stolen authenticator actually evicts the thief.
 *
 * 403 rather than 404 throughout: the existence of an admin surface is not a
 * secret, and each refusal carries a distinct stable code so the client can say
 * *why* instead of showing a blank denial (§6).
 */
final class EnsureAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Reachable only if this middleware were ever used without `auth`.
            // Denying is the right answer either way.
            throw ApiProblem::of(ProblemCode::Unauthenticated, 'This endpoint requires an authenticated session.');
        }

        if (! $user->isAdmin()) {
            $this->deny($user, $request, 'role');

            throw ApiProblem::of(
                ProblemCode::AdminRoleRequired,
                'This endpoint is restricted to administrators.'
            );
        }

        // An unverified admin is denied for the same reason as any unverified
        // account, but with the admin code, so the operator sees which gate
        // stopped them rather than a generic verification prompt.
        if ($user->email_verified_at === null) {
            $this->deny($user, $request, 'email_unverified');

            throw ApiProblem::of(
                ProblemCode::EmailNotVerified,
                'Verify your email address before using the administrative surface.'
            );
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->deny($user, $request, 'two_factor_not_enrolled');

            throw ApiProblem::of(
                ProblemCode::AdminTwoFactorRequired,
                'Two-factor authentication is mandatory for administrators. Enrol before using this endpoint.'
            );
        }

        $token = $user->currentAccessToken();

        // `satisfiesTwoFactorFor`, not `satisfiedTwoFactor`: the challenge must
        // have been passed against the second factor the account has *now*.
        // The weaker check let a session survive a credential rotation — disable
        // 2FA, enrol a new secret, and a token from before the rotation was
        // privileged again, because every condition above it was individually
        // true. See PersonalAccessToken::satisfiesTwoFactorFor().
        if (! $token instanceof PersonalAccessToken || ! $token->satisfiesTwoFactorFor($user)) {
            $this->deny($user, $request, 'two_factor_not_satisfied');

            throw ApiProblem::of(
                ProblemCode::AdminTwoFactorRequired,
                'This session did not complete a two-factor challenge. Sign in again.'
            );
        }

        AuthLog::forUser(AuthLog::ADMIN_ACCESS_GRANTED, $user, $request);

        return $next($request);
    }

    /**
     * Every refusal is logged with the reason.
     *
     * A denied admin request is either an operator hitting a gate or somebody
     * probing the admin surface, and both are worth seeing. The reason code is
     * what makes the difference legible in the log.
     */
    private function deny(User $user, Request $request, string $reason): void
    {
        AuthLog::forUser(AuthLog::ADMIN_ACCESS_DENIED, $user, $request, [
            'reason' => $reason,
            'path' => '/'.ltrim($request->path(), '/'),
        ]);
    }
}
