<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * API-1: every error is RFC 9457 `application/problem+json`.
 *
 * The value of the decision is that a client needs one parser. These tests are
 * what makes that true rather than aspirational — they check the envelope on
 * each status class the API actually produces.
 */

/** @return list<string> The members every problem must carry. */
function requiredProblemMembers(): array
{
    return ['type', 'title', 'status', 'detail', 'instance', 'code', 'correlation_id'];
}

it('returns problem+json for a validation failure', function (): void {
    $response = postJson('/api/v1/auth/register', [])->assertStatus(422);

    $response->assertHeader('content-type', 'application/problem+json')
        ->assertJsonStructure(requiredProblemMembers())
        ->assertJsonPath('type', 'urn:purrenade:error:validation_failed')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('status', 422)
        ->assertJsonPath('instance', '/api/v1/auth/register');

    // Field errors carry their own stable code, so a client can localise
    // "already taken" without parsing an English sentence.
    expect($response->json('errors.email.0.code'))->toBe('required')
        ->and($response->json('errors.email.0.message'))->toBeString();
});

it('mirrors the code in the type URN', function (): void {
    // Derived, not duplicated by hand, so the two cannot disagree. A URN
    // because RFC 9457 types need not be dereferenceable, and no documentation
    // site exists to dereference.
    $problem = postJson('/api/v1/auth/login', [])->assertStatus(422)->json();

    expect($problem['type'])->toBe('urn:purrenade:error:'.$problem['code']);
});

it('returns problem+json when unauthenticated', function (): void {
    getJson('/api/v1/auth/sessions')
        ->assertStatus(401)
        ->assertHeader('content-type', 'application/problem+json')
        ->assertJsonStructure(requiredProblemMembers())
        ->assertJsonPath('code', 'unauthenticated');
});

it('returns problem+json when forbidden', function (): void {
    withHeaders(sessionFor(User::factory()->create(), twoFactorSatisfied: true))
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertHeader('content-type', 'application/problem+json')
        ->assertJsonStructure(requiredProblemMembers())
        ->assertJsonPath('code', 'admin_role_required');
});

it('returns problem+json for a conflict', function (): void {
    withHeaders(sessionFor(User::factory()->create()))
        ->postJson('/api/v1/auth/email/verify', ['code' => '123456'])
        ->assertStatus(409)
        ->assertHeader('content-type', 'application/problem+json')
        ->assertJsonPath('code', 'email_already_verified');
});

it('returns problem+json for an unknown route', function (): void {
    getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertHeader('content-type', 'application/problem+json')
        ->assertJsonPath('code', 'not_found')
        // Never which model, never which id.
        ->assertJsonPath('detail', 'The requested resource does not exist.');
});

it('returns problem+json for a method that is not allowed', function (): void {
    call('DELETE', '/api/v1/auth/login')
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed');
});

it('returns problem+json with a retry hint when rate limited', function (): void {
    // Registration is capped per source per hour. Exceeding it must still speak
    // the same contract, with the wait in both a member and a header.
    for ($i = 0; $i < 11; $i++) {
        $response = postJson('/api/v1/auth/register', [
            'display_name' => 'Player'.$i,
            'email' => "player{$i}@example.test",
            'password' => 'sahilde-kosan-kedi-42',
            'password_confirmation' => 'sahilde-kosan-kedi-42',
        ]);

        if ($response->status() === 429) {
            $response->assertHeader('content-type', 'application/problem+json')
                ->assertHeader('Retry-After')
                ->assertJsonStructure(requiredProblemMembers())
                ->assertJsonPath('code', 'rate_limited');

            expect($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0);

            return;
        }
    }

    throw new RuntimeException('the registration limiter never triggered');
});

