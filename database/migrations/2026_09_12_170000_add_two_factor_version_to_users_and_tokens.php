<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generation counter for the second factor.
 *
 * The `two-factor` token ability records that *a* challenge was passed. It does
 * not record *which secret* it was passed against, and a Sanctum ability cannot
 * be revoked from a token that already exists. So the two facts diverge the
 * moment the credential changes:
 *
 *   1. attacker holds a 2FA-satisfied session
 *   2. owner notices, disables 2FA and re-enrols with a new secret — the
 *      textbook response to a stolen authenticator
 *   3. the account has confirmed 2FA again, and the attacker's token still
 *      carries `two-factor`, so it is privileged again
 *
 * Every account-level check passes at step 3, because each one is individually
 * true. What is false is the conjunction: that session never met *this* secret.
 *
 * The counter makes the conjunction checkable. `users.two_factor_version`
 * advances on every material change to the second factor; a token records the
 * version it was challenged against, and a mismatch is a session that predates
 * the current credential. Comparing two integers is cheaper and harder to get
 * wrong than reasoning about timestamps, and it needs no clock.
 *
 * Existing rows start at 0, and tokens issued before this migration hold NULL,
 * which never equals 0 — so every session that predates the counter is treated
 * as not satisfied, which is the safe direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('two_factor_version')
                ->default(0)
                ->after('two_factor_last_used_timestep');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // Nullable, not 0: a token with no recorded version was issued
            // before this existed, and must not compare equal to a user sitting
            // at the default.
            $table->unsignedInteger('two_factor_version')
                ->nullable()
                ->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_version');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('two_factor_version');
        });
    }
};
