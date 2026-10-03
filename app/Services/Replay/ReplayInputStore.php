<?php

declare(strict_types=1);

namespace App\Services\Replay;

use App\Enums\ReplayOutcome;
use App\Jobs\Runs\ReplayRunEvidence;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * The transient replay input: written in the acceptance transaction, read once
 * by the replay worker, and gone at the first terminal outcome (O3).
 *
 * ## Retention
 *
 * `input_expires_at = received_at + 24 h`, exactly. That is the hard **logical**
 * boundary: from that instant the input is unusable — checked before a replay
 * and again inside the commit transaction — and expired input never
 * establishes evidence. The scheduler's every-minute sweeper clears expired
 * bytes promptly, best-effort: no scheduler can promise physical deletion at
 * exactly 24:00:00 through a host outage. Every terminal outcome clears the
 * input in the statement that records it.
 *
 * ## At rest
 *
 * Encrypted with the `APP_KEY`-backed encrypter; the envelope's bytes are
 * stored, never the plaintext. Only `decrypt()` ever reads them back, and only
 * the replay job calls it. An envelope that no longer decrypts (key rotated
 * beyond `APP_PREVIOUS_KEYS`, or lost) is unusable: the run's replay evidence is
 * ABSENT and the run stays accepted.
 */
final class ReplayInputStore
{
    /** O3: the hard ceiling, from the moment the finish was received. */
    public const RETENTION_HOURS = 24;

    /**
     * The stored envelope's largest possible size, measured: see the
     * `create_run_replay_inputs_table` migration. The database CHECK is this
     * exact value; `ReplayStorageBoundTest` keeps the two equal.
     */
    public const MAX_STORED_BYTES = 190_358;

    public function __construct(
        private readonly ReplayBundles $bundles,
    ) {}

    /**
     * Record a finish's replay input. Called inside the acceptance
     * transaction, for an **accepted** run only, after RUN_LOLI_EVIDENCE — the
     * last entry in the acceptance lock order. The row is new and keyed by the
     * run that transaction holds, so it waits on nothing.
     */
    public function record(Run $run, ?ReplayInputCandidate $candidate, int $windowMs, int $toleranceMs, CarbonImmutable $receivedAt): void
    {
        if ($candidate === null) {
            return;
        }

        $outcome = $this->unusableOutcome($run, $candidate, $windowMs, $toleranceMs);
        $now = $receivedAt->utc()->format('Y-m-d H:i:s.v');

        if ($outcome instanceof ReplayOutcome || ! $candidate->input instanceof ReplayInput) {
            DB::insert(
                "INSERT INTO run_replay_inputs (run_id, state, outcome_code, attempts, input, input_expires_at, created_at, updated_at)
                 VALUES (?, 'terminal', ?, 0, NULL, NULL, ?, ?)",
                [$run->id, ($outcome ?? ReplayOutcome::InputMalformed)->value, $now, $now],
            );

            return;
        }

        DB::insert(
            "INSERT INTO run_replay_inputs (run_id, state, outcome_code, attempts, input, input_expires_at, created_at, updated_at)
             VALUES (?, 'pending', NULL, 0, decode(?, 'hex'), ?, ?, ?)",
            [
                $run->id,
                bin2hex((string) base64_decode(Crypt::encryptString($candidate->input->canonicalJson()), true)),
                $receivedAt->addHours(self::RETENTION_HOURS)->utc()->format('Y-m-d H:i:s.v'),
                $now,
                $now,
            ],
        );

        // After the acceptance transaction commits, never inside it: the
        // queue connections default to `after_commit => false`.
        ReplayRunEvidence::dispatch($run->id)->afterCommit();
    }

    /**
     * The decrypted input of a **pending** row, or null when the bytes are gone
     * or no longer decrypt to a valid input. Never logged, never returned to a
     * caller outside the replay job.
     */
    public function decrypt(string $runId): ?ReplayInput
    {
        /** @var object{hex: string|null}|null $row */
        $row = DB::selectOne(
            "SELECT encode(input, 'hex') AS hex FROM run_replay_inputs WHERE run_id = ? AND state = 'pending'",
            [$runId],
        );

        if ($row === null || $row->hex === null) {
            return null;
        }

        try {
            $plaintext = Crypt::decryptString(base64_encode((string) hex2bin($row->hex)));
            $decoded = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return (new ReplayInputParser)->parse($decoded)?->input;
    }

    private function unusableOutcome(Run $run, ReplayInputCandidate $candidate, int $windowMs, int $toleranceMs): ?ReplayOutcome
    {
        if (! $candidate->input instanceof ReplayInput) {
            return $candidate->unusable;
        }

        // The replay needs the cycle the run started from; a run started before
        // it was recorded cannot be replayed (its Loli evidence is ABSENT too).
        if ($run->start_loli_cycle_paws === null) {
            return ReplayOutcome::VersionUnsupported;
        }

        if (! $this->bundles->find($candidate->input->domainVersion) instanceof ReplayBundle) {
            return ReplayOutcome::VersionUnsupported;
        }

        // The fixed steps simulated cannot exceed what fits in the server's own
        // window: pauses and catch-up-discarded time are not steps.
        $stepMs = 1000 / (int) config('replay.fixed_step_hz');

        if ($candidate->input->totalSteps * $stepMs > max(0, $windowMs) + $toleranceMs) {
            return ReplayOutcome::InputMalformed;
        }

        return null;
    }
}
