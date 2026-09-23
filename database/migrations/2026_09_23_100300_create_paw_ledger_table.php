<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of every paw delta an accepted run applied.
 *
 * Written only for an **accepted** run with `run_paws > 0`, in the same
 * transaction that updates `player_progression`. `run_id` is unique, so a run
 * can never credit the ledger twice however its finish is retried.
 *
 * ## `bonuses_triggered` is accounting, not activation
 *
 * It counts how many 200-paw thresholds this delta crossed:
 * `floor((previous_cycle + delta) / 200)`. It is **not** the number of Loli
 * Bonuses the player saw. Queued bonuses are run-scoped and can expire unstarted
 * when the run ends, so *threshold crossed ≠ Loli activated*. No code, query or
 * report may derive `lifetime_loli_activations`, Sero's unlock or any other
 * ANTI-6 fact from this column. The column comment says so too, for whoever
 * reads the schema without reading this file.
 *
 * The `(user_id, created_at)` index waits until a query needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paw_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('run_id')->unique()->constrained('runs')->restrictOnDelete();
            $table->integer('delta');
            $table->smallInteger('resulting_cycle');
            $table->integer('bonuses_triggered');
            $table->timestamp('created_at', 3);
        });

        DB::statement('ALTER TABLE paw_ledger ADD CONSTRAINT paw_ledger_values_check CHECK (
            delta > 0
            AND resulting_cycle BETWEEN 0 AND 199
            AND bonuses_triggered >= 0
        )');

        DB::statement("COMMENT ON COLUMN paw_ledger.bonuses_triggered IS
            'Number of 200-paw thresholds this delta crossed. Accounting only: NOT Loli Bonus activations (threshold crossed != Loli activated). Never derive lifetime_loli_activations or Sero progress from it (ANTI-6).'");
    }

    public function down(): void
    {
        Schema::dropIfExists('paw_ledger');
    }
};
