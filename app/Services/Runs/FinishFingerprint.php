<?php

declare(strict_types=1);

namespace App\Services\Runs;

/**
 * The effective-request fingerprint of a finish (GR-3).
 *
 * What makes "same key, different request → 409" enforceable: the key alone
 * says a retry is claimed; the fingerprint says whether it is the same request.
 *
 * Covers exactly the facts the server acts on — the run and the three telemetry
 * integers — and nothing else. Unknown body members, including any ANTI-6
 * counter a client sends, never reach it, so they cannot make a genuine retry
 * look different. The run id is included, so reusing a key on another run is a
 * different request.
 *
 * Canonical form, versioned so a future change cannot collide with this one:
 *
 *     sha256("finish:v1\n" + lower(run_id) + "\n" + duration + "\n" + score + "\n" + paws)
 *
 * The integers are base-10 with no padding. Only integers reach this point —
 * the protocol refuses every other JSON number type with a 422 (C-7) — so there
 * is no float canonicalisation to get wrong.
 */
final class FinishFingerprint
{
    public static function of(string $runId, RunTelemetry $telemetry): string
    {
        return hash('sha256', implode("\n", [
            'finish:v1',
            strtolower($runId),
            (string) $telemetry->reportedDurationMs,
            (string) $telemetry->reportedScore,
            (string) $telemetry->reportedRunPaws,
        ]));
    }
}
