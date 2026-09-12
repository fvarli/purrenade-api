<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * An opaque public identifier for each session.
 *
 * A Sanctum token row *is* a session in this design, so the session-management
 * endpoints address these rows. Their primary key is a sequential integer, and
 * handing sequential integers to clients invites `DELETE /auth/sessions/41`
 * against someone else's session — which the ownership check refuses, but which
 * should not be expressible in the first place. A UUID is unguessable, so the
 * only sessions a client can name are ones it was shown.
 *
 * The `name` column carries the coarse device label. Sanctum already provides it
 * for exactly this purpose, and `created_at` / `last_used_at` already provide
 * the timestamps the session list needs, so no further columns are required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->after('id');
        });

        foreach (DB::table('personal_access_tokens')->select('id')->cursor() as $token) {
            DB::table('personal_access_tokens')
                ->where('id', $token->id)
                ->update(['public_id' => (string) Str::uuid()]);
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
