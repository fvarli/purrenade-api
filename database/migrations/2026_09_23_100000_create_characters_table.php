<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The minimal character catalogue M9 needs to start a run.
 *
 * `runs.character_id` is a foreign key, and `StartRunRequest.character_id` is
 * required, so something has to exist for them to point at. What exists is the
 * smallest honest shape: a public `key`, whether the character is a starter,
 * and whether its artwork ships. The unlock-criterion columns are M11 — the
 * criteria themselves are APPROVED product data, but materialising unlocks is
 * not M9 work, and a column nobody reads is a column somebody fills in wrong.
 *
 * ## Identity
 *
 * **`key` is the public identifier** — the `character_id` string on the wire,
 * stable and lower-case (`aysenur`). **`id` is internal** — the bigint that
 * `runs.character_id` references, never serialised. The two are kept apart so a
 * catalogue row can be referenced cheaply by every run without its database key
 * ever becoming part of the contract.
 *
 * ## Why the rows are written here, not in a seeder
 *
 * A seeder is a development convenience that production never runs. These rows
 * are content the application cannot start a run without, so they ship with
 * the schema that needs them and arrive through the same `migrate --force` the
 * deploy already runs. The four keys are the four APPROVED characters.
 *
 * Only Ayşenur is selectable: she is the starter and her artwork ships. Büşo,
 * Ogito and Sero exist so their keys are reserved and a request naming them is
 * refused as *unavailable* rather than *unknown* — the same answer either way,
 * and neither can start a run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 32)->unique();
            $table->boolean('is_starter')->default(false);
            $table->boolean('artwork_available')->default(false);
            $table->smallInteger('display_order');
            $table->timestamps();
        });

        // The same grammar the start request validates, so a key the API could
        // never accept cannot be stored either.
        DB::statement("ALTER TABLE characters ADD CONSTRAINT characters_key_check CHECK (key ~ '^[a-z0-9_]{1,32}$')");

        $now = now();

        DB::table('characters')->insert([
            ['key' => 'aysenur', 'is_starter' => true, 'artwork_available' => true, 'display_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'buso', 'is_starter' => false, 'artwork_available' => false, 'display_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'ogito', 'is_starter' => false, 'artwork_available' => false, 'display_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'sero', 'is_starter' => false, 'artwork_available' => false, 'display_order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
