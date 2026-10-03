<?php

declare(strict_types=1);

namespace App\Services\Replay;

use App\Enums\ReplayOutcome;
use App\Enums\RunStatus;
use App\Models\RunReplayEvidence;
use App\Support\RunReviewLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-acceptance replay evidence (ANTI-6 P3, Option D). **Never an
 * acceptance gate** (ADR-0006 Amendment A, ANTI-1): no outcome here — absence,
 * expiry, a failure of any class, an inconsistency — changes a run's status or
 * its Loli evidence. Only the audited M13 invalidation can act on a run.
 *
 * ## Processing — no transaction held
 *
 * Load the pending work row, check the logical expiry and the run's status,
 * count the attempt, decrypt, verify the bundle against its pin, replay in a
 * one-shot Node process, classify. The replay's CPU time never overlaps a row
 * lock.
 *
 * ## Commit — one short transaction
 *
 * Lock order **RUN → PROGRESSION → RUN_REPLAY_INPUTS → RUN_REPLAY_EVIDENCE**:
 *
 * 1. RUN `FOR UPDATE`, and re-check `accepted`. This is the serialisation point
 *    with M13 invalidation: whichever takes the run row first wins, and a replay
 *    that commits after an invalidation records `run_not_accepted`.
 * 2. PROGRESSION `FOR UPDATE` — the per-player point shared with finish, and the
 *    seam where M11 will evaluate replay-dependent achievements (O7 is OPEN: P3
 *    evaluates nothing and queues nothing).
 * 3. The work row `FOR UPDATE`. Already terminal → a no-op (duplicate delivery).
 * 4. Logical expiry, checked again: expired input never establishes evidence.
 * 5. Established → insert the evidence (`ON CONFLICT DO NOTHING`).
 * 6. Record the terminal outcome and clear the input, in the same statement.
 */
final class ReplayEvidenceService
{
    public function __construct(
        private readonly ReplayInputStore $inputs,
        private readonly ReplayBundles $bundles,
        private readonly ReplayRunner $runner,
        private readonly ReplayResultClassifier $classifier,
    ) {}

    /**
     * Process one run's pending replay. Throws `ReplayRunnerFailure` only for a
     * transient failure (class A), for the job to retry; every other outcome is
     * committed here and is terminal.
     */
    public function process(string $runId): void
    {
        /** @var object{input_expires_at: string|null}|null $work */
        $work = DB::selectOne(
            "SELECT input_expires_at FROM run_replay_inputs WHERE run_id = ? AND state = 'pending'",
            [$runId],
        );

        if ($work === null) {
            return;
        }

        /** @var object{user_id: int, status: string, seed: int, start_loli_cycle_paws: int|null, score: int|null, run_paws: int|null, duration_ms: int|null}|null $run */
        $run = DB::selectOne(
            'SELECT user_id, status, seed, start_loli_cycle_paws, score, run_paws, duration_ms FROM runs WHERE id = ?',
            [$runId],
        );

        if ($run === null || $run->status !== RunStatus::Accepted->value) {
            $this->commit($runId, ReplayVerdict::of(ReplayOutcome::RunNotAccepted));

            return;
        }

        if ($this->expired($work->input_expires_at)) {
            $this->commit($runId, ReplayVerdict::of(ReplayOutcome::InputExpired));

            return;
        }

        DB::update(
            "UPDATE run_replay_inputs SET attempts = attempts + 1, updated_at = ? WHERE run_id = ? AND state = 'pending'",
            [$this->timestamp(CarbonImmutable::now('UTC')), $runId],
        );

        $input = $this->inputs->decrypt($runId);

        if (! $input instanceof ReplayInput) {
            // Gone, or no longer decrypts (key rotated beyond APP_PREVIOUS_KEYS,
            // or lost): unusable, ABSENT, never a retry.
            $this->commit($runId, ReplayVerdict::of(ReplayOutcome::InputExpired), 'input_unreadable');

            return;
        }

        $bundle = $this->bundles->find($input->domainVersion);

        if (! $bundle instanceof ReplayBundle || ! $bundle->bundleIntact() || $run->start_loli_cycle_paws === null) {
            // A stale or mismatched pin fails closed: no evidence, run untouched.
            $this->commit($runId, ReplayVerdict::of(ReplayOutcome::VersionUnsupported), 'pin_unavailable');

            return;
        }

        $result = $this->runner->run($bundle, [
            'protocol' => ReplayResultClassifier::PROTOCOL,
            'domain_version' => $bundle->domainVersion,
            'seed' => (int) $run->seed,
            'start_loli_cycle_paws' => (int) $run->start_loli_cycle_paws,
            'mode' => 'run',
            'input' => [
                'format_version' => $input->formatVersion,
                'domain_version' => $input->domainVersion,
                'total_steps' => $input->totalSteps,
                'events' => $input->events,
            ],
        ]);

        $verdict = $this->classifier->classify($result, $bundle->domainVersion, $input->totalSteps, [
            'score' => (int) $run->score,
            'run_paws' => (int) $run->run_paws,
            'duration_ms' => (int) $run->duration_ms,
        ]);

        $this->commit($runId, $verdict, null, $bundle->domainVersion, $result);
    }

