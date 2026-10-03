<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative replay evidence, one row per accepted run whose canonical
 * input replayed to exactly the accepted record (ANTI-6 P3, Option D).
 *
 * The three facts are established together or not at all (mismatch granularity
 * = the whole triple): a stream that does not reproduce the accepted score,
 * paws and duration is not trustworthy for any fact derived from it. Loli is
 * not here; it is `run_loli_evidence`, independent of replay.
 *
 * ## Provenance
 *
 * - The mechanism is the table itself.
 * - `evidence_version` names the fact **definitions**: 1 = the P2 counters
 *   (cone = `lane_blocking` only, a near-miss pass counts as a safe pass — O4,
 *   O5; the three near-miss cases — O6).
 * - `domain_version` is the pinned domain build that reproduced the run.
 *
 * ## ABSENT is a missing row
 *
 * No input, a failed replay, an inconsistency, an expired input and a
 * non-accepted run all leave no row. 0 is PRESENT 0.
 *
 * ## The invariants the database owns (as `run_loli_evidence`)
 *
 * - **E1** — insert only for a run that is `accepted` at that moment.
 * - **E2** — never updated or deleted.
 * - **E3** — at most one row per run (primary key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_replay_evidence', function (Blueprint $table): void {
            $table->foreignUuid('run_id')->primary()->constrained('runs')->restrictOnDelete();
            $table->integer('cone_safe_passes');
            $table->integer('near_misses');
            $table->integer('slayyy_activations');
            $table->smallInteger('evidence_version');
            $table->string('domain_version', 32);
            $table->timestamp('established_at', 3);
        });

        DB::statement("ALTER TABLE run_replay_evidence ADD CONSTRAINT run_replay_evidence_values_check CHECK (
            cone_safe_passes >= 0
            AND near_misses >= 0
            AND slayyy_activations >= 0
            AND evidence_version >= 1
            AND domain_version ~ '^[0-9]{1,8}$'
        )");

        // E1.
        DB::unprepared("
            CREATE OR REPLACE FUNCTION run_replay_evidence_require_accepted() RETURNS trigger AS \$\$
            BEGIN
                PERFORM 1 FROM runs WHERE id = NEW.run_id AND status = 'accepted' FOR SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'run_replay_evidence may only be established for an accepted run'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER run_replay_evidence_require_accepted BEFORE INSERT ON run_replay_evidence
                FOR EACH ROW EXECUTE FUNCTION run_replay_evidence_require_accepted();
        ");

        // E2.
        DB::unprepared("
            CREATE OR REPLACE FUNCTION run_replay_evidence_refuse_change() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'run_replay_evidence is insert-only: % refused', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER run_replay_evidence_refuse_change BEFORE UPDATE OR DELETE ON run_replay_evidence
                FOR EACH ROW EXECUTE FUNCTION run_replay_evidence_refuse_change();
        ");

        DB::statement("COMMENT ON TABLE run_replay_evidence IS
            'Authoritative ANTI-6 replay evidence, insert-only, one row per accepted run whose input replayed consistently. A missing row is ABSENT; 0 is PRESENT. Never derived from run_replay_inputs, logs or client counts.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('run_replay_evidence');

        DB::unprepared('
            DROP FUNCTION IF EXISTS run_replay_evidence_refuse_change();
            DROP FUNCTION IF EXISTS run_replay_evidence_require_accepted();
        ');
    }
};
