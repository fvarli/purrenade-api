<?php

declare(strict_types=1);

namespace App\Services\Replay;

use RuntimeException;

/**
 * Storing a finish's replay input failed. It fails the whole finish: the
 * acceptance transaction rolls back and the run stays active.
 *
 * It replaces the database exception and **never chains it**. That exception's
 * message interpolates the INSERT's bindings — the encrypted envelope — and
 * PostgreSQL's own detail repeats the failing row, so it must not reach a log,
 * a failure record or a response (data-protection §5). What survives is a fixed
 * code and the SQLSTATE, which names the condition without carrying data.
 */
final class ReplayInputPersistenceFailed extends RuntimeException
{
    public const CODE = 'replay_input_persistence_failed';

    public static function withSqlState(mixed $sqlState): self
    {
        $state = is_string($sqlState) && preg_match('/^[0-9A-Z]{5}$/', $sqlState) === 1 ? $sqlState : 'unknown';

        return new self(self::CODE.' (SQLSTATE '.$state.')');
    }
}
