<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\SessionStateController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Progression\TutorialController;
use App\Support\RateLimits;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — /api/v1
|--------------------------------------------------------------------------
|
| Versioned from the first endpoint, never retrofitted. See
| docs/api/api-conventions.md §1.
|
| Every endpoint that exists here must also exist in
| docs/api/openapi.draft.yaml — an undocumented endpoint is invisible to the
| only mechanism keeping the two repositories in step, and CI fails the build if
| one is missing.
|
| Routes point at controllers rather than closures so `php artisan route:cache`
| keeps working: a closure route cannot be serialised, and the failure would
| only appear during a production deploy.
|
|--------------------------------------------------------------------------
| The access tiers
|--------------------------------------------------------------------------
|
| Access is decided by **group membership**, not by per-route annotation. That
| is what docs/security/authorization-and-roles.md §5 means by "structural": a
| route added inside a group inherits its gate, whereas a route that has to
| remember `->middleware('verified')` is one forgotten line away from public.
|
|   public              no credential
|   auth:sanctum        a session token — reachable while unverified
|   + verified          an account whose address is confirmed (AUTH-1)
|   + admin             administrator, verified, enrolled, and this session
|                       passed a two-factor challenge
|
| The unverified tier is exactly four endpoints: `me`, `email/verify`,
| `email/verify/resend` and `logout`. Enough to learn that verification is
| required, to complete it, and to leave. Everything else — including two-factor
| enrolment and session management — waits for a confirmed address.
|
*/

Route::prefix('v1')->group(function (): void {

    // Readiness: the process is up AND can reach PostgreSQL. 503 when it cannot.
    //
    // Throttled like every other public route. It was the one exception, and it
    // is the one public route that touches the database — an unauthenticated
    // caller could turn a cheap GET into unbounded query load. The limit is
    // generous enough for any monitor: a probe that needs more than this is
    // misconfigured.
    Route::get('/health', HealthController::class)
        ->middleware('throttle:'.RateLimits::HEALTH)
        ->name('health');

    /*
    |----------------------------------------------------------------------
    | Public
    |----------------------------------------------------------------------
    |
    | Every route here is rate-limited, because every route here can be called
    | by anyone. The limiters are two-dimensional — per identifier and per
    | source — and defined in App\Providers\RateLimitServiceProvider.
    |
    */
    Route::post('/auth/register', RegisterController::class)
        ->middleware('throttle:'.RateLimits::REGISTER)
        ->name('auth.register');

    Route::post('/auth/login', LoginController::class)
        ->middleware('throttle:'.RateLimits::LOGIN)
        ->name('auth.login');

    // Second factor. The only endpoint that mints a session carrying the
    // `two-factor` ability.
    Route::post('/auth/2fa/challenge', TwoFactorChallengeController::class)
        ->middleware('throttle:'.RateLimits::TWO_FACTOR_CHALLENGE)
        ->name('auth.two-factor.challenge');

    // Email-sending. The strictest limits in the product: an unlimited endpoint
    // that mails a caller-supplied address is a spam relay.
    Route::post('/auth/password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:'.RateLimits::PASSWORD_FORGOT)
        ->name('auth.password.forgot');

    Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:'.RateLimits::PASSWORD_RESET)
        ->name('auth.password.reset');

    /*
    |----------------------------------------------------------------------
    | Authenticated — verification not yet required
    |----------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function (): void {

        Route::get('/auth/me', [SessionStateController::class, 'me'])
            ->name('auth.me');

        Route::post('/auth/logout', [SessionStateController::class, 'logout'])
            ->name('auth.logout');

        Route::post('/auth/email/verify', [EmailVerificationController::class, 'verify'])
            ->middleware('throttle:'.RateLimits::EMAIL_VERIFY)
            ->name('auth.email.verify');

        Route::post('/auth/email/verify/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:'.RateLimits::EMAIL_RESEND)
            ->name('auth.email.resend');
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated and verified
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {

        Route::put('/auth/password', [PasswordResetController::class, 'update'])
            ->middleware('throttle:'.RateLimits::SENSITIVE)
            ->name('auth.password.update');

        Route::get('/auth/2fa', [TwoFactorController::class, 'show'])
            ->name('auth.two-factor.show');

        Route::post('/auth/2fa/enable', [TwoFactorController::class, 'enable'])
            ->middleware('throttle:'.RateLimits::SENSITIVE)
            ->name('auth.two-factor.enable');

        Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm'])
            ->middleware('throttle:'.RateLimits::SENSITIVE)
            ->name('auth.two-factor.confirm');

        Route::post('/auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
            ->middleware('throttle:'.RateLimits::SENSITIVE)
            ->name('auth.two-factor.recovery-codes');

        // 403 for an administrator: 2FA is mandatory for that role and cannot be
        // opted out of. Refused in the service as well as here.
        Route::post('/auth/2fa/disable', [TwoFactorController::class, 'disable'])
            ->middleware('throttle:'.RateLimits::SENSITIVE)
            ->name('auth.two-factor.disable');

        Route::get('/auth/sessions', [SessionController::class, 'index'])
            ->middleware('throttle:'.RateLimits::NORMAL)
            ->name('auth.sessions.index');

        // {session} is the token's opaque public_id, never its database key.
        // A foreign id returns 404, not 403 — see the controller.
        Route::delete('/auth/sessions/{session}', [SessionController::class, 'destroy'])
            ->middleware('throttle:'.RateLimits::NORMAL)
            ->name('auth.sessions.destroy');

        Route::delete('/auth/sessions', [SessionController::class, 'destroyOthers'])
            ->middleware('throttle:'.RateLimits::SENSITIVE)
            ->name('auth.sessions.destroy-others');

        Route::patch('/profile', [ProfileController::class, 'updateDisplayName'])
            ->middleware('throttle:'.RateLimits::DISPLAY_NAME)
            ->name('profile.update');

        /*
         * The first-run tutorial is finished — or skipped, which the product
         * counts as the same thing and the server is deliberately not told
         * apart.
         *
         * **Verified, not merely authenticated.** The contract table says
         * "authenticated", and placing it here is a deliberate, documented
         * narrowing rather than a drift: the unverified tier above is exactly
         * four endpoints by design, and the tutorial itself sits behind the
         * frontend's `verified` guard, so an unverified caller could never
         * legitimately reach it. Admitting a fifth endpoint to that tier would
         * cost more than the contract row it satisfies.
         * `docs/api/endpoints/progression.md` records the same narrowing, so
         * the route and the document cannot disagree.
         *
         * No `Idempotency-Key`: the controller's conditional UPDATE makes a
         * replay a no-op by construction, rather than by bookkeeping.
         */
        Route::post('/progression/tutorial', [TutorialController::class, 'complete'])
            ->middleware('throttle:'.RateLimits::NORMAL)
            ->name('progression.tutorial');

        /*
        |------------------------------------------------------------------
        | Administrator
        |------------------------------------------------------------------
        |
        | The `admin` middleware checks four things on every request: role,
        | verified address, enrolled second factor, and a session that actually
        | passed a two-factor challenge. Applied to the group, so a route added
        | here inherits all four — which is the whole point.
        |
        | The full path is written out rather than using ->prefix('admin') so
        | that CI's routes-in-contract check reads the same path the OpenAPI
        | document declares.
        |
        */
        Route::middleware('admin')->group(function (): void {
            Route::get('/admin/overview', OverviewController::class)
                ->middleware('throttle:'.RateLimits::NORMAL)
                ->name('admin.overview');
        });
    });
});
