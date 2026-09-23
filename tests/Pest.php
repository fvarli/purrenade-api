<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application; unit tests deliberately do not, so that
| logic which does not need the framework is proven not to need it.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
|
| Feature tests get a freshly migrated database inside a transaction that is
| rolled back afterwards, so no test can see another's rows.
|
| The suite runs in its own PostgreSQL schema (phpunit.xml sets
| DB_SEARCH_PATH), which is what makes this safe: RefreshDatabase drops and
| rebuilds everything in the search path, and sharing `public` with local
| development would destroy the developer's data on every run.
|
| Applied here rather than as a `uses()` line in every file, so a new test
| cannot forget it and silently leave rows behind.
|
*/

pest()->use(RefreshDatabase::class)->in('Feature');

/*
| The concurrency suite is the one exception, deliberately: it must commit, so
| that a second connection can observe what the first did. It isolates itself
| by truncating the test schema around each test instead — see
| tests/Concurrency/RunConcurrencyTest.php.
*/
pest()->extend(TestCase::class)->group('concurrency')->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Breach checking
|--------------------------------------------------------------------------
|
| The password policy includes `uncompromised()`, which queries the Pwned
| Passwords range API. Left live, every test that creates an account would make
| an HTTP request: slow, and red whenever the network or that service is having
| a bad day — and a suite that fails for reasons unrelated to the code is a
| suite people learn to ignore.
|
| So the verifier is stubbed permissive by default. The rule is still genuinely
| tested: PasswordPolicyTest binds a verifier that reports *compromised* and
| asserts registration is refused, which proves the rule is wired without
| depending on a third party being reachable.
|
*/

pest()->beforeEach(function (): void {
    app()->bind(UncompromisedVerifier::class, fn (): UncompromisedVerifier => new class implements UncompromisedVerifier
    {
        public function verify($data): bool
        {
            return true;
        }
    });
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Authenticate as a real Sanctum session.
 *
 * A genuine token and a genuine `Authorization` header, not `Sanctum::actingAs`.
 * The difference matters: `actingAs` installs a `TransientToken`, which is not
 * this application's `PersonalAccessToken` and carries no abilities — so the
 * admin gate's "did this session pass a two-factor challenge?" check could
 * never be exercised, and the tests that matter most would be testing a fake.
 *
 * @return array<string, string> Headers for the request helpers.
 */
function sessionFor(
    User $user,
    bool $twoFactorSatisfied = false,
    string $device = 'Chrome on Linux',
): array {
    $abilities = [PersonalAccessToken::ABILITY_SESSION];

    if ($twoFactorSatisfied) {
        $abilities[] = PersonalAccessToken::ABILITY_TWO_FACTOR;
    }

    $token = $user->createToken($device, $abilities);

    if ($twoFactorSatisfied) {
        // Stamp the generation, exactly as SessionIssuer does. A satisfied
        // session is one that met the account's *current* second factor, and a
        // helper that minted the ability without the generation would be
        // fabricating a state the application cannot produce — every admin test
        // would then be passing against a token the middleware is meant to
        // refuse. To age a session deliberately, use staleTwoFactorSessionFor().
        $token->accessToken->forceFill([
            'two_factor_version' => $user->two_factor_version,
        ])->save();
    }

    return ['Authorization' => 'Bearer '.$token->plainTextToken];
}

/**
 * A valid TOTP code for this instant, from the account's real secret.
 *
 * Computed from the stored secret rather than mocked, so the tests exercise the
 * actual TOTP arithmetic, the actual clock-skew window, and the actual replay
 * rejection.
 */
/**
 * A session that passed a challenge against a second factor the account no
 * longer has.
 *
 * The state an attacker is left holding after the owner rotates a stolen
 * authenticator: the ability is real and was honestly earned, but the secret it
 * attested to is gone. Produced by stamping an older generation, which is what
 * the account's own history would have left behind.
 */
function staleTwoFactorSessionFor(User $user, string $device = 'Chrome on Linux'): array
{
    $headers = sessionFor($user, twoFactorSatisfied: true, device: $device);

    $user->tokens()->latest('id')->first()?->forceFill([
        'two_factor_version' => $user->two_factor_version - 1,
    ])->save();

    return $headers;
}
function currentTotpCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $user->two_factor_secret);
}

/**
 * The code for a later time step.
 *
 * Google2FA reads the wall clock directly, so `travel()` does not move it: a
 * test that spends a step and then needs another cannot wait its way out
 * without actually waiting. Asking for the next step's code is the same thing
 * the authenticator would show thirty seconds later, without the thirty
 * seconds.
 */
function totpCodeAtNextStep(User $user, int $steps = 1): string
{
    $google2fa = app(Google2FA::class);

    return $google2fa->oathTotp(
        (string) $user->two_factor_secret,
        $google2fa->getTimestamp() + $steps,
    );
}

/**
 * Issue a fresh recovery-code set and return the plaintext.
 *
 * Recovery codes are stored as a keyed hash and are unrecoverable afterwards, so
 * a test that needs a working one has to be handed it at issue time — exactly as
 * a player is.
 *
 * @return list<string>
 */
function issueRecoveryCodes(User $user): array
{
    return app(TwoFactorService::class)->replaceRecoveryCodes($user);
}

/**
 * Forget the resolved authentication guard between requests in one test.
 *
 * Needed only in tests, and for a reason worth stating: a test reuses a single
 * application container across several HTTP calls, and `RequestGuard` caches
 * the user it resolved on the first one. So a credential revoked mid-test still
 * appears to work on the next call — not because revocation failed, but because
 * nothing re-asked. Real requests each boot their own container and never hit
 * this.
 *
 * Call it after anything that revokes a credential, before asserting that the
 * credential no longer works.
 */
function forgetAuthGuards(): void
{
    app('auth')->forgetGuards();
}

/*
|--------------------------------------------------------------------------
| Run helpers (M9)
|--------------------------------------------------------------------------
*/

/**
 * Start a run through the real endpoint, as the player.
 *
 * @return TestResponse<Response>
 */
function startRun(User $user, mixed $characterId = 'aysenur'): TestResponse
{
    // Tests here switch between players; without this the guard would answer
    // every request as whoever it resolved first.
    forgetAuthGuards();

    return Pest\Laravel\withHeaders(sessionFor($user))
        ->postJson('/api/v1/game-runs', ['character_id' => $characterId]);
}

/**
 * A telemetry body that every M9 rule accepts, given a window of at least
 * `$duration` milliseconds. 30 s, 1000 points, 50 paws: 33 pts/s, 1.7 paws/s,
 * above the 10-per-paw floor.
 *
 * @return array{telemetry: array{reported_duration_ms: int, reported_score: int, reported_run_paws: int}}
 */
function plausibleTelemetry(int $duration = 30000, int $score = 1000, int $paws = 50): array
{
    return ['telemetry' => [
        'reported_duration_ms' => $duration,
        'reported_score' => $score,
        'reported_run_paws' => $paws,
    ]];
}

/**
 * Finish a run through the real endpoint, as the player.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function finishRun(User $user, string $runId, array $body, ?string $key = null): TestResponse
{
    $headers = sessionFor($user);

    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    forgetAuthGuards();

    return Pest\Laravel\withHeaders($headers)
        ->postJson("/api/v1/game-runs/{$runId}/finish", $body);
}

/**
 * The raw progression row, or null — read straight from the table, so an
 * assertion cannot be satisfied by the code under test's own projection.
 */
function progressionRow(User $user): ?object
{
    return DB::table('player_progression')->where('user_id', $user->id)->first();
}
