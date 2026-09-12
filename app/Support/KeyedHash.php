<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * A keyed, deterministic hash for high-entropy secrets.
 *
 * Two kinds of secret in this system need two different hashes, and using the
 * wrong one for either is a real defect:
 *
 *   - **Low entropy** (a six-digit email verification code): must use a slow,
 *     salted hash. A fast hash of a million-value keyspace is reversible by
 *     exhaustive search, so the cost of hashing is the whole defence. Those use
 *     the application hasher (argon2id) and are found by `user_id`.
 *
 *   - **High entropy** (recovery codes, 2FA challenge tokens): exhaustive search
 *     is not a threat, so a fast hash loses nothing — and being deterministic,
 *     it can be indexed. That matters: an indexed single-row lookup is what
 *     allows recovery-code consumption to be one atomic conditional UPDATE
 *     rather than a read-then-write race.
 *
 * Keyed with the application key so the digest is useless without it: a leaked
 * table alone does not let an attacker confirm a guessed code offline.
 *
 * Rotating `APP_KEY` therefore invalidates every stored recovery code and
 * pending challenge. That is documented in `docs/architecture/auth-architecture.md`
 * and is the correct trade: the alternative is an unkeyed digest that a stolen
 * table makes verifiable.
 */
final class KeyedHash
{
    public static function make(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    /**
     * Compare in constant time, so a timing side channel cannot leak the digest.
     */
    public static function matches(string $value, string $knownHash): bool
    {
        return hash_equals($knownHash, self::make($value));
    }

    private static function key(): string
    {
        /** @var string $key */
        $key = Config::string('app.key', '');

        if ($key === '') {
            throw new RuntimeException(
                'APP_KEY is not set; keyed hashing of authentication secrets is unavailable.'
            );
        }

        // Laravel stores the key base64-encoded. Decode it so the HMAC is keyed
        // with the raw bytes rather than with their textual representation.
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
