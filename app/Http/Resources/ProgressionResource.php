<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlayerProgression;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The player's durable progression, as the `Progression` schema.
 *
 * Three paw concepts, never collapsed into one: `lifetime_paws` (a statistic,
 * never consumed), `loli_cycle_paws` (progress toward the next bonus, 0..199)
 * and the constant `loli_threshold`. `best_score` and the accepted `run_count`
 * are the facts Büşo's and Ogito's criteria read.
 *
 * **Deliberately absent:** the four `lifetime_*` `DERIVED_TELEMETRY` counters
 * (ANTI-6) and any queued or owed Loli Bonus — neither exists anywhere in M9.
 *
 * The same projection is embedded in every run result, so it is built by one
 * static method rather than twice.
 *
 * @mixin PlayerProgression
 */
final class ProgressionResource extends JsonResource
{
    public function __construct(PlayerProgression $resource, private readonly bool $tutorialCompleted)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PlayerProgression $progression */
        $progression = $this->resource;

        return self::payload($progression, $this->tutorialCompleted);
    }

    /**
     * @return array{lifetime_paws: int, loli_cycle_paws: int, loli_threshold: int, best_score: int, run_count: int, tutorial_completed: bool}
     */
    public static function payload(PlayerProgression $progression, bool $tutorialCompleted): array
    {
        return [
            'lifetime_paws' => (int) $progression->lifetime_paws,
            'loli_cycle_paws' => (int) $progression->loli_cycle_paws,
            'loli_threshold' => PlayerProgression::LOLI_THRESHOLD,
            'best_score' => (int) $progression->best_score,
            'run_count' => (int) $progression->run_count,
            'tutorial_completed' => $tutorialCompleted,
        ];
    }
}
