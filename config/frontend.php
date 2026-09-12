<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend origin
    |--------------------------------------------------------------------------
    |
    | Where a player's browser actually is. This API sends one kind of link —
    | the password-reset link — and that link must open a page the *frontend*
    | serves, not a page this service does not have.
    |
    | Configured rather than hardcoded because the .test hostname is a local
    | development fact and nothing more. Production sets FRONTEND_URL to its own
    | origin, and the reset mail follows without a code change.
    |
    | This is the only place in the backend that knows the frontend exists, which
    | keeps ADR-0001's repository independence intact: a configurable origin is
    | not a dependency on the other repository's layout.
    |
    */

    'url' => env('FRONTEND_URL', 'https://purrenade.test'),

    /*
    |--------------------------------------------------------------------------
    | Password reset path
    |--------------------------------------------------------------------------
    |
    | The frontend route that accepts a reset token. Kept separate from the
    | origin so an environment can move the page without restating the host.
    |
    */

    'password_reset_path' => env('FRONTEND_PASSWORD_RESET_PATH', '/auth/reset-password'),

];
