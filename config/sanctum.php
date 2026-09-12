<?php

declare(strict_types=1);
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful domains — deliberately empty
    |--------------------------------------------------------------------------
    |
    | Sanctum has two modes. "SPA mode" authenticates a browser with a session
    | cookie when the request's origin appears in this list; token mode
    | authenticates any caller with a bearer credential.
    |
    | **This API uses token mode only** (ADR-0005). The list is empty on purpose,
    | and that is a security property rather than a configuration gap:
    |
    |  - The browser never talks to this service. It talks to the Nuxt BFF, which
    |    holds the token server-side and attaches it upstream. A session-cookie
    |    path here would be a second way in that nothing uses and nobody tests.
    |
    |  - SPA mode requires the frontend and the API to share a parent domain and
    |    to exchange CSRF cookies cross-origin. Both constraints exist to serve
    |    browsers, and both would be inherited by a future native client, which
    |    has no cookies at all.
    |
    |  - With no stateful domain the API is genuinely origin-agnostic, so the
    |    native path ADR-0005 §5 preserves is not a parallel implementation — it
    |    is this one, called by a different client.
    |
    | Adding a domain here re-enables cookie authentication against this service.
    | Do not, without revisiting ADR-0005.
    |
    */

    'stateful' => array_filter(explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Guards consulted before the token
    |--------------------------------------------------------------------------
    |
    | Empty for the same reason. Sanctum's default is ['web'], which makes the
    | session guard the first thing tried on every request. No session guard is
    | in play here, and leaving it configured would mean an authenticated web
    | session — created by some future Blade or Livewire addition — silently
    | satisfied `auth:sanctum`, with no token and therefore no record of whether
    | a two-factor challenge was passed. The admin gate depends on that record.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Sanctum's own routes — off
    |--------------------------------------------------------------------------
    |
    | Sanctum publishes GET /sanctum/csrf-cookie for SPA mode. With SPA mode off
    | it serves nothing, but it is still a route that starts a session and sets a
    | cookie on an API that otherwise has neither. Removed rather than left
    | dangling.
    |
    */

    'routes' => false,

    /*
    |--------------------------------------------------------------------------
    | Absolute token lifetime, in minutes
    |--------------------------------------------------------------------------
    |
    | The backstop: a token older than this is refused however recently it was
    | used. Thirty days.
    |
    | This is the *absolute* timeout. The **idle** timeout lives in the BFF, next
    | to the cookie it governs, because the BFF is what observes when the browser
    | last made a request. Two tiers, each owned by the layer that can measure
    | the thing it bounds.
    |
    */

    'expiration' => (int) env('SANCTUM_EXPIRATION', 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Token prefix
    |--------------------------------------------------------------------------
    |
    | A recognisable prefix lets secret scanners spot a leaked Purrenade token in
    | a commit or a log and report it. Costs nothing; occasionally saves an
    | account.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'prrn_'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Used only by SPA mode, which is off. Left at the framework defaults so that
    | turning SPA mode on would be one deliberate change rather than several.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
