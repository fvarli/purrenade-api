<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\TwoFactorChallenge;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\AuthLog;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

// ---------------------------------------------------------------------------
// Enrolment
// ---------------------------------------------------------------------------

it('begins enrolment without switching 2FA on', function (): void {
    $user = User::factory()->create();

    $response = withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/2fa/enable', ['current_password' => UserFactory::PASSWORD])
        ->assertOk()
        ->assertJsonPath('status', 'two_factor_pending');

    expect($response->json('data.secret'))->toBeString()
        ->and($response->json('data.otpauth_uri'))->toStartWith('otpauth://totp/')
        // A data URI for an <img>, so the frontend needs no v-html and this
        // screen adds no XSS sink.
        ->and($response->json('data.qr_code'))->toStartWith('data:image/svg+xml;base64,');

    expect($response->json('data.secret'))->not->toBeEmpty();

    $user->refresh();

    // Pending, not enabled. Turning 2FA on against an unproven secret locks the
    // player out of their own account with no self-service way back.
    expect($user->hasTwoFactorPending())->toBeTrue()
        ->and($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it('requires the current password to begin enrolment', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/2fa/enable', ['current_password' => 'wrong-password-entirely'])
        ->assertStatus(422)
        ->assertJsonPath('errors.current_password.0.code', 'password_incorrect');

    expect($user->fresh()?->two_factor_secret)->toBeNull();
});

it('stores the secret encrypted, never in the response of a later read', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/2fa/enable', ['current_password' => UserFactory::PASSWORD])
        ->assertOk();

    $stored = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    $plaintext = (string) $user->fresh()?->two_factor_secret;

    // Encrypted rather than hashed: TOTP needs the secret back to compute the
    // expected code. But the column is not the plaintext.
    expect($stored)->not->toBe($plaintext);
    expect($stored)->not->toBeEmpty();

    // And it never appears in a state read.
    $body = (string) withHeaders(sessionFor($user))->getJson('/api/v1/auth/2fa')->getContent();

    expect($body)->not->toContain($plaintext);
});

it('confirms enrolment with a live code and issues recovery codes', function (): void {
    $user = User::factory()->create();
    $headers = sessionFor($user);

    withHeaders($headers)
        ->postJson('/api/v1/auth/2fa/enable', ['current_password' => UserFactory::PASSWORD])
        ->assertOk();

    $user->refresh();

    $response = withHeaders($headers)
        ->postJson('/api/v1/auth/2fa/confirm', ['code' => currentTotpCode($user)])
        ->assertOk()
        ->assertJsonPath('status', 'two_factor_enabled')
        ->assertJsonPath('data.two_factor_enabled', true);

    $codes = $response->json('meta.recovery_codes');

    expect($codes)->toBeArray()->toHaveCount(TwoFactorRecoveryCode::COUNT)
        ->and($user->fresh()?->hasTwoFactorEnabled())->toBeTrue();

    // Stored hashed: none of the plaintext codes appears in the table.
    foreach ($codes as $code) {
        expect(TwoFactorRecoveryCode::query()->where('code_hash', $code)->exists())->toBeFalse();
    }
});

it('refuses confirmation with a wrong code', function (): void {
    $user = User::factory()->create();
    $headers = sessionFor($user);

    withHeaders($headers)
        ->postJson('/api/v1/auth/2fa/enable', ['current_password' => UserFactory::PASSWORD])
        ->assertOk();

    withHeaders($headers)
        ->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_code_invalid');

    expect($user->fresh()?->hasTwoFactorEnabled())->toBeFalse();
});

it('refuses confirmation when no enrolment was started', function (): void {
    withHeaders(sessionFor(User::factory()->create()))
        ->postJson('/api/v1/auth/2fa/confirm', ['code' => '123456'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'two_factor_not_pending');
});

it('refuses to begin enrolment when 2FA is already on', function (): void {
    withHeaders(sessionFor(User::factory()->withTwoFactor()->create()))
        ->postJson('/api/v1/auth/2fa/enable', ['current_password' => UserFactory::PASSWORD])
        ->assertStatus(409)
        ->assertJsonPath('code', 'two_factor_already_enabled');
});

it('discards recovery codes from an abandoned enrolment', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $originalCodes = issueRecoveryCodes($user);

    // Turn 2FA off, then start again: an old printout must not bypass the new
    // second factor.
    app(TwoFactorService::class)->disable($user);
    $user->refresh();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/2fa/enable', ['current_password' => UserFactory::PASSWORD])
        ->assertOk();

    expect($user->fresh()?->twoFactorRecoveryCodes()->count())->toBe(0)
        ->and($originalCodes)->toHaveCount(TwoFactorRecoveryCode::COUNT);
});

// ---------------------------------------------------------------------------
// Login challenge
// ---------------------------------------------------------------------------

/** Log in with the password and return the pending challenge token. */
function startChallenge(User $user): string
{
    return (string) postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->assertOk()->json('meta.challenge_token');
}

it('completes the challenge with a valid TOTP code', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $challenge = startChallenge($user);

    $response = postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => currentTotpCode($user),
    ])
        ->assertOk()
        ->assertJsonPath('status', 'authenticated')
        ->assertJsonPath('meta.used_recovery_code', false);

    $token = PersonalAccessToken::findToken(explode('|', (string) $response->json('meta.token'))[1]);

    // The session carries the two-factor ability. This is the only endpoint
    // that grants it, and nothing can add it to an existing token.
    expect($token?->satisfiedTwoFactor())->toBeTrue()
        ->and($response->json('data.session.two_factor_satisfied'))->toBeTrue()
        // Consumed, so the challenge cannot be replayed.
        ->and(TwoFactorChallenge::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects a wrong TOTP code and counts the attempt', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $challenge = startChallenge($user);

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => '000000',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_code_invalid');

    expect(TwoFactorChallenge::query()->where('user_id', $user->id)->sole()->attempts)->toBe(1)
        ->and(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('destroys the challenge once its attempt cap is spent', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $challenge = startChallenge($user);

    for ($i = 0; $i < TwoFactorChallenge::MAX_ATTEMPTS; $i++) {
        postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challenge,
            'code' => '000000',
        ])->assertStatus(422);
    }

    // Gone: an attacker who has the password cannot grind the second factor and
    // must go back through the rate-limited password step to get a new one.
    expect(TwoFactorChallenge::query()->where('user_id', $user->id)->exists())->toBeFalse();

    // Two independent bounds land in the same place here by design — the
    // per-challenge attempt cap and the per-challenge rate limiter are both
    // five. A sixth request is refused either way; which of the two refuses it
    // is not worth coupling a test to Laravel's limiter key derivation for. The
    // "unknown challenge token" test covers the 401 on a dead token.
});

