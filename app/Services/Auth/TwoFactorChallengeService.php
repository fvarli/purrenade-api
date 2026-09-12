<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Models\TwoFactorChallenge;
use App\Support\KeyedHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The short-lived state between a correct password and an issued session.
 *
 * Fortify parks this in the HTTP session. This API cannot: ADR-0005 keeps it
 * stateless and token-authenticated so a future native client uses the same
 * endpoints, and a native client has no session cookie. So the pending state is
 * a row, named by an opaque token.
 *
 * The token proves only that the first factor was satisfied. It authenticates
 * nothing, authorises nothing, and expires in minutes.
 */
final readonly class TwoFactorChallengeService
{
    /**
     * Open a challenge, returning the plaintext token for the caller to hold.
     *
     * 32 random bytes: enough that guessing is not a threat, which is what makes
     * a fast keyed hash the right storage (see App\Support\KeyedHash).
     *
     * Any previous pending challenge for the account is discarded. Two live
     * challenges for one account would let an attacker who has the password keep
     * a spare attempt budget open alongside the victim's own login.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function start(int $userId, string $deviceLabel): array
    {
        $token = Str::random(64);
        $expiresAt = Carbon::now()->addMinutes(TwoFactorChallenge::TTL_MINUTES);

        // Delete-then-insert in one transaction, over a `unique(user_id)` index.
        // Neither half is sufficient alone: without the transaction two
        // concurrent logins interleave, and without the index the transaction
        // has nothing to conflict on under READ COMMITTED — both would leave two
        // live challenges and hand an attacker who has the password a second
        // attempt budget, which is the thing the paragraph above says must not
        // happen.
        DB::transaction(function () use ($userId, $token, $deviceLabel, $expiresAt): void {
            TwoFactorChallenge::query()->where('user_id', $userId)->lockForUpdate()->get();
            TwoFactorChallenge::query()->where('user_id', $userId)->delete();

            TwoFactorChallenge::query()->forceCreate([
                'user_id' => $userId,
                'token_hash' => KeyedHash::make($token),
                'device_label' => $deviceLabel,
                'attempts' => 0,
                'expires_at' => $expiresAt,
            ]);
        });

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Discard every pending challenge for an account.
     *
     * Called when the password changes, by either route. A challenge is opened
     * on the strength of a correct password and outlives it otherwise: whoever
     * knew the old password keeps a redeemable half-authenticated handle for the
     * rest of its five minutes, and redeeming it mints a full session *after*
     * the reset revoked every existing one. The reset is supposed to be the
     * moment the account stops answering to what the attacker knows.
     */
    public function purgeForUser(int $userId): int
    {
        return TwoFactorChallenge::query()->where('user_id', $userId)->delete();
    }

    /**
     * Resolve a token to its challenge, with the user loaded.
     *
     * One error for every failure — unknown token, expired token, exhausted
     * attempts. Distinguishing them would tell a caller holding a guessed token
     * whether it ever existed.
     *
     * @throws ApiProblem
     */
    public function resolve(string $token): TwoFactorChallenge
    {
        $challenge = TwoFactorChallenge::query()
            ->with('user')
            ->where('token_hash', KeyedHash::make($token))
            ->first();

        if (! $challenge instanceof TwoFactorChallenge || ! $challenge->isUsable()) {
            $challenge?->delete();

            throw ApiProblem::of(
                ProblemCode::TwoFactorChallengeInvalid,
                'This two-factor challenge is no longer valid. Sign in again.'
            );
        }

        return $challenge;
    }

    /**
     * Record a wrong code, destroying the challenge once the cap is reached.
     *
     * The increment is conditional on the cap, and its affected-row count is the
     * authority. `resolve()` reads the row and this writes it, so a plain
     * increment lets N parallel requests all pass the same `attempts < 5` check
     * against one snapshot and each spend a guess — turning a five-attempt cap
     * into as many attempts as the attacker can open connections, against six
     * digits. Losing the race here means the attempt did not count, so the
     * challenge is destroyed rather than left with budget the caller has
     * already spent.
     */
    public function registerFailure(TwoFactorChallenge $challenge): void
    {
        $counted = TwoFactorChallenge::query()
            ->whereKey($challenge->getKey())
            ->where('attempts', '<', TwoFactorChallenge::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($counted !== 1 || ! $challenge->fresh()?->isUsable()) {
            $challenge->delete();
        }
    }

    public function consume(TwoFactorChallenge $challenge): void
    {
        $challenge->delete();
    }

    /**
     * Drop challenges that nobody completed.
     *
     * Called from the scheduler. Expired rows are already refused by
     * `resolve()`, so this is hygiene rather than a control — but a table that
     * only ever grows is its own kind of defect.
     */
    public function pruneExpired(): int
    {
        return TwoFactorChallenge::query()
            ->where('expires_at', '<', Carbon::now())
            ->delete();
    }
}
