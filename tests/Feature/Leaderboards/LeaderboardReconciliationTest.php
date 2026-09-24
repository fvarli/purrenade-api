<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Leaderboards\LeaderboardProjector;
use App\Services\Leaderboards\LeaderboardReconciliation;
use Illuminate\Support\Facades\DB;

/**
 * Migration 2 (release B): re-merge, then fail closed on any disagreement
 * between the projection and `runs`.
 */
function reconcileMigration(): object
{
    return require database_path('migrations/2026_09_24_100100_reconcile_leaderboard_projection.php');
}

/**
 * A consistent history: two players, two weeks, ties, a flagged and a
 * rejected run — projected, with progression bests to match.
 *
 * @return array{0: User, 1: User, 2: array<string, string>}
 */
function reconciledHistory(): array
{
    [$a, $b] = User::factory()->count(2)->create()->all();

    $runs = [
        'a_best' => insertFinishedRun($a, 1500, 30000, '2026-09-16 10:00:00.000', '2026-09-16 10:05:00.000'),
        'a_week2' => insertFinishedRun($a, 900, 30000, '2026-09-22 10:00:00.000', '2026-09-22 10:05:00.000'),
        'a_flagged' => insertFinishedRun($a, 9000, 30000, '2026-09-22 11:00:00.000', '2026-09-22 11:05:00.000', 'flagged'),
        'b_best' => insertFinishedRun($b, 1500, 25000, '2026-09-22 10:00:00.000', '2026-09-22 10:05:00.000'),
        'b_rejected' => insertFinishedRun($b, 5000, 30000, '2026-09-22 12:00:00.000', '2026-09-22 12:05:00.000', 'rejected'),
    ];

    foreach ([$a->id => 1500, $b->id => 1500] as $userId => $best) {
        DB::table('player_progression')->insert(['user_id' => $userId, 'best_score' => $best, 'run_count' => 2]);
    }

    app(LeaderboardProjector::class)->mergeAcceptedRuns();

    return [$a, $b, $runs];
}

it('names the drift it refuses on', function (): void {
    [$a] = reconciledHistory();
    DB::table('player_progression')->where('user_id', $a->id)->update(['best_score' => 1]);

    expect(function (): void {
        reconcileMigration()->up();
    })
        ->toThrow(RuntimeException::class, 'Leaderboard projection disagrees with runs (all_time_score_not_best=1). Refusing to continue.');
});

it('passes on a consistent projection, and re-running it changes nothing', function (): void {
    reconciledHistory();
    $before = actualProjection();

    reconcileMigration()->up();

    expect(app(LeaderboardReconciliation::class)->violations())->each->toBe(0)
        ->and(actualProjection())->toBe($before);
});

it('heals rows a previous release accepted without projecting (W2), then passes', function (): void {
    [$a] = reconciledHistory();

    // Accepted by the old code after migration 1 ran: in runs, not projected.
    $missed = insertFinishedRun($a, 2000, 30000, '2026-09-23 09:00:00.000', '2026-09-23 09:05:00.000');
    DB::table('player_progression')->where('user_id', $a->id)->update(['best_score' => 2000]);

    expect(app(LeaderboardReconciliation::class)->violations()['all_time_wrong_representative'])->toBe(1);

    reconcileMigration()->up();

    expect(DB::table('leaderboard_all_time')->where('user_id', $a->id)->value('run_id'))->toBe($missed)
        ->and(actualProjection())->toBe(expectedProjection());
});

it('fails closed on drift the merge cannot repair', function (Closure $drift, array $checks): void {
    [$a, $b, $runs] = reconciledHistory();

    $drift($a, $b, $runs);

    $violations = array_filter(app(LeaderboardReconciliation::class)->violations());

    expect(array_keys($violations))->toEqualCanonicalizing($checks);

    // Either the merge itself refuses (a row that cannot be re-keyed violates
    // a constraint) or the assertions do; both roll the migration back.
    expect(function (): void {
        DB::transaction(function (): void {
            reconcileMigration()->up();
        });
    })->toThrow(RuntimeException::class);
})->with([
    'a player with no accepted run' => [function (User $a, User $b, array $runs): void {
        $c = User::factory()->create();
        $run = insertFinishedRun($c, 100, 30000, '2026-09-22 10:00:00.000', '2026-09-22 10:05:00.000', 'flagged');
        DB::table('leaderboard_all_time')->insert(['user_id' => $c->id, 'run_id' => $run, 'score' => 100, 'achieved_at' => '2026-09-22 10:05:00.000', 'duration_ms' => 30000]);
    }, ['all_time_extra_player', 'all_time_wrong_representative', 'all_time_row_not_its_run']],
    'a score above the run it names' => [function (User $a): void {
        DB::table('leaderboard_all_time')->where('user_id', $a->id)->update(['score' => 99999]);
    }, ['all_time_score_not_best', 'all_time_row_not_its_run']],
    'a progression best that disagrees' => [function (User $a): void {
        DB::table('player_progression')->where('user_id', $a->id)->update(['best_score' => 1400]);
    }, ['all_time_score_not_best']],
    'a weekly row in the wrong week' => [function (User $a, User $b, array $runs): void {
        DB::table('leaderboard_weekly')->where('run_id', $runs['b_best'])->update(['week_start' => '2026-09-28']);
    }, ['weekly_wrong_representative', 'weekly_row_not_its_run']],
    'a weekly row naming a flagged run' => [function (User $a, User $b, array $runs): void {
        DB::table('leaderboard_weekly')->where('run_id', $runs['a_week2'])->update(['run_id' => $runs['a_flagged'], 'score' => 9000]);
    }, ['weekly_wrong_representative', 'weekly_row_not_its_run']],
]);

it('lifts a row that lags its best run, since the merge only moves rows earlier', function (): void {
    [$a, $b, $runs] = reconciledHistory();
    DB::table('leaderboard_all_time')->where('user_id', $a->id)->update(['run_id' => $runs['a_week2'], 'score' => 900, 'achieved_at' => '2026-09-22 10:05:00.000']);

    expect(app(LeaderboardReconciliation::class)->violations()['all_time_wrong_representative'])->toBe(1);

    reconcileMigration()->up();

    expect(DB::table('leaderboard_all_time')->where('user_id', $a->id)->value('run_id'))->toBe($runs['a_best'])
        ->and(app(LeaderboardReconciliation::class)->violations())->each->toBe(0);
});

it('rolls migration 2 back as a no-op and re-applies it', function (): void {
    reconciledHistory();
    $before = actualProjection();

    reconcileMigration()->down();
    expect(actualProjection())->toBe($before);

    reconcileMigration()->up();
    expect(actualProjection())->toBe($before);
});

it('re-merges a player missing from both windows instead of failing', function (): void {
    [$a] = reconciledHistory();

    // A row deleted from both windows is simply re-merged — not drift.
    DB::table('leaderboard_weekly')->where('user_id', $a->id)->delete();
    DB::table('leaderboard_all_time')->where('user_id', $a->id)->delete();

    expect(app(LeaderboardReconciliation::class)->violations()['all_time_missing_player'])->toBe(1);

    reconcileMigration()->up();

    expect(app(LeaderboardReconciliation::class)->violations())->each->toBe(0);
});
