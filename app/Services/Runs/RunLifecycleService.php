<?php

declare(strict_types=1);

namespace App\Services\Runs;

use App\Enums\ProblemCode;
use App\Enums\RunStatus;
use App\Exceptions\ApiProblem;
use App\Http\Resources\ProgressionResource;
use App\Models\Character;
use App\Models\PlayerProgression;
use App\Models\Run;
use App\Models\User;
use App\Services\Leaderboards\LeaderboardProjector;
use App\Services\Progression\ProgressionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The authoritative normal-run lifecycle: start and finish (ADR-0006).
 *
 * The client proposes; this class decides. It creates the run, its seed and
 * its `started_at` before gameplay, and it classifies the finish and applies
 * progression — for an **accepted** run only — in one transaction.
 *
 * ## Lock order — RUN → PLAYER_PROGRESSION → PAW_LEDGER → LB_ALL_TIME → LB_WEEKLY (C-1)
 *
 * Every transaction here that touches more than one of these takes them in
 * that order, and nothing else in the application locks two of them:
 *
 * - **Start** locks the RUN (the user's active run, `FOR UPDATE`, or the
 *   speculative insert under the partial unique index). It **never** locks
 *   progression — it only reads `loli_cycle_paws` with a plain `SELECT`.
 * - **Finish** locks the RUN, then PROGRESSION `FOR UPDATE`, then appends to
 *   the PAW_LEDGER, then — for an accepted run only — upserts the player's
 *   LB_ALL_TIME row and then their LB_WEEKLY row (M10), then updates the RUN
 *   row it already holds. The leaderboard rows are keyed by the player, and a
 *   player's finishes are already serialised by the progression lock, so two
 *   finishes never contend for them in the other order; different players
 *   write different rows. Leaderboard reads take no locks.
 * - The progression row's existence is ensured **before** either transaction
 *   opens, as its own autocommitted statement
 *   (`ProgressionService::ensure()`), so no run transaction ever waits on a
 *   progression insert while holding a run lock.
 *
 * Any change here that touches both runs and progression must keep this order,
 * or two players' tabs can deadlock each other.
 *
 * ## Clock
 *
 * `received_at` is captured once per call, truncated to the millisecond the
 * `timestamp(3)` columns store, so what is compared is exactly what is
 * persisted.
 */
final class RunLifecycleService
{
    public function __construct(
        private readonly ProgressionService $progression,
        private readonly RunValidator $validator,
        private readonly RunSeedGenerator $seeds,
        private readonly LeaderboardProjector $leaderboards,
    ) {}

    /**
     * Start a new run, or resume the player's active one (GR-4, C-11).
     *
     * `$characterKey` has already passed shape validation. It is untrusted
     * input and only a **preference for a new run**: a non-stale active run is
     * resumed with its own original character whatever was requested, and
     * catalogue availability is checked only on the creation path.
     */
    public function start(User $user, string $characterKey): StartedRun
    {
        // C-1: outside, and before, the run transaction.
        $this->progression->ensure($user->id);

        $receivedAt = $this->now();

        return DB::transaction(function () use ($user, $characterKey, $receivedAt): StartedRun {
            // RUN. Re-issued once when empty: under READ COMMITTED each
            // statement takes a fresh snapshot, and a first statement that
            // waited on a row a concurrent start then finalised returns nothing
            // — while the replacement that start committed is only visible to a
            // new statement. Without the second look this start would go on to
            // create, fail the insert, and only then find the winner.
            $active = $this->lockActiveRun($user->id) ?? $this->lockActiveRun($user->id);

            // Recovery: a non-stale active run is returned unchanged. No
            // catalogue lookup — the requested character cannot invalidate,
            // swap or mutate a run that already exists.
            if ($active instanceof Run && ! $this->isStale($active, $receivedAt)) {
                return new StartedRun($active, $this->readCyclePaws($user->id), created: false);
            }

            // Creation. The catalogue is static content, read without a lock and
            // outside the lock order. Unavailable → the exception rolls this
            // transaction back before anything is written, so a stale run is
            // never consumed by a start that cannot replace it.
            $characterId = Character::query()
                ->selectableForNewRun()
                ->where('key', $characterKey)
                ->value('id');

            if ($characterId === null) {
                throw $this->characterUnavailable();
            }

            // Stale replacement and the new run's insert are one atomic unit:
            // any failure below rolls both back and the stale run stays active.
            if ($active instanceof Run) {
                $this->replaceStale($active, $receivedAt);
            }

            $insertedId = $this->insertActiveRun($user->id, (int) $characterId, $receivedAt);

            if ($insertedId === null) {
                // A concurrent start committed an active run first; the
                // speculative insert waited on it and yielded. That run is now
                // established, so resume semantics apply to this request.
                $winner = Run::query()
                    ->where('user_id', $user->id)
                    ->where('status', RunStatus::Active->value)
                    ->first();

                if (! $winner instanceof Run) {
                    throw new RuntimeException('Active-run insert yielded, but no active run is visible.');
                }

                return new StartedRun($winner, $this->readCyclePaws($user->id), created: false);
            }

            $run = Run::query()->findOrFail($insertedId);

            return new StartedRun($run, $this->readCyclePaws($user->id), created: true);
        });
    }

