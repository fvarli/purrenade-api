<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is an API-only service: every error is JSON, never an HTML page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true,
        );
    })->create();
