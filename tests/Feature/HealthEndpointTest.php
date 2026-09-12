<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

use function Pest\Laravel\getJson;

// /api/v1/health is a READINESS probe: it reports whether this service can
// actually do its job, which means reaching PostgreSQL. A green light from a
// process that cannot reach its database is worse than no light at all.
//
// The helpers are imported explicitly from Pest\Laravel rather than relied on
// as ambient globals: the binding of `$this` inside a Pest closure is not
// statically resolvable, and keeping static analysis over the test suite is
// worth more than the terser style.

it('reports ready when the database is reachable', function (): void {
    // No fake: this hits the real PostgreSQL the suite is already configured
    // against, so the happy path proves the probe works rather than proving the
    // test double works.
    $response = getJson('/api/v1/health')->assertOk();

    $response->assertJson([
        'success' => true,
        'status' => 'ok',
        'service' => 'Purrenade API',
        'environment' => 'testing',
        'checks' => [
            'application' => 'ok',
            'database' => 'ok',
        ],
    ]);

    expect(array_keys($response->json()))->toBe([
        'success', 'status', 'service', 'environment', 'timestamp', 'checks',
    ]);
});

it('timestamps the response in ISO 8601 UTC', function (): void {
    $timestamp = getJson('/api/v1/health')->json('timestamp');

    expect($timestamp)->toBeString();

    $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, (string) $timestamp);

    expect($parsed)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($parsed->getOffset())->toBe(0);
});

it('carries no version field, because there is no source of truth for one', function (): void {
    // composer.json declares no version and CHANGELOG.md records no releases.
    // The only number available would be the framework's, which is not this
    // service's version and which observability.md §7 forbids disclosing to an
    // unauthenticated caller. Inventing one would be worse than omitting it.
    expect(getJson('/api/v1/health')->json())->not->toHaveKey('version');
});

describe('when the database is unreachable', function (): void {
    beforeEach(function (): void {
        // A connection definition that cannot succeed, rather than a stubbed
        // probe. Port 1 is reserved and nothing listens on it, so the driver
        // fails immediately and deterministically — and the test then exercises
        // the real catch block with a real PDOException, which is the only way
        // to prove that real driver detail does not reach the response.
        //
        // Nothing outside this test is affected: the suite's own connection is
        // untouched and the override dies with the request.
        config([
            'database.connections.unreachable' => array_merge(
                (array) config('database.connections.pgsql'),
                ['host' => '127.0.0.1', 'port' => 1],
            ),
            'database.default' => 'unreachable',
        ]);
    });

    it('answers 503 rather than a misleading 200', function (): void {
        getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'status' => 'degraded',
                'checks' => [
                    'application' => 'ok',   // the process itself is fine
                    'database' => 'error',
                ],
            ]);
    });

    it('leaks nothing about why', function (): void {
        $body = (string) getJson('/api/v1/health')->getContent();

        // Whatever the driver said stays in the log. A PDOException message
        // routinely carries the host, port, database name, role and driver.
        expect($body)
            ->not->toContain('SQLSTATE')
            ->and($body)->not->toContain('Connection refused')
            ->and($body)->not->toContain('select 1')
            ->and($body)->not->toContain('pgsql')
            ->and($body)->not->toContain('127.0.0.1')
            ->and($body)->not->toContain((string) config('database.connections.pgsql.database'))
            ->and($body)->not->toContain(base_path());
    });

    it('still records the detail server-side', function (): void {
        // Suppressed in the response, not discarded: an operator reading the
        // journal needs the reason the probe failed.
        $logger = Log::spy();

        getJson('/api/v1/health')->assertStatus(503);

        $logger->shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'database unreachable'))
            ->once();
    });
});

it('returns JSON rather than HTML for unknown routes', function (): void {
    // This is an API-only service: there is no Blade UI and no HTML error page.
    getJson('/api/v1/does-not-exist')->assertNotFound();
});
