<?php

declare(strict_types=1);

namespace App\Services\Replay;

/**
 * One replay, one short-lived Node process (O9; architecture §6.1, §6.5).
 *
 * ## The runtime contract
 *
 * - **Executable:** `config('replay.node_binary')`, which must be an absolute
 *   path to an executable file — the host's pinned Node 24. It is never resolved
 *   through `PATH`, and nothing falls back to "whatever `node` is".
 * - **Command:** `<node> --max-old-space-size=<mb> <bundle>`, exactly as the web
 *   repository's bundle check verifies it.
 * - **Environment:** built from nothing. The child receives only
 *   `ENV_ALLOWLIST`, which is empty: no application secret, no `APP_KEY`, no
 *   database credential, no token, no inherited `NODE_OPTIONS` (which could load
 *   arbitrary code), and nothing else from the host. Node 24 and the bundle
 *   need no variable; a variable is added here only with a proven runtime need,
 *   recorded in docs/architecture/replay-runtime.md.
 * - **Working directory:** the bundle's own directory.
 * - **stdin:** one compact JSON document. **stdout:** one line, bounded by
 *   `replay.stdout_max_bytes`. **stderr:** drained and discarded; only its
 *   presence is reported.
 * - **Timeout:** a hard `SIGKILL` after `replay.timeout_seconds`.
 */
final class NodeReplayRunner implements ReplayRunner
{
    /**
     * Variables the replay process is given, by name, from this process's
     * environment. Deliberately empty.
     *
     * @var list<string>
     */
    public const ENV_ALLOWLIST = [];

    public function run(ReplayBundle $bundle, array $document): ReplayProcessResult
    {
        $node = self::nodeBinary();

        if ($node === null) {
            throw ReplayRunnerFailure::because(ReplayRunnerFailure::NODE_UNAVAILABLE);
        }

        $stdin = json_encode($document, JSON_THROW_ON_ERROR);
        $stdoutMax = (int) config('replay.stdout_max_bytes');
        $deadline = microtime(true) + (float) config('replay.timeout_seconds');
        $started = hrtime(true);

        $process = @proc_open(
            [$node, '--max-old-space-size='.(int) config('replay.max_old_space_mb'), $bundle->bundlePath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $bundle->directory,
            self::childEnvironment(),
        );

        if (! is_resource($process)) {
            throw ReplayRunnerFailure::because(ReplayRunnerFailure::SPAWN_FAILED);
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdout = '';
        $overflowed = false;
        $stderrPresent = false;
        $written = 0;
        $exitCode = null;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $stdinPipe = $pipes[0];

        try {
            while (true) {
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 9);

                    throw ReplayRunnerFailure::because(ReplayRunnerFailure::TIMED_OUT);
                }

                $read = array_values($open);
                $write = $stdinPipe !== null ? [$stdinPipe] : [];
                $except = null;

                if ($read !== [] || $write !== []) {
                    @stream_select($read, $write, $except, 0, 50_000);
                }

                if ($stdinPipe !== null && $write !== []) {
                    $chunk = @fwrite($stdinPipe, substr($stdin, $written, 65536));
                    $written += $chunk === false ? 0 : $chunk;

                    if ($chunk === false || $written >= strlen($stdin)) {
                        fclose($stdinPipe);
                        $stdinPipe = null;
                    }
                }

                foreach ($read as $stream) {
                    $data = (string) fread($stream, 65536);

                    if ($stream === ($open[1] ?? null)) {
                        $stdout .= $data;

                        if (strlen($stdout) > $stdoutMax) {
                            $overflowed = true;
                            $stdout = substr($stdout, 0, $stdoutMax);
                            proc_terminate($process, 9);
                            unset($open[1]);
                        }
                    } elseif ($data !== '') {
                        $stderrPresent = true;
                    }

                    if (feof($stream)) {
                        unset($open[array_search($stream, $open, true)]);
                    }
                }

                $status = proc_get_status($process);

                if (! $status['running']) {
                    $exitCode ??= $status['exitcode'];

                    if ($open === []) {
                        break;
                    }
                }
            }
        } finally {
            if ($stdinPipe !== null) {
                fclose($stdinPipe);
            }

            foreach ($open as $stream) {
                fclose($stream);
            }

            proc_close($process);
        }

        $elapsedMs = intdiv(hrtime(true) - $started, 1_000_000);

        if ($overflowed) {
            return new ReplayProcessResult($stdout, true, $stderrPresent, $elapsedMs);
        }

        if ($exitCode !== 0) {
            throw ReplayRunnerFailure::because(ReplayRunnerFailure::CRASHED);
        }

        return new ReplayProcessResult($stdout, false, $stderrPresent, $elapsedMs);
    }

    /**
     * The configured executable, if it is an absolute path to an executable
     * file. Never a `PATH` lookup.
     */
    public static function nodeBinary(): ?string
    {
        $node = (string) config('replay.node_binary');

        if ($node === '' || ! str_starts_with($node, '/') || ! is_file($node) || ! is_executable($node)) {
            return null;
        }

        return $node;
    }

    /**
     * The child's whole environment: the allow-listed names, nothing else.
     *
     * @return array<string, string>
     */
    public static function childEnvironment(): array
    {
        return array_intersect_key(getenv(), array_flip(self::ENV_ALLOWLIST));
    }
}
