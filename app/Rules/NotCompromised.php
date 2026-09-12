<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a password that appears in a known breach corpus.
 *
 * A rule of its own rather than `Password::uncompromised()`, and the reason is
 * the error contract. Laravel's `Password` rule aggregates every check it
 * performs — length, composition, breach — and reports a single failed rule
 * named `Password`. A client would therefore receive `password_policy` whether
 * the password was eight characters or was `123456789012`, and the two need
 * different advice: "make it longer" versus "this one has been published".
 *
 * Split out, each failure carries its own stable code and the UI can say
 * something true.
 *
 * The check itself is entirely the framework's: `UncompromisedVerifier` queries
 * the Pwned Passwords range API using k-anonymity, so the first five characters
 * of the SHA-1 leave the server and the password does not. Nothing is
 * reimplemented here, and no password is transmitted.
 *
 * When that service is unreachable the framework's verifier passes, so an outage
 * degrades the policy rather than blocking every registration. Credential
 * stuffing is the realistic attack on a consumer game; an unavailable breach
 * list is a worse reason to refuse a signup than to accept one.
 */
final class NotCompromised implements ValidationRule
{
    public function __construct(
        /**
         * How many appearances in the corpus count as compromised.
         *
         * Zero — any appearance at all. A threshold above zero exists for
         * applications that find the strict rule too noisy; a password that has
         * been published even once is already in every stuffing list.
         */
        private readonly int $threshold = 0,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            // Length and presence are other rules' business. Reporting here too
            // would give the field two messages for one mistake.
            return;
        }

        $compromised = ! app(UncompromisedVerifier::class)->verify([
            'value' => $value,
            'threshold' => $this->threshold,
        ]);

        if ($compromised) {
            $fail('This password has appeared in a known data breach. Choose a different one.');
        }
    }
}
