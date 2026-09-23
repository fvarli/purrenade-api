<?php

declare(strict_types=1);

namespace App\Enums;

use Symfony\Component\HttpFoundation\Response;

/**
 * Every error this API can return, as a closed set.
 *
 * These values are **the** contract. A client branches on `code`; it never reads
 * `detail`, which is prose and may be reworded or localised at any time. Adding
 * a case here is additive and safe; changing or removing one is a breaking
 * change and needs a new API version.
 *
 * An enum rather than loose strings so that a typo is a compile-time error and
 * so `status()` cannot disagree with itself between two call sites — the class
 * of bug where the same condition returns 403 from one endpoint and 404 from
 * another.
 *
 * See docs/api/api-conventions.md §3.
 */
enum ProblemCode: string
{
    // --- Generic -------------------------------------------------------------

    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case ServiceUnavailable = 'service_unavailable';

    // --- Credentials ---------------------------------------------------------

    /**
     * One code for "no such account" and for "wrong password", deliberately.
     * Two codes would be an account-existence oracle, which is exactly what
     * docs/security/authentication.md §5 forbids.
     */
    case InvalidCredentials = 'invalid_credentials';

    // --- Email verification --------------------------------------------------

    case EmailNotVerified = 'email_not_verified';
    case EmailAlreadyVerified = 'email_already_verified';
    case VerificationCodeInvalid = 'verification_code_invalid';
    case VerificationCodeExpired = 'verification_code_expired';
    case VerificationCodeMissing = 'verification_code_missing';
    case VerificationResendCooldown = 'verification_resend_cooldown';

    // --- Two-factor ----------------------------------------------------------

    case TwoFactorChallengeInvalid = 'two_factor_challenge_invalid';
    case TwoFactorCodeInvalid = 'two_factor_code_invalid';
    case TwoFactorAlreadyEnabled = 'two_factor_already_enabled';
    case TwoFactorNotEnabled = 'two_factor_not_enabled';
    case TwoFactorNotPending = 'two_factor_not_pending';

    /** An admin tried to turn their own second factor off. */
    case AdminTwoFactorMandatory = 'admin_two_factor_mandatory';

    // --- Authorization -------------------------------------------------------

    case AdminRoleRequired = 'admin_role_required';

    /** Correct role, but this session never passed a two-factor challenge. */
    case AdminTwoFactorRequired = 'admin_two_factor_required';

    // --- Password ------------------------------------------------------------

    case ResetTokenInvalid = 'reset_token_invalid';

    // --- Profile -------------------------------------------------------------

    case DisplayNameChangeCooldown = 'display_name_change_cooldown';

    // --- Game runs -----------------------------------------------------------

    /**
     * The run is already final — finished under another idempotency key, or
     * replaced by a later start. Not a low score: a lifecycle violation.
     */
    case RunNotActive = 'run_not_active';

    /** The same idempotency key was sent with a different effective request. */
    case IdempotencyKeyReused = 'idempotency_key_reused';

    /**
     * The HTTP status this condition always carries.
     *
     * 403 and not 404 for the admin cases: docs/security/authorization-and-roles.md
     * §6 draws the line at information disclosure, and the existence of an admin
     * surface is not a secret. A resource whose existence *would* leak — another
     * player's session — returns NotFound instead.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed,
            self::VerificationCodeInvalid,
            self::VerificationCodeExpired,
            self::VerificationCodeMissing,
            self::TwoFactorCodeInvalid,
            self::ResetTokenInvalid => Response::HTTP_UNPROCESSABLE_ENTITY,

            self::Unauthenticated,
            self::InvalidCredentials,
            self::TwoFactorChallengeInvalid => Response::HTTP_UNAUTHORIZED,

            self::Forbidden,
            self::EmailNotVerified,
            self::AdminRoleRequired,
            self::AdminTwoFactorRequired,
            self::AdminTwoFactorMandatory => Response::HTTP_FORBIDDEN,

            self::NotFound => Response::HTTP_NOT_FOUND,
            self::MethodNotAllowed => Response::HTTP_METHOD_NOT_ALLOWED,

            self::Conflict,
            self::EmailAlreadyVerified,
            self::TwoFactorAlreadyEnabled,
            self::TwoFactorNotEnabled,
            self::TwoFactorNotPending,
            self::RunNotActive,
            self::IdempotencyKeyReused => Response::HTTP_CONFLICT,

            self::RateLimited,
            self::VerificationResendCooldown,
            self::DisplayNameChangeCooldown => Response::HTTP_TOO_MANY_REQUESTS,

            self::ServiceUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            self::ServerError => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * The RFC 9457 `type` URI.
     *
     * A URN, not an `https://` URL. RFC 9457 says consumers should not
     * dereference the type, and no documentation site exists to dereference —
     * inventing one that returns 404 would be worse than being honest. A URN is
     * a valid, stable, non-dereferenceable identifier, which is precisely what
     * this field is for.
     */
    public function type(): string
    {
        return 'urn:purrenade:error:'.$this->value;
    }

    /**
     * A short, stable, English summary. RFC 9457 §3.1.2: the title describes the
     * problem type, not this occurrence of it, and is not localised.
     */
    public function title(): string
    {
        return match ($this) {
            self::ValidationFailed => 'Validation failed',
            self::Unauthenticated => 'Authentication required',
            self::Forbidden => 'Forbidden',
            self::NotFound => 'Not found',
            self::MethodNotAllowed => 'Method not allowed',
            self::Conflict => 'Conflict',
            self::RateLimited => 'Too many requests',
            self::ServerError => 'Server error',
            self::ServiceUnavailable => 'Service unavailable',
            self::InvalidCredentials => 'Invalid credentials',
            self::EmailNotVerified => 'Email address not verified',
            self::EmailAlreadyVerified => 'Email address already verified',
            self::VerificationCodeInvalid => 'Verification code is incorrect',
            self::VerificationCodeExpired => 'Verification code has expired',
            self::VerificationCodeMissing => 'No verification code is outstanding',
            self::VerificationResendCooldown => 'Verification code was requested too recently',
            self::TwoFactorChallengeInvalid => 'Two-factor challenge is invalid or expired',
            self::TwoFactorCodeInvalid => 'Two-factor code is incorrect',
            self::TwoFactorAlreadyEnabled => 'Two-factor authentication is already enabled',
            self::TwoFactorNotEnabled => 'Two-factor authentication is not enabled',
            self::TwoFactorNotPending => 'Two-factor enrolment has not been started',
            self::AdminTwoFactorMandatory => 'Two-factor authentication is mandatory for administrators',
            self::AdminRoleRequired => 'Administrator role required',
            self::AdminTwoFactorRequired => 'Two-factor authentication required for this session',
            self::ResetTokenInvalid => 'Password reset token is invalid or expired',
            self::DisplayNameChangeCooldown => 'Display name was changed too recently',
            self::RunNotActive => 'The run is no longer active',
            self::IdempotencyKeyReused => 'Idempotency key was reused with a different request',
        };
    }
}
