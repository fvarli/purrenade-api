<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor recovery codes — one row per code.
 *
 * Fortify ships recovery codes as an encrypted JSON array in a single users
 * column. That is a clean equivalent for most applications but not for this one:
 * `docs/security/two-factor.md` §4 makes "stored hashed, never plaintext" and
 * "single use, enforced by the database" APPROVED rules, and an encrypted blob
 * is reversible while an array rewrite is enforced only by application code.
 *
 * One row per code satisfies both:
 *
 *   - hashed, not encrypted — nothing can recover the plaintext;
 *   - single use enforced by the database — consumption is a conditional
 *     `UPDATE ... WHERE used_at IS NULL` whose affected-row count is the
 *     authority, so two concurrent requests cannot spend the same code.
 *
 * The hash here is a keyed HMAC-SHA256, deliberately unlike the slow hash used
 * for email verification codes. A recovery code carries ~100 bits of entropy, so
 * exhaustive search is not a threat and a fast deterministic hash is safe — and
 * it buys an indexed single-row lookup, which is what makes the atomic
 * conditional update above possible at all. See App\Support\KeyedHash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_recovery_codes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('code_hash', 64);

            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Lookup key for verification, and a guarantee that one code cannot
            // be issued twice to the same account.
            $table->unique(['user_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_recovery_codes');
    }
};
