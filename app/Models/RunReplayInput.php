<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Transient replay work for one accepted run (ANTI-6 P3). **Never evidence**
 * (W0): nothing that computes achievements, progression or rankings may read
 * it.
 *
 * `input` — the encrypted canonical stream — is hidden and never cast: only
 * `App\Services\Replay\ReplayInputStore` reads it, and only for the replay job.
 * Read-only by convention; every write goes through that store or
 * `ReplayEvidenceService`.
 *
 * @property string $run_id
 * @property string $state
 * @property string|null $outcome_code
 * @property int $attempts
 * @property Carbon|null $input_expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RunReplayInput extends Model
{
    protected $table = 'run_replay_inputs';

    protected $primaryKey = 'run_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['input'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'input_expires_at' => 'datetime',
        ];
    }
}
