<?php

declare(strict_types=1);

namespace App\Services\Leaderboards;

use App\Enums\LeaderboardWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Reads leaderboard pages from the projection (M10). Never writes, never
 * locks, never sorts `runs`, never caches.
 *
 * ## ORDER and the keyset
 *
 * Rows are served in ORDER — `score DESC, achieved_at ASC, duration_ms ASC,
 * run_id ASC` (E1), the `*_order` index — and a page continues strictly after
 * the cursor's position. No OFFSET: every query is an index range scan whose
 * cost is the page size (the page) or the rank (the counts).
 *
 * ## One snapshot per response
 *
 * The page, the rank of its first row, `has_more` and the player's own entry
 * are read in one `REPEATABLE READ READ ONLY` transaction, so they agree with
 * each other even while other players' finishes commit. **Across requests the
 * board is live**: see the consistency contract in
 * `docs/api/endpoints/leaderboards.md` — no row is served twice within a
 * traversal while invariant M holds, but an entry that moves above the cursor
 * is only seen after a refresh, and rank numbers may skip.
 *
 * ## Ranks
 *
 * Every rank is computed here, per request, as `1 + the number of visible rows
 * that precede it in ORDER`. The own entry is read fresh on every request with
 * its true rank, wherever it falls. Clients never compute a rank.
 *
 * ## Visibility — the single extension point
 *
 * {@see self::visible()} is applied to the page **and** to both rank counts.
 * It admits every row in M10: no ban state (M13) or opt-out setting (LB-8)
 * exists yet, and M10 claims no filtering. Those features add their predicate
 * there, and only there, so a hidden row can never still be counted.
 *
 * At most four statements per request, whatever the page size: the isolation
 * setting, the page (users joined by primary key), the own row by primary
 * key, and one count that yields both the page's first rank and the own rank.
 */
final class LeaderboardReader
{
    private const ORDER_BY = 'l.score DESC, l.achieved_at ASC, l.duration_ms ASC, l.run_id ASC';

    public function __construct(
        private readonly LeaderboardWeek $week,
        private readonly LeaderboardCursor $cursors,
    ) {}

    public function page(User $user, LeaderboardWindow $window, ?LeaderboardPosition $after, int $limit): LeaderboardPage
    {
        // The week comes from the cursor when there is one, so a traversal that
        // began before a Monday rollover keeps paging its own week.
        $weekStart = $window === LeaderboardWindow::Weekly
            ? ($after->weekStart ?? $this->week->weekStartFor(CarbonImmutable::now('UTC')))
            : null;

        return $this->snapshot(fn (): LeaderboardPage => $this->read($user, $window, $weekStart, $after, $limit));
    }

    private function read(User $user, LeaderboardWindow $window, ?string $weekStart, ?LeaderboardPosition $after, int $limit): LeaderboardPage
    {
        $table = $window->table();

        // The page, with one probe row to learn whether another page exists.
        [$scope, $bindings] = $this->scope('l', $weekStart);
        $sql = "SELECT l.user_id, l.score, l.achieved_at, l.duration_ms, l.run_id, u.display_name
                FROM {$table} l JOIN users u ON u.id = l.user_id
                WHERE {$scope} AND ".$this->visible('l');

        if ($after instanceof LeaderboardPosition) {
            // Strictly after the cursor in ORDER. `score <= s` first, so the
            // planner starts the range scan at the cursor's score.
            $sql .= ' AND l.score <= CAST(? AS integer)
                      AND (l.score < CAST(? AS integer)
                           OR (l.achieved_at, l.duration_ms, l.run_id)
                            > (CAST(? AS timestamp(3)), CAST(? AS integer), CAST(? AS uuid)))';
            array_push($bindings, $after->score, $after->score, $after->achievedAt(), $after->durationMs, $after->runId);
        }

        $sql .= ' ORDER BY '.self::ORDER_BY.' LIMIT ?';
        $bindings[] = $limit + 1;

        /** @var list<object{user_id: int, score: int, achieved_at: string, duration_ms: int, run_id: string, display_name: string}> $rows */
        $rows = DB::select($sql, $bindings);

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        // The player's own row, fresh, by primary key.
        $own = $this->ownRow($table, $weekStart, $user);

        // Both ranks — the page's first row (only when paging from a cursor;
        // the first page starts at 1) and the player's own — from one scan.
        [$firstAhead, $ownAhead] = $this->countAhead(
            $table,
            $weekStart,
            $after instanceof LeaderboardPosition && $rows !== [] ? $rows[0] : null,
            $own,
        );

        $entries = [];

        foreach ($rows as $index => $row) {
            $entries[] = new LeaderboardEntry(
                rank: 1 + $firstAhead + $index,
                displayName: (string) $row->display_name,
                score: (int) $row->score,
                isSelf: (int) $row->user_id === $user->id,
            );
        }

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new LeaderboardPage(
            window: $window,
            period: $weekStart === null ? null : $this->week->periodFor($weekStart),
            entries: $entries,
            ownEntry: $own === null ? null : new LeaderboardEntry(
                rank: 1 + $ownAhead,
                displayName: (string) $own->display_name,
                score: (int) $own->score,
                isSelf: true,
            ),
            nextCursor: $hasMore && $last !== null
                ? $this->cursors->encode($this->position($window, $weekStart, $last))
                : null,
            hasMore: $hasMore,
        );
    }

