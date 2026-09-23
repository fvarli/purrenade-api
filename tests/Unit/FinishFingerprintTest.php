<?php

declare(strict_types=1);

use App\Services\Runs\FinishFingerprint;
use App\Services\Runs\RunTelemetry;

/**
 * The effective-request fingerprint: exactly the run and the three integers.
 */
it('is the documented canonical form', function (): void {
    $runId = '01999999-9999-7999-8999-999999999999';

    expect(FinishFingerprint::of($runId, new RunTelemetry(30000, 1000, 50)))
        ->toBe(hash('sha256', "finish:v1\n{$runId}\n30000\n1000\n50"));
});

it('ignores run id case', function (): void {
    $t = new RunTelemetry(1, 2, 3);

    expect(FinishFingerprint::of('01999999-9999-7999-8999-ABCDEFABCDEF', $t))
        ->toBe(FinishFingerprint::of('01999999-9999-7999-8999-abcdefabcdef', $t));
});

it('changes with the run and with every telemetry member', function (): void {
    $base = FinishFingerprint::of('01999999-9999-7999-8999-999999999999', new RunTelemetry(1, 2, 3));

    expect(FinishFingerprint::of('01999999-9999-7999-8999-999999999998', new RunTelemetry(1, 2, 3)))->not->toBe($base)
        ->and(FinishFingerprint::of('01999999-9999-7999-8999-999999999999', new RunTelemetry(2, 2, 3)))->not->toBe($base)
        ->and(FinishFingerprint::of('01999999-9999-7999-8999-999999999999', new RunTelemetry(1, 3, 3)))->not->toBe($base)
        ->and(FinishFingerprint::of('01999999-9999-7999-8999-999999999999', new RunTelemetry(1, 2, 4)))->not->toBe($base);
});

it('cannot be made to collide by shifting digits between members', function (): void {
    $run = '01999999-9999-7999-8999-999999999999';

    expect(FinishFingerprint::of($run, new RunTelemetry(12, 3, 4)))
        ->not->toBe(FinishFingerprint::of($run, new RunTelemetry(1, 23, 4)));
});

it('keeps negative values distinct', function (): void {
    $run = '01999999-9999-7999-8999-999999999999';

    expect(FinishFingerprint::of($run, new RunTelemetry(-1, 0, 0)))
        ->not->toBe(FinishFingerprint::of($run, new RunTelemetry(1, 0, 0)));
});
