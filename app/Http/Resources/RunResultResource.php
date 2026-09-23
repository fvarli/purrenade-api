<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `RunResult` schema — the authoritative outcome of a finish.
 *
 * The payload is built once, inside the finish transaction, and stored with the
 * run; this resource only wraps it. A replay therefore serves the stored copy
 * rather than recomputing anything, which is what makes it the *original*
 * result (GR-3).
 *
 * Deliberately absent: `derived_facts` and every near-miss, obstacle-pass,
 * SLAYYY or Loli-activation number (ANTI-6).
 */
final class RunResultResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->resource;

        return $result;
    }
}
