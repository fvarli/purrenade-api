<?php

declare(strict_types=1);

use App\Enums\RunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative run history (ADR-0006, Layers 1 and 2).
 *
 * A run is created by the server **before** gameplay begins — with a
 * server-recorded `started_at` and a server-issued `seed` — and finalised once,
 * by the server, into one of three outcomes. The constraints below are the
 * database's half of that lifecycle; the application's half is
 * `App\Services\Runs\RunLifecycleService`.
 *
 * ## The invariants the database owns
 *
 * - **At most one active run per user (GR-4)** — a partial unique index, not a
 *   read-then-check query. Start inserts with `ON CONFLICT … WHERE status =
 *   'active' DO NOTHING`, which infers exactly this index as its arbiter.
 * - **One finish identity per user and key (GR-3)** — `UNIQUE (user_id,
 *   idempotency_key)`. There is no expiry and no separate `idempotency_keys`
 *   table: the identity lives with the run it finalised, for as long as the run
 *   does. NULLs are distinct, so active runs and stale-replaced runs (neither
 *   has a key) coexist freely.
 * - **Shape by status** — an active run has no outcome fields; an accepted or
 *   flagged run has all of them; a rejected run may lack the claimed values.
 *
 * ## What is deliberately absent
 *
 * No `run_events` table and no per-event history (ANTI-5, data minimisation).
 * No `run_token`. No column for near misses, obstacle passes, SLAYYY activations
 * or Loli activations — those four facts cannot be established in v1 (ANTI-6).
 * No leaderboard or history indexes: those follow their queries at M10.
 *
 * ## Column notes
 *
 * - `character_id` is the **internal** bigint of `characters`; the API speaks
 *   `characters.key`.
 * - `seed` is a uint32 (`0..4294967295`), so it needs `bigint` — PostgreSQL's
 *   `integer` is signed 32-bit and would overflow at half the range.
 * - `finished_at` is the server's **finalisation** time, not the moment play
 *   ended: a started run may finish offline and submit later.
 * - `duration_ms`, `score` and `run_paws` are the **claimed** values, kept once
 *   they pass structural validation. They are not promoted to server facts.
 * - `result` is the response a finish produced, stored so a replay with the
 *   same key returns the same outcome without recomputing it (GR-3).
 * - `user_id` restricts deletion: account deletion is OPEN (DM-4), and run
 *   history must never disappear as a side effect of some other change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('character_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->bigInteger('seed');
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('score')->nullable();
            $table->integer('run_paws')->nullable();
            $table->jsonb('validation_meta')->nullable();
            $table->jsonb('result')->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->char('idempotency_fingerprint', 64)->nullable();
            $table->timestamps(3);

            $table->unique(['user_id', 'idempotency_key'], 'runs_user_idempotency_key_unique');
        });

        $statuses = collect(RunStatus::cases())
            ->map(fn (RunStatus $status): string => "'".$status->value."'")
            ->implode(', ');

        DB::statement("ALTER TABLE runs ADD CONSTRAINT runs_status_check CHECK (status IN ({$statuses}))");

        DB::statement('ALTER TABLE runs ADD CONSTRAINT runs_seed_check CHECK (seed BETWEEN 0 AND 4294967295)');

        // Active exactly when unfinished — and an active run carries no outcome.
        DB::statement("ALTER TABLE runs ADD CONSTRAINT runs_active_shape_check CHECK (
            ((status = 'active') = (finished_at IS NULL))
            AND (status <> 'active' OR (
                score IS NULL AND run_paws IS NULL AND duration_ms IS NULL
                AND idempotency_key IS NULL AND result IS NULL
            ))
        )");

        DB::statement('ALTER TABLE runs ADD CONSTRAINT runs_finished_order_check CHECK (
            finished_at IS NULL OR finished_at >= started_at
        )');

        DB::statement('ALTER TABLE runs ADD CONSTRAINT runs_values_check CHECK (
            (score IS NULL OR score >= 0)
            AND (run_paws IS NULL OR run_paws >= 0)
            AND (duration_ms IS NULL OR duration_ms > 0)
        )');

        DB::statement('ALTER TABLE runs ADD CONSTRAINT runs_idem_pair_check CHECK (
            (idempotency_key IS NULL) = (idempotency_fingerprint IS NULL)
        )');

        // A classified, counted outcome is complete. A rejection may lack the
        // claimed values (they were out of domain) — but never its identity.
        DB::statement("ALTER TABLE runs ADD CONSTRAINT runs_counted_shape_check CHECK (
            status NOT IN ('accepted', 'flagged') OR (
                score IS NOT NULL AND run_paws IS NOT NULL AND duration_ms IS NOT NULL
                AND idempotency_key IS NOT NULL AND result IS NOT NULL
            )
        )");

        // GR-4. The predicate is written exactly as the start insert's
        // `ON CONFLICT (user_id) WHERE status = 'active'` so PostgreSQL infers
        // this index as the arbiter.
        DB::statement("CREATE UNIQUE INDEX runs_one_active_per_user ON runs (user_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
