<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A catalogue entry: the character a run is played as.
 *
 * `key` is the public identifier — the `character_id` string the API speaks.
 * `id` is internal and never serialised. The rows arrive with the schema (see
 * the `create_characters_table` migration), and M9 never mutates them.
 *
 * Nothing in the backend branches on a particular character. Whether one may
 * start a new run is one generic rule, {@see self::scopeSelectableForNewRun()}.
 *
 * @property int $id
 * @property string $key
 * @property bool $is_starter
 * @property bool $artwork_available
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Character extends Model
{
    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_starter' => 'boolean',
            'artwork_available' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * May a brand-new run be started as this character?
     *
     * M9's rule: a starter whose artwork ships. Unlock materialisation — the
     * second route to selectable, through Büşo's and Ogito's criteria — is M11.
     * This is consulted **only** when a start has to create a run; resuming an
     * active run never asks it (C-11).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSelectableForNewRun(Builder $query): Builder
    {
        return $query->where('is_starter', true)->where('artwork_available', true);
    }
}
