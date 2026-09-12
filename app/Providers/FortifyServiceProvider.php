<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\BreachCheck;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;
use Laravel\Sanctum\Sanctum;

/**
 * Wires Fortify and Sanctum into this application's shape.
 *
 * Four things happen here. Three of them close a door that is open by default;
 * the fourth replaces a default whose failure mode is invisible.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * 1. Fortify registers no routes.
         *
         * Its route layer is session-based and answers with redirects, which is
         * wrong for a token-authenticated API that a native client must be able
         * to call (ADR-0005 §5). Leaving it on would publish a parallel,
         * untested authentication surface alongside ours — including a login
         * endpoint that bypasses the two-factor ability this application's
         * admin gate depends on.
         *
         * Called in register(), before the package provider boots and reads it.
         */
        Fortify::ignoreRoutes();

        /*
         * 2. Passkeys register no routes either.
         *
         * laravel/passkeys arrives as a Fortify dependency. Passkeys are
         * explicitly out of scope (docs/security/two-factor.md §7), and an
         * unused authentication surface is still an authentication surface.
         */
        Passkeys::ignoreRoutes();

        /*
         * 3. The compromised-password check is bounded and observable.
         *
         * The framework binds `NotPwnedVerifier` with a 30-second timeout and
         * swallows its own transport failures, so an outage reads exactly like a
         * clean answer and a hung provider holds a worker for half a minute on
         * three unauthenticated endpoints. The subclass keeps the mechanism and
         * the fail-open policy, and fixes both of those. See App\Support\BreachCheck.
         */
        $this->app->singleton(UncompromisedVerifier::class, fn ($app) => new BreachCheck(
            $app[HttpFactory::class],
            config()->integer('auth.breach_check.timeout'),
        ));
    }

    public function boot(): void
    {
        /*
         * 4. Sanctum uses this application's token model.
         *
         * The subclass adds the opaque `public_id` the session endpoints
         * address, and `satisfiedTwoFactor()`. Without this line Sanctum
         * resolves its own model, `currentAccessToken()` returns that, and every
         * admin request fails on a method the base class does not have — at
         * runtime, on the privileged path.
         */
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
