<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureTrustedProxies();
    }

    /**
     * Teach the application which reverse proxy it sits behind.
     *
     * TLS terminates at a reverse proxy, which forwards a plain HTTP hop. Without
     * this the application reports the request as insecure and generates http://
     * URLs for a request the browser made over HTTPS.
     *
     * Configured here rather than in bootstrap/app.php for two reasons: that
     * closure runs before the configuration repository is bound, and env() must
     * never be read outside config/ — after `php artisan config:cache` the .env
     * file is no longer consulted, so a stray env() call would silently return
     * null and empty the trusted list in production only.
     *
     * The proxy list is never '*'. Trusting every proxy would let any client
     * forge X-Forwarded-For and spoof both its address and the request scheme.
     */
    private function configureTrustedProxies(): void
    {
        /** @var list<string> $proxies */
        $proxies = config('proxy.trusted_proxies', []);

        if ($proxies === []) {
            return;
        }

        TrustProxies::at($proxies);

        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
        );
    }
}