    /** Retries exhausted (class A persisted): terminal, input cleared, no evidence. */
    public function exhausted(string $runId): void
    {
        $this->commit($runId, ReplayVerdict::of(ReplayOutcome::AttemptsExhausted));
    }

    /**
     * The replay commit transaction. Returns the outcome actually recorded, or
     * null when the row was already terminal (a duplicate: nothing written).
     */
    public function commit(
        string $runId,
        ReplayVerdict $verdict,
        ?string $reason = null,
        ?string $domainVersion = null,
        ?ReplayProcessResult $result = null,
    ): ?ReplayOutcome {
        $now = CarbonImmutable::now('UTC');

        $committed = DB::transaction(function () use ($runId, $verdict, $domainVersion, $now): ?array {
            // 1. RUN.
            /** @var object{user_id: int, status: string}|null $run */
            $run = DB::selectOne('SELECT user_id, status FROM runs WHERE id = ? FOR UPDATE', [$runId]);

            if ($run === null) {
                return null;
            }

            // 2. PROGRESSION.
            DB::select('SELECT 1 FROM player_progression WHERE user_id = ? FOR UPDATE', [$run->user_id]);

            // 3. RUN_REPLAY_INPUTS.
            /** @var object{state: string, input_expires_at: string|null, attempts: int}|null $work */
            $work = DB::selectOne(
                'SELECT state, input_expires_at, attempts FROM run_replay_inputs WHERE run_id = ? FOR UPDATE',
                [$runId],
            );

            if ($work === null || $work->state !== 'pending') {
                return null;
            }

            $outcome = $verdict->outcome;

            if ($run->status !== RunStatus::Accepted->value) {
                $outcome = ReplayOutcome::RunNotAccepted;
            } elseif ($this->expired($work->input_expires_at, $now)) {
                // 4. The logical boundary holds at commit too.
                $outcome = ReplayOutcome::InputExpired;
            }

            // 5. RUN_REPLAY_EVIDENCE.
            if ($outcome === ReplayOutcome::Established && $verdict->facts !== null && $domainVersion !== null) {
                DB::insert(
                    'INSERT INTO run_replay_evidence (run_id, cone_safe_passes, near_misses, slayyy_activations, evidence_version, domain_version, established_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (run_id) DO NOTHING',
                    [
                        $runId,
                        $verdict->facts['cone_safe_passes'],
                        $verdict->facts['near_misses'],
                        $verdict->facts['slayyy_activations'],
                        RunReplayEvidence::EVIDENCE_VERSION,
                        $domainVersion,
                        $this->timestamp($now),
                    ],
                );
            }

            // 6. Terminal, and the input is gone in the same statement.
            DB::update(
                "UPDATE run_replay_inputs SET state = 'terminal', outcome_code = ?, input = NULL, updated_at = ? WHERE run_id = ?",
                [$outcome->value, $this->timestamp($now), $runId],
            );

            return ['outcome' => $outcome, 'user_id' => (int) $run->user_id, 'attempts' => (int) $work->attempts];
        });

        if ($committed === null) {
            return null;
        }

        /** @var ReplayOutcome $outcome */
        $outcome = $committed['outcome'];

        // After commit: codes and identifiers only. Never the input, the
        // replay's output, its stderr or any gameplay value.
        Log::info('run.replay.completed', array_filter([
            'run_id' => $runId,
            'outcome' => $outcome->value,
            'reason' => $reason,
            'attempts' => $committed['attempts'],
            'replay_ms' => $result?->elapsedMs,
            'stderr_present' => $result?->stderrPresent,
            'correlation_id' => Context::get('correlation_id'),
        ], fn (mixed $value): bool => $value !== null));

        if ($outcome === ReplayOutcome::ReplayInconsistent) {
            RunReviewLog::replayInconsistent($runId, $committed['user_id'], $verdict->reasons);
        }

        if ($outcome === ReplayOutcome::Established && $verdict->loliActivations !== null) {
            $this->loliCanary($runId, $verdict->loliActivations);
        }

        return $outcome;
    }

    /**
     * Operational cross-check of the P1 Loli derivation against the replay.
     * A divergence is a metric, never a correction: Loli evidence is frozen (E4).
     */
    private function loliCanary(string $runId, int $replayed): void
    {
        $frozen = DB::table('run_loli_evidence')->where('run_id', $runId)->value('loli_activations');

        if ($frozen !== null && (int) $frozen !== $replayed) {
            Log::warning('run.replay.loli_divergence', [
                'run_id' => $runId,
                'correlation_id' => Context::get('correlation_id'),
            ]);
        }
    }

    private function expired(?string $expiresAt, ?CarbonImmutable $now = null): bool
    {
        if ($expiresAt === null) {
            return true;
        }

        return ! ($now ?? CarbonImmutable::now('UTC'))->lessThan(CarbonImmutable::parse($expiresAt, 'UTC'));
    }

    private function timestamp(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s.v');
    }
}
