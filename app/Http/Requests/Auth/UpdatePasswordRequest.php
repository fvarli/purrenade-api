<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing a password while signed in.
 *
 * `current_password` is the re-authentication step. A signed-in session is not
 * proof that the person at the keyboard is the account owner — a borrowed laptop
 * or a stolen session is exactly the case this guards. See
 * App\Http\Requests\Auth\ConfirmsPassword for why this API re-authenticates with
 * the password itself rather than with a confirmation window.
 */
final class UpdatePasswordRequest extends FormRequest
{
    use ConfirmsPassword;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->currentPasswordRules(),
            'password' => PasswordPolicy::rules(),
            'password_confirmation' => PasswordPolicy::confirmationRules(),
        ];
    }
}
