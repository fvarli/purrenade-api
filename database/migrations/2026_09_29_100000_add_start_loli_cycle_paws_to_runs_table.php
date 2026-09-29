<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Loli cycle a run started from (ANTI-6 P1, F1).
 *
 * Written once, by the start transaction, from the same progression read the
 * start response already returns to the client — so it is the cycle the
 * client's domain actually ran with. It is the only input, beside the accepted
 * `run_paws`, of the acceptance-time Loli evidence (`run_loli_evidence`).
 *
 * ## NULL means ABSENT, and is never filled in
 *
 * Nullable because every run that existed before this column did not record
 * its starting cycle, and nothing can recover it: the progression row has moved
 * on, and neither `lifetime_paws` nor the finish-time cycle is the same fact.
 * There is **no backfill**. A run started before this deploy — even one
 * finished after it — keeps `NULL`, and its Loli evidence stays ABSENT.
 *
 * The CHECK is the cycle's own domain: the threshold is APPROVED at 200
 * (`paw.loliThreshold`), so a cycle holds 0..199, exactly as
 * `player_progression.loli_cycle_paws` does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->smallInteger('start_loli_cycle_paws')->nullable();
        });

        DB::statement('ALTER TABLE runs ADD CONSTRAINT runs_start_loli_cycle_paws_check CHECK (
            start_loli_cycle_paws IS NULL OR start_loli_cycle_paws BETWEEN 0 AND 199
        )');
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('start_loli_cycle_paws');
        });
    }
};
