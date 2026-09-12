<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

/**
 * Re-authentication by password, on the request that needs it.
 *
 * Fortify offers a password-confirmation *window*: confirm once, and sensitive
 * actions are unlocked for a few hours. That design depends on an HTTP session
 * to remember the confirmation, and this API has none — it is token
 * authenticated precisely so a future native client can use the same endpoints
 * (ADR-0005 §5).
 *
 * So re-authentication is per request: the sensitive call itself carries
 * `current_password`. That is stateless, identical for browser and native
 * callers, and impossible to leave half-implemented — there is no window that
 * might still be open from an earlier action. `current_password` is a framework
 * validation rule, so no custom comparison is written here.
 */
trait ConfirmsPassword
{
    /**
     * @return array<string, list<string>>
     */
    protected function currentPasswordRules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }
}
