<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\Run;
use App\Models\User;
use App\Services\Replay\ReplayBundle;
use App\Services\Replay\ReplayInputParser;
use App\Services\Replay\ReplayInputStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeReplayRunner;
use Tests\Support\RunWorkers;

/**
 * ANTI-6 P3 races, on real PostgreSQL with real concurrent connections.
 *
 * The replay commit locks RUN → PROGRESSION → RUN_REPLAY_INPUTS →
 * RUN_REPLAY_EVIDENCE: the prefix finish and (later) M13 invalidation share.
 * Every scenario asserts **no deadlock**, **exactly one terminal outcome**, at
 * most one evidence row, and that whichever side takes the run row first wins.
 */
beforeEach(function (): void {
    static $migrated = false;

    if (! $migrated) {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $migrated = true;
    }

    foreach (['pgsql_holder', 'pgsql_observer'] as $name) {
        Config::set("database.connections.{$name}", Config::array('database.connections.pgsql'));
    }

    DB::statement('TRUNCATE users RESTART IDENTITY CASCADE');
    DB::statement('TRUNCATE jobs');

    replayWorkers(fresh: true);
});

afterEach(function (): void {
    replayWorkers()->cleanUp();

    DB::statement('TRUNCATE users RESTART IDENTITY CASCADE');
    DB::statement('TRUNCATE jobs');

    DB::purge('pgsql_holder');
    DB::purge('pgsql_observer');
});

function replayWorkers(bool $fresh = false): RunWorkers
{
    static $workers = null;

    if ($fresh || ! $workers instanceof RunWorkers) {
        $workers = new RunWorkers;
    }

    return $workers;
}

/** An accepted run with progression and a pending, genuinely encrypted input. */
function seedPendingReplay(User $user, ?string $expiresAt = null): string
{
    if (! DB::table('player_progression')->where('user_id', $user->id)->exists()) {
        DB::table('player_progression')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $runId = insertFinishedRun($user, 1000, 30_000, now()->subMinute()->format('Y-m-d H:i:s.v'), now()->format('Y-m-d H:i:s.v'));
    DB::table('runs')->where('id', $runId)->update(['seed' => 42, 'start_loli_cycle_paws' => 0]);

    Queue::fake();
    app(ReplayInputStore::class)->record(
        Run::query()->findOrFail($runId),
        (new ReplayInputParser)->parse(['format_version' => 1, 'domain_version' => '1', 'total_steps' => 100, 'events' => [[0, 9]]]),
        60_000,
        5_000,
        CarbonImmutable::now('UTC'),
    );

    if ($expiresAt !== null) {
        DB::table('run_replay_inputs')->where('run_id', $runId)->update(['input_expires_at' => $expiresAt]);
    }

    return $runId;
}

function consistentAnswer(): string
{
    return FakeReplayRunner::completed(1000, 0, 30_000, 100)->run(new ReplayBundle('1', '', '', '', '', 24, '', ''), [])->stdout;
}

function spawnReplay(string $tag, string $runId): array
{
    return replayWorkers()->spawn($tag, 'NONE', '', [], null, ['run_id' => $runId, 'answer' => consistentAnswer()]);
}

/** @param  list<array{status: int, body: mixed}>  $results */
function expectNoReplayDeadlock(array $results): void
{
    foreach ($results as $result) {
        expect($result['status'])->not->toBe(500, 'A worker failed: '.json_encode($result['body']));
    }
}

it('establishes evidence exactly once when two deliveries commit concurrently', function (): void {
    $runId = seedPendingReplay(User::factory()->create());

    replayWorkers()->pauseOn('run_replay_evidence', 'INSERT', 'r1');
    replayWorkers()->holdPause();
    $r1 = spawnReplay('r1', $runId);
    replayWorkers()->waitUntilBlocked('r1');

    $r2 = spawnReplay('r2', $runId);
    replayWorkers()->waitUntilBlocked('r2');

    replayWorkers()->releasePause();

    expectNoReplayDeadlock([replayWorkers()->result($r1), replayWorkers()->result($r2)]);

    expect(DB::table('run_replay_evidence')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('outcome_code'))->toBe('established')
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('input'))->toBeNull();
});

