<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ANTI-6 replay evidence (P3)
|--------------------------------------------------------------------------
|
| Layer 3 deterministic replay is a post-acceptance evidence mechanism only
| (ADR-0006 Amendment A, ANTI-1). Nothing here can change whether a run is
| accepted: every failure ends as ABSENT replay evidence.
|
| Only the Node executable comes from the environment, because only it differs
| between hosts. Everything else is part of the replay contract
| (docs/architecture/replay-runtime.md) and must not vary by environment.
|
*/

return [

    /*
     * The absolute path of the host's pinned Node 24 executable (O9). Never
     * resolved through PATH: an empty or relative value is a preflight failure
     * and a transient job failure, never a guess.
     */
    'node_binary' => env('REPLAY_NODE_BINARY', ''),

    /* The Node major every pinned bundle was built and verified for. */
    'node_major' => 24,

    /* Where the pinned bundles live: one `domain-<DOMAIN_VERSION>/` each. */
    'bundle_root' => resource_path('replay'),

    /*
     * The dedicated queue, served by its own worker unit
     * (`purrenade-replay-worker.service`), so replay CPU time can never sit in
     * front of an authentication or email job.
     */
    'queue' => 'replay',

    /* Hard wall-clock kill for one replay process. */
    'timeout_seconds' => 15,

    /* V8 old-space ceiling, as the web's bundle check runs it. */
    'max_old_space_mb' => 128,

    /* The replay answers one small fixed-shape line; anything longer is invalid. */
    'stdout_max_bytes' => 65536,

    /*
     * The domain's fixed simulation rate (web `TUNING.sim.fixedStepHz`), used
     * only to bound `total_steps` by the server-measured run window. A bound,
     * never a simulation: PHP never runs the game rules (ANTI-1).
     */
    'fixed_step_hz' => 120,

    /*
     * The finish route's authoritative request-body limit, 256 KiB (owner
     * decision 2026-10-03). The largest conforming body is about 220.2 KB.
     */
    'max_body_bytes' => 262144,

];
