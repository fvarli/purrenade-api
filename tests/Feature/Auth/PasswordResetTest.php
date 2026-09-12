<?php

declare(strict_types=1);

use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\TwoFactorService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

const NEW_PASSWORD = 'yeni-sifre-sahilde-2026';

/** Request a reset and capture the token the notification would carry. */
function resetTokenFor(User $user): string
{
    return Password::broker()->createToken($user);
}

// ---------------------------------------------------------------------------
// Requesting a link
// ---------------------------------------------------------------------------

it('sends a reset link to a known address', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
        ->assertStatus(202)
        ->assertJsonPath('status', 'accepted');

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('answers identically for an address that does not exist', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $known = postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertStatus(202);
    $unknown = postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])->assertStatus(202);

    // Byte-for-byte identical. Any difference — body, status, even a distinct
    // message — turns this endpoint into an account-existence oracle that
    // needs no credentials to query.
    expect($unknown->getContent())->toBe($known->getContent());

    Notification::assertSentTimes(ResetPasswordNotification::class, 1);
});

it('points the reset link at the frontend, not at the API', function (): void {
    // The player lands on a Nuxt page which posts the token back through the
    // BFF. A link to this service would open a page it does not have.
    $user = User::factory()->create();

    $mail = (new ResetPasswordNotification('a-token'))->toMail($user);

    expect($mail->actionUrl)->toStartWith(config('frontend.url'))
        ->and($mail->actionUrl)->toContain(config('frontend.password_reset_path'))
        ->and($mail->actionUrl)->toContain('token=a-token')
        ->and($mail->actionUrl)->toContain(urlencode($user->email));
});

// ---------------------------------------------------------------------------
// Completing a reset
// ---------------------------------------------------------------------------

it('resets the password with a valid token', function (): void {
    $user = User::factory()->create();
    $token = resetTokenFor($user);

    postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'password_reset');

    expect(Hash::check(NEW_PASSWORD, (string) $user->fresh()?->password))->toBeTrue();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => NEW_PASSWORD])
        ->assertOk();
});

it('refuses an invalid token', function (): void {
    $user = User::factory()->create();

    postJson('/api/v1/auth/password/reset', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'reset_token_invalid');

    expect(Hash::check(UserFactory::PASSWORD, (string) $user->fresh()?->password))->toBeTrue();
});

it('refuses an expired token', function (): void {
    $user = User::factory()->create();
    $token = resetTokenFor($user);

    travel((int) config('auth.passwords.users.expire') + 1)->minutes();

    postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'reset_token_invalid');
});

it('consumes the token, so a reset cannot be replayed', function (): void {
    $user = User::factory()->create();
    $token = resetTokenFor($user);

    $payload = [
        'token' => $token,
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ];

    postJson('/api/v1/auth/password/reset', $payload)->assertOk();
    postJson('/api/v1/auth/password/reset', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'reset_token_invalid');
});

