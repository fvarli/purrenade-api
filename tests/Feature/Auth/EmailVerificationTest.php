<?php

declare(strict_types=1);

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

/**
 * A six-digit code is one million possibilities. Its security is entirely in the
 * TTL, the attempt cap, the resend cooldown and the rate limits — so those four
 * are what this file tests, rather than the code itself.
 */

/** Issue a real challenge and hand back the plaintext, as the mail would. */
function issueVerificationCode(User $user): string
{
    return app(EmailVerificationService::class)->issue($user);
}

it('verifies the address with the correct code', function (): void {
    $user = User::factory()->unverified()->create();
    $code = issueVerificationCode($user);

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    expect($user->fresh()?->email_verified_at)->not->toBeNull()
        // Single use: consumed, so a replayed request cannot succeed twice.
        ->and(EmailVerificationCode::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('refuses a wrong code and counts the attempt', function (): void {
    $user = User::factory()->unverified()->create();
    issueVerificationCode($user);

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'verification_code_invalid');

    expect(EmailVerificationCode::query()->where('user_id', $user->id)->sole()->attempts)->toBe(1)
        ->and($user->fresh()?->email_verified_at)->toBeNull();
});

it('destroys the code once the attempt cap is exhausted', function (): void {
    $user = User::factory()->unverified()->create();
    issueVerificationCode($user);
    $headers = sessionFor($user);

    for ($attempt = 1; $attempt <= EmailVerificationCode::MAX_ATTEMPTS; $attempt++) {
        withHeaders($headers)
            ->postJson('/api/v1/auth/email/verify', ['code' => '111111'])
            ->assertStatus(422);
    }

    // Gone, so guessing cannot continue against this challenge. The player must
    // request a new code, which the cooldown and the resend limiter both bound.
    expect(EmailVerificationCode::query()->where('user_id', $user->id)->exists())->toBeFalse();

    withHeaders($headers)
        ->postJson('/api/v1/auth/email/verify', ['code' => '111111'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'verification_code_missing');
});

it('refuses an expired code', function (): void {
    $user = User::factory()->unverified()->create();
    $code = issueVerificationCode($user);

    travel(EmailVerificationService::TTL_MINUTES + 1)->minutes();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('code', 'verification_code_expired');

    expect($user->fresh()?->email_verified_at)->toBeNull();
});

it('invalidates the previous code when a new one is issued', function (): void {
    $user = User::factory()->unverified()->create();
    $first = issueVerificationCode($user);

    travel(EmailVerificationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    $second = issueVerificationCode($user);

    expect($first)->not->toBe($second);

    // The old code is dead the moment a new one is sent. Otherwise every resend
    // would widen the attacker's window instead of refreshing it.
    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify', ['code' => $first])
        ->assertStatus(422)
        ->assertJsonPath('code', 'verification_code_invalid');

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify', ['code' => $second])
        ->assertOk();
});

it('keeps at most one live challenge per account', function (): void {
    $user = User::factory()->unverified()->create();

    issueVerificationCode($user);
    travel(60)->seconds();
    issueVerificationCode($user);

    expect(EmailVerificationCode::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('cannot verify another account with a code issued for someone else', function (): void {
    $owner = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $code = issueVerificationCode($owner);
    issueVerificationCode($other);

    // Structurally impossible rather than merely checked: the endpoint is
    // authenticated and looks the challenge up by the caller's own id.
    withHeaders(sessionFor($other))
        ->postJson('/api/v1/auth/email/verify', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('code', 'verification_code_invalid');

    expect($other->fresh()?->email_verified_at)->toBeNull()
        ->and($owner->fresh()?->email_verified_at)->toBeNull();
});

it('refuses a code issued for a different address', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'first@example.test']);
    $code = issueVerificationCode($user);

    // The address changed after the code was sent, so the code can no longer
    // prove anything about the current one.
    $user->forceFill(['email' => 'second@example.test'])->save();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('code', 'verification_code_invalid');
});

it('reports the remaining cooldown instead of a bare error', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    issueVerificationCode($user);

    $response = withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify/resend')
        ->assertStatus(429)
        ->assertJsonPath('code', 'verification_resend_cooldown');

    // The design renders a live countdown, which it can only do if the server
    // says how long is left.
    expect($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(EmailVerificationService::RESEND_COOLDOWN_SECONDS);

    $response->assertHeader('Retry-After');

    Notification::assertNothingSent();
});

it('resends once the cooldown has elapsed', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    issueVerificationCode($user);

    travel(EmailVerificationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify/resend')
        ->assertOk()
        ->assertJsonPath('meta.email_verification.expires_in', EmailVerificationService::TTL_MINUTES * 60);

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('refuses verification on an already-verified account', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify', ['code' => '123456'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'email_already_verified');
});

it('refuses a resend on an already-verified account', function (): void {
    Notification::fake();

    withHeaders(sessionFor(User::factory()->create()))
        ->postJson('/api/v1/auth/email/verify/resend')
        ->assertStatus(409)
        ->assertJsonPath('code', 'email_already_verified');

    Notification::assertNothingSent();
});

it('stores the code hashed, never in plaintext', function (): void {
    $user = User::factory()->unverified()->create();
    $code = issueVerificationCode($user);

    $challenge = EmailVerificationCode::query()->where('user_id', $user->id)->sole();

    expect($challenge->code_hash)->not->toBe($code)
        // A slow hash, not a fast one: six digits of entropy would be reversed
        // instantly by exhaustive search against a fast digest.
        ->and(Hash::check($code, $challenge->code_hash))->toBeTrue();
});

it('rejects a malformed code before touching the challenge', function (string $code): void {
    $user = User::factory()->unverified()->create();
    issueVerificationCode($user);

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/auth/email/verify', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    // No attempt spent: malformed input is not a guess.
    expect(EmailVerificationCode::query()->where('user_id', $user->id)->sole()->attempts)->toBe(0);
})->with([
    'too short' => ['12345'],
    'too long' => ['1234567'],
    'not digits' => ['abcdef'],
    'empty' => [''],
]);

it('requires authentication', function (): void {
    postJson('/api/v1/auth/email/verify', ['code' => '123456'])
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});

it('lets an unverified account read its own state', function (): void {
    // One of exactly four endpoints an unverified session may reach — a player
    // who cannot ask "am I verified?" cannot be told to verify.
    $user = User::factory()->unverified()->create();

    withHeaders(sessionFor($user))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonStructure(['meta' => ['email_verification' => ['resend_available_in']]]);
});
