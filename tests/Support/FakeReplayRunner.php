<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Replay\ReplayBundle;
use App\Services\Replay\ReplayProcessResult;
use App\Services\Replay\ReplayRunner;
use App\Services\Replay\ReplayRunnerFailure;

/**
 * A replay runner that answers what the test tells it to, without Node.
 *
 * The real process contract is covered by the `replay-node` group against the
 * pinned bundle; this lets every other test choose an answer exactly.
 */
final class FakeReplayRunner implements ReplayRunner
{
    /** @var list<array<string, mixed>> */
    public array $documents = [];

    public function __construct(
        private string|ReplayRunnerFailure $answer,
        private bool $overflowed = false,
    ) {}

    /** A completed replay that reproduces the given accepted record. */
    public static function completed(int $score, int $paws, int $durationMs, int $totalSteps, array $facts = []): self
    {
        return new self(json_encode([
            'protocol' => 1,
            'domain_version' => '1',
            'status' => 'completed',
            'final' => ['ended' => true, 'elapsed_ms_floor' => $durationMs, 'score' => $score, 'run_paws' => $paws, 'steps_consumed' => $totalSteps],
            'facts' => ['cone_safe_passes' => 3, 'near_misses' => 2, 'slayyy_activations' => 1, 'loli_activations' => 0, ...$facts],
        ], JSON_THROW_ON_ERROR)."\n");
    }

    public static function failing(string $reason = ReplayRunnerFailure::CRASHED): self
    {
        return new self(ReplayRunnerFailure::because($reason));
    }

    public function run(ReplayBundle $bundle, array $document): ReplayProcessResult
    {
        $this->documents[] = $document;

        if ($this->answer instanceof ReplayRunnerFailure) {
            throw $this->answer;
        }

        return new ReplayProcessResult($this->answer, $this->overflowed, false, 1);
    }
}
