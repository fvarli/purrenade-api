<?php

declare(strict_types=1);

use App\Rules\NotCompromised;
use App\Support\PasswordPolicy;
use Illuminate\Contracts\Validation\UncompromisedVerifier;

use function Pest\Laravel\postJson;

/** @return array<string, string> */
function policyPayload(string $password): array
{
    return [
        'display_name' => 'PolicyTester',
        'email' => 'policy@example.test',
        'password' => $password,
        'password_confirmation' => $password,
    ];
}

it('accepts a long passphrase with no special characters', function (): void {
    // Length is the property that resists guessing. Composition rules mostly
    // produce `Purrenade1!`, which is why current guidance is against them.
    postJson('/api/v1/auth/register', policyPayload('sahilde kosan kedi ayse'))
        ->assertCreated();
});

it('refuses a password below the minimum length', function (): void {
    postJson('/api/v1/auth/register', policyPayload(str_repeat('a', PasswordPolicy::MIN_LENGTH - 1)))
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0.code', 'too_short');
});

it('accepts a password exactly at the minimum', function (): void {
    postJson('/api/v1/auth/register', policyPayload(str_repeat('ab', 6)))
        ->assertCreated();
});

it('refuses a password over the maximum rather than truncating it', function (): void {
    // A bound exists because hashing cost is otherwise attacker-controlled: a
    // one-megabyte password is a denial-of-service request. It is a validation
    // error, never a silent trim — a password quietly shortened to fit is a
    // password the player cannot reproduce.
    postJson('/api/v1/auth/register', policyPayload(str_repeat('a', PasswordPolicy::MAX_LENGTH + 1)))
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0.code', 'too_long');
});

it('accepts a password exactly at the maximum', function (): void {
    postJson('/api/v1/auth/register', policyPayload(str_repeat('a', PasswordPolicy::MAX_LENGTH)))
        ->assertCreated();
});

it('preserves a long password exactly, with no truncation', function (): void {
    // The reason argon2id rather than bcrypt: bcrypt ignores everything past
    // byte 72, so two passwords differing only after that would both open the
    // account. This proves the full string is what authenticates.
    $long = str_repeat('a', 80).'DISTINCTIVE-TAIL';
    $truncated = substr($long, 0, 72);

    postJson('/api/v1/auth/register', policyPayload($long))->assertCreated();

    postJson('/api/v1/auth/login', ['email' => 'policy@example.test', 'password' => $long])
        ->assertOk();

    postJson('/api/v1/auth/login', ['email' => 'policy@example.test', 'password' => $truncated])
        ->assertStatus(401);
});

it('refuses a password found in a breach corpus', function (): void {
    // The permissive stub from tests/Pest.php is replaced with one that reports
    // compromised, which proves the rule is wired without depending on the
    // Pwned Passwords service being reachable from CI.
    app()->bind(UncompromisedVerifier::class, fn (): UncompromisedVerifier => new class implements UncompromisedVerifier
    {
        public function verify($data): bool
        {
            return false;
        }
    });

    postJson('/api/v1/auth/register', policyPayload('this-would-be-a-breached-password'))
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0.code', 'password_compromised');
});

it('applies one policy definition to every password entry point', function (): void {
    // Registration, reset and change all call PasswordPolicy::rules(). A reset
    // endpoint that accepts what registration refuses is how a policy becomes
    // advisory.
    $rules = PasswordPolicy::rules();

    expect($rules)->toContain('required')->toContain('string')
        ->toContain('min:12')->toContain('max:128')
        ->and(PasswordPolicy::MIN_LENGTH)->toBe(12)
        ->and(PasswordPolicy::MAX_LENGTH)->toBe(128)
        // Each failure mode gets its own code, so the UI can give advice that
        // matches the mistake.
        ->and(collect($rules)->contains(fn ($rule): bool => $rule instanceof NotCompromised))->toBeTrue();
});
