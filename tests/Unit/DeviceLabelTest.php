<?php

declare(strict_types=1);

use App\Support\DeviceLabel;

/**
 * Deliberately lossy. The session list exists so a player can recognise their
 * own devices and spot one they do not; a precise fingerprint would do that
 * marginally better while creating a tracking identifier.
 */
it('labels common browser and platform combinations', function (string $userAgent, string $expected): void {
    expect(DeviceLabel::fromUserAgent($userAgent))->toBe($expected);
})->with([
    'Chrome on Linux' => [
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        'Chrome on Linux',
    ],
    'Chrome on Android' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
        'Chrome on Android',
    ],
    'Safari on iPhone' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
        'Safari on iPhone',
    ],
    'Firefox on Windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Firefox on Windows',
    ],
    'Edge on Windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0',
        'Edge on Windows',
    ],
    'Safari on macOS' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15',
        'Safari on macOS',
    ],
    'Samsung Internet on Android' => [
        'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 SamsungBrowser/23.0 Chrome/115.0 Mobile Safari/537.36',
        'Samsung Internet on Android',
    ],
]);

it('prefers the more specific browser token', function (): void {
    // Edge and Chrome both appear in Edge's User-Agent; Opera and Chrome both
    // appear in Opera's. First match wins, and the specific tokens come first.
    expect(DeviceLabel::fromUserAgent('Mozilla/5.0 Chrome/120.0 Safari/537.36 OPR/106.0'))
        ->toBe('Opera');
});

it('falls back rather than guessing', function (string $userAgent, string $expected): void {
    expect(DeviceLabel::fromUserAgent($userAgent))->toBe($expected);
})->with([
    'empty' => ['', 'Unknown device'],
    'whitespace' => ['   ', 'Unknown device'],
    'unrecognised' => ['curl/8.5.0', 'Unknown device'],
    'platform only' => ['Mozilla/5.0 (Windows NT 10.0)', 'Windows'],
]);

it('never returns the raw User-Agent', function (): void {
    // The raw header is long, high-entropy enough to fingerprint on its own,
    // and attacker-controlled input that would otherwise be rendered back to
    // the player.
    $hostile = 'Mozilla/5.0 Chrome/120 <script>alert(1)</script> Linux';

    expect(DeviceLabel::fromUserAgent($hostile))->toBe('Chrome on Linux')
        ->and(DeviceLabel::fromUserAgent($hostile))->not->toContain('script');
});

it('produces a short label', function (): void {
    $longest = collect([
        'Mozilla/5.0 (Linux; Android 13) SamsungBrowser/23.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15 Version/17.0',
    ])->map(fn (string $ua): int => strlen(DeviceLabel::fromUserAgent($ua)))->max();

    // Short enough to render in a list row without truncation.
    expect($longest)->toBeLessThan(40);
});
