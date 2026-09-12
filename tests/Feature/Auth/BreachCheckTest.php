<?php

declare(strict_types=1);

use App\Support\BreachCheck;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\postJson;

/**
 * The compromised-password check and, more to the point, its failure policy.
 *
 * The policy is **fail open**: a password confirmed breached is refused, and a
 * provider that cannot be reached refuses nothing. Breach checking is defence in
 * depth behind a 12-character minimum, argon2id, two-dimensional throttling and
 * the second factor; failing closed would let a third party's outage take
 * registration, password reset and password change down together, including for
 * an administrator trying to recover an account mid-incident.
 *
 * That is a decision, so it is tested like one. The untested version of it is
 * indistinguishable from the check silently not running.
 */
beforeEach(function (): void {
    // The suite binds a permissive stub globally; these tests want the real
    // class with a faked transport underneath it.
    app()->forgetInstance(UncompromisedVerifier::class);

    app()->singleton(UncompromisedVerifier::class, fn ($app) => new BreachCheck(
        $app[HttpFactory::class],
        config()->integer('auth.breach_check.timeout'),
    ));
});

/** A registration payload with the given password. */
function registrationWith(string $password): array
{
    return [
        'display_name' => 'Denizci'.random_int(1000, 9999),
        'email' => 'audit'.random_int(1000, 9999).'@purrenade.invalid',
        'password' => $password,
        'password_confirmation' => $password,
    ];
}

it('rejects a password the provider reports as breached', function (): void {
    $password = 'kumsalda-gunes-batiyor';
    $suffix = mb_substr(mb_strtoupper(sha1($password)), 5);

    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response($suffix.':4213', 200),
    ]);

    postJson('/api/v1/auth/register', registrationWith($password))
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0.code', 'password_compromised');
});

it('accepts a password the provider does not know', function (): void {
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000A:1\n", 200),
    ]);

    postJson('/api/v1/auth/register', registrationWith('kumsalda-gunes-batiyor'))
        ->assertCreated();
});

it('accepts the password when the provider times out', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    postJson('/api/v1/auth/register', registrationWith('kumsalda-gunes-batiyor'))
        ->assertCreated();
});

it('accepts the password when the provider cannot be resolved', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

    postJson('/api/v1/auth/register', registrationWith('kumsalda-gunes-batiyor'))
        ->assertCreated();
});

it('accepts the password when the provider answers with an error', function (): void {
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 503),
    ]);

    postJson('/api/v1/auth/register', registrationWith('kumsalda-gunes-batiyor'))
        ->assertCreated();
});

it('records the degraded check on the security channel, with no password material', function (): void {
    $captured = [];

    Log::shouldReceive('channel')->with('security')->andReturnSelf();
    Log::shouldReceive('warning')->andReturnUsing(function (string $event, array $context) use (&$captured): void {
        $captured[] = [$event, $context];
    });

    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 500),
    ]);

    $password = 'kumsalda-gunes-batiyor';

    app(UncompromisedVerifier::class)->verify(['value' => $password, 'threshold' => 0]);

    expect($captured)->toHaveCount(1)
        ->and($captured[0][0])->toBe(BreachCheck::EVENT)
        ->and($captured[0][1]['policy'])->toBe('fail_open');

    // Nothing about the password, in any form, reaches the log.
    $serialised = json_encode($captured, JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain($password)
        ->and($serialised)->not->toContain(sha1($password))
        ->and($serialised)->not->toContain(mb_substr(mb_strtoupper(sha1($password)), 0, 5));
});

it('sends only the hash prefix, never the password or the whole hash', function (): void {
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);

    $password = 'kumsalda-gunes-batiyor';
    $hash = mb_strtoupper(sha1($password));

    app(UncompromisedVerifier::class)->verify(['value' => $password, 'threshold' => 0]);

    Http::assertSent(function ($request) use ($password, $hash): bool {
        $url = $request->url();

        return str_contains($url, mb_substr($hash, 0, 5))
            && ! str_contains($url, mb_substr($hash, 5))
            && ! str_contains($url, $password)
            && $request->body() === '';
    });
});

it('bounds the request well below the framework default', function (): void {
    // 30 seconds in-band, on three endpoints reachable without authentication,
    // is a denial-of-service amplifier pointed at our own workers through a
    // service we do not control.
    expect(config()->integer('auth.breach_check.timeout'))
        ->toBeLessThanOrEqual(5)
        ->toBeGreaterThan(0);
});
