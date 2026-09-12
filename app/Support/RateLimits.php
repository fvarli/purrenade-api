<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The rate-limit classes and their concrete values (SEC-2).
 *
 * `docs/security/rate-limiting.md` §1 makes two rules APPROVED, and every value
 * here follows from them:
 *
 *  1. **Two dimensions, always.** Per identifier *and* per source. Per-account
 *     alone misses one host attacking a thousand accounts; per-IP alone misses a
 *     botnet attacking one. Each limiter below therefore returns two limits, and
 *     the request must satisfy both.
 *
 *  2. **Throttle, never lock out** (AUTH-3). A hard lockout on failed attempts
 *     converts credential stuffing into a reliable denial-of-service against any
 *     account whose address an attacker knows — the attack becomes *easier*, not
 *     harder. So there is no lockout state anywhere in this system: exceeding a
 *     limit delays, and the delay decays on its own.
 *
 * Named classes rather than one global throttle, because one bucket shared
 * between "log in" and "read your profile" means a burst of reads spends the
 * budget that protects the password.
 *
 * The email-sending endpoints carry the strictest limits in the product. An
 * unlimited endpoint that sends mail to a caller-supplied address is a spam
 * relay, and the reputational damage lands on the product's sending domain.
 */
final class RateLimits
{
    // --- Limiter names, as referenced by route middleware -------------------

    public const LOGIN = 'auth-login';

    public const REGISTER = 'auth-register';

    public const TWO_FACTOR_CHALLENGE = 'auth-two-factor';

    public const EMAIL_VERIFY = 'auth-email-verify';

    /** Email-sending. Very strict. */
    public const EMAIL_RESEND = 'auth-email-resend';

    /** Email-sending. Very strict. */
    public const PASSWORD_FORGOT = 'auth-password-forgot';

    public const PASSWORD_RESET = 'auth-password-reset';

    public const SENSITIVE = 'auth-sensitive';

    public const DISPLAY_NAME = 'profile-display-name';

    public const NORMAL = 'api-normal';

    /** Readiness probing. Public, and the only public route that queries the DB. */
    public const HEALTH = 'api-health';

    // --- Per-identifier allowances (per minute unless noted) ---------------

    /**
     * Five password attempts a minute per account.
     *
     * Comfortable for a person who mistypes; useless for guessing, because the
     * bound that matters is the hourly one below.
     */
    public const LOGIN_PER_IDENTIFIER = 5;

    /** Per hour, per account: the real anti-guessing bound. */
    public const LOGIN_PER_IDENTIFIER_HOURLY = 20;

    /**
     * Per minute, per source address.
     *
     * Higher than the per-account limit on purpose: a household, an office or a
     * mobile carrier NAT is one address with many legitimate players.
     */
    public const LOGIN_PER_SOURCE = 20;

    public const REGISTER_PER_SOURCE_HOURLY = 10;

    public const TWO_FACTOR_PER_IDENTIFIER = 5;

    public const TWO_FACTOR_PER_SOURCE = 20;

    public const EMAIL_VERIFY_PER_IDENTIFIER = 10;

    public const EMAIL_VERIFY_PER_SOURCE = 30;

    /**
     * Per hour. The 42-second cooldown handles impatience; this handles abuse.
     * Five mails an hour to one address is already more than anybody needs.
     */
    public const EMAIL_RESEND_PER_IDENTIFIER_HOURLY = 5;

    public const EMAIL_RESEND_PER_SOURCE_HOURLY = 15;

    public const PASSWORD_FORGOT_PER_IDENTIFIER_HOURLY = 5;

    public const PASSWORD_FORGOT_PER_SOURCE_HOURLY = 15;

    public const PASSWORD_RESET_PER_SOURCE = 10;

    /** 2FA changes, recovery-code regeneration, revoke-all. */
    public const SENSITIVE_PER_IDENTIFIER = 10;

    /**
     * Display-name changes, per day.
     *
     * The leaderboard shows this name to everyone, so rapid churn is an
     * impersonation and evasion tool rather than self-expression.
     */
    public const DISPLAY_NAME_PER_IDENTIFIER_DAILY = 3;

    public const NORMAL_PER_IDENTIFIER = 60;

    /**
     * Per source, per minute. Well above any sane monitor — a probe at one
     * hertz uses a fifth of it — and far below what makes an unauthenticated
     * database round-trip worth using as an amplifier.
     */
    public const HEALTH_PER_SOURCE = 60;
}