it('never leaks internals on an unexpected failure', function (): void {
    // A route that throws something the application does not model. The client
    // must get a bare 500 whose detail comes from us, not from the exception —
    // exception messages routinely carry SQL, paths and class names.
    Route::middleware('api')->get('/api/v1/test-only-explode', function (): never {
        throw new RuntimeException('/var/secret/path: SQLSTATE[42P01] relation "users" does not exist');
    });

    $response = getJson('/api/v1/test-only-explode')->assertStatus(500);

    $body = (string) $response->getContent();

    expect($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain('/var/secret/path')
        ->and($body)->not->toContain('RuntimeException')
        ->and($body)->not->toContain('vendor/')
        ->and($body)->not->toContain('Stack trace');

    $response->assertJsonPath('code', 'server_error')
        ->assertJsonStructure(requiredProblemMembers());
});

it('keeps the same shape with debug mode on', function (): void {
    // A response shape that changes between environments is a shape nobody can
    // test. A developer gets the detail from the log instead.
    config(['app.debug' => true]);

    Route::middleware('api')->get('/api/v1/test-only-explode-debug', function (): never {
        throw new RuntimeException('leaky detail: /etc/passwd');
    });

    $response = getJson('/api/v1/test-only-explode-debug')->assertStatus(500);

    expect((string) $response->getContent())->not->toContain('/etc/passwd');

    $response->assertJsonStructure(requiredProblemMembers());
});

it('echoes a well-formed correlation id and generates one otherwise', function (): void {
    $supplied = getJson('/api/v1/auth/sessions', ['X-Correlation-Id' => 'abc123-DEF_456'])
        ->assertStatus(401);

    expect($supplied->json('correlation_id'))->toBe('abc123-DEF_456');
    $supplied->assertHeader('X-Correlation-Id', 'abc123-DEF_456');

    $generated = getJson('/api/v1/auth/sessions')->assertStatus(401);

    expect($generated->json('correlation_id'))->toBeString()
        ->and(strlen((string) $generated->json('correlation_id')))->toBeGreaterThanOrEqual(8);
});

it('refuses a malformed correlation id rather than echoing it', function (string $injected): void {
    // The value is echoed in a header and written into logs, so accepting
    // arbitrary bytes would permit header injection and log forging.
    $response = getJson('/api/v1/auth/sessions', ['X-Correlation-Id' => $injected])
        ->assertStatus(401);

    expect($response->json('correlation_id'))->not->toBe($injected);
})->with([
    'too short' => ['abc'],
    'illegal characters' => ['../../etc/passwd'],
    'header injection' => ["ok\r\nX-Injected: yes"],
    'over length' => [str_repeat('a', 200)],
]);

it('reports a wrong password as one generic code', function (): void {
    User::factory()->create(['email' => 'ayse@example.test']);

    postJson('/api/v1/auth/login', ['email' => 'ayse@example.test', 'password' => 'wrong'])
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_credentials')
        ->assertJsonMissingPath('errors');
});

it('returns the same problem whatever the client asks to Accept', function (string $accept): void {
    // The gap that let a real bug through: `getJson()` always sets
    // `Accept: application/json`, so every test in this suite exercised the one
    // header value that happened to work. The framework's `auth` middleware
    // computes a redirect for any other value — `route('login')`, which does
    // not exist here — and threw *inside the middleware*, turning a 401 into a
    // 500 for every client that does not ask for JSON: a native client, curl, a
    // monitoring probe.
    //
    // The contract has to be universal or it is not a contract.
    $response = get('/api/v1/auth/me', ['Accept' => $accept]);

    $response->assertStatus(401)
        ->assertHeader('content-type', 'application/problem+json')
        ->assertJsonPath('code', 'unauthenticated')
        ->assertJsonStructure(requiredProblemMembers());
})->with([
    'json' => ['application/json'],
    'anything' => ['*/*'],
    'html' => ['text/html'],
    'html with quality values' => ['text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
    'nothing in particular' => [''],
]);

it('never redirects, at any Accept header', function (): void {
    // An API-only service has nowhere to redirect to. A 3xx here would mean the
    // framework found a login route to send the caller to, which would be a
    // Blade surface this application does not have.
    foreach (['application/json', '*/*', 'text/html'] as $accept) {
        $status = get('/api/v1/auth/sessions', ['Accept' => $accept])->getStatusCode();

        // Not a 3xx, and specifically the refusal it should be.
        expect($status)->toBe(401);
        expect($status < 300 || $status >= 400)->toBeTrue();
    }
});

it('sends no CORS headers', function (): void {
    // ADR-0005 §4: the browser talks to one origin, the Nuxt application, so no
    // browser calls this API cross-origin. The package default is `['*']`,
    // which advertised the opposite — not exploitable while the API accepts no
    // cookie, and precisely why it is worth removing before something
    // cookie-based arrives.
    $response = getJson('/api/v1/health', ['Origin' => 'https://evil.example']);

    $response->assertOk();

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    expect(config('cors.allowed_origins'))->toBe([]);
    expect(config('cors.supports_credentials'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The envelope covers the whole host, not the /api prefix
|--------------------------------------------------------------------------
|
| The renderer used to be scoped to `api/*` and `/`, which left every other
| path on the API host to Laravel's default handler: `{"exception", "file",
| "line", "trace"}` — a full stack trace and absolute filesystem paths with
| APP_DEBUG on, and a bare `{"message"}` no client could parse without a special
| case otherwise. The docblock claimed the opposite, and the gates could not see
| it because one lints the OpenAPI document and the other reads route files.
|
| There is no second surface here. Anything that is not a route is a 404, and it
| is a 404 in the same shape as every other error.
*/

it('returns problem+json for a path outside the api prefix', function (string $path): void {
    getJson($path)
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'not_found');
})->with([
    'bare path' => ['/does-not-exist'],
    'nested path' => ['/admin/secret'],
    'the framework health route, now removed' => ['/up'],
    'the storage serve route, now removed' => ['/storage/anything'],
    'a dotted path' => ['/.env'],
]);

it('never leaks a stack trace or a filesystem path, anywhere, with debug on', function (string $path): void {
    // Asserted with APP_DEBUG=true on purpose: a response shape that is only
    // safe in production is a shape nobody tests.
    config()->set('app.debug', true);

    $body = getJson($path)->getContent();

    expect($body)->toBeString()
        ->and(json_decode($body, true))
        ->not->toHaveKeys(['exception', 'file', 'line', 'trace'])
        ->and($body)->not->toContain(base_path())
        ->and($body)->not->toContain('vendor/laravel');
})->with([
    '/does-not-exist',
    '/up',
    '/storage/anything',
    '/api/v1/does-not-exist',
    '/api/v1/auth/me',
]);

it('answers a path outside the api prefix the same way at any Accept header', function (string $accept): void {
    withHeaders(['Accept' => $accept])
        ->get('/does-not-exist')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json');
})->with([
    'json' => ['application/json'],
    'problem' => ['application/problem+json'],
    'html' => ['text/html'],
    'anything' => ['*/*'],
    'nothing' => [''],
]);

it('registers no route outside the api prefix except the root', function (): void {
    // The gate that was supposed to catch `/up` and `storage/{path}` scanned
    // route files, so it could not see a route the framework registered. This
    // asserts against the real route table.
    $paths = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->reject(fn (string $uri): bool => $uri === '/' || str_starts_with($uri, 'api/'))
        ->unique()
        ->values();

    expect($paths->all())->toBe([]);
});