it('gives the same answer for an unknown address as for a bad token', function (): void {
    $unknownAddress = postJson('/api/v1/auth/password/reset', [
        'token' => 'anything',
        'email' => 'nobody@example.test',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertStatus(422);

    $badToken = postJson('/api/v1/auth/password/reset', [
        'token' => 'anything',
        'email' => User::factory()->create()->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertStatus(422);

    expect($unknownAddress->json('code'))->toBe('reset_token_invalid')
        ->and($badToken->json('code'))->toBe('reset_token_invalid')
        ->and($unknownAddress->json('detail'))->toBe($badToken->json('detail'));
});

it('stores reset tokens hashed', function (): void {
    $user = User::factory()->create();
    $token = resetTokenFor($user);

    $stored = (string) DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

    expect($stored)->not->toBe($token);
    expect($stored)->not->toBeEmpty();
});

it('applies the same password policy as registration', function (): void {
    // A reset path that accepts a weaker password than registration is how a
    // policy quietly becomes advisory.
    $user = User::factory()->create();

    postJson('/api/v1/auth/password/reset', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => 'kisa',
        'password_confirmation' => 'kisa',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0.code', 'too_short');
});

it('revokes every session, including any the caller holds', function (): void {
    $user = User::factory()->create();

    sessionFor($user, device: 'Chrome on Linux');
    sessionFor($user, device: 'Safari on iPhone');

    expect($user->tokens()->count())->toBe(2);

    postJson('/api/v1/auth/password/reset', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    // Every one. A reset is normally a response to suspected compromise, so the
    // point is to evict whoever else is signed in — including sessions this
    // request cannot identify.
    expect($user->tokens()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// The APPROVED invariant — regression gate S8
// ---------------------------------------------------------------------------

/**
 * Regression gate S8 — the APPROVED invariant.
 *
 * Email already controls password reset. If a reset also cleared 2FA, then
 * compromising a mailbox would compromise the whole account in one step and the
 * second factor would defend against exactly the attack it exists to stop.
 *
 * docs/security/authentication.md §4.1.
 *
 * Each property is asserted by its own test, so a partial regression cannot hide
 * behind a passing neighbour. The fixture is a function rather than shared
 * `beforeEach` state: each test then states its own starting point, and the
 * snapshot it compares against is a local value nothing else can touch.
 *
 * @return array{user: User, secret: string, confirmedAt: string, codes: list<string>}
 */
function resetPasswordOnTwoFactorAccount(): array
{
    $user = User::factory()->withTwoFactor()->create();

    $secret = (string) $user->two_factor_secret;
    $confirmedAt = (string) $user->two_factor_confirmed_at?->toIso8601String();
    $codes = issueRecoveryCodes($user);

    postJson('/api/v1/auth/password/reset', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    return [
        'user' => $user->fresh() ?? $user,
        'secret' => $secret,
        'confirmedAt' => $confirmedAt,
        'codes' => $codes,
    ];
}

it('S8: leaves two-factor enrolment enabled after a reset', function (): void {
    ['user' => $user, 'confirmedAt' => $confirmedAt] = resetPasswordOnTwoFactorAccount();

    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->two_factor_confirmed_at?->toIso8601String())->toBe($confirmedAt);
});

it('S8: leaves the TOTP secret unchanged after a reset', function (): void {
    ['user' => $user, 'secret' => $secret] = resetPasswordOnTwoFactorAccount();

    expect($user->two_factor_secret)->toBe($secret);
    expect($user->two_factor_secret)->not->toBeNull();
});

it('S8: consumes no recovery code and regenerates none', function (): void {
    ['user' => $user, 'codes' => $codes] = resetPasswordOnTwoFactorAccount();

    expect(app(TwoFactorService::class)->unusedRecoveryCodeCount($user))
        ->toBe(TwoFactorRecoveryCode::COUNT);

    // The same codes, still usable — not merely the same count.
    $challenge = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => NEW_PASSWORD,
    ])->assertOk()->json('meta.challenge_token');

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'recovery_code' => $codes[0],
    ])->assertOk();
});

it('S8: still challenges the next login for the second factor', function (): void {
    ['user' => $user] = resetPasswordOnTwoFactorAccount();

    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => NEW_PASSWORD,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'two_factor_required');

    // A reset changes one factor. The other is still required, and the session
    // it eventually yields is the only kind carrying the two-factor ability.
    expect($response->json('meta.token'))->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
});

it('S8: marks no challenge as already satisfied', function (): void {
    ['user' => $user, 'codes' => $codes] = resetPasswordOnTwoFactorAccount();

    $challenge = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => NEW_PASSWORD,
    ])->assertOk()->json('meta.challenge_token');

    // A pending challenge, not a passed one: it still needs a real code.
    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => '000000',
    ])->assertStatus(422);

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'recovery_code' => $codes[0],
    ])->assertOk();
});

