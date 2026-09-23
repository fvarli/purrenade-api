<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Run lifecycle and validation parameters (M9)
|--------------------------------------------------------------------------
|
| ADR-0006 delegates these to M9 as engineering parameters. Each is labelled
| with what kind of number it is, because the difference decides what it may
| do:
|
|   STRUCTURAL  tuning-independent. May REJECT.
|   PROPOSED    derived from tuning values that are still PROPOSED in the
|               frontend's docs/game/tuning-parameters.md. May only FLAG — a
|               legitimate run is never rejected on an unresolved tuning number
|               (ANTI-4). Recorded there in §13 "Server plausibility bounds".
|
| None of these is read from the environment. They are part of the validation
| contract, and a value that differed between environments would make a run's
| classification depend on where it was submitted.
|
*/

return [

    /*
     * RUN_STALE_REPLACEMENT_AFTER — 24 hours.
     *
     * NOT an expiry. There is no scheduler and no time-based transition: an
     * untouched active run stays active indefinitely and its finish is accepted
     * whenever it arrives. The threshold acts only when the same player calls
     * Start: within it, Start resumes the active run; beyond it, Start may
     * replace the run (`rejected` / `run_stale_replaced`) — and only when a new
     * run can actually be created in the same transaction.
     */
    'stale_replacement_after_seconds' => 86400,

    /*
     * RUN_DURATION_TOLERANCE_MS — STRUCTURAL slack, 5 seconds.
     *
     * Simulated play time can never exceed the server's own wall-clock window
     * (finished_at − started_at): both ends are server clock, and the domain's
     * elapsed time excludes pauses and dropped catch-up steps. The tolerance
     * absorbs server clock step adjustments (NTP). It only ever loosens the
     * rejection, never tightens it.
     */
    'duration_tolerance_ms' => 5000,

    /*
     * The largest value any telemetry member may take: the PostgreSQL `integer`
     * domain its column stores. STRUCTURAL — outside it is `value_out_of_domain`.
     */
    'max_reported_value' => 2147483647,

    /*
     * `score.perPaw` is APPROVED and LOCKED at 10, and every score multiplier is
     * at least 1, so `score >= 10 × run_paws` is STRUCTURAL.
     */
    'score_per_paw' => 10,

    /*
     * PROPOSED — flag-only plausibility bounds.
     */
    'flag' => [
        // Points per second of claimed play. The theoretical maximum from the
        // PROPOSED speed and spawn tuning is ≈ 78.6 even with a permanent ×2.
        'max_score_per_second' => 80,

        // Paws per second. PROPOSED peak spawn is ≤ 2.08/s.
        'max_paws_per_second' => 3,

        // Half the PROPOSED base-speed distance score (`distancePerSecond` 10).
        'min_score_per_second' => 5,

        // PROPOSED `firstHazardMinMs` 2500 plus two invulnerability windows of
        // 1200 ms puts the fastest three-heart loss near 4900 ms. The bound sits
        // deliberately below that, at 4000, so it flags only clearly
        // impossible-looking runs.
        'min_duration_ms' => 4000,
    ],

];
