<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Loli evidence of one accepted run. Insert-only (ANTI-6, Option B).
 *
 * Written only by `App\Services\Runs\RunLifecycleService`, in the acceptance
 * transaction, and never updated or deleted — the database refuses both. A run
 * with no row has ABSENT Loli evidence; `loli_activations = 0` is PRESENT 0.
 * Nothing may substitute `paw_ledger`, progression or `lifetime_paws` for it.
 *
 * @property string $run_id
 * @property int $loli_activations
 * @property int $evidence_version
 * @property Carbon $established_at
 */
class RunLoliEvidence extends Model
{
    /**
     * `loli_activations = floor((start_loli_cycle_paws + run_paws) / threshold)`,
     * valid while the web domain's no-queue invariant (I-LOLI) holds.
     */
    public const EVIDENCE_VERSION = 1;

    protected $table = 'run_loli_evidence';

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
            'loli_activations' => 'integer',
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
