<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a run is in its lifecycle, and how it was classified.
 *
 * One `active` state and three terminal outcomes (ADR-0006, ANTI-2). Only
 * **accepted** mutates progression, the paw ledger, the personal best or the
 * accepted run count; **flagged** and **rejected** are recorded and change
 * nothing else. There is no transition out of a terminal state — no automatic
 * promotion from flagged, and no silent conversion of a rejection.
 *
 * The database enforces the same four values with a CHECK built from these
 * cases, so a stray write cannot invent a fifth.
 */
enum RunStatus: string
{
    case Active = 'active';
    case Accepted = 'accepted';
    case Flagged = 'flagged';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }
}
