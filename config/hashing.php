<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Password hashing driver
    |--------------------------------------------------------------------------
    |
    | **argon2id**, not bcrypt, and the reason is not fashion.
    |
    | 1. docs/security/authentication.md §2 makes "a modern memory-hard
    |    algorithm with tuned cost parameters" an APPROVED requirement. Bcrypt is
    |    CPU-hard but not memory-hard, so it is cheap to attack with GPUs and
    |    custom hardware in a way argon2id is deliberately not.
    |
    | 2. Bcrypt silently ignores everything after the 72nd byte. The password
    |    policy allows 128 characters (App\Support\PasswordPolicy), so over
    |    bcrypt a long passphrase would be truncated without anyone being told —
    |    two different passwords that differ only past byte 72 would both open
    |    the account. Argon2id has no such limit.
    |
    | 3. `Hash::needsRehash()` reports true when these parameters change, and the
    |    login controller rehashes transparently, so tightening the costs
    |    upgrades accounts as their owners sign in.
    |
    | **There is no bcrypt migration path, deliberately.** With `argon.verify`
    | true, the argon hasher refuses a `$2y$` hash outright rather than falling
    | back to PHP's algorithm dispatch — that refusal is what prevents algorithm
    | confusion, and it is worth keeping. It is free here because this driver was
    | chosen before the first account existed, so no stored hash is bcrypt. Were
    | that ever untrue, the migration would be a deliberate rehash-on-login
    | window, not a silently relaxed flag.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt options
    |--------------------------------------------------------------------------
    |
    | Retained because the framework reads them when something asks for bcrypt
    | explicitly. Nothing in this application does.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon options
    |--------------------------------------------------------------------------
    |
    | Above PHP's defaults (64 MiB / 4 passes / 1 thread), following the OWASP
    | Password Storage guidance for argon2id.
    |
    | `memory` is the parameter that matters: it is what makes parallel attack
    | hardware expensive, and it is also what bounds how many logins a server can
    | process at once. 64 MiB × the number of concurrent password verifications
    | is real memory the application must have — which is why `threads` stays at
    | 1 and the login endpoints are rate-limited. A password hash is the one
    | place where being slow is the feature; it is also a denial-of-service lever
    | if left unbounded, and the two limiters in
    | App\Providers\RateLimitServiceProvider are what bound it.
    |
    | Environment-driven so a host with different headroom can tune without a
    | code change, and so CI can lower the cost without weakening production.
    |
    */

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash on login
    |--------------------------------------------------------------------------
    |
    | Framework-level rehashing is left off: this application does it explicitly
    | in App\Http\Controllers\Auth\LoginController, where the plaintext is
    | already in hand and the behaviour is visible in the code that owns the
    | login path rather than implied by a flag.
    |
    */

    'rehash_on_login' => false,

];
