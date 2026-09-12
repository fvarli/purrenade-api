<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One live two-factor challenge per account, enforced by the database.
 *
 * `TwoFactorChallengeService::start()` always intended this — a second live
 * challenge hands an attacker who already has the password a second five-attempt
 * budget, running alongside the victim's own login. It expressed the intent as
 * delete-then-insert, which under READ COMMITTED is not an enforcement: two
 * concurrent logins both delete the row the other has not yet written and both
 * insert, and the result is exactly the state the code says cannot exist.
 *
 * `email_verification_codes` already carries this index for the same reason.
 * The omission here was an inconsistency, not a decision.
 *
 * Any pre-existing duplicates are cleared before the index is added: the rows
 * are short-lived (five minutes) and cost nothing to discard, whereas a
 * migration that fails on live data is an outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM two_factor_challenges
            WHERE id NOT IN (
                SELECT MAX(id) FROM two_factor_challenges GROUP BY user_id
            )
        SQL);

        Schema::table('two_factor_challenges', function (Blueprint $table): void {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('two_factor_challenges', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
        });
    }
};