it('rejects the same TOTP code twice inside one time step', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $code = currentTotpCode($user);

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => startChallenge($user),
        'code' => $code,
    ])->assertOk();

    // A TOTP code is valid for a whole 30-second window. Without replay
    // rejection an intercepted code could be reused inside it.
    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => startChallenge($user),
        'code' => $code,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_code_invalid');
});

it('remembers the used time step across a restart', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => startChallenge($user),
        'code' => currentTotpCode($user),
    ])->assertOk();

    // Persisted on the row, not in the cache: replay rejection must survive a
    // cache flush or a process restart, and it must be per account rather than
    // globally keyed on the digits.
    expect($user->fresh()?->two_factor_last_used_timestep)->toBeInt()->toBeGreaterThan(0);
});

it('refuses an expired challenge', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $challenge = startChallenge($user);

    travel(TwoFactorChallenge::TTL_MINUTES + 1)->minutes();

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => currentTotpCode($user->fresh()),
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'two_factor_challenge_invalid');
});

it('refuses an unknown challenge token with the same error as an expired one', function (): void {
    // One error for every failure. Distinguishing them would tell a caller
    // holding a guessed token whether it had ever existed.
    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => str_repeat('z', 64),
        'code' => '123456',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'two_factor_challenge_invalid');
});

// ---------------------------------------------------------------------------
// Recovery codes
// ---------------------------------------------------------------------------

it('completes the challenge with a recovery code', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $codes = issueRecoveryCodes($user);

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => startChallenge($user),
        'recovery_code' => $codes[0],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'authenticated')
        ->assertJsonPath('meta.used_recovery_code', true)
        ->assertJsonPath('meta.recovery_codes_remaining', TwoFactorRecoveryCode::COUNT - 1);
});

it('spends a recovery code exactly once', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $codes = issueRecoveryCodes($user);

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => startChallenge($user),
        'recovery_code' => $codes[0],
    ])->assertOk();

    // Single use, enforced by a conditional UPDATE whose affected-row count is
    // the authority — so two concurrent requests cannot both spend it.
    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => startChallenge($user),
        'recovery_code' => $codes[0],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_code_invalid');

    expect(app(TwoFactorService::class)->unusedRecoveryCodeCount($user->fresh()))
        ->toBe(TwoFactorRecoveryCode::COUNT - 1);
});

