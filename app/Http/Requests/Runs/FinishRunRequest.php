<?php

declare(strict_types=1);

namespace App\Http\Requests\Runs;

use App\Rules\StrictInteger;
use App\Services\Replay\ReplayInputCandidate;
use App\Services\Replay\ReplayInputParser;
use App\Services\Runs\RunTelemetry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /game-runs/{runId}/finish — protocol shape (C-7).
 *
 * - `Idempotency-Key` header: required, a UUID, normalised to lower case.
 * - `telemetry.reported_duration_ms`, `telemetry.reported_score`,
 *   `telemetry.reported_run_paws`: required JSON integers.
 *
 * - `replay_input`: optional, the ANTI-6 canonical input stream (P3). It has
 *   **no validation rule**: whatever its shape, it can never produce a 422 or
 *   affect classification. `replayInput()` reduces it to usable or unusable,
 *   and only an accepted run records it (ADR-0006 Amendment A).
 *
 * Anything else in the body is ignored — read by nothing, persisted nowhere,
 * absent from the fingerprint. That includes any ANTI-6 counter a client sends
 * (`reported_loli_activations` and the like): a client's count of a fact is
 * never an input to anything; the facts come only from server derivation and
 * replay.
 *
 * A well-formed integer outside the structural domain is **not** refused here:
 * it is classified, and answered with a `rejected` outcome.
 */
final class FinishRunRequest extends FormRequest
{
    use DecodesJsonBody;

    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'uuid'],
            'telemetry' => ['required', 'array'],
            'telemetry.reported_duration_ms' => ['required', new StrictInteger],
            'telemetry.reported_score' => ['required', new StrictInteger],
            'telemetry.reported_run_paws' => ['required', new StrictInteger],
        ];
    }

    /**
     * The decoded body, with the header folded in under its own name so it is
     * validated and reported like any other field. Set last, so a body member
     * of the same name can never stand in for the header.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [
            ...$this->decodedBody(),
            'idempotency_key' => $this->header(self::IDEMPOTENCY_HEADER),
        ];
    }

    public function idempotencyKey(): string
    {
        return strtolower((string) $this->validated('idempotency_key'));
    }

    /**
     * The optional canonical replay stream, structurally reduced. Null when
     * the body carries none (absent or JSON `null`). Never fingerprinted.
     */
    public function replayInput(): ?ReplayInputCandidate
    {
        return (new ReplayInputParser)->parse($this->decodedBody()['replay_input'] ?? null);
    }

    public function telemetry(): RunTelemetry
    {
        /** @var array{reported_duration_ms: int, reported_score: int, reported_run_paws: int} $telemetry */
        $telemetry = $this->validated('telemetry');

        return new RunTelemetry(
            reportedDurationMs: $telemetry['reported_duration_ms'],
            reportedScore: $telemetry['reported_score'],
            reportedRunPaws: $telemetry['reported_run_paws'],
        );
    }
}