    /**
     * Finish a run: classify the proposal and, if accepted, apply it (GR-3).
     *
     * Idempotent on `(user, key)`: the same key with the same effective request
     * returns the stored result and writes nothing; with a different request it
     * is a 409. A run that is no longer active answers 409 to any other key.
     */
    public function finish(User $user, string $runId, RunTelemetry $telemetry, string $idempotencyKey): FinishOutcome
    {
        $runId = strtolower($runId);
        $idempotencyKey = strtolower($idempotencyKey);
        $fingerprint = FinishFingerprint::of($runId, $telemetry);

        // C-1: outside, and before, the run transaction.
        $this->progression->ensure($user->id);

        $receivedAt = $this->now();

        try {
            return DB::transaction(fn (): FinishOutcome => $this->finishLocked(
                $user, $runId, $telemetry, $idempotencyKey, $fingerprint, $receivedAt,
            ));
        } catch (UniqueConstraintViolationException $e) {
            // Backstop for an interleaving the run-row lock does not serialise:
            // the same key committed on another of this player's runs between
            // our lookup and our write. The transaction has rolled back; answer
            // from what is now committed, never by writing twice.
            return $this->replayOrConflict($user->id, $idempotencyKey, $fingerprint) ?? throw $e;
        }
    }

    private function finishLocked(
        User $user,
        string $runId,
        RunTelemetry $telemetry,
        string $idempotencyKey,
        string $fingerprint,
        CarbonImmutable $receivedAt,
    ): FinishOutcome {
        // 1. RUN. Owner-scoped: another player's run is indistinguishable from
        //    one that does not exist.
        $run = Run::query()
            ->whereKey($runId)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $run instanceof Run) {
            throw ApiProblem::of(ProblemCode::NotFound, 'The requested resource does not exist.');
        }

        // 2. Durable idempotency, before anything about the run's state.
        $replay = $this->replayOrConflict($user->id, $idempotencyKey, $fingerprint);

        if ($replay instanceof FinishOutcome) {
            return $replay;
        }

        // 3. Lifecycle.
        if ($run->status !== RunStatus::Active) {
            throw ApiProblem::of(ProblemCode::RunNotActive, 'This run has already been finalised and cannot be finished again.');
        }

        // 4. PROGRESSION, locked. The row exists: ensure() committed it.
        /** @var object{lifetime_paws: int, loli_cycle_paws: int, best_score: int, run_count: int, tutorial_completed_at: string|null}|null $before */
        $before = DB::selectOne(
            'SELECT lifetime_paws, loli_cycle_paws, best_score, run_count, tutorial_completed_at
             FROM player_progression WHERE user_id = ? FOR UPDATE',
            [$user->id],
        );

        if ($before === null) {
            throw new RuntimeException('Progression row missing inside the finish transaction.');
        }

        // 5. Classify — pure, lock-free.
        $windowMs = $receivedAt->getTimestampMs() - $run->started_at->getTimestampMs();
        $classification = $this->validator->classify($telemetry, $windowMs);
        $status = $classification->status;

        $previousBest = (int) $before->best_score;
        $progression = new PlayerProgression;
        $progression->forceFill([
            'lifetime_paws' => (int) $before->lifetime_paws,
            'loli_cycle_paws' => (int) $before->loli_cycle_paws,
            'best_score' => $previousBest,
            'run_count' => (int) $before->run_count,
        ]);