it('cannot use another account recovery code', function (): void {
    $victim = User::factory()->withTwoFactor()->create();
    $attacker = User::factory()->withTwoFactor()->create();

    $victimCodes = issueRecoveryCodes($victim);
    issueRecoveryCodes($attacker);

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => startChallenge($attacker),
        'recovery_code' => $victimCodes[0],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'two_factor_code_invalid');

    // The victim's code is untouched — a failed cross-account attempt must not
    // consume it.
    expect(app(TwoFactorService::class)->unusedRecoveryCodeCount($victim))
        ->toBe(TwoFactorRecoveryCode::COUNT);
});

it('regenerates recovery codes and invalidates the previous set', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $original = issueRecoveryCodes($user);

    $response = withHeaders(sessionFor($user, twoFactorSatisfied: true))
        ->postJson('/api/v1/auth/2fa/recovery-codes', ['current_password' => UserFactory::PASSWORD])
        ->assertOk()
        ->assertJsonPath('status', 'recovery_codes_regenerated');

    $fresh = $response->json('meta.recovery_codes');

    expect($fresh)->toHaveCount(TwoFactorRecoveryCode::COUNT)
        ->and(array_intersect($fresh, $original))->toBeEmpty();

    // Regenerating means "the old list is lost or exposed". Leaving it valid
    // would answer a security action by doubling the live bypasses.
    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => startChallenge($user),
        'recovery_code' => $original[0],
    ])->assertStatus(422);
});

it('requires the current password to regenerate recovery codes', function (): void {
    withHeaders(sessionFor(User::factory()->withTwoFactor()->create(), twoFactorSatisfied: true))
        ->postJson('/api/v1/auth/2fa/recovery-codes', ['current_password' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('errors.current_password.0.code', 'password_incorrect');
});

// ---------------------------------------------------------------------------
// Disabling
// ---------------------------------------------------------------------------

it('lets a player disable 2FA', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    withHeaders(sessionFor($user, twoFactorSatisfied: true))
        ->postJson('/api/v1/auth/2fa/disable', ['current_password' => UserFactory::PASSWORD])
        ->assertNoContent();

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->twoFactorRecoveryCodes()->count())->toBe(0);
});

it('refuses to disable 2FA on an administrator account', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    // 2FA is mandatory for the role; an admin cannot opt out.
    withHeaders(sessionFor($admin, twoFactorSatisfied: true))
        ->postJson('/api/v1/auth/2fa/disable', ['current_password' => UserFactory::PASSWORD])
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_two_factor_mandatory');

    expect($admin->fresh()?->hasTwoFactorEnabled())->toBeTrue();
});

it('requires the current password to disable 2FA', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    withHeaders(sessionFor($user, twoFactorSatisfied: true))
        ->postJson('/api/v1/auth/2fa/disable', ['current_password' => 'not-it'])
        ->assertStatus(422);

    expect($user->fresh()?->hasTwoFactorEnabled())->toBeTrue();
});

it('refuses to disable 2FA that is not enabled', function (): void {
    withHeaders(sessionFor(User::factory()->create()))
        ->postJson('/api/v1/auth/2fa/disable', ['current_password' => UserFactory::PASSWORD])
        ->assertStatus(409)
        ->assertJsonPath('code', 'two_factor_not_enabled');
});

it('reports two-factor state without leaking secrets', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    issueRecoveryCodes($user);

    withHeaders(sessionFor($user, twoFactorSatisfied: true))
        ->getJson('/api/v1/auth/2fa')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'enabled' => true,
                'pending' => false,
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => TwoFactorRecoveryCode::COUNT,
                'mandatory' => false,
            ],
        ]);
});

it('refuses a challenge on an unreadable secret rather than failing with a 500', function (): void {
    // A stored secret that is not decodable base32: a truncated column, a dump
    // restored under a different APP_KEY, a bad manual edit. Google2FA throws,
    // and the throw used to reach the caller as a 500 on the login path — an
    // account nobody can sign into, presented as a server fault.
    $user = User::factory()->withTwoFactor()->create();

    $user->forceFill(['two_factor_secret' => 'not-valid-base32!!'])->save();

    expect(app(TwoFactorService::class)->verifyTotp($user->refresh(), '123456'))->toBeFalse();
});

it('records an unreadable secret as an integrity event', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $user->forceFill(['two_factor_secret' => 'not-valid-base32!!'])->save();

    Log::shouldReceive('channel')->with('security')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(
        fn (string $event): bool => $event === AuthLog::TWO_FACTOR_SECRET_UNREADABLE
    );

    app(TwoFactorService::class)->verifyTotp($user->refresh(), '123456');
});