it('keeps evidence a replay committed first, when invalidation follows (case B/C)', function (): void {
    $runId = seedPendingReplay(User::factory()->create());

    replayWorkers()->pauseOn('run_replay_evidence', 'INSERT', 'r');
    replayWorkers()->holdPause();
    $r = spawnReplay('r', $runId);
    replayWorkers()->waitUntilBlocked('r');

    $i = replayWorkers()->spawn('i', 'NONE', '', [], null, ['invalidate' => $runId]);
    replayWorkers()->waitUntilBlocked('i');

    replayWorkers()->releasePause();

    expectNoReplayDeadlock([replayWorkers()->result($r), replayWorkers()->result($i)]);

    expect(DB::table('runs')->where('id', $runId)->value('status'))->toBe('rejected')
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeTrue()
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('outcome_code'))->toBe('established');
});

it('records run_not_accepted when invalidation takes the run row first (case A/C)', function (): void {
    $runId = seedPendingReplay(User::factory()->create());

    replayWorkers()->pauseOn('runs', 'UPDATE', 'i');
    replayWorkers()->holdPause();
    $i = replayWorkers()->spawn('i', 'NONE', '', [], null, ['invalidate' => $runId]);
    replayWorkers()->waitUntilBlocked('i');

    // The replay reads the run as still accepted, replays, then waits on the
    // run row at its commit.
    $r = spawnReplay('r', $runId);
    replayWorkers()->waitUntilBlocked('r');

    replayWorkers()->releasePause();

    expectNoReplayDeadlock([replayWorkers()->result($i), replayWorkers()->result($r)]);

    expect(DB::table('runs')->where('id', $runId)->value('status'))->toBe('rejected')
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('outcome_code'))->toBe('run_not_accepted')
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('input'))->toBeNull();
});

it('records one outcome when the sweeper and a replay commit race on an expired input', function (): void {
    $runId = seedPendingReplay(User::factory()->create(), now()->subSecond()->format('Y-m-d H:i:s.v'));

    replayWorkers()->pauseOn('run_replay_inputs', 'UPDATE', 's');
    replayWorkers()->holdPause();
    $s = replayWorkers()->spawn('s', 'NONE', '', [], null, ['sweep' => true]);
    replayWorkers()->waitUntilBlocked('s');

    $r = spawnReplay('r', $runId);
    replayWorkers()->waitUntilBlocked('r');

    replayWorkers()->releasePause();

    expectNoReplayDeadlock([replayWorkers()->result($s), replayWorkers()->result($r)]);

    expect(DB::table('run_replay_inputs')->where('run_id', $runId)->value('outcome_code'))->toBe('input_expired')
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('input'))->toBeNull()
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeFalse();
});

it('records one work row and one job for a concurrent same-key finish carrying a log', function (): void {
    $user = User::factory()->create();
    $runId = (string) Str::uuid7();
    $started = now()->utc()->subSeconds(60)->format('Y-m-d H:i:s.v');
    DB::table('runs')->insert([
        'id' => $runId, 'user_id' => $user->id, 'status' => 'active', 'seed' => 42, 'start_loli_cycle_paws' => 0,
        'character_id' => Character::query()->where('key', 'aysenur')->value('id'),
        'started_at' => $started, 'created_at' => $started, 'updated_at' => $started,
    ]);
    $key = (string) Str::uuid();
    $body = [...plausibleTelemetry(), 'replay_input' => ['format_version' => 1, 'domain_version' => '1', 'total_steps' => 100, 'events' => []]];
    $headers = [...sessionFor($user), 'Idempotency-Key' => $key];
    $env = ['QUEUE_CONNECTION' => 'database'];

    replayWorkers()->pauseOn('run_replay_inputs', 'INSERT', 'f1');
    replayWorkers()->holdPause();
    $f1 = replayWorkers()->spawn('f1', 'POST', "/api/v1/game-runs/{$runId}/finish", $headers, $body, null, $env);
    replayWorkers()->waitUntilBlocked('f1');

    $f2 = replayWorkers()->spawn('f2', 'POST', "/api/v1/game-runs/{$runId}/finish", $headers, $body, null, $env);
    replayWorkers()->waitUntilBlocked('f2');

    replayWorkers()->releasePause();

    $r1 = replayWorkers()->result($f1);
    $r2 = replayWorkers()->result($f2);
    expectNoReplayDeadlock([$r1, $r2]);

    expect($r1['body']['data']['status'])->toBe('accepted')
        ->and($r2['body'])->toEqual($r1['body'])
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'replay')->count())->toBe(1);
});

