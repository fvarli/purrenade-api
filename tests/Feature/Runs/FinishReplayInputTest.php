<?php

declare(strict_types=1);

use App\Jobs\Runs\ReplayRunEvidence;
use App\Models\Run;
use App\Models\User;
use App\Services\Replay\ReplayInputStore;
use App\Services\Runs\FinishFingerprint;
use App\Services\Runs\RunTelemetry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

use Tests\Support\OpenApiContract;

/**
 * ANTI-6 P3 at the finish boundary: the optional `replay_input`.
 *
 * The properties that carry the weight: the log never changes the response,
 * the status, the fingerprint or the Loli evidence; it is recorded only for an
 * accepted run; a usable one is stored encrypted with a 24-hour logical expiry
 * and dispatched only after the acceptance transaction commits; an unusable one
 * is a terminal row with no input; and an idempotent retry writes nothing.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-10-03 12:00:00.000', 'UTC'));
    Queue::fake();
});

/** A stream that fits a 31 s window: 3 000 steps at 120 Hz is 25 s. */
function finishReplayStream(array $overrides = []): array
{
    return [
        'format_version' => 1,
        'domain_version' => '1',
        'total_steps' => 3000,
        'events' => [[0, 9], [100, 1], [40, 2], [500, 7], [0, 8]],
        ...$overrides,
    ];
}

/** Start through the endpoint, wait 31 s, and return the run id. */
function startReplayRun(User $user): string
{
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31_000)->milliseconds();

    return $runId;
}

function replayWorkRow(string $runId): ?object
{
    return DB::table('run_replay_inputs')->where('run_id', $runId)->first();
}

it('records nothing and dispatches nothing when the finish carries no log', function (): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    finishRun($user, $runId, plausibleTelemetry(), (string) Str::uuid())->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(replayWorkRow($runId))->toBeNull()
        ->and(DB::table('run_loli_evidence')->where('run_id', $runId)->exists())->toBeTrue();
    Queue::assertNothingPushed();
});

it('treats replay_input null as no log', function (): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => null], (string) Str::uuid())->assertOk();

    expect(replayWorkRow($runId))->toBeNull();
    Queue::assertNothingPushed();
});

it('stores a usable log encrypted, with an exact 24-hour expiry, and dispatches after commit', function (): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);
    $receivedAt = CarbonImmutable::now('UTC');

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream()], (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    $row = replayWorkRow($runId);

    expect($row->state)->toBe('pending')
        ->and($row->outcome_code)->toBeNull()
        ->and($row->attempts)->toBe(0)
        ->and(CarbonImmutable::parse($row->input_expires_at, 'UTC')->format('Y-m-d H:i:s.v'))
        ->toBe($receivedAt->addHours(ReplayInputStore::RETENTION_HOURS)->format('Y-m-d H:i:s.v'));

    $hex = DB::scalar("SELECT encode(input, 'hex') FROM run_replay_inputs WHERE run_id = ?", [$runId]);
    $stored = (string) hex2bin($hex);

    // Ciphertext at rest: the stream is not in the stored bytes.
    expect($stored)->not->toContain('"events"')
        ->and($stored)->not->toContain('[100,1]')
        ->and(Crypt::decryptString(base64_encode($stored)))
        ->toBe('{"format_version":1,"domain_version":"1","total_steps":3000,"events":[[0,9],[100,1],[40,2],[500,7],[0,8]]}');

    Queue::assertPushedOn('replay', ReplayRunEvidence::class, fn (ReplayRunEvidence $job): bool => $job->runId === $runId);
});

it('never lets the log change the response, the status or the fingerprint', function (): void {
    $user = User::factory()->create();
    $plain = startReplayRun($user);
    $plainBody = finishRun($user, $plain, plausibleTelemetry(), (string) Str::uuid())->assertOk()->json('data');

    $user2 = User::factory()->create();
    $logged = startReplayRun($user2);
    $key = (string) Str::uuid();
    $loggedBody = finishRun($user2, $logged, [...plausibleTelemetry(), 'replay_input' => finishReplayStream()], $key)->assertOk()->json('data');

    unset($plainBody['run_id'], $loggedBody['run_id']);
    expect($loggedBody)->toBe($plainBody)
        ->and(Run::query()->findOrFail($logged)->idempotency_fingerprint)
        ->toBe(FinishFingerprint::of($logged, new RunTelemetry(30000, 1000, 50)));
});

it('records an unusable log as a terminal row with no input, and still answers 200', function (array $log, string $outcome): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => $log], (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    $row = replayWorkRow($runId);

    expect($row->state)->toBe('terminal')
        ->and($row->outcome_code)->toBe($outcome)
        ->and($row->input)->toBeNull()
        ->and($row->input_expires_at)->toBeNull();
    Queue::assertNothingPushed();
})->with([
    'malformed' => [['format_version' => 1, 'events' => 'nope'], 'input_malformed'],
    'a string' => [['not', 'an', 'object'], 'input_malformed'],
    'unknown format' => [finishReplayStream(['format_version' => 2]), 'version_unsupported'],
    'no installed bundle for its domain version' => [finishReplayStream(['domain_version' => '99']), 'version_unsupported'],
    // 31 s window + 5 s tolerance holds 4 320 steps at 120 Hz.
    'more steps than the window holds' => [finishReplayStream(['total_steps' => 4400, 'events' => []]), 'input_malformed'],
]);

it('accepts exactly as many steps as the window and its tolerance hold', function (): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream(['total_steps' => 4320, 'events' => []])], (string) Str::uuid())
        ->assertOk();

    expect(replayWorkRow($runId)->state)->toBe('pending');
});

