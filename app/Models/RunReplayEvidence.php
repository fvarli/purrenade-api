<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The replay evidence of one accepted run. Insert-only (ANTI-6, Option D).
 *
 * Written only by `App\Services\Replay\ReplayEvidenceService`, in the replay
 * commit transaction, all three facts together. A run with no row has ABSENT
 * replay evidence; a 0 is PRESENT 0.
 *
 * @property string $run_id
 * @property int $cone_safe_passes
 * @property int $near_misses
 * @property int $slayyy_activations
 * @property int $evidence_version
 * @property string $domain_version
 * @property Carbon $established_at
 */
class RunReplayEvidence extends Model
{
    /**
     * The fact definitions as of ANTI-6 P2: a cone is exactly `lane_blocking`
     * (O4), an unprotected genuine avoidance that also produced a near miss
     * counts as a safe pass (O5), and the three near-miss cases in code (O6).
     */
    public const EVIDENCE_VERSION = 1;

    protected $table = 'run_replay_evidence';

    protected $primaryKey = 'run_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cone_safe_passes' => 'integer',
            'near_misses' => 'integer',
            'slayyy_activations' => 'integer',
            'evidence_version' => 'integer',
            'established_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
