<?php

declare(strict_types=1);

use App\Support\ValidationCodes;

/**
 * The point of these codes: a client must be able to say "this email is already
 * taken" in Turkish without parsing an English sentence.
 */
it('maps framework rules to stable codes', function (string $rule, string $code): void {
    expect(ValidationCodes::forRule($rule))->toBe($code);
})->with([
    ['Required', 'required'],
    ['Email', 'email_invalid'],
    ['Unique', 'taken'],
    ['Confirmed', 'confirmation_mismatch'],
    ['Same', 'confirmation_mismatch'],
    ['CurrentPassword', 'password_incorrect'],
    ['Min', 'too_short'],
    ['Max', 'too_long'],
    ['Uncompromised', 'password_compromised'],
    ['Password', 'password_policy'],
]);

it('maps the breach rule to its own code', function (): void {
    // Distinct from a length failure on purpose: "make it longer" and "this one
    // has been published" need different advice.
    expect(ValidationCodes::forRule('App\Rules\NotCompromised'))->toBe('password_compromised');
});

it('maps custom rule classes by their short name', function (): void {
    expect(ValidationCodes::forRule('App\Rules\DisplayNameFormat'))->toBe('format_invalid')
        ->and(ValidationCodes::forRule('App\Rules\DisplayNameAvailable'))->toBe('taken');
});

it('falls back to a snake-cased rule name rather than a generic code', function (): void {
    // An unmapped rule still produces a usable, stable code, so adding a rule
    // never silently degrades the contract to "invalid".
    expect(ValidationCodes::forRule('ProhibitedIf'))->toBe('prohibited_if')
        ->and(ValidationCodes::forRule('Illuminate\Validation\Rules\Enum'))->toBe('enum');
});

it('builds a per-field error list aligned with the messages', function (): void {
    $errors = ValidationCodes::build(
        failed: [
            'email' => ['Required' => []],
            'password' => ['Min' => [12], 'Uncompromised' => []],
        ],
        messages: [
            'email' => ['The email field is required.'],
            'password' => ['Too short.', 'This password has appeared in a leak.'],
        ],
    );

    expect($errors['email'])->toBe([
        ['code' => 'required', 'message' => 'The email field is required.'],
    ])->and($errors['password'])->toBe([
        ['code' => 'too_short', 'message' => 'Too short.'],
        ['code' => 'password_compromised', 'message' => 'This password has appeared in a leak.'],
    ]);
});

it('still reports a code when the failed rule cannot be identified', function (): void {
    $errors = ValidationCodes::build(
        failed: [],
        messages: ['email' => ['Something went wrong.']],
    );

    // A client that gets `null` here has to fall back to parsing prose.
    expect($errors['email'][0]['code'])->toBe('invalid');
});