        // The finalisation time, and the leaderboard's `achieved_at` (D2). A
        // clock stepped backwards cannot produce a finish before the start;
        // the measured window (possibly negative) is kept in the metadata as
        // observed.
        $finishedAt = $receivedAt->lessThan($run->started_at) ? CarbonImmutable::instance($run->started_at) : $receivedAt;

        // 6–7. Only an ACCEPTED run mutates progression, the ledger or the
        //      leaderboard projection.
        if ($status === RunStatus::Accepted) {
            $progression = $this->applyAccepted($user->id, $run->id, $telemetry, (int) $before->loli_cycle_paws, $receivedAt);

            // LB_ALL_TIME → LB_WEEKLY. The week is the run's server-recorded
            // start; a failure here rolls the whole finish back.
            $this->leaderboards->recordAccepted(
                $user->id,
                $run->id,
                CarbonImmutable::instance($run->started_at),
                $finishedAt,
                $telemetry->reportedScore,
                $telemetry->reportedDurationMs,
            );
        }

        $counted = $status === RunStatus::Accepted || $status === RunStatus::Flagged;

        $result = [
            'run_id' => $run->id,
            'status' => $status->value,
            'score' => $counted ? $telemetry->reportedScore : null,
            'run_paws' => $counted ? $telemetry->reportedRunPaws : null,
            'is_personal_best' => $status === RunStatus::Accepted && $telemetry->reportedScore > $previousBest,
            'previous_best_score' => $previousBest,
            'reasons' => $classification->reasons(),
            'progression' => ProgressionResource::payload(
                $progression,
                $before->tutorial_completed_at !== null || $user->tutorial_completed_at !== null,
            ),
            'achievements_unlocked' => [],
            'characters_unlocked' => [],
        ];

        // 8. RUN — the row this transaction already holds.
        Run::query()->whereKey($run->id)->update([
            'status' => $status->value,
            'finished_at' => $this->timestamp($finishedAt),
            'duration_ms' => $counted ? $telemetry->reportedDurationMs : null,
            'score' => $counted ? $telemetry->reportedScore : null,
            'run_paws' => $counted ? $telemetry->reportedRunPaws : null,
            'validation_meta' => json_encode($classification->meta(), JSON_THROW_ON_ERROR),
            'idempotency_key' => $idempotencyKey,
            'idempotency_fingerprint' => $fingerprint,
            'result' => json_encode($result, JSON_THROW_ON_ERROR),
        ]);

