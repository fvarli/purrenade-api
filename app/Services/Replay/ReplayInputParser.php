<?php

declare(strict_types=1);

namespace App\Services\Replay;

use App\Enums\ReplayOutcome;

/**
 * The finish boundary for `replay_input` (architecture §5, §6.4). Pure.
 *
 * It decides only *usable* or *unusable*. It never produces a 422 and never
 * touches classification: a log that is wrong in any way costs the run its
 * replay evidence, and nothing else (ANTI-4).
 *
 * The rules mirror the replay program's own boundary (web
 * `game/replay/replay.ts`), stricter only in that a JSON number must decode to
 * a PHP int — `12.0` is refused here, where JavaScript would accept it. Version
 * mismatches are `version_unsupported`; everything else is `input_malformed`.
 *
 * The caps are the P3 contract values (finalised from P2's PROPOSED
 * `MAX_STREAM_EVENTS` / `MAX_STREAM_STEPS`). They bound CPU per replay, the
 * stored size (`ReplayInputStore::MAX_STORED_BYTES`) and the request body.
 */
final class ReplayInputParser
{
    public const FORMAT_VERSION = 1;

    /** One hour at 120 Hz. */
    public const MAX_STEPS = 432_000;

    public const MAX_EVENTS = 20_000;

    /** Fixed-step input codes are 0..6; zero-delta control codes are 7..9. */
    public const MAX_STEP_CODE = 6;

    public const MAX_CODE = 9;

    public const DOMAIN_VERSION_PATTERN = '/^[0-9]{1,8}$/';

    /**
     * @return ReplayInputCandidate|null null when there is no input at all
     *                                   (absent or JSON `null`).
     */
    public function parse(mixed $value): ?ReplayInputCandidate
    {
        if ($value === null) {
            return null;
        }

        $malformed = ReplayInputCandidate::unusable(ReplayOutcome::InputMalformed);

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            return $malformed;
        }

        if (($value['format_version'] ?? null) !== self::FORMAT_VERSION) {
            return ReplayInputCandidate::unusable(ReplayOutcome::VersionUnsupported);
        }

        $domainVersion = $value['domain_version'] ?? null;

        if (! is_string($domainVersion) || preg_match(self::DOMAIN_VERSION_PATTERN, $domainVersion) !== 1) {
            return $malformed;
        }

        $totalSteps = $value['total_steps'] ?? null;

        if (! is_int($totalSteps) || $totalSteps < 0 || $totalSteps > self::MAX_STEPS) {
            return $malformed;
        }

        $events = $value['events'] ?? null;

        if (! is_array($events) || ! array_is_list($events) || count($events) > self::MAX_EVENTS) {
            return $malformed;
        }

        $canonical = [];
        $position = 0;
        $stepInputAt = -1;

        foreach ($events as $event) {
            if (! is_array($event) || ! array_is_list($event) || count($event) !== 2) {
                return $malformed;
            }

            [$gap, $code] = $event;

            if (! is_int($gap) || $gap < 0 || $gap > self::MAX_STEPS || ! is_int($code) || $code < 0 || $code > self::MAX_CODE) {
                return $malformed;
            }

            $position += $gap;

            if ($code <= self::MAX_STEP_CODE) {
                // A step input belongs to the step at this position, which must exist.
                if ($position >= $totalSteps) {
                    return $malformed;
                }

                $stepInputAt = $position;
            } elseif ($position > $totalSteps || $stepInputAt === $position) {
                // A control precedes the step at its position; one may follow the last step.
                return $malformed;
            }

            $canonical[] = [$gap, $code];
        }

        return ReplayInputCandidate::usable(new ReplayInput(
            self::FORMAT_VERSION,
            $domainVersion,
            $totalSteps,
            $canonical,
        ));
    }
}
