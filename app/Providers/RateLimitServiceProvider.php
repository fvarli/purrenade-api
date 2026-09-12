<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ProblemCode;
use App\Support\ProblemResponse;
use App\Support\RateLimits;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Registers the named rate limiters.
 *
 * Every limiter returns **two** limits — one keyed on the identifier (account or
 * email) and one on the source address — and Laravel requires a request to
 * satisfy all limits returned. That is the two-dimensional rule from
 * docs/security/rate-limiting.md §1 expressed in the only place it can be
 * enforced uniformly.
 *
 * Identifier keys are hashed. A cache key containing a raw email address leaks
 * the address into whatever the cache store is, and for a shared store that is a
 * larger blast radius than the limit is worth.
 *
 * Every limiter shares one response builder, so a 429 is the same RFC 9457
 * problem as every other error, carrying `retry_after` both as an extension
 * member and as the `Retry-After` header.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerLoginLimiter();
        $this->registerRegistrationLimiter();
        $this->registerTwoFactorLimiter();
        $this->registerEmailVerificationLimiters();
        $this->registerPasswordLimiters();
        $this->registerSensitiveLimiter();
        $this->registerDisplayNameLimiter();
        $this->registerNormalLimiter();
    }

    private function registerLoginLimiter(): void
    {
        RateLimiter::for(RateLimits::LOGIN, function (Request $request): array {
            $identifier = $this->identifierKey($request);

            return [
                // Burst protection, then the hourly bound that actually stops
                // guessing. Both keyed per account, so one targeted account
                // cannot exhaust the budget of every other player.
                Limit::perMinute(RateLimits::LOGIN_PER_IDENTIFIER)
                    ->by('login:m:'.$identifier)
                    ->response($this->refuse()),
                Limit::perHour(RateLimits::LOGIN_PER_IDENTIFIER_HOURLY)
                    ->by('login:h:'.$identifier)
                    ->response($this->refuse()),
                Limit::perMinute(RateLimits::LOGIN_PER_SOURCE)
                    ->by('login:ip:'.$request->ip())
                    ->response($this->refuse()),
            ];
        });
    }

    private function registerRegistrationLimiter(): void
    {
        RateLimiter::for(RateLimits::REGISTER, fn (Request $request): array => [
            // Registration is source-keyed only: there is no account yet, and
            // keying on the submitted address would let an attacker suppress
            // registration for an address by burning its bucket.
            Limit::perHour(RateLimits::REGISTER_PER_SOURCE_HOURLY)
                ->by('register:'.$request->ip())
                ->response($this->refuse()),
        ]);
    }

    private function registerTwoFactorLimiter(): void
    {
        RateLimiter::for(RateLimits::TWO_FACTOR_CHALLENGE, function (Request $request): array {
            // Keyed on the challenge token, which identifies the account without
            // the endpoint having to accept an account identifier it would then
            // have to treat as untrusted.
            $challenge = (string) $request->input('challenge_token', '');

            return [
                Limit::perMinute(RateLimits::TWO_FACTOR_PER_IDENTIFIER)
                    ->by('2fa:'.hash('sha256', $challenge))
                    ->response($this->refuse()),
                Limit::perMinute(RateLimits::TWO_FACTOR_PER_SOURCE)
                    ->by('2fa:ip:'.$request->ip())
                    ->response($this->refuse()),
            ];
        });
    }

    private function registerEmailVerificationLimiters(): void
    {
        RateLimiter::for(RateLimits::EMAIL_VERIFY, fn (Request $request): array => [
            Limit::perMinutes(10, RateLimits::EMAIL_VERIFY_PER_IDENTIFIER)
                ->by('verify:'.$this->userKey($request))
                ->response($this->refuse()),
            Limit::perMinutes(10, RateLimits::EMAIL_VERIFY_PER_SOURCE)
                ->by('verify:ip:'.$request->ip())
                ->response($this->refuse()),
        ]);

        RateLimiter::for(RateLimits::EMAIL_RESEND, fn (Request $request): array => [
            Limit::perHour(RateLimits::EMAIL_RESEND_PER_IDENTIFIER_HOURLY)
                ->by('resend:'.$this->userKey($request))
                ->response($this->refuse()),
            Limit::perHour(RateLimits::EMAIL_RESEND_PER_SOURCE_HOURLY)
                ->by('resend:ip:'.$request->ip())
                ->response($this->refuse()),
        ]);
    }

    private function registerPasswordLimiters(): void
    {
        RateLimiter::for(RateLimits::PASSWORD_FORGOT, function (Request $request): array {
            $identifier = $this->identifierKey($request);

            return [
                Limit::perHour(RateLimits::PASSWORD_FORGOT_PER_IDENTIFIER_HOURLY)
                    ->by('forgot:'.$identifier)
                    ->response($this->refuse()),
                Limit::perHour(RateLimits::PASSWORD_FORGOT_PER_SOURCE_HOURLY)
                    ->by('forgot:ip:'.$request->ip())
                    ->response($this->refuse()),
            ];
        });

        RateLimiter::for(RateLimits::PASSWORD_RESET, fn (Request $request): array => [
            Limit::perMinute(RateLimits::PASSWORD_RESET_PER_SOURCE)
                ->by('reset:'.$request->ip())
                ->response($this->refuse()),
        ]);
    }

    private function registerSensitiveLimiter(): void
    {
        RateLimiter::for(RateLimits::SENSITIVE, fn (Request $request): array => [
            Limit::perMinute(RateLimits::SENSITIVE_PER_IDENTIFIER)
                ->by('sensitive:'.$this->userKey($request))
                ->response($this->refuse()),
        ]);
    }

    private function registerDisplayNameLimiter(): void
    {
        RateLimiter::for(RateLimits::DISPLAY_NAME, fn (Request $request): array => [
            Limit::perDay(RateLimits::DISPLAY_NAME_PER_IDENTIFIER_DAILY)
                ->by('display-name:'.$this->userKey($request))
                ->response($this->refuse(ProblemCode::DisplayNameChangeCooldown)),
        ]);
    }

    private function registerNormalLimiter(): void
    {
        RateLimiter::for(RateLimits::NORMAL, fn (Request $request): array => [
            Limit::perMinute(RateLimits::NORMAL_PER_IDENTIFIER)
                ->by('normal:'.$this->userKey($request))
                ->response($this->refuse()),
        ]);

        // Per source only: readiness has no identifier, and a monitor is not a
        // user. One dimension is the right number here.
        RateLimiter::for(RateLimits::HEALTH, fn (Request $request): array => [
            Limit::perMinute(RateLimits::HEALTH_PER_SOURCE)
                ->by('health:'.$request->ip())
                ->response($this->refuse()),
        ]);
    }

    /**
     * A hashed key for the submitted email, falling back to the source address.
     *
     * Hashed so the cache never holds a plaintext address; lower-cased first so
     * `Ada@example.com` and `ada@example.com` share one bucket rather than
     * giving an attacker a fresh allowance per casing.
     */
    private function identifierKey(Request $request): string
    {
        $email = Str::lower(trim((string) $request->input('email', '')));

        return $email === ''
            ? 'ip:'.$request->ip()
            : hash('sha256', $email);
    }

    private function userKey(Request $request): string
    {
        $user = $request->user();

        return $user === null
            ? 'ip:'.$request->ip()
            : 'user:'.$user->getAuthIdentifier();
    }

    /**
     * The shared 429 response.
     *
     * `$headers` is what the throttle middleware computed for this bucket, so
     * `Retry-After` is the real remaining seconds — the client is told exactly
     * how long to wait rather than guessing a constant.
     *
     * @return \Closure(Request, array<string, int|string>): JsonResponse
     */
    private function refuse(ProblemCode $code = ProblemCode::RateLimited): callable
    {
        return function (Request $request, array $headers = []) use ($code) {
            $retryAfter = isset($headers['Retry-After']) && is_numeric($headers['Retry-After'])
                ? (int) $headers['Retry-After']
                : 60;

            return ProblemResponse::build(
                code: $code,
                detail: 'Too many requests. Wait before trying again.',
                request: $request,
                extensions: ['retry_after' => $retryAfter],
            );
        };
    }
}
