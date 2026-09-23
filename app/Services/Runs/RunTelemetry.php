<?php

declare(strict_types=1);

namespace App\Services\Runs;

/**
 * The three untrusted numbers a finish proposes (M9 finish payload).
 *
 * Integers by protocol: anything that is not a JSON integer is refused with a
 * 422 before one of these is ever built (C-7). Being an `int` here says the
 * value is well-formed — not that it is plausible, and never that it is true.
 * `App\Services\Runs\RunValidator` decides what, if anything, it is worth.
 */
final readonly class RunTelemetry
{
    public function __construct(
        public int $reportedDurationMs,
        public int $reportedScore,
        public int $reportedRunPaws,
    ) {}
}
