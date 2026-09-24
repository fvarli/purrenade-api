<?php

declare(strict_types=1);

namespace App\Services\Leaderboards;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the leaderboard projection (M10).
 *
 * `leaderboard_all_time` holds one row per player and `leaderboard_weekly` one
 * row per player per week. Each row is **represented by one accepted run** —
 * the player's first run in ORDER for that window — and carries that run's
 * score, `achieved_at` (= `runs.finished_at`, D2) and `duration_ms`. Both
 * tables are derived data: rebuildable from `runs` at any time by
 * {@see self::mergeAcceptedRuns()}.
 *
 * ## ORDER — the one comparator (E1)
 *
 *     score DESC, achieved_at ASC, duration_ms ASC, run_id ASC
 *
 * The same tuple, in the same direction, chooses each player's representative
 * run (the upsert guard below and the merge's `DISTINCT ON`), orders the
 * board (the `*_order` indexes, the keyset predicate, the cursor and the rank
 * counts). `run_id` is `UNIQUE` in each table, so the order is total.
 *
 * ## The monotone upsert — invariant M
 *
 * A row is replaced only by a run that **precedes** it in ORDER
 * ({@see self::PRECEDES}), so a row's ORDER key only ever moves earlier. The
 * upsert is idempotent — re-projecting a run that is already represented, or
 * one that does not beat the row, changes nothing — and it is the reason a
 * multi-page traversal never serves a row twice (docs/api/endpoints/
 * leaderboards.md, consistency contract). Anything that could move a row
 * *later* — run invalidation, deletion — breaks M and must say how it handles
 * open traversals.
 *
 * ## Where it is called
 *
 * Only on the **accepted** branch of the finish transaction, after the
 * progression and ledger writes, so the lock order is RUN → PROGRESSION →
 * PAW_LEDGER → LB_ALL_TIME → LB_WEEKLY. A replay returns before any write,
 * and a flagged or rejected run never reaches it. A failure here throws and
 * rolls the whole finish back.
 */
final class LeaderboardProjector
{
    /**
     * "EXCLUDED precedes t in ORDER" — the upsert guard, shared by the
     * single-run projection and the set-based merge.
     */
    public const PRECEDES = '(EXCLUDED.score > t.score OR (EXCLUDED.score = t.score AND '
        .'(EXCLUDED.achieved_at, EXCLUDED.duration_ms, EXCLUDED.run_id) < (t.achieved_at, t.duration_ms, t.run_id)))';

    private const UPDATE = 'DO UPDATE SET run_id = EXCLUDED.run_id, score = EXCLUDED.score, '
        .'achieved_at = EXCLUDED.achieved_at, duration_ms = EXCLUDED.duration_ms, updated_at = EXCLUDED.updated_at '
        .'WHERE '.self::PRECEDES;

    /** ORDER over `runs`, for the merge's `DISTINCT ON` (achieved_at = finished_at). */
    private const RUNS_ORDER = 'r.score DESC, r.finished_at ASC, r.duration_ms ASC, r.id ASC';

    public function __construct(private readonly LeaderboardWeek $week) {}

    /**
     * Project one accepted run into both windows. Must run inside the finish
     * transaction, after the progression lock is held.
     */
    public function recordAccepted(
        int $userId,
        string $runId,
        CarbonImmutable $startedAt,
        CarbonImmutable $finishedAt,
        int $score,
        int $durationMs,
    ): void {
        $achievedAt = self::timestamp($finishedAt);
        $now = self::timestamp(CarbonImmutable::now('UTC'));

        // LB_ALL_TIME, then LB_WEEKLY — the documented lock order.
        DB::statement(
            'INSERT INTO leaderboard_all_time AS t (user_id, run_id, score, achieved_at, duration_ms, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id) '.self::UPDATE,
            [$userId, $runId, $score, $achievedAt, $durationMs, $now, $now],
        );

        DB::statement(
            'INSERT INTO leaderboard_weekly AS t (week_start, user_id, run_id, score, achieved_at, duration_ms, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (week_start, user_id) '.self::UPDATE,
            [$this->week->weekStartFor($startedAt), $userId, $runId, $score, $achievedAt, $durationMs, $now, $now],
        );
    }

    /**
     * Merge every accepted run into both windows, set-based.
     *
     * Each window's candidate is the ORDER-first accepted run per key, chosen
     * by `DISTINCT ON`, and fed through the same monotone upsert as a live
     * finish — so it is idempotent, converges with concurrent live writes, and
     * never moves an existing row later. Used by the historical backfill and by
     * the reconciliation that closes the deploy window in which the previous
     * release accepted runs without projecting them.
     *
     * The weekly key is PostgreSQL's own IANA computation of
     * {@see LeaderboardWeek::weekStartFor()}: `started_at` holds naive UTC, the
     * first `AT TIME ZONE` reads it as UTC and the second converts it to the
     * zone's wall time, and `date_trunc('week')` truncates to the ISO Monday.
     */
    public function mergeAcceptedRuns(): void
    {
        DB::statement(
            "INSERT INTO leaderboard_all_time AS t (user_id, run_id, score, achieved_at, duration_ms, created_at, updated_at)
             SELECT DISTINCT ON (r.user_id)
                    r.user_id, r.id, r.score, r.finished_at, r.duration_ms,
                    now() AT TIME ZONE 'UTC', now() AT TIME ZONE 'UTC'
             FROM runs r
             WHERE r.status = 'accepted'
             ORDER BY r.user_id, ".self::RUNS_ORDER.'
             ON CONFLICT (user_id) '.self::UPDATE,
        );

        DB::statement(
            'INSERT INTO leaderboard_weekly AS t (week_start, user_id, run_id, score, achieved_at, duration_ms, created_at, updated_at)
             SELECT DISTINCT ON (w.week_start, w.user_id)
                    w.week_start, w.user_id, w.id, w.score, w.finished_at, w.duration_ms,
                    now() AT TIME ZONE \'UTC\', now() AT TIME ZONE \'UTC\'
             FROM (
                 SELECT '.self::weekKeySql('r.started_at').' AS week_start, r.*
                 FROM runs r
                 WHERE r.status = \'accepted\'
             ) w
             ORDER BY w.week_start, w.user_id, w.score DESC, w.finished_at ASC, w.duration_ms ASC, w.id ASC
             ON CONFLICT (week_start, user_id) '.self::UPDATE,
            [$this->week->timezone()],
        );
    }

    /**
     * SQL for the week key of a naive-UTC `timestamp` column. Takes one
     * binding: the IANA zone name.
     */
    public static function weekKeySql(string $column): string
    {
        return "date_trunc('week', ({$column} AT TIME ZONE 'UTC') AT TIME ZONE ?)::date";
    }

    private static function timestamp(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s.v');
    }
}
