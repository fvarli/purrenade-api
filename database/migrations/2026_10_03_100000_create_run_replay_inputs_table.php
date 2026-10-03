<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transient replay work, one row per accepted run whose finish carried a
 * `replay_input` (ANTI-6 P3, architecture §4.4). **Never evidence (W0)**:
 * achievements, progression, leaderboards and any recompute never read it.
 * The evidence is `run_replay_evidence`.
 *
 * ## What a row holds
 *
 * - `pending` — the canonical input, encrypted with the `APP_KEY`-backed
 *   encrypter, waiting for the replay worker. `input_expires_at` is exactly
 *   24 hours after the finish was received (O3): from that instant the input is
 *   unusable, and expired input never establishes evidence.
 * - `terminal` — one closed outcome code, and **no input**. Every terminal
 *   transition clears it in the same statement.
 *
 * ## The size bound is measured, not chosen
 *
 * `input` stores the raw bytes of `Crypt::encryptString()`'s envelope
 * (`base64(json{iv, value, mac, tag})`, decoded once). For AES-CBC its length is
 * a deterministic function of the plaintext length n:
 *
 *     stored(n) = 126 + 4·ceil(16·(floor(n/16) + 1) / 3)
 *
 * The largest canonical input the boundary admits is 142 657 bytes (20 000
 * events; gaps maximise digits subject to Σgap ≤ 432 000: 20 000 two-digit and
 * 2 577 three-digit gaps; an 8-digit `domain_version`), so stored(142 657) =
 * **190 358**, measured with the real encrypter. AES-GCM, the other cipher
 * family Laravel supports, stores less (190 290). The CHECK is that exact
 * measured maximum: the representation has no variable part, so any headroom
 * would only admit bytes the boundary can never produce. Growing it needs a
 * contract change and a migration (`ReplayInputStore::MAX_STORED_BYTES`).
 *
 * ## The invariants the database owns
 *
 * - A row may be inserted only for a run that is `accepted` at that moment
 *   (`BEFORE INSERT`, `FOR SHARE`), as P1's evidence E1.
 * - The shape: `pending` ⇒ input and expiry present, no outcome; `terminal` ⇒
 *   an outcome and **no input**.
 * - A terminal row is final: `BEFORE UPDATE` refuses any change to it, so an
 *   outcome M13 tooling may list can never be rewritten.
 * - Rows are not deleted (SEC-5 is OPEN; M14 relaxes this deliberately).
 *
 * `pg_dump` in the backup helper excludes this table's **data**
 * (`--exclude-table-data='*.run_replay_inputs'`), so a dump never extends the
 * input's lifetime; a restore yields the table empty and pending replays end
 * ABSENT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_replay_inputs', function (Blueprint $table): void {
            $table->foreignUuid('run_id')->primary()->constrained('runs')->restrictOnDelete();
            $table->string('state', 16);
            $table->string('outcome_code', 32)->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->binary('input')->nullable();
            $table->timestamp('input_expires_at', 3)->nullable();
            $table->timestamp('created_at', 3);
            $table->timestamp('updated_at', 3);
        });

        DB::statement("ALTER TABLE run_replay_inputs ADD CONSTRAINT run_replay_inputs_state_check CHECK (
            (state = 'pending' AND outcome_code IS NULL AND input IS NOT NULL AND input_expires_at IS NOT NULL)
            OR (state = 'terminal' AND outcome_code IS NOT NULL AND input IS NULL)
        )");

        DB::statement("ALTER TABLE run_replay_inputs ADD CONSTRAINT run_replay_inputs_outcome_check CHECK (
            outcome_code IS NULL OR outcome_code IN (
                'established', 'input_malformed', 'version_unsupported', 'result_invalid',
                'input_expired', 'run_not_accepted', 'attempts_exhausted', 'replay_inconsistent'
            )
        )");

        DB::statement('ALTER TABLE run_replay_inputs ADD CONSTRAINT run_replay_inputs_attempts_check CHECK (attempts >= 0)');

        DB::statement('ALTER TABLE run_replay_inputs ADD CONSTRAINT run_replay_inputs_input_size_check CHECK (
            input IS NULL OR octet_length(input) BETWEEN 1 AND 190358
        )');

        // The sweeper's and the re-dispatcher's only access path.
        DB::statement("CREATE INDEX run_replay_inputs_pending_idx ON run_replay_inputs (input_expires_at) WHERE state = 'pending'");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION run_replay_inputs_require_accepted() RETURNS trigger AS \$\$
            BEGIN
                PERFORM 1 FROM runs WHERE id = NEW.run_id AND status = 'accepted' FOR SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'run_replay_inputs may only be written for an accepted run'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER run_replay_inputs_require_accepted BEFORE INSERT ON run_replay_inputs
                FOR EACH ROW EXECUTE FUNCTION run_replay_inputs_require_accepted();
        ");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION run_replay_inputs_terminal_is_final() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'run_replay_inputs rows are not deleted'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.state = 'terminal' THEN
                    RAISE EXCEPTION 'a terminal run_replay_inputs row is final'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER run_replay_inputs_terminal_is_final BEFORE UPDATE OR DELETE ON run_replay_inputs
                FOR EACH ROW EXECUTE FUNCTION run_replay_inputs_terminal_is_final();
        ");

        DB::statement("COMMENT ON TABLE run_replay_inputs IS
            'Transient ANTI-6 replay work. NEVER evidence (W0). input is APP_KEY-encrypted, unusable after input_expires_at (24 h), cleared at every terminal outcome, excluded from pg_dump data.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('run_replay_inputs');

        DB::unprepared('
            DROP FUNCTION IF EXISTS run_replay_inputs_terminal_is_final();
            DROP FUNCTION IF EXISTS run_replay_inputs_require_accepted();
        ');
    }
};
