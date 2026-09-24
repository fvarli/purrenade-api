<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Leaderboard query plans at volume (M10, gates P1–P4)
|--------------------------------------------------------------------------
|
| Not part of the Pest suite — seeding a million players does not belong in
| ordinary CI. Run it by hand when the leaderboard queries or their indexes
| change, and record the output in docs/architecture/data-model.md §5:
|
|     php tests/Performance/leaderboard_explain.php [players=1000000] [--keep]
|
| It works in its own PostgreSQL schema, `purrenade_perf`, migrated fresh and
| dropped afterwards (unless --keep), so it never touches development data or
| the test schema. What it measures is the real code path: the reader is
| called, every statement it issues is captured with its bindings, and each is
| re-run under EXPLAIN (ANALYZE, BUFFERS).
|
| Shape of the data: one accepted run per player; a fifth of them started in
| the current Istanbul week (so the weekly board holds players/5 rows), the rest
| spread over the four weeks before; scores skewed low with many ties.
|
*/

use App\Enums\LeaderboardWindow;
use App\Models\Character;
use App\Models\User;
use App\Services\Leaderboards\LeaderboardPosition;
use App\Services\Leaderboards\LeaderboardProjector;
use App\Services\Leaderboards\LeaderboardReader;
use App\Services\Leaderboards\LeaderboardWeek;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$players = 1_000_000;
$keep = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--keep') {
        $keep = true;
    } elseif (ctype_digit($argument)) {
        $players = (int) $argument;
    }
}

putenv('DB_SEARCH_PATH=purrenade_perf');
$_ENV['DB_SEARCH_PATH'] = $_SERVER['DB_SEARCH_PATH'] = 'purrenade_perf';

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ((string) config('database.connections.pgsql.search_path') !== 'purrenade_perf') {
    fwrite(STDERR, "Refusing to run outside the purrenade_perf schema.\n");
    exit(1);
}

$say = function (string $line): void {
    fwrite(STDOUT, $line.PHP_EOL);
};

$timed = function (string $label, Closure $work) use ($say): mixed {
    $started = hrtime(true);
    $result = $work();
    $say(sprintf('%-44s %8.1f ms', $label, (hrtime(true) - $started) / 1e6));

    return $result;
};

// --- Schema -----------------------------------------------------------------

config(['database.connections.pgsql_perf_bootstrap' => [...config('database.connections.pgsql'), 'search_path' => 'public']]);
DB::connection('pgsql_perf_bootstrap')->statement('DROP SCHEMA IF EXISTS purrenade_perf CASCADE');
DB::connection('pgsql_perf_bootstrap')->statement('CREATE SCHEMA purrenade_perf');
DB::purge('pgsql');

$say('PostgreSQL '.DB::scalar('SHOW server_version').", {$players} players");
$timed('migrate:fresh', fn () => Artisan::call('migrate:fresh', ['--force' => true]));

// --- Data -------------------------------------------------------------------

$now = CarbonImmutable::now('UTC');
$week = app(LeaderboardWeek::class);
[$weekStartsAt] = $week->periodFor($week->weekStartFor($now));
$characterId = (int) Character::query()->where('key', 'aysenur')->value('id');
$currentWeekPlayers = intdiv($players, 5);

