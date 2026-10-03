<?php

declare(strict_types=1);

namespace App\Services\Replay;

/**
 * A structurally valid canonical replay input (format 1): the domain `step()`
 * invocation stream, never raw browser events (ADR-0006 Amendment A, RNG-2).
 *
 * Untrusted and transient. It is never logged, never part of the finish
 * fingerprint, and never evidence: only a replay that reproduces the accepted
 * run establishes anything.
 */
final readonly class ReplayInput
{
    /**
     * @param  list<array{0: int, 1: int}>  $events  `[gap, code]` pairs.
     */
    public function __construct(
        public int $formatVersion,
        public string $domainVersion,
        public int $totalSteps,
        public array $events,
    ) {}

    /**
     * The canonical re-encoding that is stored: exactly the four members, in a
     * fixed order, compact. Anything else the client sent is never persisted.
     */
    public function canonicalJson(): string
    {
        return json_encode([
            'format_version' => $this->formatVersion,
            'domain_version' => $this->domainVersion,
            'total_steps' => $this->totalSteps,
            'events' => $this->events,
        ], JSON_THROW_ON_ERROR);
    }
}
