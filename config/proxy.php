<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Reverse Proxies
    |--------------------------------------------------------------------------
    |
    | TLS terminates at a reverse proxy, which forwards a plain HTTP hop to the
    | application. Without trusting that proxy, Laravel reports the request as
    | insecure and generates http:// URLs for a request the browser made over
    | HTTPS.
    |
    | Locally this is Nginx on loopback. Production defines its own topology
    | through TRUSTED_PROXIES rather than inheriting a developer machine's.
    |
    | This value is read from the environment HERE, in a config file, and never
    | from bootstrap/app.php: after `php artisan config:cache` the .env file is
    | no longer consulted, so a stray env() call outside config/ would silently
    | return null — emptying this list and breaking HTTPS detection in
    | production only.
    |
    | Never set this to '*'. Trusting every proxy lets any client forge
    | X-Forwarded-For and spoof both its address and the request scheme.
    |
    */

    'trusted_proxies' => array_values(array_filter(
        array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1'))
        ),
        static fn (string $proxy): bool => $proxy !== ''
    )),

];
