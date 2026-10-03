<?php

declare(strict_types=1);

namespace App\Services\Replay;

use App\Enums\ReplayOutcome;

/**
 * The classified result of one replay. `facts` is set only when the outcome is
 * `established`; `reasons` only when it is `replay_inconsistent` (which of the
 * accepted record's fields disagreed — codes, never values).
 */
final readonly class ReplayVerdict
{
    /**
     * @param  array{cone_safe_passes: int, near_misses: int, slayyy_activations: int}|null  $facts
     * @param  list<string>  $reasons
     */
    public function __construct(
        public ReplayOutcome $outcome,
        public ?array $facts = null,
        public array $reasons = [],
        public ?int $loliActivations = null,
    ) {}

    public static function of(ReplayOutcome $outcome): self
    {
        return new self($outcome);
    }
}
