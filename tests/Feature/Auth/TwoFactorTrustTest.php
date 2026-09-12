<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Factories\UserFactory;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * Trust in a second factor does not outlive the second factor.
 *
 * The `two-factor` ability records that a challenge was passed. Sanctum cannot
 * withdraw an ability from a token that already exists, so on its own the
 * ability survives the secret it was proof of — and every account-level check
 * reads as satisfied again the moment a new secret is confirmed. Each condition
 * is individually true; their conjunction is false.
 *
 * These are the transitions where that gap opened. The scenario is not exotic:
 * it is what happens whenever somebody rotates a stolen authenticator, which is
 * the one time the answer has to be right.
 */
it('refuses an admin session after the account disables two-factor', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $headers = sessionFor($admin, twoFactorSatisfied: true);

    withHeaders($headers)->getJson('/api/v1/admin/overview')->assertOk();

    // Forced rather than through the endpoint: an admin cannot disable 2FA, so
    // this is the state left by an operator or a support action, and the gate
    // must hold whatever produced it.
    $admin->forceFill([
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    // The guard caches the resolved user for the lifetime of the application
    // instance, and a test reuses one across requests where production would
    // build a new one. Without this the second request re-reads the first
    // request's user and the assertion tests nothing.
    forgetAuthGuards();

    withHeaders($headers)
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_two_factor_required');
});

it('does not re-arm an old session when two-factor is disabled and enabled again', function (): void {
    // The defect this whole mechanism exists for. Before the fix, every
    // condition became true again at the end of this cycle and the old session
    // was privileged once more, having never met the new secret.
    $user = User::factory()->admin()->withTwoFactor()->create();
    $stale = sessionFor($user, twoFactorSatisfied: true);

    withHeaders($stale)->getJson('/api/v1/admin/overview')->assertOk();

    $service = app(TwoFactorService::class);

    $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $service->beginEnrolment($user);
    $service->confirmEnrolment($user->refresh(), currentTotpCode($user));

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();

    forgetAuthGuards();

    // 401, not 403: the rotation revoked the token outright, so there is no
    // longer a session to evaluate. That is the first of the two defences and
    // the one that fires here. The second — the generation check, for a session
    // that survives the eviction because it is the one performing it — is
    // proved separately below.
    withHeaders($stale)
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    expect($user->tokens()->count())->toBe(0);
});

it('refuses a session stamped with an older second factor', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    withHeaders(staleTwoFactorSessionFor($admin))
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_two_factor_required');
});

it('refuses a session issued before the generation counter existed', function (): void {
    // A token carrying the ability but no generation at all — every row that
    // predates the migration. Null must not compare equal to the default.
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $headers = sessionFor($admin, twoFactorSatisfied: true);

    $admin->tokens()->latest('id')->first()?->forceFill(['two_factor_version' => null])->save();

    withHeaders($headers)
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_two_factor_required');
});

it('ends every other session when enrolment begins', function (): void {
    $user = User::factory()->create();
    $current = sessionFor($user);
    sessionFor($user, device: 'Safari on iPhone');
    sessionFor($user, device: 'Firefox on Windows');

    expect($user->tokens()->count())->toBe(3);

    withHeaders($current)
        ->postJson('/api/v1/auth/2fa/enable', ['current_password' => UserFactory::PASSWORD])
        ->assertOk();

    // The caller keeps the device they are setting 2FA up on; nothing else
    // survives a change to the account's second factor.
    expect($user->tokens()->count())->toBe(1);

    withHeaders($current)->getJson('/api/v1/auth/me')->assertOk();
});

it('ends every other session when recovery codes are regenerated', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $current = sessionFor($user, twoFactorSatisfied: true);
    sessionFor($user, twoFactorSatisfied: true, device: 'Safari on iPhone');

    expect($user->tokens()->count())->toBe(2);

    withHeaders($current)
        ->postJson('/api/v1/auth/2fa/recovery-codes', ['current_password' => UserFactory::PASSWORD])
        ->assertOk();

    expect($user->tokens()->count())->toBe(1);
});

it('ends every other session when two-factor is disabled', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $current = sessionFor($user, twoFactorSatisfied: true);
    sessionFor($user, twoFactorSatisfied: true, device: 'Safari on iPhone');

    withHeaders($current)
        ->postJson('/api/v1/auth/2fa/disable', [
            'current_password' => UserFactory::PASSWORD,
            'code' => currentTotpCode($user),
        ])
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(1);
});

it('advances the generation on each material change', function (): void {
    $user = User::factory()->create();
    $service = app(TwoFactorService::class);

    $start = $user->two_factor_version;

    $service->beginEnrolment($user);
    $afterBegin = $user->refresh()->two_factor_version;

    $service->confirmEnrolment($user, currentTotpCode($user));
    $afterConfirm = $user->refresh()->two_factor_version;

    $service->replaceRecoveryCodes($user);
    $afterCodes = $user->refresh()->two_factor_version;

    expect($afterBegin)->toBeGreaterThan($start)
        ->and($afterConfirm)->toBeGreaterThan($afterBegin)
        ->and($afterCodes)->toBeGreaterThan($afterConfirm);
});

it('restores admin access only after a fresh challenge against the new secret', function (): void {
    // The whole cycle, end to end: rotating the factor locks the admin out of
    // the privileged surface until they prove the new one, and then lets them
    // back in. Losing access permanently would be a different bug.
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $service = app(TwoFactorService::class);

    $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $service->beginEnrolment($admin);
    $service->confirmEnrolment($admin->refresh(), currentTotpCode($admin));

    $challenge = postJson('/api/v1/auth/login', [
        'email' => $admin->email,
        'password' => UserFactory::PASSWORD,
    ])->json('meta.challenge_token');

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        // The next step's code. The confirmation above spent the current one,
        // and the replay guard is right to refuse it a second time — not even
        // its owner gets to present the same code twice.
        'code' => totpCodeAtNextStep($admin->refresh()),
    ])->assertOk();

    $token = $admin->tokens()->latest('id')->first();

    expect($token?->two_factor_version)->toBe($admin->refresh()->two_factor_version);
});
