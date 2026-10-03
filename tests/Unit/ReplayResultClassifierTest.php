<?php

declare(strict_types=1);

use App\Enums\ReplayOutcome;
use App\Services\Replay\ReplayProcessResult;
use App\Services\Replay\ReplayResultClassifier;

/**
 * A replay answer → a verdict (architecture §15). Pure.
 */
function replayAnswer(array $overrides = [], array $final = [], array $facts = []): string
{
    return json_encode([
        'protocol' => 1,
        'domain_version' => '1',
        'status' => 'completed',
        'final' => ['ended' => true, 'elapsed_ms_floor' => 34591, 'score' => 615, 'run_paws' => 22, 'steps_consumed' => 4333, ...$final],
        'facts' => ['cone_safe_passes' => 10, 'near_misses' => 9, 'slayyy_activations' => 0, 'loli_activations' => 0, ...$facts],
        ...$overrides,
    ], JSON_THROW_ON_ERROR)."\n";
}

function replayClassify(string $stdout, bool $overflowed = false): mixed
{
    return (new ReplayResultClassifier)->classify(
        new ReplayProcessResult($stdout, $overflowed, false, 5),
        '1',
        4333,
        ['score' => 615, 'run_paws' => 22, 'duration_ms' => 34591],
    );
}

it('establishes the triple from a consistent replay', function (): void {
    $verdict = replayClassify(replayAnswer());

    expect($verdict->outcome)->toBe(ReplayOutcome::Established)
        ->and($verdict->facts)->toBe(['cone_safe_passes' => 10, 'near_misses' => 9, 'slayyy_activations' => 0])
        ->and($verdict->loliActivations)->toBe(0)
        ->and($verdict->reasons)->toBe([]);
});

it('establishes PRESENT zero', function (): void {
    $verdict = replayClassify(replayAnswer(facts: ['cone_safe_passes' => 0, 'near_misses' => 0]));

    expect($verdict->outcome)->toBe(ReplayOutcome::Established)
        ->and($verdict->facts['cone_safe_passes'])->toBe(0);
});

it('maps the replay program\'s refusals', function (string $status, ReplayOutcome $outcome): void {
    $stdout = json_encode(['protocol' => 1, 'domain_version' => '1', 'status' => $status])."\n";

    expect(replayClassify($stdout)->outcome)->toBe($outcome);
})->with([
    ['invalid_input', ReplayOutcome::InputMalformed],
    ['unsupported_version', ReplayOutcome::VersionUnsupported],
]);

it('is replay_inconsistent, naming the fields, when the replay disagrees with the accepted run', function (array $final, array $reasons): void {
    $verdict = replayClassify(replayAnswer(final: $final));

    expect($verdict->outcome)->toBe(ReplayOutcome::ReplayInconsistent)
        ->and($verdict->reasons)->toBe($reasons)
        ->and($verdict->facts)->toBeNull();
})->with([
    'score' => [['score' => 616], ['score']],
    'paws' => [['run_paws' => 21], ['run_paws']],
    'duration' => [['elapsed_ms_floor' => 34590], ['duration']],
    'all three' => [['score' => 1, 'run_paws' => 1, 'elapsed_ms_floor' => 1], ['score', 'run_paws', 'duration']],
]);

it('is result_invalid for anything not the documented, possible answer', function (string $stdout): void {
    expect(replayClassify($stdout)->outcome)->toBe(ReplayOutcome::ResultInvalid);
})->with([
    'empty' => [''],
    'not json' => ['replay crashed'],
    'wrong protocol' => [replayAnswer(['protocol' => 2])],
    'wrong domain echo' => [replayAnswer(['domain_version' => '2'])],
    'unknown status' => [replayAnswer(['status' => 'done'])],
    'not ended' => [replayAnswer(final: ['ended' => false])],
    'stream not consumed' => [replayAnswer(final: ['steps_consumed' => 4332])],
    'negative fact' => [replayAnswer(facts: ['near_misses' => -1])],
    'float fact' => [replayAnswer(facts: ['near_misses' => 1.5])],
    'string fact' => [replayAnswer(facts: ['slayyy_activations' => '0'])],
    'missing fact' => [json_encode(['protocol' => 1, 'domain_version' => '1', 'status' => 'completed', 'final' => ['ended' => true, 'elapsed_ms_floor' => 1, 'score' => 1, 'run_paws' => 1, 'steps_consumed' => 4333], 'facts' => []])],
]);

it('is result_invalid when the answer overflowed its bound', function (): void {
    expect(replayClassify(replayAnswer(), overflowed: true)->outcome)->toBe(ReplayOutcome::ResultInvalid);
});
