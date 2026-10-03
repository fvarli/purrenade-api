<?php

declare(strict_types=1);

use App\Jobs\Runs\ReplayRunEvidence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

/**
 * The every-minute sweeper (O3, O8): the prompt physical purge of expired
 * input, independent of traffic, and the re-dispatch of orphaned replays.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-10-03 12:00:00.000', 'UTC'));
    Queue::fake();
});

function sweepPendingRun(): string
{
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31_000)->milliseconds();

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => [
        'format_version' => 1, 'domain_version' => '1', 'total_steps' => 3000, 'events' => [[0, 9]],
    ]], (string) Str::uuid())->assertOk();

    return $runId;
}

function sweepRow(string $runId): object
{
    return DB::table('run_replay_inputs')->where('run_id', $runId)->first();
}

it('physically clears input at its expiry, with no traffic and no replay', function (): void {
    $runId = sweepPendingRun();
    $expiresAt = CarbonImmutable::parse(sweepRow($runId)->input_expires_at, 'UTC');

    travelTo($expiresAt->subMillisecond());
    Artisan::call('replay:sweep');
    expect(sweepRow($runId)->state)->toBe('pending');

    travelTo($expiresAt);
    Artisan::call('replay:sweep');

    $row = sweepRow($runId);
    expect($row->state)->toBe('terminal')
        ->and($row->outcome_code)->toBe('input_expired')
        ->and($row->input)->toBeNull()
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('runs')->where('id', $runId)->value('status'))->toBe('accepted');
});

it('leaves terminal rows alone', function (): void {
    $runId = sweepPendingRun();
    DB::update("UPDATE run_replay_inputs SET state = 'terminal', outcome_code = 'established', input = NULL WHERE run_id = ?", [$runId]);
    travel(2)->days();

    expect(Artisan::call('replay:sweep'))->toBe(0)
        ->and(sweepRow($runId)->outcome_code)->toBe('established');
});

it('re-dispatches a pending replay that has gone quiet, and only that', function (): void {
    $quiet = sweepPendingRun();
    Queue::fake();
    travel(11)->minutes();
    $fresh = sweepPendingRun();
    Queue::fake();

    Artisan::call('replay:sweep');

    Queue::assertPushed(ReplayRunEvidence::class, 1);
    Queue::assertPushedOn('replay', ReplayRunEvidence::class, fn (ReplayRunEvidence $job): bool => $job->runId === $quiet);
    expect(CarbonImmutable::parse(sweepRow($quiet)->updated_at, 'UTC')->equalTo(CarbonImmutable::now('UTC')))->toBeTrue();

    // Touched, so not again until it has been quiet as long once more.
    Queue::fake();
    Artisan::call('replay:sweep');
    Queue::assertNothingPushed();
});

it('schedules the sweeper every minute, and nothing else', function (): void {
    $events = collect(app(Schedule::class)->events());

    expect($events)->toHaveCount(1)
        ->and($events->first()->command)->toContain('replay:sweep')
        ->and($events->first()->expression)->toBe('* * * * *');
});
