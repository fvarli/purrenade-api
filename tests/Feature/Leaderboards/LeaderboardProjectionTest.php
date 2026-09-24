<?php

declare(strict_types=1);

use App\Models\Run;
use App\Models\User;
use App\Services\Leaderboards\LeaderboardProjector;
use App\Services\Leaderboards\LeaderboardWeek;
use App\Services\Progression\ProgressionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

/**
 * The write side of M10: what reaches the projection, which run represents
 * each row, and that the one ORDER comparator (E1) is the same everywhere.
 *
 * Eligibility and the finish integration go through the real endpoint;
 * representative selection uses crafted rows, because exact ties on
 * `achieved_at` and `run_id` cannot be produced on demand through it.
 */
beforeEach(function (): void {
    // Wednesday 2026-09-23 15:00 Istanbul.
    travelTo(CarbonImmutable::parse('2026-09-23 12:00:00.000', 'UTC'));
});

/** Start a run now, move the clock on, finish it through the endpoint. */
function playRun(User $user, array $telemetry, int $windowMs = 31_000, ?string $key = null): TestResponse
{
    $runId = startRun($user)->assertSuccessful()->json('data.run_id');

    travel($windowMs)->milliseconds();

    return finishRun($user, $runId, $telemetry, $key ?? (string) Str::uuid());
}

function crafted(string $suffix): string
{
    return '0192f000-0000-7000-8000-'.str_pad($suffix, 12, '0', STR_PAD_LEFT);
}

