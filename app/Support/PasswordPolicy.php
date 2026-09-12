<?php

declare(strict_types=1);

namespace App\Support;

use App\Rules\NotCompromised;

/**
 * The password policy (SEC-1), defined once.
 *
 * Registration, reset and change all call `rules()`, so the policy cannot be
 * stricter on one path than another — which is the usual way a "policy" becomes
 * advisory: a reset endpoint that accepts what registration refuses.
 *
 * ### The choices, and why
 *
 * **Minimum 12 characters, no composition rules.** Length is the property that
 * resists guessing; mandatory character classes mostly produce `Purrenade1!`.
 * Current guidance (NIST SP 800-63B, OWASP ASVS) is explicitly against
 * composition rules and in favour of length plus a breach check. Twelve rather
 * than eight because a public leaderboard gives every account a reason to be
 * attacked and player 2FA is optional.
 *
 * **Maximum 128 characters, and nothing is truncated.** A bound exists because
 * hashing cost is attacker-controllable otherwise: a one-megabyte password is a
 * denial-of-service request. 128 is far above any real passphrase. The limit is
 * a *validation error*, never a silent trim — a password quietly shortened to
 * fit is a password the player cannot reproduce.
 *
 * This is also why the hasher is **argon2id** rather than bcrypt (see
 * `config/hashing.php`): bcrypt ignores everything past 72 bytes, so a 128-
 * character maximum over bcrypt would be a truncation bug dressed as a policy.
 * Argon2id has no such limit and is memory-hard, which is what
 * docs/security/authentication.md §2 requires.
 *
 * **Breach checking, on.** App\Rules\NotCompromised delegates to the framework's
 * `UncompromisedVerifier`, which queries the Pwned Passwords range API using
 * k-anonymity: the first five characters of the SHA-1 leave the server, the
 * password does not. Credential stuffing is the realistic attack on a consumer
 * game, and rejecting already-breached passwords addresses it directly. When the
 * service is unreachable the check passes, so an outage degrades the policy
 * rather than blocking every registration.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 128;

    /**
     * Validation rules for a new password.
     *
     * Plain `min` and `max` plus a dedicated breach rule, rather than Laravel's
     * `Password` rule. Not a style choice: `Password` aggregates every check it
     * performs and reports one failed rule named `Password`, so the client would
     * get the same code for "too short" and for "this password has been
     * published" — two problems needing opposite advice. Split, each failure
     * carries its own stable code. See App\Rules\NotCompromised.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'min:'.self::MIN_LENGTH,
            'max:'.self::MAX_LENGTH,
            new NotCompromised,
        ];
    }

    /**
     * Rules for the confirmation field.
     *
     * Separate from `rules()` so the confirmation is not breach-checked twice —
     * two HTTP round trips to the range API for one form submission.
     *
     * @return list<string>
     */
    public static function confirmationRules(): array
    {
        return ['required', 'string', 'same:password'];
    }
}
