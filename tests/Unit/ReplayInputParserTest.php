<?php

declare(strict_types=1);

use App\Enums\ReplayOutcome;
use App\Services\Replay\ReplayInput;
use App\Services\Replay\ReplayInputParser;

/**
 * The finish boundary for `replay_input`: usable, unusable with a code, or
 * none — never an exception, never a 422.
 */
function replayParse(mixed $value): mixed
{
    return (new ReplayInputParser)->parse($value);
}

/** @return array<string, mixed> */
function replayStream(array $overrides = []): array
{
    return [
        'format_version' => 1,
        'domain_version' => '1',
        'total_steps' => 100,
        'events' => [[0, 9], [5, 1], [0, 2], [10, 7], [0, 8]],
        ...$overrides,
    ];
}

it('treats an absent or null log as no input at all', function (): void {
    expect(replayParse(null))->toBeNull();
});

it('accepts a well-formed stream and re-encodes only its four members', function (): void {
    $candidate = replayParse([...replayStream(), 'device' => 'leak', 'timestamps' => [1, 2]]);

    expect($candidate->isUsable())->toBeTrue()
        ->and($candidate->input)->toBeInstanceOf(ReplayInput::class)
        ->and($candidate->input->canonicalJson())->toBe(
            '{"format_version":1,"domain_version":"1","total_steps":100,"events":[[0,9],[5,1],[0,2],[10,7],[0,8]]}'
        );
});

it('is version_unsupported for another format version', function (mixed $format): void {
    expect(replayParse(replayStream(['format_version' => $format]))->unusable)->toBe(ReplayOutcome::VersionUnsupported);
})->with([0, 2, '1', 1.0, null]);

it('is input_malformed for any structural defect', function (array $stream): void {
    expect(replayParse($stream)->unusable)->toBe(ReplayOutcome::InputMalformed);
})->with([
    'domain version not a string' => [replayStream(['domain_version' => 1])],
    'domain version not digits' => [replayStream(['domain_version' => '1-dirty'])],
    'domain version too long' => [replayStream(['domain_version' => '123456789'])],
    'total steps negative' => [replayStream(['total_steps' => -1])],
    'total steps float' => [replayStream(['total_steps' => 100.0])],
    'total steps over the cap' => [replayStream(['total_steps' => 432_001])],
    'events not a list' => [replayStream(['events' => ['a' => [0, 1]]])],
    'event not a pair' => [replayStream(['events' => [[0, 1, 2]]])],
    'event an object' => [replayStream(['events' => [['gap' => 0, 'code' => 1]]])],
    'gap negative' => [replayStream(['events' => [[-1, 1]]])],
    'gap float' => [replayStream(['events' => [[1.0, 1]]])],
    'gap boolean' => [replayStream(['events' => [[true, 1]]])],
    'code out of range' => [replayStream(['events' => [[0, 10]]])],
    'code string' => [replayStream(['events' => [[0, '1']]])],
    'step input at total_steps' => [replayStream(['total_steps' => 5, 'events' => [[5, 1]]])],
    'control after the end' => [replayStream(['total_steps' => 5, 'events' => [[6, 7]]])],
    'control after a step input at the same position' => [replayStream(['events' => [[3, 1], [0, 7]]])],
    'gap sum past the end' => [replayStream(['total_steps' => 10, 'events' => [[8, 1], [3, 7]]])],
]);

it('accepts a control that follows the last step', function (): void {
    expect(replayParse(replayStream(['total_steps' => 5, 'events' => [[5, 7]]]))->isUsable())->toBeTrue();
});

it('is input_malformed for a non-object', function (mixed $value): void {
    expect(replayParse($value)->unusable)->toBe(ReplayOutcome::InputMalformed);
})->with(['a string', 12, true, [[1, 2]]]);

it('holds the event cap exactly', function (): void {
    $at = replayStream(['total_steps' => 432_000, 'events' => array_fill(0, 20_000, [0, 1])]);
    $over = replayStream(['total_steps' => 432_000, 'events' => array_fill(0, 20_001, [0, 1])]);

    expect(replayParse($at)->isUsable())->toBeTrue()
        ->and(replayParse($over)->unusable)->toBe(ReplayOutcome::InputMalformed);
});

it('holds the step cap exactly', function (): void {
    expect(replayParse(replayStream(['total_steps' => 432_000, 'events' => []]))->isUsable())->toBeTrue()
        ->and(replayParse(replayStream(['total_steps' => 432_001, 'events' => []]))->unusable)->toBe(ReplayOutcome::InputMalformed);
});
