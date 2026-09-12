<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The database half of the readiness check.
 *
 * A separate class rather than a few lines in the controller for one reason
 * that matters: the failure path has to be provable. Swapping this out of the
 * container in a test exercises the 503 branch honestly, without taking the
 * real database down, corrupting configuration mid-request, or any of the other
 * tricks that make such a test lie later.
 */
final readonly class DatabaseHealth
{
    /**
     * @param  string|null  $connection  Which connection readiness means. Null is the
     *                                   default connection, which is the answer for a
     *                                   service with one database. Named explicitly so
     *                                   a deployment with a replica can probe the one
     *                                   that actually matters — and so a test can point
     *                                   it at an unreachable server without disturbing
     *                                   the connection its own transaction runs on.
     */
    public function __construct(
        private DatabaseManager $database,
        private LoggerInterface $logger,
        private ?string $connection = null,
    ) {}

    /**
     * Can the application reach its default database connection?
     *
     * The cheapest statement that proves a real round trip: it needs a live
     * connection, an accepted authentication, and a responsive server, but
     * reads no table and so cannot be defeated by an empty schema.
     *
     * Any failure is logged in full server-side and reported to the caller as
     * nothing more than "error". The exception message routinely carries the
     * host, port, database name, role and driver detail — none of which an
     * unauthenticated caller has any business learning from a probe.
     */
    public function isReachable(): bool
    {
        try {
            $this->database->connection($this->connection)->select('select 1');

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Health check: database unreachable.', [
                'exception' => $e,
            ]);

            return false;
        }
    }
}
