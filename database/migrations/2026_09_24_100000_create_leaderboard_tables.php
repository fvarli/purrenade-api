<?php

declare(strict_types=1);

use App\Services\Leaderboards\LeaderboardProjector;
use App\Services\Leaderboards\LeaderboardWeek;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leaderboard projection (M10, release A): maintained PostgreSQL ranking
 * tables (DM-2, CACHE-3), written inline by the accepted finish (BA-3).
 *
 * ## What a row is
 *
 * One row per player (all-time) or per player and Istanbul week (weekly),
 * **represented by one accepted run** — the player's first run in ORDER for
 * that window:
 *
 *     score DESC, achieved_at ASC, duration_ms ASC, run_id ASC    (E1)
 *
 * `achieved_at` is the representative run's `finished_at` (D2). `week_start`
 * is the local Monday of its `started_at` in Europe/Istanbul (LB-1).
 *
 * ## What a row deliberately is not
 *
 * No display name or any other public identity is copied here: names are
 * joined live from `users`, so a rename or a future anonymisation is never
 * served stale from a projection (D1). The tables are **derived** and can be
 * rebuilt from `runs` at any time.
 *
 * ## Constraints
 *
 * - `run_id` is `UNIQUE` in each table. A run belongs to one player and one
 *   week, so it can represent at most one row per table — which makes the
 *   ORDER tuple unique per row, and ORDER a total order the database enforces.
 * - Both foreign keys are `ON DELETE RESTRICT` (E2), matching the history
 *   tables `runs` and `paw_ledger`. Every row implies an accepted run whose
 *   own `user_id` is already RESTRICT, so this adds no new operational
 *   constraint; it makes account deletion (SEC-3) handle the projection
 *   explicitly. It does **not** decide LB-5.
 * - The `*_order` indexes are ORDER itself, serving the page, the keyset
 *   predicate and the rank counts as index range scans.
 *
 * ## Backfill
 *
 * Every accepted run already in `runs` is merged in by
 * {@see LeaderboardProjector::mergeAcceptedRuns()} — the same monotone upsert
 * a live finish uses. It is idempotent; the release-B reconciliation re-runs
 * it and asserts the result.
 *
 * `down()` drops both tables. They are derived, and `down()` is for local and
 * CI use only: production never rolls a migration back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_all_time', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignUuid('run_id')->unique()->constrained('runs')->restrictOnDelete();
            $table->integer('score');
            $table->timestamp('achieved_at', 3);
            $table->integer('duration_ms');
            $table->timestamps(3);
        });

        Schema::create('leaderboard_weekly', function (Blueprint $table): void {
            $table->date('week_start');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('run_id')->unique()->constrained('runs')->restrictOnDelete();
            $table->integer('score');
            $table->timestamp('achieved_at', 3);
            $table->integer('duration_ms');
            $table->timestamps(3);

            $table->primary(['week_start', 'user_id']);
        });

        DB::statement('ALTER TABLE leaderboard_all_time ADD CONSTRAINT leaderboard_all_time_values_check CHECK (
            score >= 0 AND duration_ms > 0
        )');

        DB::statement('ALTER TABLE leaderboard_weekly ADD CONSTRAINT leaderboard_weekly_values_check CHECK (
            score >= 0 AND duration_ms > 0
        )');

        // A week key is always a Monday.
        DB::statement('ALTER TABLE leaderboard_weekly ADD CONSTRAINT leaderboard_weekly_monday_check CHECK (
            EXTRACT(ISODOW FROM week_start) = 1
        )');

        DB::statement('CREATE INDEX leaderboard_all_time_order
            ON leaderboard_all_time (score DESC, achieved_at, duration_ms, run_id)');

        DB::statement('CREATE INDEX leaderboard_weekly_order
            ON leaderboard_weekly (week_start, score DESC, achieved_at, duration_ms, run_id)');

        (new LeaderboardProjector(LeaderboardWeek::fromConfig()))->mergeAcceptedRuns();
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_weekly');
        Schema::dropIfExists('leaderboard_all_time');
    }
};
