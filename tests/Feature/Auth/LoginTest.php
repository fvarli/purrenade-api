<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

it('authenticates with correct credentials', function (): void {
    $user = User::factory()->create(['email' => 'ayse@example.test']);

    $response = postJson('/api/v1/auth/login', [
        'email' => 'ayse@example.test',
        'password' => UserFactory::PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'authenticated')
        ->assertJsonPath('data.id', $user->id);

    expect($response->json('meta.token'))->toBeString();
    expect($response->json('meta.token'))->not->toBeEmpty();
});

it('issues a session without the two-factor ability when 2FA is off', function (): void {
    $user = User::factory()->create();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->sole();

    // The distinction the admin gate rests on: a password-only login can never
    // produce a session that claims to have passed a challenge.
    expect($token->satisfiedTwoFactor())->toBeFalse()
        ->and($token->abilities)->toBe([PersonalAccessToken::ABILITY_SESSION])
        // Never the wildcard: `*` would make tokenCan('two-factor') true.
        ->and($token->abilities)->not->toContain('*');
});

it('records the device label on the session', function (): void {
    $user = User::factory()->create();

    postJson(
        '/api/v1/auth/login',
        ['email' => $user->email, 'password' => UserFactory::PASSWORD],
        ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'],
    )->assertOk();

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->sole()->name)
        ->toBe('Chrome on Android');
});

it('gives the same answer for a wrong password and an unknown address', function (): void {
    User::factory()->create(['email' => 'ayse@example.test']);

    $wrongPassword = postJson('/api/v1/auth/login', [
        'email' => 'ayse@example.test',
        'password' => 'definitely-not-the-password',
    ])->assertStatus(401);

    $unknownAccount = postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.test',
        'password' => 'definitely-not-the-password',
    ])->assertStatus(401);

    // Identical, field for field. Any difference is an account-existence
    // oracle that needs no credentials to query.
    expect($wrongPassword->json('code'))->toBe('invalid_credentials')
        ->and($unknownAccount->json('code'))->toBe('invalid_credentials')
        ->and($wrongPassword->json('detail'))->toBe($unknownAccount->json('detail'))
        ->and($wrongPassword->json('title'))->toBe($unknownAccount->json('title'));
});

it('does not distinguish an unverified account at the login step', function (): void {
    // An unverified player may sign in and is routed to verification by the
    // gate, not refused at the door — otherwise the login screen would reveal
    // which addresses are registered but unconfirmed.
    $user = User::factory()->unverified()->create();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'authenticated')
        ->assertJsonPath('data.email_verified', false);
});

it('requires the second factor when 2FA is enabled', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'two_factor_required')
        ->assertJsonPath('meta.recovery_codes_available', true);

    // No session yet. A correct password alone must not produce a credential.
    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0)
        ->and($response->json('meta.challenge_token'))->toBeString()
        ->and($response->json('data'))->toBeNull();

    expect($response->json('meta.challenge_token'))->not->toBeEmpty();

    $challenge = TwoFactorChallenge::query()->where('user_id', $user->id)->sole();

    // Stored as a keyed hash, so the table alone does not yield a usable token.
    expect($challenge->token_hash)->not->toBe($response->json('meta.challenge_token'))
        ->and($challenge->attempts)->toBe(0);
});

it('replaces a previous pending challenge rather than keeping two', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $first = postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => UserFactory::PASSWORD,
    ])->json('meta.challenge_token');

    postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => UserFactory::PASSWORD,
    ])->assertOk();

    // Two live challenges would give an attacker who has the password a spare
    // attempt budget alongside the victim's own login.
    expect(TwoFactorChallenge::query()->where('user_id', $user->id)->count())->toBe(1);

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $first,
        'code' => currentTotpCode($user),
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'two_factor_challenge_invalid');
});

it('rehashes a password stored with weaker argon parameters', function (): void {
    // The realistic upgrade path: the cost parameters are raised, and accounts
    // are re-hashed as their owners sign in rather than all at once or never.
    $user = User::factory()->create();

    $weak = password_hash(UserFactory::PASSWORD, PASSWORD_ARGON2ID, [
        'memory_cost' => 512,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    $user->forceFill(['password' => $weak])->save();

    expect(Hash::needsRehash($weak))->toBeTrue();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    $rehashed = (string) $user->fresh()?->password;

    expect($rehashed)->not->toBe($weak)
        ->and(Hash::check(UserFactory::PASSWORD, $rehashed))->toBeTrue()
        ->and(Hash::needsRehash($rehashed))->toBeFalse();
});

it('refuses a bcrypt hash rather than falling back across algorithms', function (): void {
    // `argon.verify` is on, so the hasher will not verify a `$2y$` hash. That is
    // the algorithm-confusion defence, and it is free because argon2id was
    // chosen before the first account existed. Asserted so that relaxing the
    // flag — which would silently widen what counts as a valid password hash —
    // cannot happen unnoticed.
    $bcrypt = password_hash(UserFactory::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

    expect(fn (): bool => Hash::check(UserFactory::PASSWORD, $bcrypt))
        ->toThrow(RuntimeException::class);
});

it('rejects a login with no credentials at all', function (): void {
    postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.email.0.code', 'required')
        ->assertJsonPath('errors.password.0.code', 'required');
});

it('never echoes the submitted password', function (): void {
    $body = (string) postJson('/api/v1/auth/login', [
        'email' => 'ayse@example.test',
        'password' => 'a-very-distinctive-password-99',
    ])->getContent();

    expect($body)->not->toContain('a-very-distinctive-password-99');
});

it('signs out only the current session', function (): void {
    $user = User::factory()->create();

    $first = sessionFor($user, device: 'Chrome on Linux');
    sessionFor($user, device: 'Safari on iPhone');

    expect($user->tokens()->count())->toBe(2);

    withHeaders($first)->postJson('/api/v1/auth/logout')->assertNoContent();

    // The credential row is deleted, not merely forgotten — revocation is real
    // rather than a cookie being dropped (ADR-0005 §3).
    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->sole()->name)->toBe('Safari on iPhone')
        ->and(PersonalAccessToken::findToken(explode('|', (string) preg_replace('/^Bearer /', '', $first['Authorization']))[1] ?? ''))
        ->toBeNull();

    forgetAuthGuards();

    withHeaders($first)->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});
