<?php

declare(strict_types=1);

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

// The web root of an API-only service. Without an explicit route this returns a
// bare framework 404, which tells an operator nothing and reads like a broken
// deployment.
//
// Helpers are imported explicitly from Pest\Laravel rather than relied on as
// ambient globals: the binding of `$this` inside a Pest closure is not
// statically resolvable, and keeping static analysis over the test suite is
// worth more than the terser style.

it('answers the web root with the API-only signpost', function (): void {
    getJson('/')
        ->assertOk()
        ->assertExactJson([
            'success' => false,
            'message' => 'This is an API-only application. No web access allowed.',
            'service' => 'Purrenade API',
            'environment' => 'testing',
            'api_base' => '/api/v1',
            'health' => '/api/v1/health',
            'documentation' => null,
        ]);
});

it('claims no documentation URL, because none is published', function (): void {
    // Guards the decision, not the value: a plausible-looking URL that 404s is
    // worse than admitting there is nothing to link to yet.
    expect(config('service.documentation_url'))->toBeNull();
});

it('serves the root as JSON even to a browser that asks for HTML', function (): void {
    // There is no Blade layout and no HTML error page anywhere in this service.
    $response = get('/', ['Accept' => 'text/html']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

it('discloses nothing beyond the service descriptor at the root', function (): void {
    $body = get('/')->getContent();

    expect($body)
        ->not->toContain(base_path())        // no filesystem paths
        ->and($body)->not->toContain('pgsql') // no driver or connection detail
        ->and($body)->not->toContain('Laravel'); // no framework fingerprint

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $body, true);

    // An exact key list: a future field cannot be added without a test failing
    // and forcing someone to think about whether it belongs in public.
    expect(array_keys($payload))->toBe([
        'success', 'message', 'service', 'environment',
        'api_base', 'health', 'documentation',
    ]);
});
