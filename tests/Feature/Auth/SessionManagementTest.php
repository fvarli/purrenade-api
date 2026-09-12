<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

it('lists the caller own sessions, newest first', function (): void {
    $user = User::factory()->create();

    sessionFor($user, device: 'Safari on iPhone');
    $current = sessionFor($user, device: 'Chrome on Linux');

    $response = withHeaders($current)->getJson('/api/v1/auth/sessions')->assertOk();

    $sessions = $response->json('data');

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]['device'])->toBe('Chrome on Linux')
        ->and($sessions[0]['is_current'])->toBeTrue()
        ->and($sessions[1]['is_current'])->toBeFalse();
});

it('never exposes the token or the database id', function (): void {
    $user = User::factory()->create();
    $headers = sessionFor($user);

    $response = withHeaders($headers)->getJson('/api/v1/auth/sessions')->assertOk();
    $session = ($response->json('data'))[0];

    // The identifier is an unguessable UUID, not the sequential primary key: a
    // client can only name sessions it was shown.
    expect($session['id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($session)->not->toHaveKey('token')
        ->and($session)->not->toHaveKey('tokenable_id')
        // AUTH-4 stays OPEN: no IP, no derived location, so no personal-data
        // retention obligation is created before the decision is taken.
        ->and($session)->not->toHaveKey('ip_address')
        ->and($session)->not->toHaveKey('location');

    $body = (string) $response->getContent();
    $plaintext = explode('|', (string) preg_replace('/^Bearer /', '', $headers['Authorization']))[1];

    expect($body)->not->toContain($plaintext);
});

it('exposes only the documented session fields', function (): void {
    $user = User::factory()->create();

    $session = (withHeaders(sessionFor($user))
        ->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->json('data'))[0];

    // An exact key list, so a field cannot be added to a public projection
    // without a test failing and forcing a decision about it.
    expect(array_keys($session))->toBe([
        'id', 'device', 'is_current', 'two_factor_satisfied', 'created_at', 'last_active_at',
    ]);
});

it('revokes one session by its public id', function (): void {
    $user = User::factory()->create();

    $current = sessionFor($user, device: 'Chrome on Linux');
    sessionFor($user, device: 'Safari on iPhone');

    $other = collect(withHeaders($current)->getJson('/api/v1/auth/sessions')->json('data'))
        ->firstWhere('is_current', false);

    withHeaders($current)
        ->deleteJson('/api/v1/auth/sessions/'.$other['id'])
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->sole()->name)->toBe('Chrome on Linux');
});

it('returns 404, not 403, for a session belonging to someone else', function (): void {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    $ownerSession = PersonalAccessToken::query()
        ->where('tokenable_id', $owner->id)
        ->orWhere('tokenable_id', $owner->id)
        ->firstOr(function () use ($owner): PersonalAccessToken {
            sessionFor($owner);

            /** @var PersonalAccessToken $token */
            $token = PersonalAccessToken::query()->where('tokenable_id', $owner->id)->sole();

            return $token;
        });

    // 403 would confirm that the session exists and belongs to somebody, which
    // makes the endpoint an existence oracle over a table of session ids.
    withHeaders(sessionFor($attacker))
        ->deleteJson('/api/v1/auth/sessions/'.$ownerSession->public_id)
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    expect($owner->tokens()->count())->toBe(1);
});

it('returns 404 for an id that is not a session at all', function (): void {
    withHeaders(sessionFor(User::factory()->create()))
        ->deleteJson('/api/v1/auth/sessions/'.Str::uuid())
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});

it('signs out every other device and keeps the current one', function (): void {
    $user = User::factory()->create();

    $current = sessionFor($user, device: 'Chrome on Linux');
    sessionFor($user, device: 'Safari on iPhone');
    sessionFor($user, device: 'Firefox on Windows');

    withHeaders($current)
        ->deleteJson('/api/v1/auth/sessions', ['current_password' => UserFactory::PASSWORD])
        ->assertOk()
        ->assertJsonPath('status', 'sessions_revoked')
        ->assertJsonPath('meta.revoked_count', 2);

    // AUTH-2 resolved: revoke-all keeps the caller's own session. The action is
    // "get everyone else out"; POST /auth/logout exists for the other intent.
    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->sole()->name)->toBe('Chrome on Linux');
});

it('requires the current password to sign out other devices', function (): void {
    $user = User::factory()->create();
    $current = sessionFor($user);
    sessionFor($user, device: 'Safari on iPhone');

    withHeaders($current)
        ->deleteJson('/api/v1/auth/sessions', ['current_password' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('errors.current_password.0.code', 'password_incorrect');

    expect($user->tokens()->count())->toBe(2);
});

it('never touches another account sessions on revoke-all', function (): void {
    $user = User::factory()->create();
    $bystander = User::factory()->create();

    $current = sessionFor($user);
    sessionFor($user, device: 'Safari on iPhone');
    sessionFor($bystander, device: 'Chrome on macOS');

    withHeaders($current)
        ->deleteJson('/api/v1/auth/sessions', ['current_password' => UserFactory::PASSWORD])
        ->assertOk();

    expect($bystander->tokens()->count())->toBe(1);
});

it('reports which sessions passed a two-factor challenge', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $challenged = sessionFor($user, twoFactorSatisfied: true, device: 'Chrome on Linux');
    sessionFor($user, twoFactorSatisfied: false, device: 'Safari on iPhone');

    $sessions = collect(withHeaders($challenged)
        ->getJson('/api/v1/auth/sessions')->assertOk()->json('data'))
        ->keyBy('device');

    expect($sessions['Chrome on Linux']['two_factor_satisfied'])->toBeTrue()
        ->and($sessions['Safari on iPhone']['two_factor_satisfied'])->toBeFalse();
});

it('requires a verified address to manage sessions', function (): void {
    // AUTH-1: the unverified allowance is exactly four endpoints, and session
    // management is not one of them.
    withHeaders(sessionFor(User::factory()->unverified()->create()))
        ->getJson('/api/v1/auth/sessions')
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified');
});

it('requires authentication to list sessions', function (): void {
    getJson('/api/v1/auth/sessions')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});
