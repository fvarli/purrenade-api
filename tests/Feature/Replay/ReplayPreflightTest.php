<?php

declare(strict_types=1);

use App\Services\Replay\ReplayBundles;
use App\Services\Replay\ReplayRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\FakeReplayRunner;

/**
 * `replay:preflight` — the deploy's application-side hard gate. Each failure
 * path is proven without Node; the passing path, which needs the real Node 24,
 * is in the `replay-node` group.
 */
function preflightRoot(): string
{
    $root = sys_get_temp_dir().'/replay-preflight-'.Str::random(8);
    mkdir($root.'/domain-1', 0777, true);

    foreach (['manifest.json', 'golden.json', 'purrenade-replay.mjs'] as $file) {
        copy(resource_path('replay/domain-1/'.$file), $root.'/domain-1/'.$file);
    }

    app()->instance(ReplayBundles::class, new ReplayBundles($root));

    return $root;
}

function removePreflightRoot(string $root): void
{
    foreach (glob($root.'/*/*') ?: [] as $file) {
        unlink($file);
    }
    foreach (glob($root.'/*') ?: [] as $dir) {
        rmdir($dir);
    }
    rmdir($root);
}

function preflight(): array
{
    $code = Artisan::call('replay:preflight');

    return [$code, Artisan::output()];
}

it('fails without an absolute, executable Node', function (string $value): void {
    config(['replay.node_binary' => $value]);

    [$code, $output] = preflight();

    expect($code)->toBe(1)->and($output)->toContain('FAIL node_binary_invalid');
})->with(['', 'node', '/nonexistent/node', '/etc/hostname']);

it('fails on a Node that is not major 24', function (): void {
    // /bin/true is executable and prints nothing: no version, no major.
    config(['replay.node_binary' => '/bin/true']);

    [$code, $output] = preflight();

    expect($code)->toBe(1)->and($output)->toContain('FAIL node_major_mismatch');
});

it('fails when the bundle no longer matches its pin', function (): void {
    $root = preflightRoot();
    file_put_contents($root.'/domain-1/purrenade-replay.mjs', "\n// tampered", FILE_APPEND);

    [$code, $output] = preflight();
    removePreflightRoot($root);

    expect($code)->toBe(1)->and($output)->toContain('FAIL bundle_hash_mismatch domain-1');
});

it('fails when the golden no longer matches its pin', function (): void {
    $root = preflightRoot();
    file_put_contents($root.'/domain-1/golden.json', ' ', FILE_APPEND);

    [$code, $output] = preflight();
    removePreflightRoot($root);

    expect($code)->toBe(1)->and($output)->toContain('FAIL golden_hash_mismatch domain-1');
});

it('fails on a manifest that is not from a clean web commit', function (): void {
    $root = preflightRoot();
    $manifest = json_decode((string) file_get_contents($root.'/domain-1/manifest.json'), true);
    $manifest['web_commit'] .= '-dirty';
    file_put_contents($root.'/domain-1/manifest.json', json_encode($manifest));

    [$code, $output] = preflight();
    removePreflightRoot($root);

    expect($code)->toBe(1)->and($output)->toContain('FAIL manifest_invalid domain-1');
});

it('fails when no bundle is pinned at all', function (): void {
    $root = sys_get_temp_dir().'/replay-preflight-'.Str::random(8);
    mkdir($root);
    app()->instance(ReplayBundles::class, new ReplayBundles($root));

    [$code, $output] = preflight();
    rmdir($root);

    expect($code)->toBe(1)->and($output)->toContain('FAIL no_bundle');
});

it('fails when the golden self-replay does not reproduce the expected answer', function (): void {
    config(['replay.node_binary' => replayNodeBinaryOrSelf()]);
    app()->instance(ReplayRunner::class, FakeReplayRunner::completed(1, 1, 1, 1));

    [$code, $output] = preflight();

    expect($code)->toBe(1)->and($output)->toContain('FAIL golden_mismatch domain-1');
});

/**
 * Any absolute executable that answers `--version` like Node 24, so the
 * self-replay step is reached with a fake runner: a tiny script.
 */
function replayNodeBinaryOrSelf(): string
{
    $script = sys_get_temp_dir().'/fake-node-'.getmypid();
    file_put_contents($script, "#!/bin/sh\necho v24.0.0\n");
    chmod($script, 0755);

    return $script;
}
