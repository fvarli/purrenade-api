<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates real concurrent requests for the concurrency suite.
 *
 * Each request runs in its own PHP process on its own PostgreSQL connection
 * (`tests/Concurrency/worker.php`), so locks, snapshots and unique-index waits
 * are PostgreSQL's real behaviour rather than a reproduction of it.
 *
 * Interleavings are made **deterministic** with two tools, neither of which
 * touches application code:
 *
 * - **Pause points.** A test-only trigger on a table makes the write of one
 *   tagged request wait on an advisory lock the orchestrator holds. The request
 *   is then parked mid-transaction, holding exactly the locks it has taken so
 *   far, until the orchestrator releases it.
 * - **Observed waits.** `pg_stat_activity` shows when a request is blocked on a
 *   lock, so the orchestrator proceeds only once the interleaving it wants has
 *   actually happened — never on a sleep.
 */
final class RunWorkers
{
    public const PAUSE_LOCK = 424242;

    /** @var list<array{process: resource, pipes: array<int, resource>}> */
    private array $running = [];

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     * @return array{process: resource, pipes: array<int, resource>, tag: string}
     */
    public function spawn(string $tag, string $method, string $uri, array $headers, ?array $body): array
    {
        $spec = json_encode(compact('tag', 'method', 'uri', 'headers', 'body'), JSON_THROW_ON_ERROR);

        $env = [
            ...getenv(),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_SEARCH_PATH' => (string) config('database.connections.pgsql.search_path'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BCRYPT_ROUNDS' => '4',
        ];

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/Concurrency/worker.php', $spec],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env,
        );

        if (! is_resource($process)) {
            throw new RuntimeException("Could not start worker {$tag}.");
        }

        $worker = ['process' => $process, 'pipes' => $pipes, 'tag' => $tag];
        $this->running[] = $worker;

        return $worker;
    }

    /**
     * Wait until the tagged request is blocked on a lock — an advisory pause,
     * a row lock, or another transaction's speculative insert.
     */
    public function waitUntilBlocked(string $tag, float $timeoutSeconds = 20.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $waiting = DB::connection('pgsql_observer')->scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                [$tag],
            );

            if ((int) $waiting > 0) {
                return;
            }

            usleep(10_000);
        }

        throw new RuntimeException("Worker {$tag} never blocked on a lock.");
    }

    /**
     * Read a worker's answer, waiting for it to finish.
     *
     * @param  array{process: resource, pipes: array<int, resource>, tag: string}  $worker
     * @return array{status: int, body: mixed}
     */
    public function result(array $worker, float $timeoutSeconds = 30.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $stderr = '';

        stream_set_blocking($worker['pipes'][1], false);
        stream_set_blocking($worker['pipes'][2], false);

        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($worker['pipes'][1]);
            $stderr .= (string) stream_get_contents($worker['pipes'][2]);

            if (! proc_get_status($worker['process'])['running']) {
                $stdout .= (string) stream_get_contents($worker['pipes'][1]);
                $stderr .= (string) stream_get_contents($worker['pipes'][2]);

                $line = trim($stdout);

                if ($line === '') {
                    throw new RuntimeException("Worker {$worker['tag']} produced no result. stderr: {$stderr}");
                }

                /** @var array{status: int, body: mixed} */
                return json_decode(strtok($line, "\n") ?: $line, true, 64, JSON_THROW_ON_ERROR);
            }

            usleep(10_000);
        }

        proc_terminate($worker['process'], 9);

        throw new RuntimeException("Worker {$worker['tag']} timed out. stderr: {$stderr}");
    }

    /** Is the tagged request still running (not yet answered)? */
    public function stillRunning(array $worker): bool
    {
        return proc_get_status($worker['process'])['running'];
    }

    /**
     * Park any write of `$table` by the tagged request (AFTER INSERT or UPDATE)
     * on the orchestrator's advisory lock.
     */
    public function pauseOn(string $table, string $event, string $tag): void
    {
        DB::unprepared("
            CREATE OR REPLACE FUNCTION purrenade_test_pause() RETURNS trigger AS \$\$
            BEGIN
                IF current_setting('application_name') = TG_ARGV[0] THEN
                    PERFORM pg_advisory_xact_lock(".self::PAUSE_LOCK.');
                END IF;
                RETURN NULL;
            END $$ LANGUAGE plpgsql;
        ');

        $name = "purrenade_test_pause_{$table}_".strtolower($event);

        DB::unprepared("DROP TRIGGER IF EXISTS {$name} ON {$table}");
        DB::unprepared("CREATE TRIGGER {$name} AFTER {$event} ON {$table} FOR EACH ROW EXECUTE FUNCTION purrenade_test_pause('{$tag}')");
    }

    public function holdPause(): void
    {
        DB::connection('pgsql_holder')->select('SELECT pg_advisory_lock(?)', [self::PAUSE_LOCK]);
    }

    public function releasePause(): void
    {
        DB::connection('pgsql_holder')->select('SELECT pg_advisory_unlock(?)', [self::PAUSE_LOCK]);
    }

    /** Remove every pause trigger and stop any worker still running. */
    public function cleanUp(): void
    {
        foreach ($this->running as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process'], 9);
            }
        }

        $this->running = [];

        DB::connection('pgsql_holder')->select('SELECT pg_advisory_unlock_all()');

        if (DB::connection('pgsql_holder')->transactionLevel() > 0) {
            DB::connection('pgsql_holder')->rollBack();
        }

        foreach (DB::select("SELECT tgname, tgrelid::regclass::text AS tbl FROM pg_trigger WHERE tgname LIKE 'purrenade_test_pause_%'") as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger->tgname} ON {$trigger->tbl}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS purrenade_test_pause()');
    }
}