$timed('seed users', fn () => DB::statement("
    INSERT INTO users (display_name, display_name_normalized, email, email_verified_at, password, role, created_at, updated_at)
    SELECT 'perf'||g, 'perf'||g, 'perf'||g||'@perf.invalid', now(), 'not-a-hash', 'player', now(), now()
    FROM generate_series(1, ?) g
", [$players]));

// A fifth start inside the current week (between its start and now), the rest
// uniformly over the four weeks before it.
$timed('seed accepted runs', fn () => DB::statement("
    INSERT INTO runs (id, user_id, character_id, status, seed, started_at, finished_at, duration_ms, score, run_paws,
                      validation_meta, result, idempotency_key, idempotency_fingerprint, created_at, updated_at)
    SELECT gen_random_uuid(), u.id, ?, 'accepted', 1, s.started_at, s.started_at + interval '2 minutes',
           20000 + (random() * 100000)::int, floor(power(random(), 2) * 100000)::int, 0,
           '{}', '{}', gen_random_uuid(), repeat('a', 64), s.started_at, s.started_at
    FROM users u
    CROSS JOIN LATERAL (
        SELECT CASE WHEN u.id <= ?
            THEN CAST(? AS timestamp(3)) + random() * (CAST(? AS timestamp(3)) - CAST(? AS timestamp(3)) - interval '3 minutes')
            ELSE CAST(? AS timestamp(3)) - random() * interval '28 days'
        END AS started_at
    ) s
", [
    $characterId, $currentWeekPlayers,
    $weekStartsAt->format('Y-m-d H:i:s.v'), $now->format('Y-m-d H:i:s.v'), $weekStartsAt->format('Y-m-d H:i:s.v'),
    $weekStartsAt->format('Y-m-d H:i:s.v'),
]));

$timed('backfill (mergeAcceptedRuns)', fn () => app(LeaderboardProjector::class)->mergeAcceptedRuns());
$timed('VACUUM ANALYZE', fn () => DB::statement('VACUUM ANALYZE'));

$say(sprintf('rows: all-time %d, current week %d, all weekly %d',
    DB::scalar('SELECT count(*) FROM leaderboard_all_time'),
    DB::scalar('SELECT count(*) FROM leaderboard_weekly WHERE week_start = ?', [$week->weekStartFor($now)]),
    DB::scalar('SELECT count(*) FROM leaderboard_weekly'),
));

// --- The viewer and the cursor, both at the median ---------------------------

/** @return array{0: User, 1: LeaderboardPosition} */
$median = function (LeaderboardWindow $window) use ($week, $now): array {
    $weekStart = $window === LeaderboardWindow::Weekly ? $week->weekStartFor($now) : null;
    $table = $window->table();
    $count = (int) DB::scalar("SELECT count(*) FROM {$table}".($weekStart ? ' WHERE week_start = ?' : ''), $weekStart ? [$weekStart] : []);

    $row = DB::selectOne(
        "SELECT user_id, score, achieved_at, duration_ms, run_id FROM {$table}"
        .($weekStart ? ' WHERE week_start = ?' : '')
        .' ORDER BY score DESC, achieved_at, duration_ms, run_id OFFSET ? LIMIT 1',
        [...($weekStart ? [$weekStart] : []), intdiv($count, 2)],
    );

    return [
        User::query()->findOrFail($row->user_id),
        new LeaderboardPosition($window, $weekStart, (int) $row->score,
            CarbonImmutable::parse($row->achieved_at, 'UTC')->getTimestampMs(), (int) $row->duration_ms, (string) $row->run_id),
    ];
};

// --- Plans ------------------------------------------------------------------

$capture = function (Closure $read): array {
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = [$query->sql, $query->bindings];
    });
    $read();
    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $statements;
};

$reader = app(LeaderboardReader::class);

foreach ([LeaderboardWindow::AllTime, LeaderboardWindow::Weekly] as $window) {
    [$viewer, $position] = $median($window);

    foreach (['first page, limit 25' => [null, 25], 'page after the median, limit 100' => [$position, 100]] as $label => [$after, $limit]) {
        $say('');
        $say("=== {$window->value}: {$label}; viewer at the median ===");

        $statements = $capture(fn () => $reader->page($viewer, $window, $after, $limit));
        $say(count($statements).' statement(s) issued by the reader');

        foreach ($statements as [$sql, $bindings]) {
            if (str_starts_with($sql, 'SET TRANSACTION')) {
                $say('-- '.$sql);

                continue;
            }

            $say('-- '.preg_replace('/\s+/', ' ', $sql));

            foreach (DB::select('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, TIMING ON) '.$sql, $bindings) as $line) {
                $say('   '.$line->{'QUERY PLAN'});
            }
        }
    }

    // Latency of the whole read, as the endpoint calls it.
    $durations = [];
    for ($i = 0; $i < 60; $i++) {
        $started = hrtime(true);
        $reader->page($viewer, $window, $i % 2 === 0 ? null : $position, $i % 3 === 0 ? 100 : 25);
        $durations[] = (hrtime(true) - $started) / 1e6;
    }
    sort($durations);
    $say(sprintf('%s read latency over 60 calls: p50 %.1f ms, p95 %.1f ms, max %.1f ms',
        $window->value, $durations[29], $durations[56], $durations[59]));
}

// --- The write path (P2): the two upserts an accepted finish adds ------------

$projector = app(LeaderboardProjector::class);
$sample = DB::select("SELECT user_id, id, started_at, finished_at, score, duration_ms FROM runs WHERE status = 'accepted' ORDER BY random() LIMIT 500");
$durations = [];

DB::beginTransaction();
foreach ($sample as $run) {
    $started = hrtime(true);
    $projector->recordAccepted((int) $run->user_id, (string) $run->id,
        CarbonImmutable::parse($run->started_at, 'UTC'), CarbonImmutable::parse($run->finished_at, 'UTC'),
        (int) $run->score + 1, (int) $run->duration_ms);
    $durations[] = (hrtime(true) - $started) / 1e6;
}
DB::rollBack();
sort($durations);
$say('');
$say(sprintf('projector.recordAccepted (both upserts) over 500 finishes: p50 %.2f ms, p95 %.2f ms',
    $durations[249], $durations[474]));

if (! $keep) {
    DB::connection('pgsql_perf_bootstrap')->statement('DROP SCHEMA purrenade_perf CASCADE');
    $say('schema purrenade_perf dropped');
}
