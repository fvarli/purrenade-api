<?php

declare(strict_types=1);

namespace App\Services\Replay;

/**
 * What a replay process answered. `stdout` is the bounded answer line;
 * `stdoutOverflowed` means it exceeded the bound and was cut off. stderr is
 * never kept — only whether there was any — because it is diagnostic output
 * that must never reach a log verbatim.
 */
final readonly class ReplayProcessResult
{
    public function __construct(
        public string $stdout,
        public bool $stdoutOverflowed,
        public bool $stderrPresent,
        public int $elapsedMs,
    ) {}
}
