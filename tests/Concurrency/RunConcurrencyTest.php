<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RunWorkers;

/**
 * The start and finish races, on real PostgreSQL, with real concurrent
 * connections (§8 race walkthrough; gates D3, S10).
 *
 * The feature suite runs each test inside one rolled-back transaction, which is
 * right for isolation and useless for concurrency: nothing is ever committed,
 * so no second connection can observe anything. This suite commits for real
 * and runs each request in its own process (see `Tests\Support\RunWorkers`),
 * parking one request mid-transaction at a chosen write and releasing it only
 * once the other is observed waiting on the lock it needs.
 *
 * Every scenario asserts the same invariants on top of its own: **no deadlock**
 * (PostgreSQL would abort one side with 40P01, surfacing as a 500), **at most
 * one active run**, and **no duplicate reward**.
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

    runWorkers(fresh: true);
});

afterEach(function (): void {
    runWorkers()->cleanUp();

    DB::statement('TRUNCATE users RESTART IDENTITY CASCADE');
    Character::query()->where('key', 'buso')->update(['is_starter' => false, 'artwork_available' => false]);

    DB::purge('pgsql_holder');
    DB::purge('pgsql_observer');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** The orchestrator for the current test; replaced before each one. */
function runWorkers(bool $fresh = false): RunWorkers
{
    static $workers = null;

    if ($fresh || ! $workers instanceof RunWorkers) {
        $workers = new RunWorkers;
    }

    return $workers;
}

function spawnStart(RunWorkers $workers, string $tag, User $user, string $character): array
{
    return $workers->spawn($tag, 'POST', '/api/v1/game-runs', sessionFor($user), ['character_id' => $character]);
}

function spawnFinish(RunWorkers $workers, string $tag, User $user, string $runId, string $key, int $score = 1000): array
{
    return $workers->spawn(
        $tag,
        'POST',
        "/api/v1/game-runs/{$runId}/finish",
        [...sessionFor($user), 'Idempotency-Key' => $key],
        plausibleTelemetry(30_000, $score, 50),
    );
}

/** An active run inserted directly, started `$ageSeconds` ago. */
function seedActiveRun(User $user, int $ageSeconds): string
{
    $id = (string) Str::uuid7();
    $started = now()->utc()->subSeconds($ageSeconds)->format('Y-m-d H:i:s.v');

    DB::table('runs')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'character_id' => Character::query()->where('key', 'aysenur')->value('id'),
        'status' => 'active',
        'seed' => 12345,
        'started_at' => $started,
        'created_at' => $started,
        'updated_at' => $started,
    ]);

    return $id;
}

function activeRunIds(User $user): array
{
    return DB::table('runs')->where('user_id', $user->id)->where('status', 'active')->pluck('id')->all();
}

/** @param  list<array{status: int, body: mixed}>  $results */
function expectNoDeadlock(array $results): void
{
    foreach ($results as $result) {
        expect($result['status'])->not->toBe(500, 'A request failed with a server error: '.json_encode($result['body']));
    }
}

// ---------------------------------------------------------------------------
// start / start
// ---------------------------------------------------------------------------

it('lets exactly one of two simultaneous starts create; the other resumes it', function (string $second): void {
    $user = User::factory()->create();

    if ($second === 'buso') {
        Character::query()->where('key', 'buso')->update(['is_starter' => true, 'artwork_available' => true]);
    }

    // S1 inserts its run and is parked there, uncommitted.
    runWorkers()->pauseOn('runs', 'INSERT', 's1');
    runWorkers()->holdPause();
    $s1 = spawnStart(runWorkers(), 's1', $user, 'aysenur');
    runWorkers()->waitUntilBlocked('s1');

    // S2 sees no committed run, takes the creation path, and its speculative
    // insert waits on S1's under the partial unique index.
    $s2 = spawnStart(runWorkers(), 's2', $user, $second);
    runWorkers()->waitUntilBlocked('s2');

    runWorkers()->releasePause();

    $r1 = runWorkers()->result($s1);
    $r2 = runWorkers()->result($s2);

    expectNoDeadlock([$r1, $r2]);

    expect($r1['status'])->toBe(201)
        ->and($r2['status'])->toBe(200)
        ->and($r2['body']['data']['run_id'])->toBe($r1['body']['data']['run_id'])
        ->and($r2['body']['data']['character_id'])->toBe('aysenur')
        ->and($r2['body']['data']['seed'])->toBe($r1['body']['data']['seed'])
        ->and(activeRunIds($user))->toBe([$r1['body']['data']['run_id']])
        ->and(DB::table('runs')->count())->toBe(1);
})->with(['same character' => ['aysenur'], 'different available character' => ['buso']]);

