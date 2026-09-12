<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A coarse, human-readable label for a session's device.
 *
 * Deliberately lossy. The session list exists so a player can recognise their
 * own devices and spot one they do not recognise — "Chrome on Android" does that
 * job. A precise fingerprint would do it slightly better while creating a
 * tracking identifier and a personal-data retention obligation for no
 * proportionate gain.
 *
 * The raw User-Agent is never stored: it is long, it is high-entropy enough to
 * be a fingerprint on its own, and it is attacker-controlled input that would
 * otherwise be rendered back to the player.
 *
 * IP address and derived location are **not** recorded in M2. v0.3 board 20
 * shows an approximate location, and that remains OPEN (AUTH-4): it needs a
 * geo-IP source and a retention policy decided together, not a column added
 * because the design has a slot for it.
 */
final class DeviceLabel
{
    private const UNKNOWN = 'Unknown device';

    /** Order matters: the first match wins, so specific tokens precede generic ones. */
    private const BROWSERS = [
        'Edge' => ['Edg/', 'Edge/'],
        'Opera' => ['OPR/', 'Opera'],
        'Samsung Internet' => ['SamsungBrowser'],
        'Firefox' => ['Firefox/', 'FxiOS'],
        'Chrome' => ['Chrome/', 'CriOS'],
        'Safari' => ['Safari/'],
    ];

    private const PLATFORMS = [
        'Android' => ['Android'],
        'iPhone' => ['iPhone'],
        'iPad' => ['iPad'],
        'Windows' => ['Windows'],
        'macOS' => ['Macintosh', 'Mac OS X'],
        'Linux' => ['Linux'],
    ];

    public static function fromRequest(Request $request): string
    {
        return self::fromUserAgent((string) $request->userAgent());
    }

    public static function fromUserAgent(string $userAgent): string
    {
        if (trim($userAgent) === '') {
            return self::UNKNOWN;
        }

        $browser = self::match($userAgent, self::BROWSERS);
        $platform = self::match($userAgent, self::PLATFORMS);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => self::UNKNOWN,
        };
    }

    /**
     * @param  array<string, list<string>>  $candidates
     */
    private static function match(string $userAgent, array $candidates): ?string
    {
        foreach ($candidates as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($userAgent, $needle)) {
                    return $label;
                }
            }
        }

        return null;
    }
}
