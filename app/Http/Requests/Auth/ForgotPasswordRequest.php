<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Deliberately no `exists:users,email`. That rule would make the
        // validation response itself an account-existence oracle, defeating the
        // whole point of the endpoint's uniform reply.
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email', ''))),
        ]);
    }
}
