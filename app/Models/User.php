<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Support\DisplayName;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $display_name
 * @property string $display_name_normalized
 * @property Carbon|null $display_name_changed_at
 * @property Carbon|null $tutorial_completed_at
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property string|null $two_factor_secret
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_timestep
 * @property int $two_factor_version
 * @property-read Collection<int, TwoFactorRecoveryCode> $twoFactorRecoveryCodes
 * @property-read EmailVerificationCode|null $emailVerificationCode
 */
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Deliberately guarded rather than fillable.
     *
     * `role`, `email_verified_at` and the two-factor columns are privilege
     * state. One `User::create($request->all())` against a permissive fillable
     * list is a privilege-escalation bug, so every write in this application
     * names its columns explicitly instead.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Match the schema default in memory, not only in the database.
     *
     * A model that has just been created carries only what was written, so
     * `two_factor_version` would read as null on the very instance that issues
     * the account's first session — and null is how this design spells "a
     * generation nobody can match". The row would say 0 and the token would say
     * nothing, and the two would never agree again.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'two_factor_version' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'display_name_changed_at' => 'datetime',
            'tutorial_completed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_timestep' => 'integer',
            'two_factor_version' => 'integer',
            'password' => 'hashed',
            'role' => UserRole::class,
            // Encrypted, not hashed: TOTP verification needs the secret back to
            // compute the expected code. docs/security/two-factor.md §2.
            'two_factor_secret' => 'encrypted',
        ];
    }

    // ---------------------------------------------------------------------
    // Relations
    // ---------------------------------------------------------------------

    /** @return HasOne<EmailVerificationCode, $this> */
    public function emailVerificationCode(): HasOne
    {
        return $this->hasOne(EmailVerificationCode::class);
    }

    /** @return HasMany<TwoFactorRecoveryCode, $this> */
    public function twoFactorRecoveryCodes(): HasMany
    {
        return $this->hasMany(TwoFactorRecoveryCode::class);
    }

    // ---------------------------------------------------------------------
    // Role
    // ---------------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    // ---------------------------------------------------------------------
    // Two-factor
    // ---------------------------------------------------------------------

    /**
     * Is two-factor authentication actually active?
     *
     * A secret alone is not enough. Enrolment writes the secret first and sets
     * `two_factor_confirmed_at` only once the player has proven possession by
     * entering a live code — because switching 2FA on against an unproven secret
     * locks the player out of their own account.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /** Enrolment has been started but possession has not yet been proven. */
    public function hasTwoFactorPending(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at === null;
    }

    /**
     * An admin who cannot yet use the admin surface.
     *
     * Reachable in one way: an operator promotes a player who has not enrolled.
     * It is a real state, so the API reports it rather than leaving the account
     * looking simply broken.
     */
    public function requiresTwoFactorEnrolment(): bool
    {
        return $this->role->requiresTwoFactor() && ! $this->hasTwoFactorEnabled();
    }

    // ---------------------------------------------------------------------
    // Display name
    // ---------------------------------------------------------------------

    /**
     * Assign a display name and its comparison form together.
     *
     * One method, so the normalised column can never drift from the visible one
     * — which would silently defeat case-insensitive uniqueness.
     */
    public function setDisplayName(string $displayName): void
    {
        $this->display_name = $displayName;
        $this->display_name_normalized = DisplayName::normalize($displayName);
    }

    // ---------------------------------------------------------------------
    // Notifications
    // ---------------------------------------------------------------------

    /**
     * Send the password-reset notification.
     *
     * Overridden so the link points at the **frontend**, not at this API: the
     * player clicks through to a Nuxt page, which posts the token back through
     * the BFF. A link to this service would render a page it does not have.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
