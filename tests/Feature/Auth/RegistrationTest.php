<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\EmailVerificationService;
use App\Support\DisplayName;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;

beforeEach(function (): void {
    Notification::fake();
});

/** @return array<string, string> */
function registrationPayload(array $overrides = []): array
{
    return [
        'display_name' => 'Aysenur',
        'email' => 'ayse@example.test',
        'password' => 'sahilde-kosan-kedi-42',
        'password_confirmation' => 'sahilde-kosan-kedi-42',
        ...$overrides,
    ];
}

it('creates an unverified player and issues a session', function (): void {
    $response = postJson('/api/v1/auth/register', registrationPayload())
        ->assertCreated();

    $response->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.role', 'player')
        ->assertJsonPath('data.display_name', 'Aysenur')
        ->assertJsonPath('status', 'authenticated');

    // The session token is returned to the caller — the BFF — and is the only
    // way the new account can reach the verification endpoints.
    expect($response->json('meta.token'))->toBeString();
    expect($response->json('meta.token'))->not->toBeEmpty();

    $user = User::query()->where('email', 'ayse@example.test')->sole();

    expect($user->role)->toBe(UserRole::Player)
        ->and($user->email_verified_at)->toBeNull()
        // Privilege columns are never mass-assignable, so a caller cannot
        // arrive pre-verified or pre-promoted.
        ->and($user->display_name_normalized)->toBe('aysenur');
});

it('never lets a caller choose their own role or verification state', function (): void {
    postJson('/api/v1/auth/register', registrationPayload([
        'role' => 'admin',
        'email_verified_at' => now()->toIso8601String(),
    ]))->assertCreated();

    $user = User::query()->where('email', 'ayse@example.test')->sole();

    expect($user->role)->toBe(UserRole::Player)
        ->and($user->email_verified_at)->toBeNull();
});

it('dispatches a six-digit verification code', function (): void {
    postJson('/api/v1/auth/register', registrationPayload())->assertCreated();

    $user = User::query()->where('email', 'ayse@example.test')->sole();

    Notification::assertSentTo($user, VerifyEmailNotification::class);

    $challenge = EmailVerificationCode::query()->where('user_id', $user->id)->sole();

    // Hashed at rest. The plaintext exists only in the outgoing mail.
    expect($challenge->code_hash)->not->toBeEmpty()
        ->and($challenge->code_hash)->not->toMatch('/^\d{6}$/')
        ->and($challenge->email)->toBe('ayse@example.test')
        ->and($challenge->attempts)->toBe(0);
});

it('returns the resend cooldown so the client can render the countdown', function (): void {
    $remaining = postJson('/api/v1/auth/register', registrationPayload())
        ->assertCreated()
        ->json('meta.email_verification.resend_available_in');

    // A range, not the constant: the request itself takes a moment (argon2id is
    // deliberately slow), so a second or two has genuinely elapsed by the time
    // the response is written. Pinning the exact value would be asserting that
    // registration is instantaneous.
    expect($remaining)->toBeInt()
        ->toBeLessThanOrEqual(EmailVerificationService::RESEND_COOLDOWN_SECONDS)
        ->toBeGreaterThan(EmailVerificationService::RESEND_COOLDOWN_SECONDS - 10);
});

it('locks the resend cooldown at the value the design specifies', function (): void {
    // v0.3 board 04 renders "(0:42)". One constant is the source of both the
    // countdown and the server-side refusal.
    expect(EmailVerificationService::RESEND_COOLDOWN_SECONDS)->toBe(42);
});

it('refuses a duplicate email address', function (): void {
    User::factory()->create(['email' => 'ayse@example.test']);

    postJson('/api/v1/auth/register', registrationPayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.email.0.code', 'taken');
});

it('treats email addresses as case-insensitive', function (): void {
    User::factory()->create(['email' => 'ayse@example.test']);

    // Lower-cased before validation, so a differently-cased address cannot
    // create a second account for the same mailbox.
    postJson('/api/v1/auth/register', registrationPayload(['email' => 'AySe@Example.TEST']))
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0.code', 'taken');
});

it('refuses a display name that differs only by case', function (): void {
    $existing = User::factory()->create();
    $existing->setDisplayName('SahilKedisi');
    $existing->save();

    postJson('/api/v1/auth/register', registrationPayload(['display_name' => 'sahilkedisi']))
        ->assertStatus(422)
        ->assertJsonPath('errors.display_name.0.code', 'taken');
});

it('folds the Turkish dotted capital I, so lookalike names collide', function (): void {
    // Not a nicety: the primary audience writes Turkish, and without this
    // "İstanbul" and "istanbul" would be two separate leaderboard entries.
    expect(DisplayName::normalize('İSTANBUL'))->toBe(DisplayName::normalize('istanbul'))
        ->and(DisplayName::normalize('İstanbul'))->toBe(DisplayName::normalize('Istanbul'));
});

it('keeps dotless ı distinct from i', function (): void {
    // The accepted asymmetry: no locale-blind fold can satisfy the Turkish and
    // English mappings of `I` at once, so `ı` remains its own letter.
    expect(DisplayName::normalize('ıstanbul'))->not->toBe(DisplayName::normalize('istanbul'));
});

it('collapses compatibility forms of the same glyphs', function (): void {
    // NFKC, so a name cannot be duplicated by choosing fullwidth code points.
    expect(DisplayName::normalize('Ａyse'))->toBe(DisplayName::normalize('ayse'));
});

it('refuses a registration whose display name differs only by the dotted capital I', function (): void {
    $existing = User::factory()->create();
    $existing->setDisplayName('istanbul');
    $existing->save();

    postJson('/api/v1/auth/register', registrationPayload(['display_name' => 'İSTANBUL']))
        ->assertStatus(422)
        ->assertJsonPath('errors.display_name.0.code', 'taken');
});

dataset('malformed display names', [
    'too short' => ['ab'],
    'too long' => ['abcdefghijklmnopqrstuvwxyz'],
    'leading punctuation' => ['_ayse'],
    'trailing punctuation' => ['ayse.'],
    'no letters' => ['123_456'],
    'disallowed character' => ['ayse nur'],
    'emoji' => ['ayse🐾'],
]);

it('refuses malformed display names', function (string $displayName): void {
    postJson('/api/v1/auth/register', registrationPayload(['display_name' => $displayName]))
        ->assertStatus(422)
        ->assertJsonPath('errors.display_name.0.code', 'format_invalid');
})->with('malformed display names');

it('accepts Turkish and Spanish letters in a display name', function (string $displayName): void {
    postJson('/api/v1/auth/register', registrationPayload(['display_name' => $displayName]))
        ->assertCreated();
})->with([
    'Turkish' => ['Ayşenur'],
    'Spanish' => ['Señorita'],
    'with permitted punctuation' => ['ayse_nur-01'],
]);

it('refuses a password shorter than the policy minimum', function (): void {
    postJson('/api/v1/auth/register', registrationPayload([
        'password' => 'kisa-sifre',
        'password_confirmation' => 'kisa-sifre',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0.code', 'too_short');
});

it('refuses a mismatched confirmation', function (): void {
    postJson('/api/v1/auth/register', registrationPayload([
        'password_confirmation' => 'baska-bir-sifre-42',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.password_confirmation.0.code', 'confirmation_mismatch');
});

it('never returns the password or its hash', function (): void {
    $body = (string) postJson('/api/v1/auth/register', registrationPayload())->getContent();

    expect($body)
        ->not->toContain('sahilde-kosan-kedi-42')
        ->and($body)->not->toContain('$argon2')
        ->and($body)->not->toContain('password');
});
