<?php

declare(strict_types=1);

namespace App\Services\Replay;

/**
 * Runs one replay document through a pinned bundle and returns the raw answer.
 *
 * Implementations throw `ReplayRunnerFailure` for every **transient** failure
 * (class A: spawn failure, missing executable, timeout, crash, non-zero exit),
 * which the job retries. Anything the process actually answered is returned
 * for classification, never interpreted here.
 */
interface ReplayRunner
{
    /**
     * @param  array<string, mixed>  $document  The stdin document (architecture §6.5).
     *
     * @throws ReplayRunnerFailure
     */
    public function run(ReplayBundle $bundle, array $document): ReplayProcessResult;
}
