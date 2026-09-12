<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-authentication for a security-sensitive action that needs no other input.
 *
 * Used by: start 2FA enrolment, disable 2FA, regenerate recovery codes, and
 * sign out all other devices. Each of those either weakens the account's
 * defences or evicts other sessions, so each asks the person to prove they are
 * the owner and not merely the holder of an open session.
 */
final class SensitiveActionRequest extends FormRequest
{
    use ConfirmsPassword;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->currentPasswordRules();
    }
}
