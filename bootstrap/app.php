<?php

declare(strict_types=1);

use App\Exceptions\ApiProblem;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Support\ProblemResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        // No `health:` route. Laravel's `/up` renders a Blade view — HTML, from
        // a service whose root route says "no web access allowed" — and it was
        // in no contract, because the routes-in-contract gate scanned route
        // files and never saw a route the framework registered. The readiness
        // endpoint is `GET /api/v1/health`, which reports the database too.
        //
        // The web root sits above the /api prefix, so it cannot live in the api
        // routes file. `then:` registers it without introducing a `web:` file
        // and its session/CSRF middleware group, which this API has no use for.
        then: function (): void {
            require __DIR__.'/../routes/root.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies are configured in AppServiceProvider::boot(), not here:
        // this closure runs before the configuration repository is bound, so
        // config() is unavailable — and env() must never be read outside config/.

        // First in the API group, so a correlation id exists before anything can
        // fail. One assigned after a failure is of no use to anybody.
        $middleware->prependToGroup('api', AssignCorrelationId::class);

        $middleware->alias([
            // Replaces Laravel's own `auth`, whose `redirectTo()` calls
            // `route('login')` for any request that does not ask for JSON —
            // which throws inside the middleware in an API-only application and
            // turns a 401 into a 500. See the middleware for the full reason.
            'auth' => Authenticate::class,

            // Replaces Laravel's own `verified`, which answers with a bare
            // `{"message": ...}`. The refusal has to be an RFC 9457 problem with
            // a stable code, or the client needs a special case for one error.
            'verified' => EnsureEmailIsVerified::class,

            // Role + verified address + enrolled second factor + a session that
            // actually passed a challenge. See the middleware for why the last
            // one is not the same question as the third.
            'admin' => EnsureAdministrator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is an API-only service: every error is JSON, never an HTML page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true,
        );

        /*
         * One renderer for every error (API-1).
         *
         * RFC 9457 `application/problem+json`, built in one place, so a client
         * that can parse one error can parse them all — and so no endpoint can
         * quietly invent a different shape. `ApiProblem` is how application code
         * asks for a specific one; anything else is mapped, and anything
         * unrecognised becomes a bare 500 whose detail comes from us rather than
         * from an exception message that may contain SQL or a file path.
         *
         * This holds with APP_DEBUG=true as well. A response shape that changes
         * between environments is a shape the test suite cannot pin down.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            // No path condition, deliberately.
            //
            // An earlier version scoped this to `api/*` and `/`, which left the
            // rest of the host to Laravel's default handler. `/anything-else`
            // answered with `{"exception", "file", "line", "trace"}` — a full
            // stack trace and absolute filesystem paths under APP_DEBUG, and a
            // bare `{"message"}` no client could parse without a special case
            // otherwise. The docblock above claimed the opposite was true.
            //
            // The whole host is the API. There is no second surface to exclude,
            // so an exclusion could only ever be a hole.

            // HttpResponseException already carries a finished response — it is
            // how the throttle middleware returns its own 429, which is already
            // an RFC 9457 problem. Rewriting it here turned every rate-limit
            // refusal into a 500.
            if ($e instanceof HttpResponseException) {
                return null;
            }

            return ProblemResponse::fromThrowable($e, $request);
        });

        // An ApiProblem is a control-flow signal, not an incident. Reporting it
        // would fill the log with expected outcomes — every wrong password,
        // every rate limit — and bury the failures that matter.
        $exceptions->dontReport([
            ApiProblem::class,
        ]);
    })->create();