it('refuses an unavailable start that decides before the winner commits, without creating', function (): void {
    $user = User::factory()->create();

    runWorkers()->pauseOn('runs', 'INSERT', 's1');
    runWorkers()->holdPause();
    $s1 = spawnStart(runWorkers(), 's1', $user, 'aysenur');
    runWorkers()->waitUntilBlocked('s1');

    // No committed run is visible to S2: creation path, and Büşo cannot start
    // one. It answers without ever waiting on S1.
    $r2 = runWorkers()->result(spawnStart(runWorkers(), 's2', $user, 'buso'));

    expect(runWorkers()->stillRunning($s1))->toBeTrue();

    runWorkers()->releasePause();
    $r1 = runWorkers()->result($s1);

    expectNoDeadlock([$r1, $r2]);

    expect($r2['status'])->toBe(422)
        ->and($r2['body']['errors']['character_id'][0]['code'])->toBe('character_unavailable')
        ->and($r1['status'])->toBe(201)
        ->and(DB::table('runs')->count())->toBe(1);
});

it('resumes the winner for an unavailable start that observes it established', function (): void {
    $user = User::factory()->create();

    $r1 = runWorkers()->result(spawnStart(runWorkers(), 's1', $user, 'aysenur'));
    $r2 = runWorkers()->result(spawnStart(runWorkers(), 's2', $user, 'buso'));

    expect($r1['status'])->toBe(201)
        ->and($r2['status'])->toBe(200)
        ->and($r2['body']['data'])->toBe($r1['body']['data'])
        ->and(DB::table('runs')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// start / start — stale variants, and the READ COMMITTED re-select
// ---------------------------------------------------------------------------

it('resumes the replacement for a start that waited on the stale run it replaced', function (string $second): void {
    $user = User::factory()->create();
    $stale = seedActiveRun($user, 25 * 3600);

    // S1 locks the stale run, marks it replaced, and is parked there.
    runWorkers()->pauseOn('runs', 'UPDATE', 's1');
    runWorkers()->holdPause();
    $s1 = spawnStart(runWorkers(), 's1', $user, 'aysenur');
    runWorkers()->waitUntilBlocked('s1');

    // S2's locked select waits on the same row.
    $s2 = spawnStart(runWorkers(), 's2', $user, $second);
    runWorkers()->waitUntilBlocked('s2');

    runWorkers()->releasePause();

    $r1 = runWorkers()->result($s1);
    $r2 = runWorkers()->result($s2);

    expectNoDeadlock([$r1, $r2]);

    // When S1 commits, S2's first statement re-evaluates the row it waited on,
    // finds it no longer active, and returns nothing. Only the re-issued
    // statement — a fresh READ COMMITTED snapshot — sees S1's new run. So S2
    // resumes it (200) instead of refusing Büşo or creating a second run.
    expect($r1['status'])->toBe(201)
        ->and($r2['status'])->toBe(200)
        ->and($r2['body']['data']['run_id'])->toBe($r1['body']['data']['run_id'])
        ->and($r2['body']['data']['character_id'])->toBe('aysenur')
        ->and(DB::table('runs')->where('id', $stale)->value('status'))->toBe('rejected')
        ->and(activeRunIds($user))->toBe([$r1['body']['data']['run_id']]);
})->with(['unavailable' => ['buso'], 'available' => ['aysenur']]);

it('leaves a stale run for the next start when an unavailable start holds it first', function (): void {
    $user = User::factory()->create();
    $stale = seedActiveRun($user, 25 * 3600);

    // Park S2 after it has locked the stale run, at its catalogue read.
    $holder = DB::connection('pgsql_holder');
    $holder->beginTransaction();
    $holder->statement('LOCK TABLE characters IN ACCESS EXCLUSIVE MODE');

    $s2 = spawnStart(runWorkers(), 's2', $user, 'buso');
    runWorkers()->waitUntilBlocked('s2');

    // S1 waits on the row S2 holds.
    $s1 = spawnStart(runWorkers(), 's1', $user, 'aysenur');
    runWorkers()->waitUntilBlocked('s1');

    $holder->rollBack();

    $r2 = runWorkers()->result($s2);
    $r1 = runWorkers()->result($s1);

    expectNoDeadlock([$r1, $r2]);

    // S2 wrote nothing and consumed nothing; S1 found the stale run still
    // active and replaced it.
    expect($r2['status'])->toBe(422)
        ->and($r1['status'])->toBe(201)
        ->and(DB::table('runs')->where('id', $stale)->value('status'))->toBe('rejected')
        ->and(json_decode((string) DB::table('runs')->where('id', $stale)->value('validation_meta'), true)['rules'][0]['code'])->toBe('run_stale_replaced')
        ->and(activeRunIds($user))->toBe([$r1['body']['data']['run_id']]);
});

// ---------------------------------------------------------------------------
// start / finish
// ---------------------------------------------------------------------------

it('lets a finish that locked first complete, then starts fresh from the new progression', function (string $character, int $expectedStatus): void {
    $user = User::factory()->create();
    $run = seedActiveRun($user, 60);

    // F has locked the run and progression, and is parked after the counters.
    runWorkers()->pauseOn('player_progression', 'UPDATE', 'f');
    runWorkers()->holdPause();
    $f = spawnFinish(runWorkers(), 'f', $user, $run, (string) Str::uuid());
    runWorkers()->waitUntilBlocked('f');

    $s = spawnStart(runWorkers(), 's', $user, $character);
    runWorkers()->waitUntilBlocked('s');

    runWorkers()->releasePause();

    $rf = runWorkers()->result($f);
    $rs = runWorkers()->result($s);

    expectNoDeadlock([$rf, $rs]);

    expect($rf['status'])->toBe(200)
        ->and($rf['body']['data']['status'])->toBe('accepted')
        ->and($rs['status'])->toBe($expectedStatus)
        ->and(DB::table('paw_ledger')->count())->toBe(1);

    if ($expectedStatus === 201) {
        expect($rs['body']['data']['run_id'])->not->toBe($run)
            ->and($rs['body']['data']['loli_cycle_paws'])->toBe(50);
    } else {
        expect(activeRunIds($user))->toBe([]);
    }
})->with(['available' => ['aysenur', 201], 'unavailable' => ['buso', 422]]);

it('refuses a finish on a stale run a concurrent start replaced first', function (): void {
    $user = User::factory()->create();
    $stale = seedActiveRun($user, 25 * 3600);

    runWorkers()->pauseOn('runs', 'UPDATE', 's');
    runWorkers()->holdPause();
    $s = spawnStart(runWorkers(), 's', $user, 'aysenur');
    runWorkers()->waitUntilBlocked('s');

    $f = spawnFinish(runWorkers(), 'f', $user, $stale, (string) Str::uuid());
    runWorkers()->waitUntilBlocked('f');

    runWorkers()->releasePause();

    $rs = runWorkers()->result($s);
    $rf = runWorkers()->result($f);

    expectNoDeadlock([$rs, $rf]);

    expect($rs['status'])->toBe(201)
        ->and($rf['status'])->toBe(409)
        ->and($rf['body']['code'])->toBe('run_not_active')
        ->and(DB::table('paw_ledger')->count())->toBe(0)
        ->and((int) DB::table('player_progression')->where('user_id', $user->id)->value('run_count'))->toBe(0);
});

it('lets a finish on a stale run win when it locked first; the start then creates', function (): void {
    $user = User::factory()->create();
    $stale = seedActiveRun($user, 25 * 3600);

    runWorkers()->pauseOn('player_progression', 'UPDATE', 'f');
    runWorkers()->holdPause();
    $f = spawnFinish(runWorkers(), 'f', $user, $stale, (string) Str::uuid());
    runWorkers()->waitUntilBlocked('f');

    $s = spawnStart(runWorkers(), 's', $user, 'aysenur');
    runWorkers()->waitUntilBlocked('s');

    runWorkers()->releasePause();

    $rf = runWorkers()->result($f);
    $rs = runWorkers()->result($s);

    expectNoDeadlock([$rf, $rs]);

    // Replacement and finish never both win: the finish committed first, so
    // the start saw a finalised run and created a new one without touching it.
    expect($rf['body']['data']['status'])->toBe('accepted')
        ->and($rs['status'])->toBe(201)
        ->and(DB::table('runs')->where('id', $stale)->value('status'))->toBe('accepted')
        ->and(DB::table('runs')->where('id', $stale)->value('validation_meta'))->not->toContain('run_stale_replaced');
});

// ---------------------------------------------------------------------------
// finish / finish
// ---------------------------------------------------------------------------

it('replays a concurrent retry with the same key, rewarding once', function (): void {
    $user = User::factory()->create();
    $run = seedActiveRun($user, 60);
    $key = (string) Str::uuid();

    runWorkers()->pauseOn('player_progression', 'UPDATE', 'f1');
    runWorkers()->holdPause();
    $f1 = spawnFinish(runWorkers(), 'f1', $user, $run, $key);
    runWorkers()->waitUntilBlocked('f1');

    $f2 = spawnFinish(runWorkers(), 'f2', $user, $run, $key);
    runWorkers()->waitUntilBlocked('f2');

    runWorkers()->releasePause();

    $r1 = runWorkers()->result($f1);
    $r2 = runWorkers()->result($f2);

    expectNoDeadlock([$r1, $r2]);

    expect($r1['status'])->toBe(200)
        ->and($r2['status'])->toBe(200)
        ->and($r2['body'])->toEqual($r1['body'])
        ->and(DB::table('paw_ledger')->count())->toBe(1)
        ->and((int) DB::table('player_progression')->where('user_id', $user->id)->value('lifetime_paws'))->toBe(50);
});

it('refuses a concurrent finish under a different key once the first commits', function (): void {
    $user = User::factory()->create();
    $run = seedActiveRun($user, 60);

    runWorkers()->pauseOn('player_progression', 'UPDATE', 'f1');
    runWorkers()->holdPause();
    $f1 = spawnFinish(runWorkers(), 'f1', $user, $run, (string) Str::uuid());
    runWorkers()->waitUntilBlocked('f1');

    $f2 = spawnFinish(runWorkers(), 'f2', $user, $run, (string) Str::uuid(), 1200);
    runWorkers()->waitUntilBlocked('f2');

    runWorkers()->releasePause();

    $r1 = runWorkers()->result($f1);
    $r2 = runWorkers()->result($f2);

    expectNoDeadlock([$r1, $r2]);

    expect($r1['body']['data']['status'])->toBe('accepted')
        ->and($r2['status'])->toBe(409)
        ->and($r2['body']['code'])->toBe('run_not_active')
        ->and(DB::table('paw_ledger')->count())->toBe(1)
        ->and((int) DB::table('player_progression')->where('user_id', $user->id)->value('best_score'))->toBe(1000);
});

it('keeps one key to one run when the same key races onto a finalised run', function (): void {
    $user = User::factory()->create();
    $key = (string) Str::uuid();

    // An earlier, already-final run, and the current active one.
    $earlier = seedActiveRun($user, 120);
    DB::table('runs')->where('id', $earlier)->update([
        'status' => 'flagged', 'finished_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        'duration_ms' => 1, 'score' => 1, 'run_paws' => 0, 'result' => '{}', 'validation_meta' => '{}',
        'idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('a', 64),
    ]);
    $current = seedActiveRun($user, 60);

    // F1 has written the key onto the current run, uncommitted.
    runWorkers()->pauseOn('runs', 'UPDATE', 'f1');
    runWorkers()->holdPause();
    $f1 = spawnFinish(runWorkers(), 'f1', $user, $current, $key);
    runWorkers()->waitUntilBlocked('f1');

    // F2 uses the same key on the final run: it cannot see F1's key yet, and
    // the run is not active, so it is refused without waiting.
    $r2 = runWorkers()->result(spawnFinish(runWorkers(), 'f2', $user, $earlier, $key));

    runWorkers()->releasePause();
    $r1 = runWorkers()->result($f1);

    expectNoDeadlock([$r1, $r2]);

    expect($r2['status'])->toBe(409)
        ->and($r1['body']['data']['status'])->toBe('accepted')
        ->and(DB::table('runs')->where('idempotency_key', $key)->pluck('id')->all())->toBe([$current]);
});
