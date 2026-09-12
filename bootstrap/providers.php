<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,

    // Disables Fortify's and Passkeys' route layers, and points Sanctum at this
    // application's token model. All three are doors that are open by default.
    FortifyServiceProvider::class,

    // The named rate limiters. Registered in a provider of their own because
    // every authentication endpoint depends on one existing, and a missing
    // limiter name is a 500 on the login path.
    RateLimitServiceProvider::class,
];
