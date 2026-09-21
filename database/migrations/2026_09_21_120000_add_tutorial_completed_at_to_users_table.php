<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the player first finished — or skipped — the tutorial.
 *
 * Null until then, and **never re-stamped**: completion is a one-time fact, so
 * replaying the tutorial from Settings leaves this exactly as it was. The write
 * is a conditional `UPDATE ... WHERE tutorial_completed_at IS NULL` whose
 * affected-row count is the authority, which makes that structural rather than
 * a rule the controller has to remember.
 *
 * ## Why this is on `users` rather than `player_progression`
 *
 * **Progression owns this fact** — `docs/architecture/domain-boundaries.md` §4
 * is unchanged and still authoritative. What this migration chooses is where
 * the column physically lives *until M9*, and it chooses `users` because
 * `player_progression` does not exist: it is PROPOSED in
 * `docs/architecture/data-model.md` §4, and every other column in it
 * (`lifetime_paws`, `best_score`, `run_count`, the telemetry counters) is
 * derived from accepted run submissions, which is M9 scope and blocked on
 * ADR-0006. Creating that table now to hold one unrelated column would be
 * implementing a proposed design early, and would invite the rest of it to be
 * filled in by whoever needed the next field.
 *
 * `tutorial_completed_at` is also the one row in that table with **no
 * verification source** — the data-model table prints `—` for it, while every
 * neighbour is `DERIVED_PERSISTENT` or `DERIVED_TELEMETRY`. It is not derived
 * from a run, it has no concurrency coupling to the paw ledger, and it needs
 * none of the machinery the rest of that table exists for.
 *
 * **This placement is temporary and must not be forgotten.** When M9 creates
 * `player_progression`, the intended migration is
 * `users.tutorial_completed_at` → `player_progression.tutorial_completed_at`,
 * backfilling every existing value so no player is asked to repeat a tutorial
 * they already finished. That is recorded in `docs/architecture/data-model.md`
 * §4 so it survives this comment.
 *
 * The precedent for the shape is `display_name_changed_at`, three columns over:
 * a nullable timestamp on the user's own row, written by naming the column
 * explicitly because `$guarded = ['*']` disables mass assignment outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Nullable with no default: "has not completed it" is the absence of
            // a completion, not a sentinel date. Every existing player starts
            // here, which is correct — nobody has seen a tutorial that until now
            // did not exist.
            $table->timestamp('tutorial_completed_at')
                ->nullable()
                ->after('display_name_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('tutorial_completed_at');
        });
    }
};
