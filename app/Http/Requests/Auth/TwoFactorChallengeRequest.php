<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The second factor: either a TOTP code or a recovery code, never both.
 *
 * `required_without` in both directions, so one of the two must be present, and
 * the endpoint does not have to guess which flow the caller meant.
 */
final class TwoFactorChallengeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'max:128'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
        ];
    }

    public function totpCode(): ?string
    {
        $code = $this->input('code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function recoveryCode(): ?string
    {
        $code = $this->input('recovery_code');

        return is_string($code) && $code !== '' ? trim($code) : null;
    }
}
