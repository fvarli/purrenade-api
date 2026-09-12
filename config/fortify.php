<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Fortify as a capability provider, not as a UI
|--------------------------------------------------------------------------
|
| Fortify ships two things: authentication *machinery* (actions, the TOTP
| provider, recovery-code generation, the password-broker wiring, validation
| rules) and an HTTP *layer* that exposes it through session-based, Blade- or
| Inertia-oriented routes.
|
| This project takes the first and declines the second, and the reason is
| ADR-0005 rather than taste. Fortify's routes park login state in the HTTP
| session (`login.id` between the password step and the two-factor step) and
| answer with redirects. This API is token-authenticated and stateless
| specifically so a future native client can call the same endpoints, and a
| native client has no session and follows no redirect. So routing is ours —
| App\Http\Controllers\Auth — and it calls Fortify's primitives underneath.
|
| What that split buys: no reimplementation of TOTP arithmetic, recovery-code
| formats, encrypted-secret storage, or breach-checked password rules. What it
| costs: our own login, challenge and logout endpoints, which we need anyway
| because their responses are RFC 9457 and their credential is a token.
|
| The responsibility split is documented in
| docs/architecture/auth-architecture.md §3.
|
| Route registration is disabled in App\Providers\FortifyServiceProvider.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Guard and broker
    |--------------------------------------------------------------------------
    |
    | The guard is irrelevant while Fortify's routes are off — nothing here
    | authenticates a request. The *broker* is not: Fortify's password-reset
    | plumbing and the `current_password` rule both resolve through it, so it
    | must name the real user provider.
    |
    */

    'guard' => 'web',

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username field
    |--------------------------------------------------------------------------
    |
    | Purrenade signs in with an email address. `display_name` is public,
    | user-chosen and changeable, which makes it a poor credential.
    |
    */

    'username' => 'email',

    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Views and routes — off
    |--------------------------------------------------------------------------
    |
    | This is an API-only service: no Blade, no login page, no redirect targets.
    |
    */

    'views' => false,

    'home' => null,

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Empty, not ['web']. Fortify's routes are not registered, and the `web`
    | group would start a session on requests that have no use for one.
    |
    */

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Limiters
    |--------------------------------------------------------------------------
    |
    | Null because Fortify's routes are not registered. Rate limiting for the
    | real endpoints is declared per route against the named limiters in
    | App\Providers\RateLimitServiceProvider, where each limit is two-dimensional
    | (per identifier and per source) as docs/security/rate-limiting.md requires.
    |
    */

    'limiters' => [
        'login' => null,
        'passkeys' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Enabled features gate Fortify's *actions*, which this application does
    | call. Nothing is switched on speculatively.
    |
    | Not enabled, and why:
    |
    |  - `registration`, `resetPasswords`, `emailVerification` — these control
    |    Fortify's route layer, which is off. The flows exist; they are our
    |    controllers over Laravel's password broker and MustVerifyEmail.
    |
    |  - `updateProfileInformation`, `updatePasswords` — same: our endpoints,
    |    with re-authentication shaped for a token API.
    |
    |  - `passkeys` — out of scope for M2 and explicitly deferred
    |    (docs/security/two-factor.md §7). The laravel/passkeys package arrives
    |    as a Fortify dependency; leaving the feature off is what keeps it
    |    inert. Its routes are disabled alongside Fortify's.
    |
    */

    'features' => [
        Features::twoFactorAuthentication([
            // Possession must be proven before 2FA becomes active. Without this
            // a mis-scanned QR code locks the player out of their own account,
            // with no self-service way back in.
            'confirm' => true,

            // Fortify's own password-confirmation window is session-based and
            // therefore unusable here. Re-authentication is per request, by
            // `current_password` on the sensitive call itself — see
            // App\Http\Requests\Auth\ConfirmsPassword.
            'confirmPassword' => false,

            // One time step of tolerance either side of the current one: enough
            // for real clock drift between a phone and the server, tight enough
            // that the window a code stays valid for does not grow. Replay
            // inside that window is refused separately and per user, in
            // App\Services\Auth\TwoFactorService.
            'window' => 1,

            // 16 base32 characters — 80 bits. The standard length every
            // authenticator app handles, and far beyond guessing.
            'secret-length' => 16,
        ]),
    ],

];
