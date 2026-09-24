<?php

declare(strict_types=1);

use App\Services\Leaderboards\LeaderboardProjector;
use App\Services\Leaderboards\LeaderboardReconciliation;
use App\Services\Leaderboards\LeaderboardWeek;
use Illuminate\Database\Migrations\Migration;

/**
 * Reconcile the leaderboard projection with `runs` (M10, release B).
 *
 * ## Why this exists — deployment window W2
 *
 * The deploy checks the new code out, migrates, then reloads PHP-FPM. Between
 * release A's migration and that reload, the **previous** release's code can
 * still accept runs — and it does not project them. Release A cannot close
 * that window itself: nothing runs after the reload. By release B's deploy,
 * release A's code is live and projecting every accepted finish, so this merge
 * converges: it picks up whatever W2 missed, through the same idempotent,
 * monotone upsert, and never moves a row later.
 *
 * ## Fail closed
 *
 * After the merge, the projection is **asserted** against `runs`
 * ({@see LeaderboardReconciliation}): the player sets, the best scores (also
 * against `player_progression.best_score`), the ORDER-first representative per
 * player and per Istanbul week, and that no row references a run that is not
 * accepted. Any disagreement throws, the migration's transaction rolls back,
 * and the deploy stops at MIGRATION — before the read endpoint is served.
 *
 * `down()` does nothing: this migration changes no schema, and the merge it
 * performed is correct under either release.
 */
return new class extends Migration
{
    public function up(): void
    {
        $week = LeaderboardWeek::fromConfig();

        (new LeaderboardProjector($week))->mergeAcceptedRuns();
        (new LeaderboardReconciliation($week))->assertConsistent();
    }

    public function down(): void
    {
        // Data-only and idempotent: nothing to undo.
    }
};