// ---------------------------------------------------------------------------
// Changing a password from inside a session
// ---------------------------------------------------------------------------

it('changes the password with the current one supplied', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->putJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])
        ->assertNoContent();

    expect(Hash::check(NEW_PASSWORD, (string) $user->fresh()?->password))->toBeTrue();
});

it('refuses a change without the current password', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong',
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.current_password.0.code', 'password_incorrect');

    expect(Hash::check(UserFactory::PASSWORD, (string) $user->fresh()?->password))->toBeTrue();
});

it('keeps the current session and ends the others on a password change', function (): void {
    $user = User::factory()->create();

    $current = sessionFor($user, device: 'Chrome on Linux');
    sessionFor($user, device: 'Safari on iPhone');
    sessionFor($user, device: 'Firefox on Windows');

    withHeaders($current)
        ->putJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])
        ->assertNoContent();

    // A deliberate change by someone who proved the old password is not the
    // same event as a reset: signing them out of the screen they just used
    // would be friction with no security value.
    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->sole()->name)->toBe('Chrome on Linux');
});

it('leaves two-factor state alone on a password change too', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $secret = $user->two_factor_secret;
    issueRecoveryCodes($user);

    withHeaders(sessionFor($user, twoFactorSatisfied: true))
        ->putJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])
        ->assertNoContent();

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->two_factor_secret)->toBe($secret)
        ->and(app(TwoFactorService::class)->unusedRecoveryCodeCount($user))
        ->toBe(TwoFactorRecoveryCode::COUNT);
});

it('requires a verified address to change a password', function (): void {
    withHeaders(sessionFor(User::factory()->unverified()->create()))
        ->putJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified');
});

/*
|--------------------------------------------------------------------------
| S9 — a reset evicts the pending challenge too
|--------------------------------------------------------------------------
|
| Revoking tokens is not the whole eviction. A two-factor challenge is opened
| on the strength of a correct password and then lives in its own table for five
| minutes, so an attacker who knew the old password kept a redeemable
| half-authenticated handle across the reset — and redeeming it minted a full
| session *after* every existing one had been destroyed.
|
| The companion of S8. S8 says a reset must not weaken the second factor; S9
| says it must not leave the first one's leftovers lying around.
*/

it('S9: kills a challenge opened before the reset', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $challenge = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->json('meta.challenge_token');

    expect($challenge)->toBeString();

    postJson('/api/v1/auth/password/reset', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => currentTotpCode($user->refresh()),
    ])
        // 401 and the same code an unknown token gets — the purged challenge is
        // indistinguishable from one that never existed, which is the point.
        ->assertStatus(401)
        ->assertJsonPath('code', 'two_factor_challenge_invalid');

    expect(DB::table('two_factor_challenges')->where('user_id', $user->id)->count())->toBe(0);
});

it('S9: kills a challenge opened before an authenticated password change', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $challenge = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ])->json('meta.challenge_token');

    withHeaders(sessionFor($user, twoFactorSatisfied: true))
        ->putJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => NEW_PASSWORD,
            'password_confirmation' => NEW_PASSWORD,
        ])
        ->assertNoContent();

    postJson('/api/v1/auth/2fa/challenge', [
        'challenge_token' => $challenge,
        'code' => totpCodeAtNextStep($user->refresh()),
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'two_factor_challenge_invalid');
});

it('S9: still leaves two-factor enrolment intact', function (): void {
    // The purge is aimed at the pending challenge, not at the second factor.
    // Deleting a row too many here would quietly re-create the S8 hole.
    $user = User::factory()->withTwoFactor()->create();
    $secret = $user->two_factor_secret;

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => UserFactory::PASSWORD,
    ]);

    postJson('/api/v1/auth/password/reset', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->two_factor_secret)->toBe($secret)
        ->and($user->twoFactorRecoveryCodes()->whereNull('used_at')->count())
        ->toBe(TwoFactorRecoveryCode::COUNT);
});
