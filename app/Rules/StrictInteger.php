<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A JSON **integer**, and nothing that merely looks like one.
 *
 * Laravel's `integer` rule accepts `"12"` and `12.0`. The run-finish protocol
 * does not (C-7): a string, a float — including `12.0` — a boolean, null, an
 * object, or a number too large for a PHP int (which JSON decoding turns into a
 * float) is a malformed request, refused with a 422 before anything is
 * classified or fingerprinted.
 *
 * Applied to the decoded request body, never to middleware-transformed input,
 * so what is checked is exactly what the client sent.
 */
final class StrictInteger implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail('The :attribute must be a JSON integer.');
        }
    }
}
