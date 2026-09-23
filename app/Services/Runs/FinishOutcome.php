<?php

declare(strict_types=1);

namespace App\Services\Runs;

/**
 * The authoritative answer to a finish, and whether it was a replay.
 *
 * `result` is the `RunResult` payload exactly as it was stored with the run the
 * first time. A replay returns the stored copy and changes nothing, so it is
 * semantically equal to the original response — the same status and the same
 * decoded `data` — though not byte-identical: `jsonb` does not keep key order.
 */
final readonly class FinishOutcome
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public array $result,
        public bool $replayed,
    ) {}
}
