<?php

declare(strict_types=1);

namespace App\Services\Replay;

use App\Enums\ReplayOutcome;

/**
 * What the finish boundary made of a `replay_input`: usable, or unusable with
 * the terminal outcome it will be recorded as. Never a 422, and never an input
 * to classification (architecture §5).
 */
final readonly class ReplayInputCandidate
{
    private function __construct(
        public ?ReplayInput $input,
        public ?ReplayOutcome $unusable,
    ) {}

    public static function usable(ReplayInput $input): self
    {
        return new self($input, null);
    }

    public static function unusable(ReplayOutcome $outcome): self
    {
        return new self(null, $outcome);
    }

    public function isUsable(): bool
    {
        return $this->input instanceof ReplayInput;
    }
}
