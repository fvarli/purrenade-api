<?php

declare(strict_types=1);

namespace App\Http\Requests\Runs;

use App\Rules\StrictInteger;
use App\Services\Runs\RunTelemetry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /game-runs/{runId}/finish — protocol shape (C-7).
 *
 * - `Idempotency-Key` header: required, a UUID, normalised to lower case.
 * - `telemetry.reported_duration_ms`, `telemetry.reported_score`,
 *   `telemetry.reported_run_paws`: required JSON integers.
 *
 * Anything else in the body is ignored — read by nothing, persisted nowhere,
 * absent from the fingerprint. That includes any ANTI-6 counter a client sends
 * (`reported_loli_activations` and the like): those facts are not established
 * in M9, and a client's count of them is not an input to anything.
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
