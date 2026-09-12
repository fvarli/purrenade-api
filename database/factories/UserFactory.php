<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use App\Support\DisplayName;
use App\Support\KeyedHash;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The password every factory user shares, hashed once.
     *
     * Argon2id at production cost takes tens of milliseconds. A suite that
     * creates a hundred users would otherwise spend seconds hashing the same
     * string, so the hash is computed once and reused.
     */
    protected static ?string $password = null;

    public const PASSWORD = 'correct-horse-battery-staple';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Faker's names contain spaces and apostrophes, which the display-name
        // rules reject — a factory user built from one would be a user the API
        // itself would refuse to create. Generated from the permitted alphabet
        // instead, comfortably inside the 3–20 character bound.
        $displayName = 'Player'.Str::lower(Str::random(8));

        return [
            'display_name' => $displayName,
            'display_name_normalized' => DisplayName::normalize($displayName),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'role' => UserRole::Player,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A freshly registered account that has not entered its code yet.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * An administrator.
     *
     * Deliberately **not** 2FA-enabled by default. The admin tests that matter
     * most are the negative ones — an admin without a second factor, an admin
     * whose session never passed a challenge — so the default state is the one
     * that must be refused. Compose with `withTwoFactor()` for the account that
     * should succeed.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * 2FA enrolled and confirmed, with a full set of recovery codes.
     *
     * A real secret from Fortify's provider, so a test can compute a genuine
     * TOTP code against it rather than mocking verification — which would prove
     * only that the mock works.
     */
    public function withTwoFactor(): static
    {
        return $this->afterCreating(function (User $user): void {
            $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();

            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => now(),
                'two_factor_last_used_timestep' => null,
            ])->save();

            $now = now();

            $user->twoFactorRecoveryCodes()->insert(
                collect(range(1, TwoFactorRecoveryCode::COUNT))
                    ->map(fn (): array => [
                        'user_id' => $user->id,
                        'code_hash' => KeyedHash::make(RecoveryCode::generate()),
                        'used_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all()
            );
        });
    }

    /**
     * Enrolment started but never confirmed — 2FA is off.
     */
    public function withPendingTwoFactor(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->forceFill([
                'two_factor_secret' => app(TwoFactorAuthenticationProvider::class)->generateSecretKey(),
                'two_factor_confirmed_at' => null,
            ])->save();
        });
    }
}