    /**
     * The player's own row in this window, with its public name — or null
     * when they have no accepted run in it.
     *
     * @return object{score: int, achieved_at: string, duration_ms: int, run_id: string, display_name: string}|null
     */
    private function ownRow(string $table, ?string $weekStart, User $user): ?object
    {
        [$scope, $bindings] = $this->scope('l', $weekStart);

        /** @var object{score: int, achieved_at: string, duration_ms: int, run_id: string, display_name: string}|null */
        return DB::selectOne(
            "SELECT l.score, l.achieved_at, l.duration_ms, l.run_id, u.display_name
             FROM {$table} l JOIN users u ON u.id = l.user_id
             WHERE l.user_id = ? AND {$scope}",
            [$user->id, ...$bindings],
        );
    }

    /**
     * How many visible rows precede each of up to two rows in ORDER — their
     * ranks minus one — counted in **one** scan of the window.
     *
     * A rank costs O(rank): every row above it is counted. Counting both in
     * the same pass, from the lower of the two scores, costs the larger of the
     * two ranks rather than their sum.
     *
     * @return array{0: int, 1: int}
     */
    private function countAhead(string $table, ?string $weekStart, ?object $first, ?object $own): array
    {
        if ($first === null && $own === null) {
            return [0, 0];
        }

        $select = [];
        $bindings = [];

        foreach ([$first, $own] as $index => $row) {
            if ($row === null) {
                $select[] = "0 AS ahead_{$index}";

                continue;
            }

            /** @var object{score: int, achieved_at: string, duration_ms: int, run_id: string} $row */
            $select[] = 'count(*) FILTER (WHERE l.score > CAST(? AS integer)
                OR (l.score = CAST(? AS integer)
                    AND (l.achieved_at, l.duration_ms, l.run_id)
                      < (CAST(? AS timestamp(3)), CAST(? AS integer), CAST(? AS uuid)))) AS ahead_'.$index;
            array_push($bindings, $row->score, $row->score, $row->achieved_at, $row->duration_ms, $row->run_id);
        }

        [$scope, $scopeBindings] = $this->scope('l', $weekStart);
        $floor = min(array_map(fn (object $row): int => (int) $row->score, array_filter([$first, $own])));

        /** @var object{ahead_0: int, ahead_1: int} $counts */
        $counts = DB::selectOne(
            'SELECT '.implode(', ', $select)." FROM {$table} l
             WHERE {$scope} AND ".$this->visible('l').' AND l.score >= CAST(? AS integer)',
            [...$bindings, ...$scopeBindings, $floor],
        );

        return [(int) $counts->ahead_0, (int) $counts->ahead_1];
    }

    /**
     * The window's rows: one week for the weekly window, every row for
     * all-time.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function scope(string $alias, ?string $weekStart): array
    {
        return $weekStart === null
            ? ['TRUE', []]
            : ["{$alias}.week_start = CAST(? AS date)", [$weekStart]];
    }

    /**
     * THE visibility predicate — applied to the page and to both rank counts.
     * Admits every row in M10; ban filtering (M13) and opt-out (LB-8) extend
     * it here. It must stay a predicate on the projection row alone (a
     * correlated `NOT EXISTS` is fine), so the counts need no join.
     */
    private function visible(string $alias): string
    {
        return 'TRUE';
    }

    private function position(LeaderboardWindow $window, ?string $weekStart, object $row): LeaderboardPosition
    {
        /** @var object{score: int, achieved_at: string, duration_ms: int, run_id: string} $row */
        return new LeaderboardPosition(
            window: $window,
            weekStart: $weekStart,
            score: (int) $row->score,
            achievedAtMs: CarbonImmutable::parse($row->achieved_at, 'UTC')->getTimestampMs(),
            durationMs: (int) $row->duration_ms,
            runId: (string) $row->run_id,
        );
    }

    /**
     * Run the reads in one `REPEATABLE READ READ ONLY` transaction.
     *
     * The isolation level can only be set as a transaction's first
     * statement. Requests always arrive here outside a transaction; the one
     * exception is the feature-test harness, which wraps every test in its
     * own, and there the enclosing transaction's snapshot applies instead.
     * The concurrency suite, which runs without that wrapper, proves the
     * real behaviour.
     *
     * @template T
     *
     * @param  Closure(): T  $reads
     * @return T
     */
    private function snapshot(Closure $reads): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $reads();
        }

        return DB::transaction(function () use ($reads): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $reads();
        });
    }
}
