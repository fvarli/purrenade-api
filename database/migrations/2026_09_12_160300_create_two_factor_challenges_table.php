<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gap between "password accepted" and "session issued".
 *
 * Fortify parks this state in the HTTP session (`login.id`). This API is
 * stateless and token-authenticated — a requirement, not an accident: ADR-0005
 * keeps the API usable by a future native client, which has no session cookie.
 * So the pending state becomes a short-lived server-side record identified by an
 * opaque token instead.
 *
 * The token is high-entropy, so a keyed HMAC gives an indexed lookup with no
 * loss of security (same reasoning as recovery codes). It is returned to the
 * caller — the BFF, which holds it in its own server-side session — and never
 * reaches the browser.
 *
 * A row here confers nothing on its own: it proves only that the first factor
 * was satisfied, and it expires in minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_challenges', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();

            // The device label to stamp on the session once the challenge is
            // passed. Captured at login, because that is the request that knows
            // which client is actually signing in.
            $table->string('device_label')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_challenges');
    }
};
