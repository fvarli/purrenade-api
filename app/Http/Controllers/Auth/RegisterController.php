<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ProblemCode;
use App\Enums\UserRole;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\SessionIssuer;
use App\Support\AuthLog;
use App\Support\DeviceLabel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /auth/register
 *
 * Creates an unverified account, issues a session, and sends a six-digit code.
 *
 * A session is issued immediately, before verification, because the product
 * routes a new player straight to the code-entry screen (v0.3 boards 02 → 04)
 * and that screen has to be able to call `resend`. The session is narrow: the
 * verified-email gate confines it to four endpoints until the code is entered.
 */
final class RegisterController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly SessionIssuer $sessions,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $deviceLabel = DeviceLabel::fromRequest($request);

        try {
            /** @var array{user: User, token: string} $result */
            $result = DB::transaction(function () use ($request, $deviceLabel): array {
                $user = new User;

                // Column-by-column, never mass assignment: `role` and
                // `email_verified_at` are in this table, and a fillable list
                // that ever included them would be a privilege-escalation bug
                // one careless `create()` away.
                $user->setDisplayName((string) $request->string('display_name'));
                $user->email = (string) $request->string('email');
                $user->password = (string) $request->string('password');
                $user->role = UserRole::Player;
                $user->save();

                $code = $this->verification->issue($user);

                // Queued, `afterCommit`: a code for a row a rollback removes is
                // worse than a slightly later mail.
                $user->notify(new VerifyEmailNotification($code));

                $token = $this->sessions->issue($user, $deviceLabel, twoFactorSatisfied: false);

                // Attach it, so the projection describes the session this
                // response created rather than reporting none.
                $user->withAccessToken($token->accessToken);

                return ['user' => $user, 'token' => $token->plainTextToken];
            });
        } catch (UniqueConstraintViolationException) {
            // The validator already checked availability, but two simultaneous
            // registrations can both pass validation and only one can commit.
            // The index is the guarantee; this turns losing that race into the
            // same answer the validator would have given.
            throw ApiProblem::of(
                ProblemCode::Conflict,
                'That email address or display name was just taken. Try again.'
            );
        }

        $user = $result['user'];

        AuthLog::forUser(AuthLog::REGISTERED, $user, $request);
        AuthLog::forUser(AuthLog::EMAIL_VERIFICATION_SENT, $user, $request, ['trigger' => 'registration']);

        return AuthenticatedUserResource::make($user)
            ->additional([
                // `status` is top-level, not inside `meta`: it is the
                // discriminator a client branches on, and on /auth/login it
                // decides the response *shape*. A discriminator nested inside
                // another object cannot be declared in OpenAPI, so it lives at
                // the top of every envelope that has one — consistently, rather
                // than only where the union needs it.
                'status' => 'authenticated',
                'meta' => [
                    'token' => $result['token'],
                    'email_verification' => [
                        'resend_available_in' => $this->verification->cooldownRemaining($user),
                    ],
                ],
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
