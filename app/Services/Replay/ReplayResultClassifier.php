<?php

declare(strict_types=1);

namespace App\Services\Replay;

use App\Enums\ReplayOutcome;
use JsonException;

/**
 * Turns a replay process's answer into a verdict (architecture §15). Pure.
 *
 * - The process refused the input → `input_malformed` / `version_unsupported`.
 * - The answer is not the documented shape, echoes another protocol or
 *   version, or describes an impossible run → `result_invalid`.
 * - A well-formed replay that does not reproduce the accepted score, paws or
 *   duration → `replay_inconsistent` (class C). The whole triple is withheld:
 *   a stream that does not reproduce the accepted record is not trustworthy for
 *   any fact derived from it.
 * - Otherwise → `established`, with the three replay facts.
 *
 * Loli is never taken from here as evidence: `loli_activations` is reported
 * only for the operational canary against the frozen P1 evidence.
 */
final class ReplayResultClassifier
{
    public const PROTOCOL = 1;

    /** The facts a counter may reach is far below this; anything above is not a fact. */
    private const FACT_CEILING = 2_147_483_647;

    /**
     * @param  array{score: int, run_paws: int, duration_ms: int}  $accepted
     */
    public function classify(ReplayProcessResult $result, string $domainVersion, int $totalSteps, array $accepted): ReplayVerdict
    {
        if ($result->stdoutOverflowed) {
            return ReplayVerdict::of(ReplayOutcome::ResultInvalid);
        }

        try {
            $answer = json_decode(trim($result->stdout), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ReplayVerdict::of(ReplayOutcome::ResultInvalid);
        }

        if (! is_array($answer)
            || ($answer['protocol'] ?? null) !== self::PROTOCOL
            || ($answer['domain_version'] ?? null) !== $domainVersion
        ) {
            return ReplayVerdict::of(ReplayOutcome::ResultInvalid);
        }

        $status = $answer['status'] ?? null;

        if ($status === 'invalid_input') {
            return ReplayVerdict::of(ReplayOutcome::InputMalformed);
        }

        if ($status === 'unsupported_version') {
            return ReplayVerdict::of(ReplayOutcome::VersionUnsupported);
        }

        $final = $answer['final'] ?? null;
        $facts = $answer['facts'] ?? null;

        if ($status !== 'completed' || ! is_array($final) || ! is_array($facts)) {
            return ReplayVerdict::of(ReplayOutcome::ResultInvalid);
        }

        foreach (['elapsed_ms_floor', 'score', 'run_paws', 'steps_consumed'] as $field) {
            if (! $this->isCount($final[$field] ?? null)) {
                return ReplayVerdict::of(ReplayOutcome::ResultInvalid);
            }
        }

        foreach (['cone_safe_passes', 'near_misses', 'slayyy_activations', 'loli_activations'] as $field) {
            if (! $this->isCount($facts[$field] ?? null)) {
                return ReplayVerdict::of(ReplayOutcome::ResultInvalid);
            }
        }

        // An impossible deterministic result: the run did not end, or the
        // stream was not consumed exactly.
        if (($final['ended'] ?? null) !== true || $final['steps_consumed'] !== $totalSteps) {
            return ReplayVerdict::of(ReplayOutcome::ResultInvalid);
        }

        $reasons = [];

        if ($final['score'] !== $accepted['score']) {
            $reasons[] = 'score';
        }

        if ($final['run_paws'] !== $accepted['run_paws']) {
            $reasons[] = 'run_paws';
        }

        if ($final['elapsed_ms_floor'] !== $accepted['duration_ms']) {
            $reasons[] = 'duration';
        }

        if ($reasons !== []) {
            return new ReplayVerdict(ReplayOutcome::ReplayInconsistent, reasons: $reasons);
        }

        return new ReplayVerdict(
            ReplayOutcome::Established,
            facts: [
                'cone_safe_passes' => $facts['cone_safe_passes'],
                'near_misses' => $facts['near_misses'],
                'slayyy_activations' => $facts['slayyy_activations'],
            ],
            loliActivations: $facts['loli_activations'],
        );
    }

    private function isCount(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= self::FACT_CEILING;
    }
}
