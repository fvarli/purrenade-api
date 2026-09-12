<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // The same policy as registration. A reset path that accepts a
            // weaker password than registration is how a policy quietly becomes
            // optional.
            'password' => PasswordPolicy::rules(),
            'password_confirmation' => PasswordPolicy::confirmationRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email', ''))),
        ]);
    }
}
