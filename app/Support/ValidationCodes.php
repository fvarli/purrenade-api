<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stable per-field error codes for validation failures.
 *
 * The point of this file: a client must be able to say "this email is already
 * taken" in Turkish without parsing an English sentence. Laravel gives messages;
 * this maps the *rule that failed* onto a stable code, so the response carries
 * both and the client only ever branches on the code.
 *
 * Laravel exposes the failed rules through `Validator::failed()`, keyed by field
 * and then by rule class name — which is why this map is keyed on those names
 * rather than on message text.
 */
final class ValidationCodes
{
    /**
     * Rule name (as Laravel reports it) → stable code.
     *
     * Rules absent from this map fall through to a lower-cased form of the rule
     * name, so an unmapped rule still produces a usable, stable code rather than
     * a generic one — and adding a rule does not silently degrade the contract.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'Required' => 'required',
        'Present' => 'required',
        'Filled' => 'required',
        'Email' => 'email_invalid',
        'Unique' => 'taken',
        'Exists' => 'not_found',
        'Confirmed' => 'confirmation_mismatch',
        'Same' => 'confirmation_mismatch',
        'CurrentPassword' => 'password_incorrect',
        'Min' => 'too_short',
        'MinDigits' => 'too_short',
        'Max' => 'too_long',
        'MaxDigits' => 'too_long',
        'Size' => 'invalid_length',
        'Digits' => 'digits_expected',
        'String' => 'type_invalid',
        'Integer' => 'type_invalid',
        'Boolean' => 'type_invalid',
        'Regex' => 'format_invalid',
        'NotRegex' => 'format_invalid',
        'In' => 'value_not_allowed',
        'Uncompromised' => 'password_compromised',
        'Password' => 'password_policy',

        // Custom rules, mapped explicitly so the client sees `taken` rather
        // than a code derived from a class name it should not have to know.
        'NotCompromised' => 'password_compromised',
        'DisplayNameFormat' => 'format_invalid',
        'DisplayNameAvailable' => 'taken',
    ];

    /**
     * Translate one failed rule name into a stable code.
     */
    public static function forRule(string $rule): string
    {
        // Custom rule objects are reported by their fully-qualified class name.
        $shortName = str_contains($rule, '\\')
            ? (string) substr(strrchr($rule, '\\') ?: $rule, 1)
            : $rule;

        if (isset(self::MAP[$shortName])) {
            return self::MAP[$shortName];
        }

        // snake_case fallback: `ProhibitedIf` → `prohibited_if`.
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }

    /**
     * Build the `errors` extension member from a validator's output.
     *
     * @param  array<string, array<string, mixed>>  $failed  Validator::failed()
     * @param  array<string, list<string>>  $messages  Validator::errors()->messages()
     * @return array<string, list<array{code: string, message: string}>>
     */
    public static function build(array $failed, array $messages): array
    {
        $errors = [];

        foreach ($messages as $field => $fieldMessages) {
            $rules = array_keys($failed[$field] ?? []);

            foreach (array_values($fieldMessages) as $index => $message) {
                $errors[$field][] = [
                    // Messages and failed rules are produced in the same order
                    // by the validator, so index alignment is correct. When a
                    // rule cannot be identified the field still reports a code,
                    // because a client that gets `null` here has to fall back to
                    // parsing prose.
                    'code' => isset($rules[$index])
                        ? self::forRule((string) $rules[$index])
                        : 'invalid',
                    'message' => $message,
                ];
            }
        }

        return $errors;
    }
}
