<?php

declare(strict_types=1);

namespace App\Services\Replay;

/**
 * One pinned replay bundle, as its manifest describes it (O9).
 *
 * `resources/replay/domain-<domain_version>/` holds a byte copy of the web
 * repository's built `purrenade-replay.mjs`, a byte copy of its replay golden,
 * and `manifest.json`:
 *
 *     { "domain_version", "sha256", "node_major", "web_commit", "golden_sha256" }
 *
 * The first four members are the web build's own manifest, verbatim;
 * `golden_sha256` is the API's addition. See docs/architecture/replay-runtime.md.
 */
final readonly class ReplayBundle
{
    public function __construct(
        public string $domainVersion,
        public string $directory,
        public string $bundlePath,
        public string $goldenPath,
        public string $sha256,
        public int $nodeMajor,
        public string $webCommit,
        public string $goldenSha256,
    ) {}

    /** Does the installed bundle still match its pin, byte for byte? */
    public function bundleIntact(): bool
    {
        return is_file($this->bundlePath)
            && hash_equals($this->sha256, (string) hash_file('sha256', $this->bundlePath));
    }

    public function goldenIntact(): bool
    {
        return is_file($this->goldenPath)
            && hash_equals($this->goldenSha256, (string) hash_file('sha256', $this->goldenPath));
    }
}
