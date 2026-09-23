<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One paw delta credited by one accepted run. Append-only.
 *
 * `bonuses_triggered` counts 200-paw **threshold crossings** — accounting only.
 * It is never a Loli Bonus activation and must never feed one (ANTI-6).
 *
 * @property int $id
 * @property int $user_id
 * @property string $run_id
 * @property int $delta
 * @property int $resulting_cycle
 * @property int $bonuses_triggered
 * @property Carbon $created_at
 */
class PawLedgerEntry extends Model
{
    protected $table = 'paw_ledger';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'resulting_cycle' => 'integer',
            'bonuses_triggered' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
