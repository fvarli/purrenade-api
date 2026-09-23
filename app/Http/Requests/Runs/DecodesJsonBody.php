<?php

declare(strict_types=1);

namespace App\Http\Requests\Runs;

/**
 * Validate the request body exactly as the client sent it.
 *
 * The global middleware trims strings and turns empty strings into null. That
 * is a convenience for forms and wrong for a protocol whose types are part of
 * the contract: `" aysenur "` is not `"aysenur"`, and a check on the
 * transformed value would be a check on something the client never sent. So
 * the run endpoints validate the raw decoded JSON. A body that is not a JSON
 * object validates as empty, and every required member then fails.
 */
trait DecodesJsonBody
{
    /**
     * @return array<string, mixed>
     */
    protected function decodedBody(): array
    {
        try {
            $decoded = json_decode($this->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }
}
