<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use App\Support\DisplayName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Case-insensitive display-name uniqueness.
 *
 * A dedicated rule rather than Laravel's `unique:users,display_name_normalized`
 * for two reasons: the value has to be normalised before it is compared, and the
 * error must be reported against `display_name` — the field the player typed —
 * not against the internal comparison column, whose name has no business
 * appearing in an API response.
 *
 * This check is a courtesy, not the guarantee. The guarantee is the unique index
 * on `display_name_normalized`: two simultaneous registrations can both pass
 * validation and only one can commit. The controller therefore treats a unique
 * violation as a real outcome rather than assuming this rule prevented it.
 */
final class DisplayNameAvailable implements ValidationRule
{
    /**
     * @param  int|null  $ignoreUserId  The account being renamed, so a player can
     *                                  re-submit their own name unchanged (or change
     *                                  only its casing) without colliding with itself.
     */
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $taken = User::query()
            ->where('display_name_normalized', DisplayName::normalize($value))
            ->when($this->ignoreUserId !== null, fn ($query) => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail('That display name is already taken.');
        }
    }
}