it('cannot be made to refuse the finish by a hostile log', function (mixed $log): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => $log], (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');
})->with([
    'a number' => [42],
    'a string' => ['AAAA'],
    'deeply nested' => [['events' => [[[[[[1]]]]]]]],
    'huge numbers' => [finishReplayStream(['total_steps' => PHP_INT_MAX])],
]);

it('records no log for a flagged or rejected run, and drops it unread', function (array $telemetry, string $status): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    finishRun($user, $runId, [...$telemetry, 'replay_input' => finishReplayStream()], (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', $status);

    expect(replayWorkRow($runId))->toBeNull();
    Queue::assertNothingPushed();
})->with([
    'flagged' => [plausibleTelemetry(duration: 30_000, score: 100_000, paws: 50), 'flagged'],
    'rejected' => [plausibleTelemetry(duration: 30_000, score: 10, paws: 50), 'rejected'],
]);

it('records a pre-cycle run\'s log as version_unsupported: it cannot be replayed', function (): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);
    DB::table('runs')->where('id', $runId)->update(['start_loli_cycle_paws' => null]);

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream()], (string) Str::uuid())->assertOk();

    expect(replayWorkRow($runId)->outcome_code)->toBe('version_unsupported');
});

it('writes nothing new on an idempotent retry, even with a different log: the first log stands', function (): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);
    $key = (string) Str::uuid();

    $first = finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream()], $key)->assertOk()->json();
    $firstInput = DB::scalar("SELECT encode(input, 'hex') FROM run_replay_inputs WHERE run_id = ?", [$runId]);

    $second = finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream(['events' => []])], $key)->assertOk()->json();

    expect($second)->toEqual($first)
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::scalar("SELECT encode(input, 'hex') FROM run_replay_inputs WHERE run_id = ?", [$runId]))->toBe($firstInput);
    Queue::assertPushed(ReplayRunEvidence::class, 1);
});

it('dispatches nothing when the acceptance transaction rolls back', function (): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    // Fail the transaction after the work row is written: the final step.
    DB::unprepared("
        CREATE OR REPLACE FUNCTION purrenade_test_fail() RETURNS trigger AS \$\$
        BEGIN RAISE EXCEPTION 'forced rollback'; END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER purrenade_test_fail AFTER INSERT ON run_replay_inputs FOR EACH ROW EXECUTE FUNCTION purrenade_test_fail();
    ");

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream()], (string) Str::uuid())->assertStatus(500);

    expect(Run::query()->findOrFail($runId)->status->value)->toBe('active')
        ->and(replayWorkRow($runId))->toBeNull();
    Queue::assertNothingPushed();
});

/**
 * The writes of one finish that matter for the dispatch boundary, each with how
 * deep in transactions it ran relative to the test's own: +1 is inside the
 * acceptance transaction, 0 is after it committed.
 *
 * @return list<string>
 */
function recordDispatchBoundary(callable $finish): array
{
    $base = DB::transactionLevel();
    $seen = [];

    DB::listen(function (QueryExecuted $query) use (&$seen, $base): void {
        if (preg_match('/^\s*(insert into "?cache_locks|insert into run_replay_inputs|update "runs")/i', $query->sql, $m) === 1) {
            $seen[] = strtolower(str_replace('"', '', $m[1])).' @'.(DB::transactionLevel() - $base);
        }
    });

    $finish();

    return $seen;
}

it('takes the replay job\'s uniqueness lock only after the acceptance transaction commits (database cache store)', function (): void {
    config(['cache.default' => 'database']);
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    $seen = recordDispatchBoundary(fn () => finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream()], (string) Str::uuid())->assertOk());

    expect($seen)->toBe([
        'update runs @1',
        'insert into run_replay_inputs @1',
        'insert into cache_locks @0',
    ]);
    expect(DB::table('cache_locks')->where('key', 'like', '%'.$runId)->count())->toBe(1);
    Queue::assertPushedOn('replay', ReplayRunEvidence::class, fn (ReplayRunEvidence $job): bool => $job->runId === $runId);
});

it('writes no uniqueness lock and dispatches nothing when the acceptance rolls back (database cache store)', function (): void {
    config(['cache.default' => 'database']);
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    DB::unprepared("
        CREATE OR REPLACE FUNCTION purrenade_test_fail() RETURNS trigger AS \$\$
        BEGIN RAISE EXCEPTION 'forced rollback'; END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER purrenade_test_fail AFTER INSERT ON run_replay_inputs FOR EACH ROW EXECUTE FUNCTION purrenade_test_fail();
    ");

    $seen = recordDispatchBoundary(fn () => finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream()], (string) Str::uuid())->assertStatus(500));

    expect($seen)->not->toContain('insert into cache_locks @0')
        ->and($seen)->not->toContain('insert into cache_locks @1')
        ->and(DB::table('cache_locks')->where('key', 'like', '%'.$runId)->exists())->toBeFalse()
        ->and(Run::query()->findOrFail($runId)->status->value)->toBe('active');
    Queue::assertNothingPushed();
});

it('documents replay_input in the contract, and the response still conforms', function (): void {
    $user = User::factory()->create();
    $runId = startReplayRun($user);

    expect(OpenApiContract::violations(['telemetry' => plausibleTelemetry()['telemetry'], 'replay_input' => finishReplayStream()], 'FinishRunRequest'))->toBe([])
        ->and(OpenApiContract::violations(finishReplayStream(), 'ReplayInput'))->toBe([]);

    $body = finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => finishReplayStream()], (string) Str::uuid())->assertOk()->json('data');

    expect(OpenApiContract::violations($body, 'RunResult'))->toBe([]);
});
