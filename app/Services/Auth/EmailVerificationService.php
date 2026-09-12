<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The six-digit email verification challenge.
 *
 * The product requires a code, not Laravel's signed-link flow (v0.3 board 04),
 * but the *semantics* stay Laravel's: success sets `email_verified_at` and fires
 * `Verified`, so `MustVerifyEmail`, the `verified` middleware and anything else
 * built on framework conventions keep working.
 *
 * Six digits is one million possibilities, which is not much. Everything
 * defensive about this flow is therefore in the surrounding rules rather than in
 * the code itself: a short TTL, a per-code attempt cap, a resend cooldown, and
 * rate limits on both submission and resend. Those four are the security of this
 * feature — see docs/security/authentication.md §3.
 */
final readonly class EmailVerificationService
{
    /**
     * How long a code remains usable.
     *
     * Ten minutes: long enough for mail delivery plus a person switching apps,
     * short enough that a code sitting in an unattended inbox is usually dead.
     */
    public const TTL_MINUTES = 10;

    /**
     * Seconds before a new code may be requested.
     *
     * **42 seconds, locked by product decision** — v0.3 board 04 renders the
     * countdown as `(0:42)`. This constant is the single source of that number,
     * and the API returns the remaining seconds so the client renders the same
     * countdown rather than guessing.
     */
    public const RESEND_COOLDOWN_SECONDS = 42;

    public function __construct(private Hasher $hasher) {}

    /**
     * Issue a fresh code, returning the plaintext for delivery.
     *
     * The plaintext exists only in the return value and in the outgoing mail. It
     * is never stored, never logged, and never returned by an HTTP endpoint.
     *
     * Issuing replaces any previous challenge, so the old code stops working the
     * instant a new one is sent. Without that, every resend would widen the
     * attacker's window instead of refreshing it.
     */
    public function issue(User $user): string
    {
        $code = $this->generateCode();

        DB::transaction(function () use ($user, $code): void {
            // Lock the account's existing challenge for the duration. The
            // `unique(user_id)` index already prevents two live rows, but on its
            // own it turns a concurrent resend into a constraint violation and a
            // 500. Serialising here makes the second caller wait and then
            // replace, which is what "issuing replaces any previous challenge"
            // was supposed to mean.
            EmailVerificationCode::query()->where('user_id', $user->id)->lockForUpdate()->get();

            EmailVerificationCode::query()->where('user_id', $user->id)->delete();

            // forceCreate, because the model guards every column. These columns
            // are never populated from request input, so the guard is not a
            // protection here — but keeping it and writing through explicitly
            // means no future caller can fill this table from user data by
            // accident.
            EmailVerificationCode::query()->forceCreate([
                'user_id' => $user->id,
                'code_hash' => $this->hasher->make($code),
                'email' => $user->email,
                'attempts' => 0,
                'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        return $code;
    }

    /**
     * Seconds remaining before a resend is permitted; zero when it is.
     */
    public function cooldownRemaining(User $user): int
    {
        $challenge = $user->emailVerificationCode()->first();

        if (! $challenge instanceof EmailVerificationCode || $challenge->created_at === null) {
            return 0;
        }

        $elapsed = $challenge->created_at->diffInSeconds(Carbon::now(), absolute: true);

        return (int) max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    /**
     * Consume a code and mark the address verified.
     *
     * @throws ApiProblem when the code is missing, expired, exhausted or wrong.
     */
    public function verify(User $user, string $submitted): void
    {
        $challenge = $user->emailVerificationCode()->first();

        if (! $challenge instanceof EmailVerificationCode) {
            throw ApiProblem::of(
                ProblemCode::VerificationCodeMissing,
                'No verification code is outstanding for this account. Request a new one.'
            );
        }

        if ($challenge->isExpired()) {
            // Destroyed rather than left to linger: an expired challenge is only
            // useful to an attacker counting attempts.
            $challenge->delete();

            throw ApiProblem::of(
                ProblemCode::VerificationCodeExpired,
                'That code has expired. Request a new one.'
            );
        }

        if (! $challenge->isUsableFor($user->email)) {
            // Either the attempt cap is exhausted, or the account's address
            // changed after the code was sent. Both mean this challenge can no
            // longer prove anything about the current address.
            $challenge->delete();

            throw ApiProblem::of(
                ProblemCode::VerificationCodeInvalid,
                'That code is no longer valid. Request a new one.'
            );
        }

        if (! $this->hasher->check($submitted, $challenge->code_hash)) {
            // Count the failure first, so a crash between check and increment
            // cannot hand an attacker a free attempt — and count it
            // conditionally, so parallel guesses cannot each spend the same
            // remaining attempt. `isUsableFor()` above reads a snapshot; without
            // the `attempts <` guard here, N concurrent requests all pass that
            // check at the same value and every one of them gets a free guess
            // against a six-digit code, which is the whole security of this
            // feature. The affected-row count is the authority.
            $counted = EmailVerificationCode::query()
                ->whereKey($challenge->getKey())
                ->where('attempts', '<', EmailVerificationCode::MAX_ATTEMPTS)
                ->increment('attempts');

            if ($counted !== 1 || ! $challenge->fresh()?->hasAttemptsLeft()) {
                $challenge->delete();
            }

            throw ApiProblem::of(
                ProblemCode::VerificationCodeInvalid,
                'That code is not correct.'
            );
        }

        DB::transaction(function () use ($user, $challenge): void {
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();

            // Single use: consumed on success, so the same code cannot be
            // replayed if the response is lost and the client retries.
            $challenge->delete();
        });

        event(new Verified($user));
    }

    /**
     * A uniformly distributed six-digit code.
     *
     * `random_int` is the CSPRNG; `rand`/`mt_rand` are predictable from a few
     * observed outputs, which for a six-digit code would be fatal. Zero-padded,
     * so `000042` is a real code and the keyspace is the full million rather
     * than the nine hundred thousand a naive `random_int(100000, 999999)` gives.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }
}
