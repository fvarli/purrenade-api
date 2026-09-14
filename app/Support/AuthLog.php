<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Security-relevant authentication events, in one shape.
 *
 * Every auth event goes through this class so that two properties hold without
 * anyone having to remember them at the call site:
 *
 *  1. **The field set is fixed.** `event`, `user_id`, `role`, `device`, and the
 *     correlation id — nothing else. A log that is a different shape per event
 *     cannot be queried, and a log nobody queries is not a control.
 *
 *  2. **Secrets cannot get in.** No call site passes a credential because no
 *     method accepts one. Passwords, TOTP secrets, recovery codes, verification
 *     codes, reset tokens, session cookies, bearer tokens, `APP_KEY` and
 *     database credentials are all structurally absent rather than filtered out
 *     later, which is the only version of that guarantee that survives a
 *     refactor. docs/architecture/observability.md §3.1.
 *
 * Email addresses are logged only for events where no `user_id` exists yet — a
 * failed login against an unknown address is uninvestigable without it — and
 * never alongside one, since the id already identifies the account.
 */
final class AuthLog
{
    public const CHANNEL = 'security';

    // Session lifecycle
    public const LOGIN_SUCCEEDED = 'auth.login.succeeded';

    public const LOGIN_FAILED = 'auth.login.failed';

    public const LOGIN_THROTTLED = 'auth.login.throttled';

    public const LOGOUT = 'auth.logout';

    public const REGISTERED = 'auth.registered';

    // Email verification
    public const EMAIL_VERIFIED = 'auth.email.verified';

    public const EMAIL_VERIFICATION_FAILED = 'auth.email.verification_failed';

    public const EMAIL_VERIFICATION_SENT = 'auth.email.verification_sent';

    // Password
    public const PASSWORD_RESET_REQUESTED = 'auth.password.reset_requested';

    public const PASSWORD_RESET_COMPLETED = 'auth.password.reset_completed';

    public const PASSWORD_CHANGED = 'auth.password.changed';

    // Two-factor
    public const TWO_FACTOR_CHALLENGED = 'auth.two_factor.challenged';

    public const TWO_FACTOR_CHALLENGE_FAILED = 'auth.two_factor.challenge_failed';

    public const TWO_FACTOR_ENABLED = 'auth.two_factor.enabled';

    public const TWO_FACTOR_DISABLED = 'auth.two_factor.disabled';

    public const TWO_FACTOR_RECOVERY_CODE_USED = 'auth.two_factor.recovery_code_used';

    public const TWO_FACTOR_RECOVERY_CODES_REGENERATED = 'auth.two_factor.recovery_codes_regenerated';

    /**
     * The stored TOTP secret could not be decoded.
     *
     * Not a credential event — an integrity one. It means an account cannot
     * complete a challenge no matter what its owner types, and the only way to
     * learn that otherwise was from a 500.
     */
    public const TWO_FACTOR_SECRET_UNREADABLE = 'auth.two_factor.secret_unreadable';

    // Sessions
    public const SESSION_REVOKED = 'auth.session.revoked';

    public const SESSIONS_REVOKED_ALL = 'auth.session.revoked_all';

    // Authorization
    public const ADMIN_ACCESS_DENIED = 'auth.admin.access_denied';

    public const ADMIN_ACCESS_GRANTED = 'auth.admin.access_granted';

    /**
     * An operator granted the administrator role from the command line.
     *
     * The only event here that records a *change* of privilege rather than the
     * outcome of a check. It is written by `purrenade:admin:promote`, which is
     * the sole supported way an account becomes an administrator, so this line
     * is the audit trail for every administrator that has ever existed.
     */
    public const ADMIN_ROLE_GRANTED = 'auth.admin.role_granted';

    // Profile
    public const DISPLAY_NAME_CHANGED = 'auth.profile.display_name_changed';

    /**
     * Record an event for a known account.
     *
     * @param  array<string, scalar|null>  $context  Only non-secret facts: a reason
     *                                               code, a count, a session's public id.
     */
    public static function forUser(string $event, User $user, ?Request $request = null, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info($event, [
            'event' => $event,
            'user_id' => $user->id,
            'role' => $user->role->value,
            'device' => $request instanceof Request ? DeviceLabel::fromRequest($request) : null,
            ...$context,
        ]);
    }

    /**
     * Record an event where no account has been established.
     *
     * Used for failed logins and forgot-password requests, where the address is
     * the only identifier available and omitting it would make the event
     * uninvestigable. The address is never paired with a `user_id`, so this does
     * not silently add PII to events that already identify the account.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function forAttempt(string $event, string $email, ?Request $request = null, array $context = []): void
    {
        Log::channel(self::CHANNEL)->warning($event, [
            'event' => $event,
            'email' => $email,
            'device' => $request instanceof Request ? DeviceLabel::fromRequest($request) : null,
            ...$context,
        ]);
    }
}
