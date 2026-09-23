<?php

declare(strict_types=1);

namespace App\Services\Runs;

use App\Models\Run;

/**
 * The run a start call answers with, and whether it was created by this call.
 *
 * `created` is false for a resume — the player's existing active run, returned
 * unchanged with its own character, seed and `started_at` — and for the rare
 * start that lost a creation race to a concurrent one.
 */
final readonly class StartedRun
{
    public function __construct(
        public Run $run,
        public int $loliCyclePaws,
        public bool $created,
    ) {}
}
