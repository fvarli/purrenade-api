<?php

declare(strict_types=1);

namespace App\Services\Runs;

/**
 * The numbers `RunValidator` compares against, read once from
 * `config/game_runs.php`. Kept as a value object so the validator stays pure —
 * no configuration repository, no container — and a unit test can hand it any
 * bounds it likes.
 */
final readonly class RunValidationBounds
{
    public function __construct(
        public int $durationToleranceMs,
        public int $maxReportedValue,
        public int $scorePerPaw,
        public int $flagMaxScorePerSecond,
        public int $flagMaxPawsPerSecond,
        public int $flagMinScorePerSecond,
        public int $flagMinDurationMs,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `game_runs` configuration array.
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, int> $flag */
        $flag = $config['flag'];

        return new self(
            durationToleranceMs: (int) $config['duration_tolerance_ms'],
            maxReportedValue: (int) $config['max_reported_value'],
            scorePerPaw: (int) $config['score_per_paw'],
            flagMaxScorePerSecond: (int) $flag['max_score_per_second'],
            flagMaxPawsPerSecond: (int) $flag['max_paws_per_second'],
            flagMinScorePerSecond: (int) $flag['min_score_per_second'],
            flagMinDurationMs: (int) $flag['min_duration_ms'],
        );
    }
}