function projectionSnapshot(): array
{
    return [
        DB::table('leaderboard_all_time')->orderBy('user_id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('leaderboard_weekly')->orderBy('week_start')->orderBy('user_id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

// ---------------------------------------------------------------------------
// Eligibility — accepted only (L1)
// ---------------------------------------------------------------------------

it('projects an accepted run into both windows, from the stored run', function (): void {
    $user = User::factory()->create();

    $response = playRun($user, plausibleTelemetry(30_000, 1000, 50))->assertOk();
    $run = Run::query()->findOrFail($response->json('data.run_id'));

    expect($response->json('data.status'))->toBe('accepted')
        ->and(allTimeRows())->toBe([[
            'user_id' => $user->id,
            'run_id' => $run->id,
            'score' => 1000,
            'achieved_at' => $run->finished_at->format('Y-m-d H:i:s.v'),
            'duration_ms' => 30000,
        ]])
        ->and(weeklyRows())->toBe([[
            'week_start' => '2026-09-21',
            'user_id' => $user->id,
            'run_id' => $run->id,
            'score' => 1000,
            'achieved_at' => $run->finished_at->format('Y-m-d H:i:s.v'),
            'duration_ms' => 30000,
        ]]);
});

it('never projects a flagged run, however high its score', function (): void {
    $user = User::factory()->create();
    playRun($user, plausibleTelemetry(30_000, 1000, 50))->assertOk();
    $before = projectionSnapshot();

    // 300 points a second: over the PROPOSED bound, so flagged, not rejected.
    $flagged = playRun($user, plausibleTelemetry(30_000, 9000, 50))->assertOk();

    expect($flagged->json('data.status'))->toBe('flagged')
        ->and(projectionSnapshot())->toBe($before)
        ->and(DB::table('leaderboard_all_time')->value('score'))->toBe(1000);
});

it('never projects a flagged run for a player with no accepted run', function (): void {
    $user = User::factory()->create();

    // Below the PROPOSED minimum duration.
    expect(playRun($user, plausibleTelemetry(3_000, 200, 5))->json('data.status'))->toBe('flagged')
        ->and(allTimeRows())->toBe([])
        ->and(weeklyRows())->toBe([]);
});

it('never projects a rejected run', function (): void {
    $user = User::factory()->create();

    // Claims a minute of play inside a 31-second window: structurally impossible.
    expect(playRun($user, plausibleTelemetry(60_000, 1000, 50))->json('data.status'))->toBe('rejected')
        ->and(allTimeRows())->toBe([])
        ->and(weeklyRows())->toBe([]);
});

it('never projects a stale-replaced run', function (): void {
    $user = User::factory()->create();
    startRun($user)->assertCreated();

    travel(25)->hours();
    startRun($user)->assertCreated();

    expect(DB::table('runs')->where('status', 'rejected')->count())->toBe(1)
        ->and(allTimeRows())->toBe([])
        ->and(weeklyRows())->toBe([]);
});

// ---------------------------------------------------------------------------
// Representative selection — the monotone upsert
// ---------------------------------------------------------------------------

it('replaces the representative only with a run that precedes it in ORDER', function (array $first, array $second, string $winner): void {
    $user = User::factory()->create();

    $a = insertFinishedRun($user, $first[0], $first[2], '2026-09-23 10:00:00.000', $first[1], id: crafted($first[3]));
    $b = insertFinishedRun($user, $second[0], $second[2], '2026-09-23 10:30:00.000', $second[1], id: crafted($second[3]));

    projectStoredRun($a);
    projectStoredRun($b);

    $expected = $winner === 'first' ? $a : $b;

    expect(DB::table('leaderboard_all_time')->value('run_id'))->toBe($expected)
        ->and(DB::table('leaderboard_weekly')->value('run_id'))->toBe($expected);
})->with([
    // [score, achieved_at, duration_ms, id suffix]
    'a higher score replaces' => [[900, '2026-09-23 11:00:00.000', 30000, '1'], [1000, '2026-09-23 11:30:00.000', 30000, '2'], 'second'],
    'a lower score does not' => [[1000, '2026-09-23 11:00:00.000', 30000, '1'], [900, '2026-09-23 11:30:00.000', 30000, '2'], 'first'],
    'an equal score achieved later does not' => [[1000, '2026-09-23 11:00:00.000', 30000, '1'], [1000, '2026-09-23 11:30:00.000', 20000, '2'], 'first'],
    'an equal score achieved earlier does' => [[1000, '2026-09-23 11:30:00.000', 20000, '1'], [1000, '2026-09-23 11:00:00.000', 30000, '2'], 'second'],
    'equal score and time: the shorter duration wins' => [[1000, '2026-09-23 11:00:00.000', 30000, '1'], [1000, '2026-09-23 11:00:00.000', 20000, '2'], 'second'],
    'equal score and time: a longer duration does not' => [[1000, '2026-09-23 11:00:00.000', 20000, '1'], [1000, '2026-09-23 11:00:00.000', 30000, '2'], 'first'],
    'all equal: the lower run id wins, projected second' => [[1000, '2026-09-23 11:00:00.000', 30000, '9'], [1000, '2026-09-23 11:00:00.000', 30000, '3'], 'second'],
    'all equal: the higher run id does not replace' => [[1000, '2026-09-23 11:00:00.000', 30000, '3'], [1000, '2026-09-23 11:00:00.000', 30000, '9'], 'first'],
]);

it('is idempotent: re-projecting the representative changes nothing, not even updated_at', function (): void {
    $user = User::factory()->create();
    $run = insertFinishedRun($user, 1000, 30000, '2026-09-23 10:00:00.000', '2026-09-23 10:01:00.000');

    projectStoredRun($run);
    $before = projectionSnapshot();

    travel(5)->minutes();
    projectStoredRun($run);
    app(LeaderboardProjector::class)->mergeAcceptedRuns();

    expect(projectionSnapshot())->toBe($before);
});

it('keeps the all-time and weekly representatives independent', function (): void {
    $user = User::factory()->create();

    // Week of 2026-09-14: the better run. Week of 2026-09-21: a weaker one.
    $old = insertFinishedRun($user, 2000, 30000, '2026-09-16 10:00:00.000', '2026-09-16 10:01:00.000');
    $new = insertFinishedRun($user, 1500, 30000, '2026-09-23 10:00:00.000', '2026-09-23 10:01:00.000');

    projectStoredRun($old);
    projectStoredRun($new);

    expect(DB::table('leaderboard_all_time')->value('run_id'))->toBe($old)
        ->and(DB::table('leaderboard_weekly')->orderBy('week_start')->pluck('run_id', 'week_start')->all())
        ->toBe(['2026-09-14' => $old, '2026-09-21' => $new]);
});

// ---------------------------------------------------------------------------
// Order unity (E1) — one comparator everywhere
// ---------------------------------------------------------------------------

it('selects the same representatives live, by merge and by the PHP comparator, and orders the board by it', function (int $seed): void {
    mt_srand($seed);

    $users = User::factory()->count(12)->create();
    $weekStarts = ['2026-09-06 21:00:00.000', '2026-09-13 21:00:00.000', '2026-09-20 21:00:00.000'];
    $runIds = [];

    foreach ($users as $user) {
        for ($i = 0, $n = mt_rand(1, 7); $i < $n; $i++) {
            // Few distinct values, so every tie level is exercised; starts
            // straddle week boundaries by a millisecond.
            $start = CarbonImmutable::parse($weekStarts[mt_rand(0, 2)], 'UTC')
                ->addMilliseconds(mt_rand(-1, 1) === 0 ? mt_rand(0, 5) * 86_400_000 : mt_rand(-1, 0));
            $finished = $start->addMinutes(mt_rand(1, 3));
            $status = ['accepted', 'accepted', 'accepted', 'flagged', 'rejected'][mt_rand(0, 4)];

            $runIds[] = [insertFinishedRun(
                $user,
                [500, 1000, 1000, 1500][mt_rand(0, 3)],
                [20000, 30000][mt_rand(0, 1)],
                $start->format('Y-m-d H:i:s.v'),
                $finished->format('Y-m-d H:i:s.v'),
                $status,
            ), $status];
        }
    }

    // Equal (score, achieved_at, duration) across different players, so the
    // board's final key is exercised too.
    foreach ($users->take(3) as $user) {
        $runIds[] = [insertFinishedRun($user, 5000, 30000, '2026-09-22 09:00:00.000', '2026-09-22 09:05:00.000'), 'accepted'];
    }

    $expected = expectedProjection();

    // Live, in a random order.
    shuffle($runIds);
    foreach ($runIds as [$id, $status]) {
        if ($status === 'accepted') {
            projectStoredRun($id);
        }
    }

    expect(actualProjection())->toBe($expected);

    // Merge, from nothing.
    DB::table('leaderboard_weekly')->delete();
    DB::table('leaderboard_all_time')->delete();
    app(LeaderboardProjector::class)->mergeAcceptedRuns();

    expect(actualProjection())->toBe($expected);

    // The board: the index's ORDER equals the PHP sort.
    $board = DB::select('SELECT run_id, score, achieved_at, duration_ms FROM leaderboard_all_time
        ORDER BY score DESC, achieved_at ASC, duration_ms ASC, run_id ASC');
    $sorted = $expected['all_time'];
    usort($sorted, 'leaderboardOrder');

    expect(array_column(array_map(fn ($r) => (array) $r, $board), 'run_id'))->toBe(array_column($sorted, 'run_id'));
})->with([1, 7, 42]);

// ---------------------------------------------------------------------------
// Schema — totality and constraints
// ---------------------------------------------------------------------------

it('refuses a second row for the same run: ORDER is total', function (): void {
    [$a, $b] = User::factory()->count(2)->create()->all();
    $run = insertFinishedRun($a, 1000, 30000, '2026-09-23 10:00:00.000', '2026-09-23 10:01:00.000');
    projectStoredRun($run);

    expectRefused(fn () => DB::table('leaderboard_all_time')->insert([
        'user_id' => $b->id, 'run_id' => $run, 'score' => 1000,
        'achieved_at' => '2026-09-23 10:01:00.000', 'duration_ms' => 30000,
    ]));

    expectRefused(fn () => DB::table('leaderboard_weekly')->insert([
        'week_start' => '2026-09-14', 'user_id' => $a->id, 'run_id' => $run, 'score' => 1000,
        'achieved_at' => '2026-09-23 10:01:00.000', 'duration_ms' => 30000,
    ]));
});

it('refuses rows the projector cannot produce', function (array $overrides): void {
    $user = User::factory()->create();
    $run = insertFinishedRun($user, 1000, 30000, '2026-09-23 10:00:00.000', '2026-09-23 10:01:00.000');

    expectRefused(fn () => DB::table('leaderboard_weekly')->insert([
        'week_start' => '2026-09-21', 'user_id' => $user->id, 'run_id' => $run, 'score' => 1000,
        'achieved_at' => '2026-09-23 10:01:00.000', 'duration_ms' => 30000,
        ...$overrides,
    ]));
})->with([
    'a week that does not start on Monday' => [['week_start' => '2026-09-22']],
    'a negative score' => [['score' => -1]],
    'a zero duration' => [['duration_ms' => 0]],
    'an unknown run' => [['run_id' => '0192f000-0000-7000-8000-00000000dead']],
]);

it('restricts deleting a run or a player the projection references (E2)', function (): void {
    $user = User::factory()->create();
    $run = insertFinishedRun($user, 1000, 30000, '2026-09-23 10:00:00.000', '2026-09-23 10:01:00.000');
    projectStoredRun($run);

    $restrict = DB::select("SELECT conname, confdeltype FROM pg_constraint
        WHERE contype = 'f' AND conrelid::regclass::text IN ('leaderboard_all_time', 'leaderboard_weekly')
        ORDER BY conname");

    expect(array_column(array_map(fn ($r) => (array) $r, $restrict), 'confdeltype'))->toBe(['r', 'r', 'r', 'r']);

    // Only the projection references this run (it credited no paws).
    expectRefused(fn () => DB::table('runs')->where('id', $run)->delete());
});

it('stores no public identity in the projection (D1)', function (): void {
    $columns = fn (string $table): array => DB::table('information_schema.columns')
        ->where('table_name', $table)->where('table_schema', DB::raw('current_schema()'))
        ->orderBy('column_name')->pluck('column_name')->all();

    expect($columns('leaderboard_all_time'))->toBe(['achieved_at', 'created_at', 'duration_ms', 'run_id', 'score', 'updated_at', 'user_id'])
        ->and($columns('leaderboard_weekly'))->toBe(['achieved_at', 'created_at', 'duration_ms', 'run_id', 'score', 'updated_at', 'user_id', 'week_start']);
});

// ---------------------------------------------------------------------------
// Weekly attribution (L3, D2)
// ---------------------------------------------------------------------------

it('attributes a run started Sunday 23:58 Istanbul and finished on Monday to the old week', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-27 20:58:00.000', 'UTC'));
    $user = User::factory()->create();

    $run = playRun($user, plausibleTelemetry(), windowMs: 5 * 60_000)->assertOk();

    expect(weeklyRows())->toHaveCount(1)
        ->and(weeklyRows()[0]['week_start'])->toBe('2026-09-21')
        ->and(weeklyRows()[0]['run_id'])->toBe($run->json('data.run_id'))
        ->and(allTimeRows()[0]['achieved_at'])->toBe('2026-09-27 21:03:00.000');
});

it('lets a delayed finish update its start week and all-time, never the current week, with finish-time tie priority', function (): void {
    // X starts on Sunday of the week of 2026-09-21 and submits on Wednesday.
    travelTo(CarbonImmutable::parse('2026-09-27 20:00:00.000', 'UTC'));
    [$x, $y] = User::factory()->count(2)->create()->all();
    $xRun = startRun($x)->assertCreated()->json('data.run_id');

    // Y plays a run with the same score on Monday of the next week.
    travelTo(CarbonImmutable::parse('2026-09-28 09:00:00.000', 'UTC'));
    $yRun = playRun($y, plausibleTelemetry(30_000, 1000, 50))->json('data.run_id');

    travelTo(CarbonImmutable::parse('2026-09-30 09:00:00.000', 'UTC'));
    finishRun($x, $xRun, plausibleTelemetry(30_000, 1000, 50), (string) Str::uuid())
        ->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(DB::table('leaderboard_weekly')->where('user_id', $x->id)->pluck('week_start')->all())->toBe(['2026-09-21'])
        ->and(DB::table('leaderboard_weekly')->where('user_id', $y->id)->pluck('week_start')->all())->toBe(['2026-09-28'])
        // Equal scores: Y was accepted first, so Y ranks first all-time.
        ->and(DB::table('leaderboard_all_time')->orderByDesc('score')->orderBy('achieved_at')->orderBy('duration_ms')->orderBy('run_id')->pluck('run_id')->all())
        ->toBe([$yRun, $xRun]);
});

// ---------------------------------------------------------------------------
// Idempotency and atomicity
// ---------------------------------------------------------------------------

it('changes nothing on an idempotent replay or a reused key', function (): void {
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31)->seconds();
    $key = (string) Str::uuid();

    finishRun($user, $runId, plausibleTelemetry(), $key)->assertOk();
    $before = projectionSnapshot();

    travel(1)->minutes();
    finishRun($user, $runId, plausibleTelemetry(), $key)->assertOk();
    finishRun($user, $runId, plausibleTelemetry(30_000, 1200, 50), $key)
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');

    expect(projectionSnapshot())->toBe($before)
        ->and((int) progressionRow($user)->run_count)->toBe(1);
});

it('rolls the whole finish back when the projection write fails', function (): void {
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31)->seconds();
    app(ProgressionService::class)->ensure($user->id);
    $before = (array) progressionRow($user);

    // Fails the last projection write — after progression, the ledger and the
    // all-time row — so every one of them must roll back.
    DB::unprepared("
        CREATE FUNCTION purrenade_test_fail_weekly() RETURNS trigger AS \$\$
        BEGIN RAISE EXCEPTION 'injected failure'; END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER purrenade_test_fail_weekly BEFORE INSERT OR UPDATE ON leaderboard_weekly
            FOR EACH ROW EXECUTE FUNCTION purrenade_test_fail_weekly();
    ");

    $key = (string) Str::uuid();
    finishRun($user, $runId, plausibleTelemetry(), $key)->assertStatus(500);

    expect((array) progressionRow($user))->toBe($before)
        ->and(DB::table('paw_ledger')->count())->toBe(0)
        ->and(DB::table('runs')->where('id', $runId)->value('status'))->toBe('active')
        ->and(allTimeRows())->toBe([])
        ->and(weeklyRows())->toBe([]);

    DB::unprepared('DROP TRIGGER purrenade_test_fail_weekly ON leaderboard_weekly; DROP FUNCTION purrenade_test_fail_weekly();');

    finishRun($user, $runId, plausibleTelemetry(), $key)->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(allTimeRows())->toHaveCount(1)
        ->and(weeklyRows())->toHaveCount(1)
        ->and((int) progressionRow($user)->run_count)->toBe(1);
});

it('keeps the all-time score equal to the progression best', function (): void {
    $user = User::factory()->create();

    foreach ([800, 1200, 900] as $score) {
        playRun($user, plausibleTelemetry(30_000, $score, 50))->assertOk();
    }

    expect((int) DB::table('leaderboard_all_time')->value('score'))->toBe((int) progressionRow($user)->best_score)
        ->and((int) progressionRow($user)->best_score)->toBe(1200);
});

// ---------------------------------------------------------------------------
// PHP ↔ PostgreSQL week-key parity
// ---------------------------------------------------------------------------

it('computes the same week key in PHP and in PostgreSQL', function (): void {
    $week = app(LeaderboardWeek::class);
    $instants = [];

    foreach ([
        '2026-09-20 21:00:00.000', '2026-12-27 21:00:00.000', '2027-01-03 21:00:00.000',
        '2028-02-27 21:00:00.000', '2028-02-29 12:00:00.000',
        '2015-01-11 22:00:00.000', '2015-01-11 21:30:00.000',
        '2015-03-22 22:00:00.000', '2015-03-29 21:00:00.000', '2015-03-29 01:00:00.000',
        '2015-11-01 21:00:00.000', '2015-11-08 22:00:00.000', '2015-11-08 01:00:00.000',
        '2016-09-06 21:00:00.000',
    ] as $instant) {
        $at = CarbonImmutable::parse($instant, 'UTC');
        array_push($instants, $at->subMillisecond(), $at, $at->addMillisecond());
    }

    foreach ($instants as $at) {
        $literal = $at->format('Y-m-d H:i:s.v');
        $sql = DB::scalar('SELECT '.LeaderboardProjector::weekKeySql('CAST(? AS timestamp(3))'), [$literal, $week->timezone()]);

        expect((string) $sql)->toBe($week->weekStartFor($at), "week key of {$literal}");
    }
});

it('reads the week zone from configuration, not from the environment', function (): void {
    expect(config('leaderboards.week_timezone'))->toBe('Europe/Istanbul')
        ->and(app(LeaderboardWeek::class)->timezone())->toBe('Europe/Istanbul')
        ->and(file_get_contents(config_path('leaderboards.php')))->not->toContain('env(');
});

// ---------------------------------------------------------------------------
// Migration 1 — historical backfill
// ---------------------------------------------------------------------------

it('backfills the projection from an existing M9 history, accepted runs only', function (): void {
    $migration = require database_path('migrations/2026_09_24_100000_create_leaderboard_tables.php');
    $migration->down();

    [$a, $b, $c] = User::factory()->count(3)->create()->all();

    // A: several weeks, a flagged run above everything, a tie on score.
    insertFinishedRun($a, 1200, 30000, '2026-09-16 10:00:00.000', '2026-09-16 10:01:00.000');
    insertFinishedRun($a, 1200, 25000, '2026-09-16 11:00:00.000', '2026-09-16 11:01:00.000');
    insertFinishedRun($a, 9000, 30000, '2026-09-22 10:00:00.000', '2026-09-22 10:01:00.000', 'flagged');
    insertFinishedRun($a, 700, 30000, '2026-09-22 11:00:00.000', '2026-09-22 11:01:00.000');
    // B: a run started on Sunday 23:59 Istanbul, finished on Monday.
    insertFinishedRun($b, 1500, 30000, '2026-09-20 20:59:00.000', '2026-09-20 21:02:00.000');
    // C: only a rejected run — no entry at all.
    insertFinishedRun($c, 5000, 30000, '2026-09-22 10:00:00.000', '2026-09-22 10:01:00.000', 'rejected');

    $migration->up();

    $expected = expectedProjection();

    expect(actualProjection())->toBe($expected)
        ->and(array_column($expected['all_time'], 'user_id'))->toBe([$a->id, $b->id])
        ->and(DB::table('leaderboard_weekly')->where('user_id', $b->id)->value('week_start'))->toBe('2026-09-14')
        ->and(DB::table('leaderboard_all_time')->where('user_id', $a->id)->value('score'))->toBe(1200)
        ->and(DB::table('leaderboard_all_time')->where('user_id', $a->id)->value('duration_ms'))->toBe(30000);
});

it('merges runs a previous release accepted without projecting, never lowering a row', function (): void {
    $user = User::factory()->create();
    $projected = insertFinishedRun($user, 500, 30000, '2026-09-23 09:00:00.000', '2026-09-23 09:01:00.000');
    projectStoredRun($projected);

    // Accepted while the old code was still serving: in runs, not projected.
    $missed = insertFinishedRun($user, 900, 30000, '2026-09-23 10:00:00.000', '2026-09-23 10:01:00.000');

    app(LeaderboardProjector::class)->mergeAcceptedRuns();

    expect(DB::table('leaderboard_all_time')->value('run_id'))->toBe($missed)
        ->and(actualProjection())->toBe(expectedProjection());
});

it('rolls migration 1 back and forward cleanly, re-running its backfill', function (): void {
    $user = User::factory()->create();
    playRun($user, plausibleTelemetry())->assertOk();
    $before = actualProjection();

    $migration = require database_path('migrations/2026_09_24_100000_create_leaderboard_tables.php');
    $migration->down();

    expect(DB::getSchemaBuilder()->hasTable('leaderboard_all_time'))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasTable('leaderboard_weekly'))->toBeFalse();

    $migration->up();

    expect(actualProjection())->toBe($before);
});

it('declares the ORDER indexes exactly', function (): void {
    $definition = fn (string $name): string => (string) DB::scalar(
        'SELECT pg_get_indexdef(c.oid) FROM pg_class c WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace',
        [$name],
    );

    expect($definition('leaderboard_all_time_order'))->toContain('(score DESC, achieved_at, duration_ms, run_id)')
        ->and($definition('leaderboard_weekly_order'))->toContain('(week_start, score DESC, achieved_at, duration_ms, run_id)');
});

it('calls the projector from the accepted branch only, after progression and the ledger', function (): void {
    // Structural guard on the call site, not only on behaviour: the projector
    // is invoked from exactly one place, inside the accepted branch.
    $source = file_get_contents(app_path('Services/Runs/RunLifecycleService.php'));

    expect(substr_count($source, '->recordAccepted('))->toBe(1)
        ->and(preg_match('/if \(\$status === RunStatus::Accepted\) \{[^}]*applyAccepted[^}]*recordAccepted\(/s', $source))->toBe(1);
});

it('merges an empty history into an empty projection', function (): void {
    expect(fn () => app(LeaderboardProjector::class)->mergeAcceptedRuns())->not->toThrow(QueryException::class)
        ->and(allTimeRows())->toBe([]);
});
