<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Runs\StartedRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `StartedRun` schema: the server-owned facts of a run a client may play.
 *
 * `character_id` is the catalogue's public **key**, reached through the
 * relation; the internal bigint never leaves the server. On a resume every
 * field is the existing run's own — its original character, seed and
 * `started_at` — whatever the request asked for.
 *
 * `seed` is a JSON integer in `0..4294967295`. `started_at` is UTC with
 * millisecond precision, the precision it is stored at. `loli_cycle_paws` is
 * the player's persistent Loli progress, which the run starts from.
 *
 * Fresh versus resumed is carried by the status code (201 / 200), not a field.
 *
 * @mixin StartedRun
 */
final class StartedRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StartedRun $started */
        $started = $this->resource;
        $run = $started->run;

        return [
            'run_id' => $run->id,
            'character_id' => $run->character->key,
            'seed' => $run->seed,
            'started_at' => $run->started_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'loli_cycle_paws' => $started->loliCyclePaws,
        ];
    }
}
