<?php

declare(strict_types=1);

namespace App\Http\Requests\Runs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /game-runs — shape only (C-11 step 0).
 *
 * `character_id` must be present, a JSON string, and match
 * `^[a-z0-9_]{1,32}$` — the grammar of `characters.key`. Failing that is a 422
 * **even when the player has an active run**: a malformed request is refused
 * before any state is consulted.
 *
 * Whether the character is *available* is deliberately not asked here. That
 * question exists only when a new run has to be created, and is answered inside
 * the start transaction (`RunLifecycleService::start()`).
 */
final class StartRunRequest extends FormRequest
{
    use DecodesJsonBody;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `\z`, not `$`: PCRE's `$` also matches before a trailing newline.
            'character_id' => ['required', 'string', 'regex:/^[a-z0-9_]{1,32}\z/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->decodedBody();
    }

    public function characterKey(): string
    {
        return (string) $this->validated('character_id');
    }
}
