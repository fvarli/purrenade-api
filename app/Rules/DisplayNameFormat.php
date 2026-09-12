<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\DisplayName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The APPROVED display-name shape rules, as a single validation rule.
 *
 * One rule rather than a stack of `min`, `max`, `regex` so the failure carries
 * one stable code and one message the player can act on. A `regex` failure tells
 * a player nothing; "3–20 characters, letters, digits, and `_ . -`" tells them
 * what to type.
 *
 * The rules themselves live in App\Support\DisplayName, shared with the
 * normalisation used for uniqueness, so shape and comparison cannot disagree.
 */
final class DisplayNameFormat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! DisplayName::isWellFormed($value)) {
            $fail(sprintf(
                'A display name is %d–%d characters of letters, digits, and _ . - '
                .'It must contain at least one letter and cannot start or end with punctuation.',
                DisplayName::MIN_LENGTH,
                DisplayName::MAX_LENGTH,
            ));
        }
    }
}