it('serialises a replay commit with the same player\'s next finish on progression, without deadlock', function (): void {
    $user = User::factory()->create();
    $replayed = seedPendingReplay($user);

    $next = (string) Str::uuid7();
    $started = now()->utc()->subSeconds(60)->format('Y-m-d H:i:s.v');
    DB::table('runs')->insert([
        'id' => $next, 'user_id' => $user->id, 'status' => 'active', 'seed' => 7, 'start_loli_cycle_paws' => 0,
        'character_id' => Character::query()->where('key', 'aysenur')->value('id'),
        'started_at' => $started, 'created_at' => $started, 'updated_at' => $started,
    ]);

    // The replay commit holds the progression lock, parked at its evidence.
    replayWorkers()->pauseOn('run_replay_evidence', 'INSERT', 'r');
    replayWorkers()->holdPause();
    $r = spawnReplay('r', $replayed);
    replayWorkers()->waitUntilBlocked('r');

    $f = replayWorkers()->spawn('f', 'POST', "/api/v1/game-runs/{$next}/finish", [...sessionFor($user), 'Idempotency-Key' => (string) Str::uuid()], plausibleTelemetry());
    replayWorkers()->waitUntilBlocked('f');

    replayWorkers()->releasePause();

    $rr = replayWorkers()->result($r);
    $rf = replayWorkers()->result($f);
    expectNoReplayDeadlock([$rr, $rf]);

    expect($rf['body']['data']['status'])->toBe('accepted')
        ->and(DB::table('run_replay_inputs')->where('run_id', $replayed)->value('outcome_code'))->toBe('established');
});

it('keeps a committed acceptance when the after-commit replay fails synchronously (local sync queue)', function (): void {
    $user = User::factory()->create();
    $runId = (string) Str::uuid7();
    $started = now()->utc()->subSeconds(60)->format('Y-m-d H:i:s.v');
    DB::table('runs')->insert([
        'id' => $runId, 'user_id' => $user->id, 'status' => 'active', 'seed' => 42, 'start_loli_cycle_paws' => 0,
        'character_id' => Character::query()->where('key', 'aysenur')->value('id'),
        'started_at' => $started, 'created_at' => $started, 'updated_at' => $started,
    ]);
    $key = (string) Str::uuid();
    $body = [...plausibleTelemetry(), 'replay_input' => ['format_version' => 1, 'domain_version' => '1', 'total_steps' => 100, 'events' => []]];
    $headers = [...sessionFor($user), 'Idempotency-Key' => $key];

    // The sync driver runs the job inline once the finish commits; with no Node
    // the replay fails there, and the driver surfaces that as a 500 — after the
    // commit. Its own process, its own connection: a real COMMIT, not a savepoint.
    $env = ['QUEUE_CONNECTION' => 'sync', 'REPLAY_NODE_BINARY' => ''];
    $first = replayWorkers()->result(replayWorkers()->spawn('f', 'POST', "/api/v1/game-runs/{$runId}/finish", $headers, $body, null, $env));

    expect($first['status'])->toBe(500);

    // Committed and untouched by the replay's failure.
    expect(DB::table('runs')->where('id', $runId)->value('status'))->toBe('accepted')
        ->and((int) DB::table('player_progression')->where('user_id', $user->id)->value('run_count'))->toBe(1)
        ->and(DB::table('paw_ledger')->where('run_id', $runId)->count())->toBe(1)
        ->and(DB::table('run_loli_evidence')->where('run_id', $runId)->exists())->toBeTrue()
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('outcome_code'))->toBe('attempts_exhausted')
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('input'))->toBeNull()
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeFalse();

    // A retry under the same key answers the stored acceptance and writes nothing.
    $retry = replayWorkers()->result(replayWorkers()->spawn('r', 'POST', "/api/v1/game-runs/{$runId}/finish", $headers, $body, null, $env));

    expect($retry['status'])->toBe(200)
        ->and($retry['body']['data']['status'])->toBe('accepted')
        ->and((int) DB::table('player_progression')->where('user_id', $user->id)->value('run_count'))->toBe(1)
        ->and(DB::table('paw_ledger')->where('run_id', $runId)->count())->toBe(1);
});
