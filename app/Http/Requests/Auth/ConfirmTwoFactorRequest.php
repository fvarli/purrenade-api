<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Proving possession of a freshly scanned TOTP secret.
 *
 * No `current_password` here: the caller has already re-authenticated to *start*
 * enrolment, and this step is itself a possession proof. Asking for the password
 * again would add friction to the one screen where a player is already juggling
 * two devices.
 */
final class ConfirmTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
