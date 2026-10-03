<?php

declare(strict_types=1);

namespace App\Services\Replay;

use RuntimeException;

/**
 * A transient replay failure (class A), retried by the job.
 *
 * The message is always one of the fixed reason codes below and nothing else:
 * an exception message lands in `failed_jobs` and in logs, so it can never
 * carry the input, the process's output or its stderr.
 */
final class ReplayRunnerFailure extends RuntimeException
{
    public const NODE_UNAVAILABLE = 'replay_node_unavailable';

    public const SPAWN_FAILED = 'replay_spawn_failed';

    public const TIMED_OUT = 'replay_timed_out';

    public const CRASHED = 'replay_crashed';

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
