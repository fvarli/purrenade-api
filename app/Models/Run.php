<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One authoritative normal run.
 *
 * Created by the server before gameplay (`active`) and finalised once into
 * `accepted`, `flagged` or `rejected`. The lifecycle lives in
 * `App\Services\Runs\RunLifecycleService`; the invariants that must hold under
 * concurrency live in the database (see the `create_runs_table` migration).
 *
 * `character_id` is the internal catalogue id. The API speaks the character's
 * public `key`, reached through {@see self::character()}.
 *
 * @property string $id
 * @property int $user_id
 * @property int $character_id
 * @property RunStatus $status
 * @property int $seed
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $score
 * @property int|null $run_paws
 * @property array<string, mixed>|null $validation_meta
 * @property array<string, mixed>|null $result
 * @property string|null $idempotency_key
 * @property string|null $idempotency_fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Character $character
 */
class Run extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'seed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'score' => 'integer',
            'run_paws' => 'integer',
            'validation_meta' => 'array',
            'result' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Character, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