        return new FinishOutcome($result, replayed: false);
    }

    /**
     * PROGRESSION then PAW_LEDGER, for an accepted run. One atomic `UPDATE`
     * for the counters — never read-modify-write — with the paw overflow
     * computed in SQL: the cycle wraps at the threshold and keeps the rest.
     *
     * The arithmetic is `bigint`, cast explicitly: PostgreSQL types an untyped
     * parameter from the column beside it, so `loli_cycle_paws + ?` would be
     * smallint arithmetic and a plausible delta above 32767 would fail. Only the
     * remainder — always 0..199 — is stored back into the smallint column.
     */
    private function applyAccepted(int $userId, string $runId, RunTelemetry $telemetry, int $previousCycle, CarbonImmutable $receivedAt): PlayerProgression
    {
        $paws = $telemetry->reportedRunPaws;
        $threshold = PlayerProgression::LOLI_THRESHOLD;

        /** @var object{lifetime_paws: int, loli_cycle_paws: int, best_score: int, run_count: int} $after */
        $after = DB::selectOne(
            'UPDATE player_progression SET
                lifetime_paws = lifetime_paws + CAST(? AS bigint),
                loli_cycle_paws = (CAST(loli_cycle_paws AS bigint) + CAST(? AS bigint)) % CAST(? AS bigint),
                best_score = GREATEST(best_score, ?),
                run_count = run_count + 1,
                updated_at = ?
             WHERE user_id = ?
             RETURNING lifetime_paws, loli_cycle_paws, best_score, run_count',
            [$paws, $paws, $threshold, $telemetry->reportedScore, $this->timestamp($receivedAt), $userId],
        );

        if ($paws > 0) {
            // Threshold crossings — accounting only, never Loli activations.
            DB::table('paw_ledger')->insert([
                'user_id' => $userId,
                'run_id' => $runId,
                'delta' => $paws,
                'resulting_cycle' => (int) $after->loli_cycle_paws,
                'bonuses_triggered' => intdiv($previousCycle + $paws, $threshold),
                'created_at' => $this->timestamp($receivedAt),
            ]);
        }

        $progression = new PlayerProgression;
        $progression->forceFill([
            'lifetime_paws' => (int) $after->lifetime_paws,
            'loli_cycle_paws' => (int) $after->loli_cycle_paws,
            'best_score' => (int) $after->best_score,
            'run_count' => (int) $after->run_count,
        ]);

        return $progression;
    }

    /**
     * A prior finish under this key, if there is one: its stored result when
     * the request is the same, a 409 when it is not, null when the key is new.
     */
    private function replayOrConflict(int $userId, string $idempotencyKey, string $fingerprint): ?FinishOutcome
    {
        $prior = Run::query()
            ->where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first(['id', 'idempotency_fingerprint', 'result']);

        if (! $prior instanceof Run) {
            return null;
        }

        if (! hash_equals((string) $prior->idempotency_fingerprint, $fingerprint)) {
            throw ApiProblem::of(
                ProblemCode::IdempotencyKeyReused,
                'This idempotency key was already used for a different request.',
            );
        }

        return new FinishOutcome((array) $prior->result, replayed: true);
    }

    private function lockActiveRun(int $userId): ?Run
    {
        return Run::query()
            ->where('user_id', $userId)
            ->where('status', RunStatus::Active->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * RUN_STALE_REPLACEMENT_AFTER. Not an expiry: it is asked only here, when
     * the same player starts again. Recovery holds while
     * `started_at > received_at − threshold`.
     */
    private function isStale(Run $run, CarbonImmutable $receivedAt): bool
    {
        $threshold = $receivedAt->subSeconds((int) config('game_runs.stale_replacement_after_seconds'));

        return ! $run->started_at->greaterThan($threshold);
    }

    private function replaceStale(Run $run, CarbonImmutable $receivedAt): void
    {
        Run::query()->whereKey($run->id)->update([
            'status' => RunStatus::Rejected->value,
            'finished_at' => $this->timestamp($receivedAt),
            'validation_meta' => json_encode([
                'v' => 1,
                'window_ms' => $receivedAt->getTimestampMs() - $run->started_at->getTimestampMs(),
                'rules' => [['code' => 'run_stale_replaced']],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Insert the new active run under the partial unique index (GR-4).
     *
     * Raw SQL because Eloquent cannot express an `ON CONFLICT` that targets a
     * **partial** index: the `WHERE status = 'active'` inference predicate must
     * match the index predicate for PostgreSQL to use it as the arbiter. A
     * concurrent insert for the same player makes this one wait on it and then
     * do nothing — the index decides, never a read-then-insert.
     *
     * @return string|null The new run's id, or null when another start won.
     */
    private function insertActiveRun(int $userId, int $characterId, CarbonImmutable $receivedAt): ?string
    {
        $now = $this->timestamp($receivedAt);

        /** @var object{id: string}|null $row */
        $row = DB::selectOne(
            "INSERT INTO runs (id, user_id, character_id, status, seed, started_at, created_at, updated_at)
             VALUES (?, ?, ?, 'active', ?, ?, ?, ?)
             ON CONFLICT (user_id) WHERE status = 'active' DO NOTHING
             RETURNING id",
            [(string) Str::uuid7(), $userId, $characterId, $this->seeds->next(), $now, $now, $now],
        );

        return $row?->id;
    }

    /** Unlocked: start never takes the progression lock (C-1). */
    private function readCyclePaws(int $userId): int
    {
        return (int) DB::table('player_progression')->where('user_id', $userId)->value('loli_cycle_paws');
    }

    private function characterUnavailable(): ApiProblem
    {
        return new ApiProblem(
            ProblemCode::ValidationFailed,
            'The given data was invalid.',
            errors: ['character_id' => [[
                'code' => 'character_unavailable',
                'message' => 'That character cannot start a new run.',
            ]]],
        );
    }

    private function now(): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return $now->setMicrosecond(intdiv($now->microsecond, 1000) * 1000);
    }

    private function timestamp(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s.v');
    }
}
