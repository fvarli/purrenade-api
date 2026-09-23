<?php

declare(strict_types=1);

namespace App\Services\Runs;

use App\Enums\RunStatus;

/**
 * Classifies one finish proposal: accepted, flagged or rejected (ADR-0006).
 *
 * **Pure.** No database, no HTTP, no clock, no configuration lookup — the caller
 * passes the telemetry, the server-measured window and the bounds. That is what
 * lets the finish transaction call it while holding row locks without it ever
 * waiting on anything, and what lets every rule be unit-tested at its bound.
 *
 * ## Reject versus flag (ANTI-4)
 *
 * REJECT rules are **structural**: provable without any unresolved tuning
 * value. FLAG rules depend on still-PROPOSED tuning and may never reject. All
 * hits within a class are recorded. A rejection is terminal, so flag rules are
 * not evaluated for a run already rejected.
 *
 * | Code | Class | Condition |
 * |---|---|---|
 * | `value_out_of_domain` | reject | any member `< 0` or `> 2147483647` |
 * | `duration_non_positive` | reject | `duration == 0` |
 * | `duration_exceeds_server_window` | reject | `duration > window + tolerance` |
 * | `score_below_paw_floor` | reject | `score < 10 × paws` (perPaw LOCKED at 10) |
 * | `score_rate_high` | flag | `score / seconds > 80` |
 * | `paw_rate_high` | flag | `paws / seconds > 3` |
 * | `score_below_duration_floor` | flag | `score / seconds < 5` |
 * | `duration_below_minimum` | flag | `duration < 4000` |
 *
 * Rates are compared in integer arithmetic (`score × 1000 > 80 × duration`), so
 * no float rounding sits on a boundary. Every operand is within `0..2^31` by the
 * time a rate is computed, so no product can overflow a PHP int.
 *
 * Nothing here reads, returns or derives near misses, obstacle passes, SLAYYY
 * activations or Loli activations (ANTI-6).
 */
final readonly class RunValidator
{
    public function __construct(
        private RunValidationBounds $bounds,
    ) {}

    public function classify(RunTelemetry $telemetry, int $windowMs): RunClassification
    {
        $members = [
            'reported_duration_ms' => $telemetry->reportedDurationMs,
            'reported_score' => $telemetry->reportedScore,
            'reported_run_paws' => $telemetry->reportedRunPaws,
        ];

        // --- Structural: out of the representable domain -----------------
        //
        // Checked first and alone: the remaining comparisons are meaningless
        // for a value the column could not even hold.
        $rules = [];

        foreach ($members as $field => $value) {
            if ($value < 0 || $value > $this->bounds->maxReportedValue) {
                $rules[] = ['code' => 'value_out_of_domain', 'field' => $field, 'observed' => $value];
            }
        }

        if ($rules !== []) {
            return new RunClassification(RunStatus::Rejected, $windowMs, $rules);
        }

        $duration = $telemetry->reportedDurationMs;
        $score = $telemetry->reportedScore;
        $paws = $telemetry->reportedRunPaws;

        // --- Structural: impossible within the domain ---------------------
        if ($duration === 0) {
            $rules[] = ['code' => 'duration_non_positive', 'observed' => $duration];
        }

        $maxDuration = $windowMs + $this->bounds->durationToleranceMs;

        if ($duration > $maxDuration) {
            $rules[] = ['code' => 'duration_exceeds_server_window', 'observed' => $duration, 'bound' => $maxDuration];
        }

        $pawFloor = $this->bounds->scorePerPaw * $paws;

        if ($score < $pawFloor) {
            $rules[] = ['code' => 'score_below_paw_floor', 'observed' => $score, 'bound' => $pawFloor];
        }

        if ($rules !== []) {
            return new RunClassification(RunStatus::Rejected, $windowMs, $rules);
        }

        // --- Plausibility: PROPOSED tuning, flag only ---------------------
        if ($score * 1000 > $this->bounds->flagMaxScorePerSecond * $duration) {
            $rules[] = ['code' => 'score_rate_high', 'observed' => $score, 'bound' => $this->bounds->flagMaxScorePerSecond];
        }

        if ($paws * 1000 > $this->bounds->flagMaxPawsPerSecond * $duration) {
            $rules[] = ['code' => 'paw_rate_high', 'observed' => $paws, 'bound' => $this->bounds->flagMaxPawsPerSecond];
        }

        if ($score * 1000 < $this->bounds->flagMinScorePerSecond * $duration) {
            $rules[] = ['code' => 'score_below_duration_floor', 'observed' => $score, 'bound' => $this->bounds->flagMinScorePerSecond];
        }

        if ($duration < $this->bounds->flagMinDurationMs) {
            $rules[] = ['code' => 'duration_below_minimum', 'observed' => $duration, 'bound' => $this->bounds->flagMinDurationMs];
        }

        return new RunClassification(
            $rules === [] ? RunStatus::Accepted : RunStatus::Flagged,
            $windowMs,
            $rules,
        );
    }
}
