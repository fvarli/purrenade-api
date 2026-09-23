<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable progression, one row per player — and the new home of tutorial
 * completion.
 *
 * ## What is here, and what deliberately is not
 *
 * Only the `DERIVED_PERSISTENT` facts M9 can establish: the two paw counters,
 * `best_score` and the accepted `run_count`, each written solely by an
 * ACCEPTED run. Plus `tutorial_completed_at`, which moves here from `users`.
 *
 * **Not here:** the four `lifetime_*` `DERIVED_TELEMETRY` counters (near
 * misses, lane-blocking passes, SLAYYY activations, actual Loli activations).
 * Nothing in v1 can establish the per-run facts they would accumulate, and the
 * APPROVED authority rule forbids adopting the client's count — ANTI-6, which
 * blocks M11. Nor is there any queued or owed Loli Bonus field: the bonus is a
 * run-scoped reward, never a bankable entitlement.
 *
 * ## The tutorial relocation — expand, backfill, verify
 *
 * This is the **expand** half of the two-deployment move recorded in
 * `docs/architecture/data-model.md` §4.1. The table is created, every player's
 * existing `users.tutorial_completed_at` is copied across exactly, and the copy
 * is **asserted inside this migration** before it commits: if a single row is
 * missing or differs, the migration throws and its transaction rolls back, so
 * a deploy can never proceed on a partial backfill. That turns the production
 * backfill check into a gate the established deploy already runs, with no
 * manual step.
 *
 * `users.tutorial_completed_at` is **not dropped here**, and must not be until a
 * later, separate deployment has verified this one in production. Until then
 * the application writes both columns and reads either, so rolling the code
 * back to the pre-M9 release is safe at any point.
 *
 * `tutorial_completed_at` is `timestamp(0)`, the same type as the column it is
 * copied from, so the copy is exact rather than merely close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_progression', function (Blueprint $table): void {
            // One row per player, keyed by the player. Cascade, not restrict:
            // progression has no meaning without its owner, and it is fully
            // derived — from accepted runs and the tutorial — so nothing is lost
            // that the rest of the schema does not still record.
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();

            // bigint: a lifetime statistic that is never consumed only grows.
            $table->bigInteger('lifetime_paws')->default(0);
            $table->smallInteger('loli_cycle_paws')->default(0);
            $table->integer('best_score')->default(0);
            $table->integer('run_count')->default(0);

            $table->timestamp('tutorial_completed_at')->nullable();
            $table->timestamps();
        });

        // The threshold is APPROVED at 200 (`paw.loliThreshold`), so the cycle
        // can only ever hold 0..199 — overflow is carried, never stored above.
        DB::statement('ALTER TABLE player_progression ADD CONSTRAINT player_progression_values_check CHECK (
            lifetime_paws >= 0
            AND loli_cycle_paws BETWEEN 0 AND 199
            AND best_score >= 0
            AND run_count >= 0
        )');

        $this->backfill();
        $this->assertBackfill();
    }

    public function down(): void
    {
        // Safe to drop: `users.tutorial_completed_at` is still dual-written, so
        // the fact this table relocated still exists where it came from.
        Schema::dropIfExists('player_progression');
    }

    /**
     * Copy every player's completion across, exactly.
     *
     * One set-based statement, idempotent through `ON CONFLICT`, so a re-run
     * after a partial failure completes the copy rather than duplicating it.
     */
    public function backfill(): void
    {
        DB::statement('
            INSERT INTO player_progression (user_id, tutorial_completed_at, created_at, updated_at)
            SELECT id, tutorial_completed_at, now(), now() FROM users
            ON CONFLICT (user_id) DO NOTHING
        ');
    }

    /**
     * Refuse to commit unless every player has a row carrying exactly their
     * existing completion timestamp.
     *
     * `IS DISTINCT FROM` rather than `<>`, so null-versus-timestamp counts as a
     * difference — the case `<>` would silently call equal.
     */
    public function assertBackfill(): void
    {
        $mismatched = (int) DB::scalar('
            SELECT count(*) FROM users u
            LEFT JOIN player_progression p ON p.user_id = u.id
            WHERE p.user_id IS NULL
               OR p.tutorial_completed_at IS DISTINCT FROM u.tutorial_completed_at
        ');

        if ($mismatched !== 0) {
            throw new RuntimeException(
                "player_progression backfill mismatch: {$mismatched} player(s) missing or differing. Refusing to continue."
            );
        }
    }
};
