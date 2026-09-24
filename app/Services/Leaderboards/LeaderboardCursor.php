<?php

declare(strict_types=1);

namespace App\Services\Leaderboards;

use App\Enums\LeaderboardWindow;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * The opaque leaderboard cursor (LB-2, APPROVED: clients never construct or
 * parse one).
 *
 * Payload, version 1:
 *
 *     {"v":1, "w":window, "p":week_start|null, "s":score, "a":achieved_at_ms, "d":duration_ms, "r":run_id}
 *
 * — the ORDER key of the last row served, and the window and week it belongs
 * to. JSON, then `Crypt::encryptString()` (authenticated encryption under
 * APP_KEY: AES-256 with a MAC), then base64url, so the token matches
 * `^[A-Za-z0-9_-]+$` and needs no URL escaping. A cursor carries no user id —
 * the tie-break identifier is the representative run (E1) — and neither id
 * is ever readable by the client.
 *
 * {@see self::decode()} returns null for anything it did not mint for this
 * window: undecodable, tampered, a wrong version, a malformed payload, or
 * another window's cursor. The request layer answers that with 422
 * `cursor_invalid`. Rotating APP_KEY invalidates every outstanding cursor the
 * same way — harmless: the client restarts from page one.
 */
final class LeaderboardCursor
{
    public const VERSION = 1;

    /** The longest cursor the endpoint accepts; minted ones are well below it. */
    public const MAX_LENGTH = 512;

    public function __construct(private readonly LeaderboardWeek $week) {}

    public function encode(LeaderboardPosition $position): string
    {
        $payload = json_encode([
            'v' => self::VERSION,
            'w' => $position->window->value,
            'p' => $position->weekStart,
            's' => $position->score,
            'a' => $position->achievedAtMs,
            'd' => $position->durationMs,
            'r' => $position->runId,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(Crypt::encryptString($payload), '+/', '-_'), '=');
    }

    public function decode(string $cursor, LeaderboardWindow $window): ?LeaderboardPosition
    {
        if ($cursor === '' || strlen($cursor) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+\z/', $cursor) !== 1) {
            return null;
        }

        $encrypted = strtr($cursor, '-_', '+/');
        $encrypted .= str_repeat('=', (4 - strlen($encrypted) % 4) % 4);

        try {
            $payload = json_decode(Crypt::decryptString($encrypted), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload) || array_keys($payload) !== ['v', 'w', 'p', 's', 'a', 'd', 'r']) {
            return null;
        }

        ['v' => $v, 'w' => $w, 'p' => $p, 's' => $s, 'a' => $a, 'd' => $d, 'r' => $r] = $payload;

        $valid = $v === self::VERSION
            && $w === $window->value
            && ($window === LeaderboardWindow::Weekly
                ? is_string($p) && $this->week->isWeekStart($p)
                : $p === null)
            && is_int($s) && $s >= 0
            && is_int($a) && $a >= 0
            && is_int($d) && $d > 0
            && is_string($r) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $r) === 1;

        if (! $valid) {
            return null;
        }

        /** @var int $s */
        /** @var int $a */
        /** @var int $d */
        /** @var string $r */
        /** @var string|null $p */
        return new LeaderboardPosition($window, $p, $s, $a, $d, $r);
    }
}
