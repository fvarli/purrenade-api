<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every terminal outcome of an ANTI-6 replay work row, as a closed set
 * (architecture §15). The database refuses any other value.
 *
 * Only `Established` produces evidence. Every other outcome leaves the run's
 * replay evidence **ABSENT** — and none of them, in any class, changes the
 * run's status or its Loli evidence (O1, ANTI-6-M).
 *
 * - **Class B** (deterministic or contract failure): `InputMalformed`,
 *   `VersionUnsupported`, `ResultInvalid`, `InputExpired`, `RunNotAccepted`.
 *   Terminal at once, never retried.
 * - **Class C**: `ReplayInconsistent` — the only review signal.
 * - **Class A exhausted**: `AttemptsExhausted`.
 */
enum ReplayOutcome: string
{
    case Established = 'established';
    case InputMalformed = 'input_malformed';
    case VersionUnsupported = 'version_unsupported';
    case ResultInvalid = 'result_invalid';
    case InputExpired = 'input_expired';
    case RunNotAccepted = 'run_not_accepted';
    case AttemptsExhausted = 'attempts_exhausted';
    case ReplayInconsistent = 'replay_inconsistent';
}
