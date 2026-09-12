<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\DisplayNameAvailable;
use App\Rules\DisplayNameFormat;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDisplayNameRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'display_name' => [
                'required',
                'string',
                new DisplayNameFormat,
                // Ignoring the caller's own row, so re-submitting the current
                // name — or changing only its casing — is not a collision with
                // itself.
                new DisplayNameAvailable($user?->getAuthIdentifier() === null ? null : (int) $user->getAuthIdentifier()),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'display_name' => trim((string) $this->input('display_name', '')),
        ]);
    }
}
