<?php

declare(strict_types=1);

use App\Enums\RunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative Loli evidence, one row per accepted run (ANTI-6 P1, Option B).
 *
 * `loli_activations = floor((runs.start_loli_cycle_paws + run_paws) / 200)`,
 * derived once, in the acceptance transaction, and frozen. It equals the
 * activations the run actually had because the web domain's CI proves the Loli
 * queue can never be left non-empty under the current tuning (I-LOLI);
 * `evidence_version = 1` names exactly that derivation.
 *
 * ## ABSENT is a missing row — never NULL, never zero
 *
 * - An accepted run with a recorded start cycle gets a row, **including zero
 *   paws**: that is PRESENT 0, not ABSENT.
 * - A run that was flagged or rejected gets no row.
 * - An accepted run whose start cycle was never recorded (started before
 *   `runs.start_loli_cycle_paws` existed) gets no row. Nothing is backfilled or
 *   reconstructed for it, from `lifetime_paws`, progression or anything else.
 *
 * ## The invariants the database owns
 *
 * - **E1** — a row may be inserted only for a run whose status is `accepted`
 *   at that moment: a `BEFORE INSERT` trigger reads the run, `FOR SHARE`, so a
 *   concurrent status change waits for it rather than racing it. The
 *   application writes the row after the finish has set the status, while it
 *   still holds the run lock.
 * - **E2** — rows are never updated or deleted: a `BEFORE UPDATE OR DELETE`
 *   trigger refuses both. A future deletion/anonymisation path (SEC-3) needs a
 *   deliberate, audited relaxation of this trigger; it is not provided here.
 * - **E3** — at most one row per run: `run_id` is the primary key, so no
 *   retry, replay or race can establish the fact twice.
 *
 * `run_id` restricts deletion, as every table referencing `runs` does. There
 * is no `user_id`: per-player reads go through `runs` (E5).
 *
 * The trigger functions are `CREATE OR REPLACE` because `migrate:fresh` drops
 * tables, not functions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_loli_evidence', function (Blueprint $table): void {
            $table->foreignUuid('run_id')->primary()->constrained('runs')->restrictOnDelete();
            $table->integer('loli_activations');
            $table->smallInteger('evidence_version');
            $table->timestamp('established_at', 3);
        });

        DB::statement('ALTER TABLE run_loli_evidence ADD CONSTRAINT run_loli_evidence_values_check CHECK (
            loli_activations >= 0
            AND evidence_version >= 1
        )');

        $accepted = RunStatus::Accepted->value;

        // E1.
        DB::unprepared("
            CREATE OR REPLACE FUNCTION run_loli_evidence_require_accepted() RETURNS trigger AS \$\$
            BEGIN
                PERFORM 1 FROM runs WHERE id = NEW.run_id AND status = '{$accepted}' FOR SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'run_loli_evidence may only be established for an accepted run'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER run_loli_evidence_require_accepted BEFORE INSERT ON run_loli_evidence
                FOR EACH ROW EXECUTE FUNCTION run_loli_evidence_require_accepted();
        ");

        // E2.
        DB::unprepared("
            CREATE OR REPLACE FUNCTION run_loli_evidence_refuse_change() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'run_loli_evidence is insert-only: % refused', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER run_loli_evidence_refuse_change BEFORE UPDATE OR DELETE ON run_loli_evidence
                FOR EACH ROW EXECUTE FUNCTION run_loli_evidence_refuse_change();
        ");

        DB::statement("COMMENT ON TABLE run_loli_evidence IS
            'Authoritative ANTI-6 Loli evidence, insert-only, one row per accepted run. A missing row is ABSENT; 0 is PRESENT. Never derive or backfill from lifetime_paws, progression or paw_ledger.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('run_loli_evidence');

        DB::unprepared('
            DROP FUNCTION IF EXISTS run_loli_evidence_refuse_change();
            DROP FUNCTION IF EXISTS run_loli_evidence_require_accepted();
        ');
    }
};
