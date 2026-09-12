<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Refuse to boot a production deployment on a development default.
 *
 * Every value checked here is safe locally and dangerous in production, which
 * is exactly the combination that survives a deployment unnoticed: nothing
 * fails, nothing warns, and the first symptom arrives as an incident.
 *
 * `MAIL_MAILER=log` is the sharpest of them. It does not error — it writes the
 * verification code to a log file and reports success. Registration appears to
 * work and no account can ever be verified, and the only evidence is an absence.
 *
 * The guard runs on `production` only. A staging or local environment keeps
 * every convenience; the point is that promoting one to production without
 * changing its configuration should stop, loudly, at boot rather than serve
 * traffic in a state nobody chose.
 *
 * Deliberately not a warning. A warning in a deployment log is a warning nobody
 * reads.
 */
final class EnvironmentGuard
{
    /**
     * @return list<string> the reasons this configuration must not run in
     *                      production, empty when it may
     */
    public static function violations(): array
    {
        $violations = [];

        if (config('app.debug') === true) {
            $violations[] = 'APP_DEBUG is true, which would return framework internals to clients.';
        }

        if (config('mail.default') === 'log') {
            $violations[] = 'MAIL_MAILER is "log": verification and password-reset mail would be written to a file and silently never delivered.';
        }

        if (config('queue.default') === 'sync') {
            $violations[] = 'QUEUE_CONNECTION is "sync": queued mail would run inside the request, so a slow provider becomes a slow signup.';
        }

        foreach (['app.url' => config('app.url'), 'frontend.url' => config('frontend.url')] as $key => $url) {
            if (is_string($url) && preg_match('/\.(test|local|localhost)(:\d+)?$/', rtrim($url, '/')) === 1) {
                $violations[] = sprintf('%s is a development hostname (%s).', $key, $url);
            }
        }

        return $violations;
    }

    /**
     * @throws RuntimeException when production is configured for development
     */
    public static function enforce(string $environment): void
    {
        if ($environment !== 'production') {
            return;
        }

        $violations = self::violations();

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            "Refusing to boot: this is APP_ENV=production running a development configuration.\n  - "
            .implode("\n  - ", $violations)
            ."\nFix the configuration, or run under a non-production APP_ENV."
        );
    }
}
