<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email verification challenges — a 6-digit code, not a magic link.
 *
 * A separate table rather than columns on `users`, because a challenge has its
 * own lifecycle (issued, attempted, expired, consumed, replaced) and because a
 * consumed challenge should leave the user row untouched.
 *
 * Six digits is a keyspace of one million. Its security therefore comes entirely
 * from the TTL, the attempt limit and the rate limits — which is why all three
 * are columns or constraints here rather than conventions elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A slow, salted hash (argon2id via the application hasher), not an
            // HMAC. A six-digit code has so little entropy that a fast hash of
            // it is reversible by exhaustive search in microseconds, so the cost
            // of the hash is the only thing protecting a leaked table. Lookup is
            // by user_id, so no indexable deterministic hash is needed.
            $table->string('code_hash');

            // The address the code was issued for. A player who changes their
            // email mid-flow must not be verified by a code sent to the old one.
            $table->string('email');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            // At most one live challenge per user: issuing a new code deletes
            // the previous row, so the old code stops working immediately.
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
