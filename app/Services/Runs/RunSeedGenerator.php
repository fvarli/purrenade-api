<?php

declare(strict_types=1);

namespace App\Services\Runs;

/**
 * Issues the authoritative RNG seed for a new normal run (RNG-1).
 *
 * A uint32 — `0..4294967295`, the domain the frontend's `createRunState`
 * accepts — drawn from the CSPRNG. It is exact as a JSON integer in PHP,
 * PostgreSQL `bigint` and JavaScript (well below 2^53), so it crosses every
 * boundary as a number and never needs a string encoding.
 *
 * A class of its own so a test can substitute one that fails, to prove that a
 * start which cannot create its run rolls back entirely.
 */
class RunSeedGenerator
{
    public const MAX = 4294967295;

    public function next(): int
    {
        return random_int(0, self::MAX);
    }
}
