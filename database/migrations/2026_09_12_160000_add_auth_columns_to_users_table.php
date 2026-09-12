<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Support\DisplayName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The authentication columns the product actually needs.
 *
 * Additive rather than a rewrite of the bootstrap users migration: the local and
 * CI databases already carry the M1 schema, and a migration that only runs on a
 * fresh database is a migration nobody has tested.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The framework's generic `name` becomes the product's display name —
            // user-supplied content shown to every other player on the
            // leaderboard, which is a very different thing from a person's name.
            $table->renameColumn('name', 'display_name');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Case-insensitive uniqueness, computed by the application rather
            // than by a functional index over lower(display_name): PostgreSQL's
            // lower() is locale-dependent, and Turkish dotted/dotless I is
            // exactly where that bites. One normalizer, in PHP, that the test
            // suite can reason about. See App\Support\DisplayName.
            $table->string('display_name_normalized')->nullable()->after('display_name');

            // Rate-limiting a display-name change needs to survive a cache
            // flush, so the timestamp lives in the row, not in a limiter bucket.
            $table->timestamp('display_name_changed_at')->nullable()->after('display_name_normalized');

            $table->string('role', 20)->default(UserRole::Player->value)->after('password');

            // Encrypted at rest via the model cast, never hashed: TOTP needs the
            // secret back to compute the expected code.
            $table->text('two_factor_secret')->nullable()->after('role');

            // Enrolment is not complete until possession is proven. A secret
            // without this timestamp means "setup started", not "2FA on".
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');

            // Replay defence: the highest TOTP time step already accepted for
            // this user. A code is valid for a whole 30-second window, so
            // without this an intercepted code can be reused inside it.
            $table->unsignedBigInteger('two_factor_last_used_timestep')->nullable()->after('two_factor_confirmed_at');
        });

        // Backfill before the unique index, so an existing row cannot break it.
        foreach (DB::table('users')->select('id', 'display_name')->cursor() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'display_name_normalized' => DisplayName::normalize((string) $user->display_name),
            ]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('display_name_normalized');
            $table->index('role');
        });

        // Two roles, enforced by the database as well as by the PHP enum. A
        // role column is a privilege column: a stray UPDATE that writes
        // 'administrator' or '' must fail loudly rather than produce an account
        // whose privileges depend on how some other code happens to compare it.
        $allowed = collect(UserRole::cases())
            ->map(fn (UserRole $role): string => "'".$role->value."'")
            ->implode(', ');

        // Dropped first, so a re-run after a partial failure adds the constraint
        // rather than dying on a duplicate name. `up()` should be as re-runnable
        // as `down()` already is.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ({$allowed}))");
    }

    /**
     * Structurally reversible, and destructive. Both are worth stating.
     *
     * Rolling back drops `role`, `two_factor_secret`, `two_factor_confirmed_at`
     * and `display_name_changed_at`. Re-running `up()` restores the columns but
     * not their contents, and `role` comes back at its default — so a
     * rollback-then-migrate cycle on a populated database **demotes every
     * administrator to a player, silently**, and turns off every second factor.
     *
     * That is acceptable in development, where the database is disposable. On
     * anything with real accounts, take a dump first and restore these four
     * columns from it; there is no way for this migration to preserve data it
     * has been asked to drop.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['display_name_normalized']);
            $table->dropIndex(['role']);
            $table->dropColumn([
                'display_name_normalized',
                'display_name_changed_at',
                'role',
                'two_factor_secret',
                'two_factor_confirmed_at',
                'two_factor_last_used_timestep',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('display_name', 'name');
        });
    }
};
