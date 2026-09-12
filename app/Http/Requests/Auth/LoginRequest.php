<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Login input.
 *
 * Note what is *not* validated: the password has no length or policy rule. A
 * login form that rejects a 6-character password as "too short" before checking
 * it tells an attacker the password policy and, worse, distinguishes malformed
 * input from wrong credentials. Here everything that is not a correct pair is
 * the same single outcome.
 */
final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email', ''))),
        ]);
    }
}
