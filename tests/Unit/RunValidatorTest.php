<?php

declare(strict_types=1);

use App\Enums\RunStatus;
use App\Services\Runs\RunTelemetry;
use App\Services\Runs\RunValidationBounds;
use App\Services\Runs\RunValidator;

/**
 * The classifier on its own — no framework, no database — every rule at its
 * bound ± 1. The bounds are the shipped configuration, read from the file, so
 * the tests pin the numbers the server actually uses.
 */
function runValidator(): RunValidator
{
    return new RunValidator(RunValidationBounds::fromConfig(require dirname(__DIR__, 2).'/config/game_runs.php'));
}

function classifyRun(int $duration, int $score, int $paws, int $window = 1_000_000): array
{
    $result = runValidator()->classify(new RunTelemetry($duration, $score, $paws), $window);

    return [$result->status, $result->reasons()];
}

it('accepts a plausible run with no reasons', function (): void {
    expect(classifyRun(30_000, 1000, 50))->toBe([RunStatus::Accepted, []]);
});

it('rejects out-of-domain integers on any member, and nothing else', function (int $d, int $s, int $p): void {
    expect(classifyRun($d, $s, $p))->toBe([RunStatus::Rejected, ['value_out_of_domain']]);
})->with([
    'duration -1' => [-1, 1000, 50],
    'score -1' => [30_000, -1, 0],
    'paws -1' => [30_000, 1000, -1],
    'duration int4 max + 1' => [2_147_483_648, 1000, 50],
    'score int4 max + 1' => [30_000, 2_147_483_648, 0],
    'paws int4 max + 1' => [30_000, 1000, 2_147_483_648],
    'PHP_INT_MAX' => [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
    'PHP_INT_MIN' => [PHP_INT_MIN, 0, 0],
]);

it('treats the int4 maximum as in domain', function (): void {
    [$status, $reasons] = classifyRun(2_147_483_647, 2_147_483_647, 0, 2_147_483_647);

    expect($reasons)->not->toContain('value_out_of_domain');
});

it('records the offending member and value', function (): void {
    $result = runValidator()->classify(new RunTelemetry(30_000, -7, 0), 40_000);

    expect($result->rules)->toBe([['code' => 'value_out_of_domain', 'field' => 'reported_score', 'observed' => -7]])
        ->and($result->meta())->toBe(['v' => 1, 'window_ms' => 40_000, 'rules' => $result->rules]);
});

it('rejects a zero duration', function (): void {
    expect(classifyRun(0, 0, 0))->toBe([RunStatus::Rejected, ['duration_non_positive']]);
});

it('rejects a duration beyond the server window plus 5000 ms, at the bound', function (): void {
    expect(classifyRun(15_000, 1000, 20, 10_000)[0])->toBe(RunStatus::Accepted)
        ->and(classifyRun(15_001, 1000, 20, 10_000))->toBe([RunStatus::Rejected, ['duration_exceeds_server_window']]);
});

it('rejects against a negative window (a clock stepped back) by the same rule', function (): void {
    expect(classifyRun(5_000, 300, 0, -1)[1])->toContain('duration_exceeds_server_window');
});

it('rejects a score below ten per paw, at the bound', function (): void {
    expect(classifyRun(30_000, 500, 50)[0])->toBe(RunStatus::Accepted)
        ->and(classifyRun(30_000, 499, 50))->toBe([RunStatus::Rejected, ['score_below_paw_floor']]);
});

it('records every structural hit, and evaluates no flag once rejected', function (): void {
    // Window 1 s: the 30 s claim exceeds it; score is below the paw floor; the
    // score rate would also flag, but a rejected run is not flagged.
    $result = runValidator()->classify(new RunTelemetry(30_000, 499, 50), 1_000);

    expect($result->status)->toBe(RunStatus::Rejected)
        ->and($result->reasons())->toBe(['duration_exceeds_server_window', 'score_below_paw_floor']);
});

it('flags each PROPOSED plausibility bound at the bound, never rejects', function (int $d, int $s, int $p, array $reasons): void {
    [$status, $got] = classifyRun($d, $s, $p);

    expect($got)->toBe($reasons)
        ->and($status)->toBe($reasons === [] ? RunStatus::Accepted : RunStatus::Flagged);
})->with([
    'score rate 80/s' => [30_000, 2400, 50, []],
    'score rate 80/s + 1 point' => [30_000, 2401, 50, ['score_rate_high']],
    'paw rate 3/s' => [30_000, 900, 90, []],
    'paw rate 3/s + 1 paw' => [30_000, 910, 91, ['paw_rate_high']],
    'score 5/s' => [30_000, 150, 0, []],
    'score 5/s − 1 point' => [30_000, 149, 0, ['score_below_duration_floor']],
    'duration 4000' => [4000, 100, 5, []],
    'duration 3999' => [3999, 100, 5, ['duration_below_minimum']],
    'several at once' => [1000, 1000, 10, ['score_rate_high', 'paw_rate_high', 'duration_below_minimum']],
]);

it('never flags lateness: a huge window is not a reason', function (): void {
    expect(classifyRun(30_000, 1000, 50, PHP_INT_MAX - 10_000))->toBe([RunStatus::Accepted, []]);
});
