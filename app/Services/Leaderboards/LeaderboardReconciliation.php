<?php

declare(strict_types=1);

namespace App\Services\Leaderboards;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Proves the projection equals what `runs` says it must be — fail closed.
 *
 * Used by the release-B migration after it re-merges accepted runs: a deploy
 * whose projection disagrees with the authoritative history stops at its
 * MIGRATION step, before the read endpoint goes live, exactly as the M9
 * progression backfill does. Every check is recomputed from `runs` with the
 * same ORDER and the same IANA week key, independently of how the rows were
 * written.
 */
final class LeaderboardReconciliation
{
    public function __construct(private readonly LeaderboardWeek $week) {}

    /**
     * Throw unless every check passes.
     */
    public function assertConsistent(): void
    {
        $violations = array_filter($this->violations());

        if ($violations !== []) {
            $summary = implode(', ', array_map(
                fn (string $check, int $count): string => "{$check}={$count}",
                array_keys($violations),
                $violations,
            ));

            throw new RuntimeException("Leaderboard projection disagrees with runs ({$summary}). Refusing to continue.");
        }
    }

    /**
     * Each check and how many rows fail it; all zero when consistent.
     *
     * @return array<string, int>
     */
    public function violations(): array
    {
        $zone = $this->week->timezone();

        return [
            // The all-time player set is exactly the players with an accepted run.
            'all_time_missing_player' => $this->count(
                "SELECT count(*) FROM (SELECT DISTINCT user_id FROM runs WHERE status = 'accepted') r
                 WHERE NOT EXISTS (SELECT 1 FROM leaderboard_all_time l WHERE l.user_id = r.user_id)",
            ),
            'all_time_extra_player' => $this->count(
                "SELECT count(*) FROM leaderboard_all_time l
                 WHERE NOT EXISTS (SELECT 1 FROM runs r WHERE r.user_id = l.user_id AND r.status = 'accepted')",
            ),

            // The all-time score is the best accepted score, and so is the
            // progression best.
            'all_time_score_not_best' => $this->count(
                "SELECT count(*) FROM leaderboard_all_time l
                 JOIN (SELECT user_id, max(score) AS best FROM runs WHERE status = 'accepted' GROUP BY user_id) b
                   ON b.user_id = l.user_id
                 LEFT JOIN player_progression p ON p.user_id = l.user_id
                 WHERE l.score <> b.best OR p.best_score IS DISTINCT FROM b.best",
            ),

            // Each all-time row is represented by the ORDER-first accepted run.
            'all_time_wrong_representative' => $this->count(
                "SELECT count(*) FROM (
                     SELECT DISTINCT ON (user_id) user_id, id
                     FROM runs WHERE status = 'accepted'
                     ORDER BY user_id, score DESC, finished_at ASC, duration_ms ASC, id ASC
                 ) e
                 FULL JOIN leaderboard_all_time l ON l.user_id = e.user_id
                 WHERE l.run_id IS DISTINCT FROM e.id",
            ),

            // The weekly (week, player) set and representatives, per Istanbul
            // start-week.
            'weekly_wrong_representative' => $this->count(
                'SELECT count(*) FROM (
                     SELECT DISTINCT ON (w.week_start, w.user_id) w.week_start, w.user_id, w.id
                     FROM (
                         SELECT '.LeaderboardProjector::weekKeySql('started_at')." AS week_start, user_id, id,
                                score, finished_at, duration_ms
                         FROM runs WHERE status = 'accepted'
                     ) w
                     ORDER BY w.week_start, w.user_id, w.score DESC, w.finished_at ASC, w.duration_ms ASC, w.id ASC
                 ) e
                 FULL JOIN leaderboard_weekly l ON l.week_start = e.week_start AND l.user_id = e.user_id
                 WHERE l.run_id IS DISTINCT FROM e.id",
                [$zone],
            ),

            // No row references a run that is not accepted, or carries values
            // other than its run's.
            'all_time_row_not_its_run' => $this->count(
                "SELECT count(*) FROM leaderboard_all_time l JOIN runs r ON r.id = l.run_id
                 WHERE r.status <> 'accepted' OR r.user_id <> l.user_id OR r.score <> l.score
                    OR r.finished_at <> l.achieved_at OR r.duration_ms <> l.duration_ms",
            ),
            'weekly_row_not_its_run' => $this->count(
                "SELECT count(*) FROM leaderboard_weekly l JOIN runs r ON r.id = l.run_id
                 WHERE r.status <> 'accepted' OR r.user_id <> l.user_id OR r.score <> l.score
                    OR r.finished_at <> l.achieved_at OR r.duration_ms <> l.duration_ms
                    OR ".LeaderboardProjector::weekKeySql('r.started_at').' <> l.week_start',
                [$zone],
            ),
        ];
    }

    /**
     * @param  list<string>  $bindings
     */
    private function count(string $sql, array $bindings = []): int
    {
        return (int) DB::scalar($sql, $bindings);
    }
}
