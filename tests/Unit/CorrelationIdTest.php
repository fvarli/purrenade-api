<?php

declare(strict_types=1);

use App\Support\CorrelationId;

it('generates a sortable identifier', function (): void {
    $first = CorrelationId::generate();
    $second = CorrelationId::generate();

    // A ULID rather than a UUID: lexicographically sortable by creation time,
    // so a log ordered by correlation id is also ordered by arrival.
    expect($first)->toBeString()
        ->and(CorrelationId::isAcceptable($first))->toBeTrue()
        ->and($first)->not->toBe($second);
});

it('accepts a well-formed inbound value', function (string $value): void {
    expect(CorrelationId::isAcceptable($value))->toBeTrue()
        ->and(CorrelationId::resolve($value))->toBe($value);
})->with([
    'ulid' => ['01HQ8ZQ9Z9ZQ9ZQ9ZQ9ZQ9ZQ9Z'],
    'uuid' => ['3f2504e0-4f89-11d3-9a0c-0305e82c3301'],
    'minimum length' => ['abcd1234'],
    'underscores and hyphens' => ['trace_id-0001'],
]);

it('rejects anything that could be injected into a header or a log', function (?string $value): void {
    // This value is echoed in a response header and written into log lines, so
    // the allowed alphabet must contain neither CR nor LF.
    expect(CorrelationId::isAcceptable($value))->toBeFalse();

    $resolved = CorrelationId::resolve($value);

    expect($resolved)->not->toBe($value)
        ->and(CorrelationId::isAcceptable($resolved))->toBeTrue();
})->with([
    'null' => [null],
    'empty' => [''],
    'too short' => ['abc'],
    'too long' => [str_repeat('a', 65)],
    'carriage return' => ["abcd1234\r\nX-Injected: 1"],
    'newline' => ["abcd1234\nfoo"],
    'path traversal' => ['../../etc/passwd'],
    'spaces' => ['abcd 1234'],
    'quotes' => ['"abcd1234"'],
]);

it('names the header once', function (): void {
    expect(CorrelationId::HEADER)->toBe('X-Correlation-Id');
});
