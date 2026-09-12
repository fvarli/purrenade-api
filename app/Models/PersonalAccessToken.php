<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * A token row *is* a session.
 *
 * There is no second session concept in this design. The BFF holds one Sanctum
 * token per signed-in device in its own server-side session store, so "list my
 * sessions", "sign this device out" and "sign out everywhere else" are queries
 * and deletes against this table. That is what makes revocation immediate and
 * real, as ADR-0005 §3 requires: deleting the row kills the credential itself,
 * not merely a cookie pointing at it.
 *
 * Subclassed for two reasons: a UUID the API can safely show a client, and the
 * ability abbreviations that record *how* the session was authenticated.
 *
 * @property string $public_id
 * @property string $name
 * @property int|null $two_factor_version
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Granted to every session. Its absence means the token is not a browser or
     * app session at all, which no code path currently produces — it exists so
     * that abilities are always an explicit list.
     */
    public const ABILITY_SESSION = 'session';

    /**
     * Granted only by the two-factor challenge endpoint.
     *
     * This is how "the current session satisfied 2FA" is represented without a
     * session store: the fact is welded to the credential at the moment it is
     * minted and cannot be acquired later. A password-only login produces a
     * token without it, so every admin-scoped route refuses that token — and
     * keeps refusing it, because there is no endpoint that adds the ability to
     * an existing token.
     *
     * The ability alone is not sufficient, though — it says a challenge was
     * passed, not which secret it was passed against. See
     * {@see self::satisfiesTwoFactorFor()}.
     */
    public const ABILITY_TWO_FACTOR = 'two-factor';

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            if ($token->public_id === null) {
                $token->public_id = (string) Str::uuid();
            }
        });
    }

    /**
     * Did this session pass a two-factor challenge?
     *
     * Not `tokenCan()`: that returns true for the `*` wildcard, and a wildcard
     * would silently answer "yes" for a password-only login. Nothing in this
     * application issues `*`, and this check makes that guarantee local instead
     * of relying on it.
     */
    public function satisfiedTwoFactor(): bool
    {
        return in_array(self::ABILITY_TWO_FACTOR, $this->abilities ?? [], true);
    }

    /**
     * Did this session pass a challenge against the account's *current* second
     * factor?
     *
     * This is the question authorization actually needs, and it is not the same
     * as {@see self::satisfiedTwoFactor()}. That one is historical: it records
     * that some challenge was passed once. An ability cannot be withdrawn from
     * an issued token, so on its own it survives the credential it was proof of.
     *
     * The gap it leaves is not theoretical. Disabling 2FA and enrolling a new
     * secret — what anyone does after losing an authenticator — re-satisfies
     * every account-level check while the old sessions still carry the ability.
     * The counter closes it: a token stamped with an earlier generation was
     * challenged against a secret that no longer exists.
     *
     * A token issued before the counter existed holds NULL, which equals no
     * generation, so it is treated as unsatisfied. Failing that way round is
     * the point.
     */
    public function satisfiesTwoFactorFor(User $user): bool
    {
        return $this->satisfiedTwoFactor()
            && $this->two_factor_version !== null
            && $this->two_factor_version === $user->two_factor_version;
    }
}
