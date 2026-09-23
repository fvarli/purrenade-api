<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player's durable progression. One row per player, keyed by `user_id`.
 *
 * Every counter here is written only by an **accepted** run, in one atomic
 * `UPDATE`, by `App\Services\Runs\RunLifecycleService` — never by
 * read-modify-write through this model. `tutorial_completed_at` is written only
 * by `App\Services\Progression\ProgressionService::completeTutorial()`.
 *
 * The row is created lazily and idempotently by
 * `ProgressionService::ensure()`, so a missing row simply reads as zeros.
 *
 * @property int $user_id
 * @property int $lifetime_paws
 * @property int $loli_cycle_paws
 * @property int $best_score
 * @property int $run_count
 * @property Carbon|null $tutorial_completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlayerProgression extends Model
{
    /** APPROVED (`paw.loliThreshold`). The cycle holds 0..199; overflow carries. */
    public const LOLI_THRESHOLD = 200;

    protected $table = 'player_progression';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifetime_paws' => 'integer',
            'loli_cycle_paws' => 'integer',
            'best_score' => 'integer',
            'run_count' => 'integer',
            'tutorial_completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
