<?php

declare(strict_types=1);

use App\Support\EnvironmentGuard;

/**
 * Production must not boot on a development default.
 *
 * Each value below is correct locally and wrong in production, which is exactly
 * the combination that survives a deployment: nothing errors, nothing warns, and
 * the first symptom is an incident.
 *
 * `MAIL_MAILER=log` is the one worth naming. It does not fail — it writes the
 * verification code to a file and reports success — so registration appears to
 * work and no account can ever be verified. The evidence is an absence, which is
 * the hardest kind to notice.
 */
it('lets a correctly configured production boot', function (): void {
    config()->set([
        'app.debug' => false,
        'mail.default' => 'smtp',
        'queue.default' => 'redis',
        'app.url' => 'https://api.purrenade.com',
        'frontend.url' => 'https://purrenade.com',
    ]);

    expect(EnvironmentGuard::violations())->toBe([])
        ->and(fn () => EnvironmentGuard::enforce('production'))->not->toThrow(RuntimeException::class);
});

it('refuses to boot production on a development default', function (string $key, mixed $value): void {
    config()->set([
        'app.debug' => false,
        'mail.default' => 'smtp',
        'queue.default' => 'redis',
        'app.url' => 'https://api.purrenade.com',
        'frontend.url' => 'https://purrenade.com',
    ]);

    config()->set($key, $value);

    expect(EnvironmentGuard::violations())->not->toBe([]);

    expect(fn () => EnvironmentGuard::enforce('production'))
        ->toThrow(RuntimeException::class);
})->with([
    'debug mode' => ['app.debug', true],
    'mail to a log file' => ['mail.default', 'log'],
    'queue running inline' => ['queue.default', 'sync'],
    'a .test api url' => ['app.url', 'https://api.purrenade.test'],
    'a .test frontend url' => ['frontend.url', 'https://purrenade.test'],
    'a localhost frontend url' => ['frontend.url', 'https://purrenade.localhost'],
]);

it('leaves every other environment alone', function (string $environment): void {
    // Local development keeps all of it. The guard exists to stop a promotion
    // that forgot to change anything, not to make development awkward.
    config()->set(['app.debug' => true, 'mail.default' => 'log', 'queue.default' => 'sync']);

    expect(EnvironmentGuard::violations())->not->toBe([])
        ->and(fn () => EnvironmentGuard::enforce($environment))->not->toThrow(RuntimeException::class);
})->with(['local', 'testing', 'staging']);

it('names every violation, not just the first', function (): void {
    config()->set([
        'app.debug' => true,
        'mail.default' => 'log',
        'queue.default' => 'sync',
        'app.url' => 'https://api.purrenade.test',
        'frontend.url' => 'https://purrenade.test',
    ]);

    // An operator fixing one line at a time, redeploying between each, is a
    // slow way to learn five things.
    expect(EnvironmentGuard::violations())->toHaveCount(5);
});

it('is wired into the application boot, not merely available', function (): void {
    $source = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($source)->toContain('EnvironmentGuard::enforce');
});
