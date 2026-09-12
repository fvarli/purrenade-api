<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SensitiveActionRequest;
use App\Http\Resources\SessionResource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\SessionIssuer;
use App\Support\AuthLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Active session and device management (v0.3 board 20).
 *
 * Every action here is an **ownership** check, not a role check. That is the
 * distinction docs/security/authorization-and-roles.md §3 singles out as the
 * source of most authorization bugs: a role check on this resource would let any
 * player sign out any other player. So the owner is part of every query rather
 * than something verified afterwards — an ownership check written as a separate
 * step is a step somebody can forget.
 */
final class SessionController extends Controller
{
    public function __construct(private readonly SessionIssuer $sessions) {}

    /**
     * GET /auth/sessions
     *
     * Newest first, with the caller's own session flagged so the UI can label it
     * and avoid presenting "sign out" as though it referred to another device.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentId = $user->currentAccessToken()?->getKey();

        // Ordered by key, not by `created_at`. Two sessions created in the same
        // second tie on the timestamp, and a tie makes the order arbitrary — the
        // list would reshuffle between reads of the same data. The key is
        // monotonic, so this is a total order and also the newest-first order
        // the screen wants. Same reasoning as the total-order rule for cursors
        // in docs/api/api-conventions.md §6.
        /** @var Collection<int, PersonalAccessToken> $tokens */
        $tokens = $user->tokens()->orderByDesc('id')->get();

        return response()->json([
            'data' => $tokens
                ->map(fn (PersonalAccessToken $token): array => SessionResource::make(
                    $token,
                    isCurrent: $token->getKey() === $currentId,
                )->resolve($request))
                ->all(),
        ]);
    }

    /**
     * DELETE /auth/sessions/{session}
     *
     * **404, not 403**, when the id is not the caller's. A `403` would confirm
     * that the session exists and belongs to somebody, which makes the endpoint
     * an existence oracle over a table of session identifiers. §6 draws that
     * line explicitly, and revoking sessions is exactly the case it draws it for.
     *
     * Revoking the current session is permitted — it is simply a logout — but
     * the client is told which happened so it can clear its own state.
     */
    public function destroy(Request $request, string $session): Response
    {
        /** @var User $user */
        $user = $request->user();

        $wasCurrent = $user->currentAccessToken() instanceof PersonalAccessToken
            && $user->currentAccessToken()->public_id === $session;

        if (! $this->sessions->revoke($user, $session)) {
            throw ApiProblem::of(
                ProblemCode::NotFound,
                'That session does not exist.'
            );
        }

        AuthLog::forUser(AuthLog::SESSION_REVOKED, $user, $request, [
            'session_id' => $session,
            'was_current' => $wasCurrent,
        ]);

        return response()->noContent();
    }

    /**
     * DELETE /auth/sessions — sign out every other device.
     *
     * Resolves AUTH-2: the current session survives. The action a player is
     * taking is "get everyone else out", and `POST /auth/logout` already exists
     * for the other intent; ending the caller's own session here would make
     * every use of this button end at the login screen.
     *
     * Requires `current_password`. It evicts sessions the caller cannot see, so
     * it should not be available to whoever happens to be holding an open one.
     */
    public function destroyOthers(SensitiveActionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $current = $user->currentAccessToken();

        $revoked = $this->sessions->revokeAllExceptCurrent(
            $user,
            $current === null ? null : (int) $current->getKey(),
        );

        AuthLog::forUser(AuthLog::SESSIONS_REVOKED_ALL, $user, $request, ['sessions_revoked' => $revoked]);

        return response()->json([
            'status' => 'sessions_revoked',
            'meta' => ['revoked_count' => $revoked],
        ]);
    }
}
