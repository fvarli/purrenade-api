<?php

declare(strict_types=1);

namespace App\Services\Replay;

use JsonException;

/**
 * The pinned replay bundles installed in this repository (O9).
 *
 * A manifest that is missing, malformed, or not from a clean web commit makes
 * its bundle **not installed**: a replay for that domain version ends as
 * `version_unsupported`, evidence ABSENT, and the run stays accepted. The pin
 * fails closed; it never fails open.
 */
final class ReplayBundles
{
    public const BUNDLE_FILE = 'purrenade-replay.mjs';

    public const GOLDEN_FILE = 'golden.json';

    public const MANIFEST_FILE = 'manifest.json';

    public function __construct(
        private readonly string $root,
    ) {}

    public static function fromConfig(): self
    {
        return new self((string) config('replay.bundle_root'));
    }

    public function find(string $domainVersion): ?ReplayBundle
    {
        if (preg_match(ReplayInputParser::DOMAIN_VERSION_PATTERN, $domainVersion) !== 1) {
            return null;
        }

        return $this->load($this->root.'/domain-'.$domainVersion, $domainVersion);
    }

    /**
     * Every `domain-*` directory, whether or not its manifest is valid — so a
     * broken pin is reported by preflight rather than silently skipped.
     *
     * @return array<string, ReplayBundle|null> keyed by directory name
     */
    public function all(): array
    {
        $found = [];

        foreach (glob($this->root.'/domain-*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            $found[$name] = $this->load($directory, substr($name, strlen('domain-')));
        }

        ksort($found);

        return $found;
    }

    private function load(string $directory, string $domainVersion): ?ReplayBundle
    {
        $manifestPath = $directory.'/'.self::MANIFEST_FILE;

        if (! is_file($manifestPath)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($manifest)
            || array_keys($manifest) !== ['domain_version', 'sha256', 'node_major', 'web_commit', 'golden_sha256']
            || $manifest['domain_version'] !== $domainVersion
            || ! is_string($manifest['sha256']) || preg_match('/^[0-9a-f]{64}$/', $manifest['sha256']) !== 1
            || ! is_string($manifest['golden_sha256']) || preg_match('/^[0-9a-f]{64}$/', $manifest['golden_sha256']) !== 1
            || ! is_int($manifest['node_major'])
            // A clean, full commit only: never `<sha>-dirty`, `unknown` or a short SHA.
            || ! is_string($manifest['web_commit']) || preg_match('/^[0-9a-f]{40}$/', $manifest['web_commit']) !== 1
        ) {
            return null;
        }

        return new ReplayBundle(
            domainVersion: $domainVersion,
            directory: $directory,
            bundlePath: $directory.'/'.self::BUNDLE_FILE,
            goldenPath: $directory.'/'.self::GOLDEN_FILE,
            sha256: $manifest['sha256'],
            nodeMajor: $manifest['node_major'],
            webCommit: $manifest['web_commit'],
            goldenSha256: $manifest['golden_sha256'],
        );
    }
}
