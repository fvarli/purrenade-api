<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyEmailRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // `digits:6` rather than a numeric range: a code is a six-character
        // string, and `000042` is a valid one. Treating it as an integer would
        // silently turn it into 42 and fail every leading-zero code.
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
