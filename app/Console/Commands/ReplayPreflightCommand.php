<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Replay\NodeReplayRunner;
use App\Services\Replay\ReplayBundle;
use App\Services\Replay\ReplayBundles;
use App\Services\Replay\ReplayRunner;
use App\Services\Replay\ReplayRunnerFailure;
use Illuminate\Console\Command;
use JsonException;

/**
 * Verify the ANTI-6 replay runtime before a deploy changes anything (O9; owner
 * decision D3: a hard deploy gate, run before backup and migration).
 *
 * 1. `REPLAY_NODE_BINARY` is an absolute path to an executable file.
 * 2. It reports the expected Node major (24), which every manifest also names.
 * 3. Every pinned bundle and golden exists and matches its manifest's SHA-256,
 *    and every manifest comes from a clean web commit.
 * 4. **Golden self-replay**: every golden case replays through the real runner,
 *    with the real process contract, to exactly its expected answer.
 *
 * It prints codes and versions only, and exits non-zero on any failure. It
 * changes nothing. A failure here stops a deployment; it never touches a run.
 * At runtime the same conditions fail closed per replay, to ABSENT evidence.
 */
final class ReplayPreflightCommand extends Command
{
    protected $signature = 'replay:preflight';

    protected $description = 'Verify the pinned Node runtime and replay bundles (ANTI-6 P3 deploy gate)';

    private bool $failed = false;

    public function handle(ReplayBundles $bundles, ReplayRunner $runner): int
    {
        $expectedMajor = (int) config('replay.node_major');
        $node = NodeReplayRunner::nodeBinary();

        if ($node === null) {
            $this->failCheck('node_binary_invalid', 'REPLAY_NODE_BINARY must be an absolute path to an executable file');
        } else {
            $version = $this->nodeVersion($node);
            $major = preg_match('/^v(\d+)\.\d+\.\d+$/', $version, $m) === 1 ? (int) $m[1] : null;

            $major === $expectedMajor
                ? $this->line("ok node {$version}")
                : $this->failCheck('node_major_mismatch', "expected Node {$expectedMajor}");
        }

        $installed = $bundles->all();

        if ($installed === []) {
            $this->failCheck('no_bundle', 'no pinned replay bundle is installed');
        }

        foreach ($installed as $name => $bundle) {
            if (! $bundle instanceof ReplayBundle) {
                $this->failCheck('manifest_invalid', $name);

                continue;
            }

            if ($bundle->nodeMajor !== $expectedMajor) {
                $this->failCheck('manifest_node_major', $name);
            }

            if (! $bundle->bundleIntact()) {
                $this->failCheck('bundle_hash_mismatch', $name);

                continue;
            }

            if (! $bundle->goldenIntact()) {
                $this->failCheck('golden_hash_mismatch', $name);

                continue;
            }

            $this->line("ok bundle {$name} sha256 {$bundle->sha256} web {$bundle->webCommit}");

            if ($node !== null) {
                $this->selfReplay($bundle, $runner, $name);
            }
        }

        if ($this->failed) {
            $this->error('replay preflight FAILED');

            return self::FAILURE;
        }

        $this->info('replay preflight passed');

        return self::SUCCESS;
    }

    private function selfReplay(ReplayBundle $bundle, ReplayRunner $runner, string $name): void
    {
        try {
            $cases = json_decode((string) file_get_contents($bundle->goldenPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->failCheck('golden_invalid', $name);

            return;
        }

        if (! is_array($cases) || $cases === []) {
            $this->failCheck('golden_invalid', $name);

            return;
        }

        $passed = 0;

        foreach ($cases as $case) {
            if (! is_array($case) || ! is_array($case['document'] ?? null) || ! is_array($case['expected'] ?? null)) {
                $this->failCheck('golden_invalid', $name);

                return;
            }

            try {
                $result = $runner->run($bundle, $case['document']);
                $answer = json_decode(trim($result->stdout), true, 8, JSON_THROW_ON_ERROR);
            } catch (ReplayRunnerFailure $e) {
                $this->failCheck('golden_replay_failed', "{$name} {$e->getMessage()}");

                return;
            } catch (JsonException) {
                $this->failCheck('golden_replay_failed', "{$name} unreadable answer");

                return;
            }

            if ($result->stdoutOverflowed || $result->stderrPresent || $answer !== $case['expected']) {
                $this->failCheck('golden_mismatch', $name);

                return;
            }

            $passed++;
        }

        $this->line("ok golden {$name} {$passed}/".count($cases));
    }

    private function nodeVersion(string $node): string
    {
        $process = @proc_open([$node, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, NodeReplayRunner::childEnvironment());

        if (! is_resource($process)) {
            return '';
        }

        $version = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $version;
    }

    private function failCheck(string $code, string $detail): void
    {
        $this->failed = true;
        $this->line("FAIL {$code} {$detail}");
    }
}
