<?php

declare(strict_types=1);

use App\Services\Replay\NodeReplayRunner;
use App\Services\Replay\ReplayBundle;
use App\Services\Replay\ReplayBundles;
use App\Services\Replay\ReplayRunnerFailure;
use Illuminate\Support\Facades\Artisan;

/**
 * The real process contract, against the real pinned bundle on the real Node
 * 24 (group `replay-node`).
 *
 * Excluded from the default run, because it needs Node 24 at
 * `REPLAY_NODE_BINARY`; the CI `replay` job runs it explicitly, and there a
 * missing binary FAILS — it is never skipped. Locally:
 *
 *     REPLAY_NODE_BINARY=/abs/path/to/node24 vendor/bin/pest --group=replay-node
 */
pest()->group('replay-node');

beforeEach(function (): void {
    $node = (string) getenv('REPLAY_NODE_BINARY');

    expect($node)->not->toBe('', 'REPLAY_NODE_BINARY must name the Node 24 executable for this group');
    config(['replay.node_binary' => $node]);
});

function fixtureBundle(string $script): ReplayBundle
{
    $dir = base_path('tests/Fixtures/replay');

    return new ReplayBundle('1', $dir, $dir.'/'.$script, '', '', 24, '', '');
}

function pinnedBundle(): ReplayBundle
{
    $bundle = app(ReplayBundles::class)->find('1');
    expect($bundle)->toBeInstanceOf(ReplayBundle::class);

    return $bundle;
}

it('replays every pinned golden case to exactly its expected answer', function (): void {
    $bundle = pinnedBundle();
    $cases = json_decode((string) file_get_contents($bundle->goldenPath), true, 512, JSON_THROW_ON_ERROR);

    expect($cases)->toHaveCount(4);

    foreach ($cases as $case) {
        $result = (new NodeReplayRunner)->run($bundle, $case['document']);

        expect(json_decode($result->stdout, true))->toBe($case['expected'], $case['name'])
            ->and($result->stderrPresent)->toBeFalse()
            ->and($result->stdoutOverflowed)->toBeFalse();
    }
});

it('passes the production preflight', function (): void {
    expect(Artisan::call('replay:preflight'))->toBe(0)
        ->and(Artisan::output())->toContain('ok golden domain-1 4/4');
});

it('gives the process no secret, no NODE_OPTIONS and nothing from the host', function (): void {
    $seeded = [
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'DB_PASSWORD' => 'secret-db-password',
        'NODE_OPTIONS' => '--require=/tmp/evil.js',
        'PURRENADE_API_TOKEN' => 'secret-token',
        'UNRELATED_X' => 'host-noise',
    ];

    foreach ($seeded as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        $result = (new NodeReplayRunner)->run(fixtureBundle('print-env.mjs'), []);
    } finally {
        foreach (array_keys($seeded) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    $env = json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR);

    foreach (array_keys($seeded) as $name) {
        expect($env)->not->toHaveKey($name);
    }

    foreach (array_keys($env) as $name) {
        expect(NodeReplayRunner::ENV_ALLOWLIST)->toContain($name);
    }

    foreach ($env as $value) {
        expect((string) $value)->not->toContain('secret');
    }
});

it('delivers a maximal document whole through stdin', function (): void {
    $document = ['input' => ['events' => array_fill(0, 20_000, [432_000, 9])]];

    $result = (new NodeReplayRunner)->run(fixtureBundle('echo-stdin.mjs'), $document);

    expect((int) $result->stdout)->toBe(strlen(json_encode($document)));
});

it('kills a process that runs past the timeout, as a transient failure', function (): void {
    config(['replay.timeout_seconds' => 1]);
    $started = microtime(true);

    expect(fn () => (new NodeReplayRunner)->run(fixtureBundle('hang.mjs'), []))
        ->toThrow(ReplayRunnerFailure::class, ReplayRunnerFailure::TIMED_OUT);

    expect(microtime(true) - $started)->toBeLessThan(5.0);
});

it('cuts off an answer longer than its bound', function (): void {
    $result = (new NodeReplayRunner)->run(fixtureBundle('flood.mjs'), []);

    expect($result->stdoutOverflowed)->toBeTrue()
        ->and(strlen($result->stdout))->toBe((int) config('replay.stdout_max_bytes'));
});

it('treats a crash as transient, and never carries its stderr in the failure', function (): void {
    $caught = null;

    try {
        (new NodeReplayRunner)->run(fixtureBundle('crash.mjs'), []);
    } catch (ReplayRunnerFailure $e) {
        $caught = $e;
    }

    // Exactly the fixed code: the fixture's stderr is not in it.
    expect($caught?->getMessage())->toBe(ReplayRunnerFailure::CRASHED);
});

it('treats a missing executable as transient, never a PATH lookup', function (string $node): void {
    config(['replay.node_binary' => $node]);

    expect(fn () => (new NodeReplayRunner)->run(pinnedBundle(), []))
        ->toThrow(ReplayRunnerFailure::class, ReplayRunnerFailure::NODE_UNAVAILABLE);
})->with(['', 'node', '/nonexistent/node']);
