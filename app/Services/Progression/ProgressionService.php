<?php

declare(strict_types=1);

namespace App\Services\Progression;

use App\Models\PlayerProgression;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the `player_progression` row: its existence, and tutorial completion.
 *
 * The run-derived counters are **not** written here. They change only inside
 * the finish transaction of an accepted run, where they must share a lock order
 * with the run row — see `App\Services\Runs\RunLifecycleService`.
 */
final class ProgressionService
{
    /**
     * Make sure the player has a progression row. Idempotent and race-safe.
     *
     * One `INSERT … ON CONFLICT DO NOTHING`, so two concurrent callers both
     * succeed and exactly one row results. Called **before** any run transaction
     * opens (lock order C-1): as its own statement it commits at once and holds
     * nothing afterwards, so a later `SELECT … FOR UPDATE` on the row always
     * finds it, and no run transaction ever waits on a progression insert while
     * holding a run lock.
     *
     * Existing players were backfilled by the M9 migration; this covers anyone
     * created since, including by the previous release in the window between
     * migration and reload.
     *
     * **An existing row is detected with a plain read first.** The insert's
     * unique check would otherwise *wait* on any concurrent transaction holding
     * an uncommitted update to the row — a finish in flight — even though the
     * row plainly exists. That wait holds no lock and cannot deadlock, but it
     * would serialise every start and finish behind any in-flight finish for the
     * same player. The read never blocks, and rows are never deleted except with
     * their player, so "it exists" cannot become false before it is used.
     */
    public function ensure(int $userId): void
    {
        if (DB::table('player_progression')->where('user_id', $userId)->exists()) {
            return;
        }

        $now = Carbon::now();

        DB::statement(
            'INSERT INTO player_progression (user_id, created_at, updated_at) VALUES (?, ?, ?) ON CONFLICT (user_id) DO NOTHING',
            [$userId, $now, $now],
        );
    }

    /**
     * Record tutorial completion — once, and in both columns.
     *
     * Completion is a one-time fact: each write is a conditional `UPDATE …
     * WHERE tutorial_completed_at IS NULL`, so a replay never re-stamps it.
     *
     * **Dual-write** during the expand/contract relocation: the new column on
     * `player_progression`, then the legacy mirror on `users`, with the same
     * instant. Rolling the code back to the pre-M9 release stays safe for as long
     * as the legacy column is written. The order is PROGRESSION → users and no
     * run is touched, so this cannot participate in the run lock order.
     */
    public function completeTutorial(User $user): void
    {
        $this->ensure($user->id);

        $now = Carbon::now();

        DB::transaction(function () use ($user, $now): void {
            PlayerProgression::query()
                ->whereKey($user->id)
                ->whereNull('tutorial_completed_at')
                ->update(['tutorial_completed_at' => $now]);

            $user->newQuery()
                ->whereKey($user->getKey())
                ->whereNull('tutorial_completed_at')
                ->update(['tutorial_completed_at' => $now]);
        });
    }

    /**
     * The player's progression as it stands, without writing anything.
     *
     * A missing row reads as the zero state rather than being created: reads
     * never write.
     */
    public function current(User $user): PlayerProgression
    {
        $progression = PlayerProgression::query()->find($user->id);

        if ($progression instanceof PlayerProgression) {
            return $progression;
        }

        $empty = new PlayerProgression;
        $empty->forceFill([
            'user_id' => $user->id,
            'lifetime_paws' => 0,
            'loli_cycle_paws' => 0,
            'best_score' => 0,
            'run_count' => 0,
            'tutorial_completed_at' => null,
        ]);

        return $empty;
    }
}
