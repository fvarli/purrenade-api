<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\DisplayNameAvailable;
use App\Rules\DisplayNameFormat;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', new DisplayNameFormat, new DisplayNameAvailable],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', 'unique:users,email'],
            'password' => PasswordPolicy::rules(),
            'password_confirmation' => PasswordPolicy::confirmationRules(),
        ];
    }

    /**
     * Normalise the address before validation, not after.
     *
     * Lower-cased and trimmed so the `unique` rule, the stored row, and the
     * rate-limiter bucket all agree. Without this, `Ada@Example.com` registers
     * alongside `ada@example.com`: two accounts, one mailbox, and a login form
     * that cannot tell the player which one they meant.
     *
     * The domain half of an address is case-insensitive by specification; the
     * local half technically is not, but no mail provider in practice treats it
     * otherwise, and the alternative is the duplicate-account problem above.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email', ''))),
            'display_name' => trim((string) $this->input('display_name', '')),
        ]);
    }
}
