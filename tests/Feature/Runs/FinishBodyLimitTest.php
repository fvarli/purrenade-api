<?php

declare(strict_types=1);

use App\Models\Run;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\call;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

use Symfony\Component\Yaml\Yaml;
use Tests\Support\OpenApiContract;

/**
 * The finish route's authoritative 256 KiB body limit: 413
 * `payload_too_large` in the problem envelope, from the application, with
 * nothing recorded. The largest conforming body is ~220 KB.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-10-03 12:00:00.000', 'UTC'));
    Queue::fake();
});

/** A raw JSON body of exactly `$bytes` bytes: valid telemetry, padded. */
function finishBodyOfSize(int $bytes): string
{
    $prefix = '{"telemetry":{"reported_duration_ms":30000,"reported_score":1000,"reported_run_paws":50},"pad":"';
    $suffix = '"}';

    return $prefix.str_repeat('a', $bytes - strlen($prefix) - strlen($suffix)).$suffix;
}

function rawFinish(User $user, string $runId, string $body, array $server = []): mixed
{
    forgetAuthGuards();
    $headers = sessionFor($user);

    return call('POST', "/api/v1/game-runs/{$runId}/finish", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => $headers['Authorization'],
        'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
        ...$server,
    ], $body);
}

function startLimitRun(User $user): string
{
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31_000)->milliseconds();

    return $runId;
}

it('accepts a body of exactly 256 KiB', function (): void {
    $user = User::factory()->create();
    $runId = startLimitRun($user);

    rawFinish($user, $runId, finishBodyOfSize(262_144))
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');
});

it('refuses one byte more with 413 payload_too_large, and records nothing', function (): void {
    $user = User::factory()->create();
    $runId = startLimitRun($user);

    $response = rawFinish($user, $runId, finishBodyOfSize(262_145))
        ->assertStatus(413)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'payload_too_large')
        ->assertJsonPath('type', 'urn:purrenade:error:payload_too_large');

    expect(OpenApiContract::violations($response->json(), 'Problem'))->toBe([])
        ->and(Run::query()->findOrFail($runId)->status->value)->toBe('active');
});

it('measures the body itself when the declared length understates it', function (): void {
    $user = User::factory()->create();
    $runId = startLimitRun($user);

    rawFinish($user, $runId, finishBodyOfSize(262_145), ['CONTENT_LENGTH' => '100'])
        ->assertStatus(413)
        ->assertJsonPath('code', 'payload_too_large');
});

it('refuses on a declared length above the limit', function (): void {
    $user = User::factory()->create();
    $runId = startLimitRun($user);

    rawFinish($user, $runId, finishBodyOfSize(1_000), ['CONTENT_LENGTH' => '262145'])
        ->assertStatus(413);
});

it('documents the 413 on the finish operation', function (): void {
    $document = Yaml::parseFile(base_path('docs/api/openapi.draft.yaml'));
    $response = $document['paths']['/game-runs/{runId}/finish']['post']['responses']['413'] ?? null;

    expect($response['content']['application/problem+json']['schema']['$ref'] ?? null)
        ->toBe('#/components/schemas/Problem')
        ->and($response['description'])->toContain('payload_too_large');
});

it('limits only the finish route', function (): void {
    $user = User::factory()->create();

    // A large body on start is not this middleware's business.
    forgetAuthGuards();
    call('POST', '/api/v1/game-runs', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => sessionFor($user)['Authorization'],
    ], '{"character_id":"aysenur","pad":"'.str_repeat('a', 300_000).'"}')
        ->assertStatus(201);
});
