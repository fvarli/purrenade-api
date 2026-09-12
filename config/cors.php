<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing — off
    |--------------------------------------------------------------------------
    |
    | `allowed_origins` is deliberately **empty**, which stops the middleware
    | emitting any `Access-Control-*` header at all.
    |
    | The package default is `['*']`. That is wrong for this service, and it was
    | being sent: ADR-0005 §4 says the browser talks to **one origin** — the Nuxt
    | application — so no browser ever calls this API cross-origin, and a
    | wildcard advertised the opposite.
    |
    | It was not directly exploitable, because this API accepts no cookie and
    | authenticates only with a bearer token that a cross-origin page does not
    | have. That is exactly why it is worth removing now rather than later: it is
    | a latent misconfiguration that becomes a real vulnerability the moment
    | anything cookie-based is added, and by then nobody would remember the
    | wildcard was never intended.
    |
    | A future native client is unaffected — native HTTP clients do not enforce
    | CORS, and it exists only to constrain browsers.
    |
    | If a genuine cross-origin browser consumer ever appears, name its origin
    | here explicitly. Never restore the wildcard, and never pair one with
    | `supports_credentials`.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
