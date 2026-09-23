<?php

declare(strict_types=1);

namespace App\Services\Runs;

use App\Enums\RunStatus;

/**
 * What `RunValidator` decided about one finish, and why.
 *
 * `rules` is the minimum validation metadata persisted with the run: each hit's
 * stable code plus the observed value and the bound it crossed, so a flag or a
 * rejection can be explained later without keeping anything else.
 */
final readonly class RunClassification
{
    /**
     * @param  list<array{code: string, field?: string, observed?: int, bound?: int}>  $rules
     */
    public function __construct(
        public RunStatus $status,
        public int $windowMs,
        public array $rules,
    ) {}

    /**
     * The stable reason codes, de-duplicated, in the order they were hit.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $rule): string => $rule['code'],
            $this->rules,
        )));
    }

    /**
     * @return array{v: int, window_ms: int, rules: list<array<string, int|string>>}
     */
    public function meta(): array
    {
        return ['v' => 1, 'window_ms' => $this->windowMs, 'rules' => $this->rules];
    }
}
