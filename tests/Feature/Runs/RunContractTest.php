<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

use Tests\Support\OpenApiContract;

/**
 * Every M9 response, pinned to `docs/api/openapi.draft.yaml` (gate M-B).
 *
 * The frontend generates its wire types from that document and nothing else,
 * so these tests are what stop the implementation and the contract drifting —
 * the way the tutorial envelope drifted at M8. An undeclared member fails here,
 * which is also how an ANTI-6 field leaking into a response would be caught.
 */
function assertConforms(TestResponse $response, string $path, string $method): void
{
    $schema = OpenApiContract::responseSchema($path, $method, $response->status());

    expect(OpenApiContract::violationsAgainst($response->json(), $schema))->toBe([]);
}

it('conforms: start, new run (201) and resume (200)', function (): void {
    $user = User::factory()->create();

    assertConforms(startRun($user)->assertCreated(), '/game-runs', 'post');
    assertConforms(startRun($user)->assertOk(), '/game-runs', 'post');
});

it('conforms: finish, for every outcome and for a replay', function (array $telemetry, string $status): void {
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31)->seconds();
    $key = (string) Str::uuid();

    $first = finishRun($user, $runId, ['telemetry' => $telemetry], $key)->assertOk()->assertJsonPath('data.status', $status);
    assertConforms($first, '/game-runs/{runId}/finish', 'post');

    assertConforms(finishRun($user, $runId, ['telemetry' => $telemetry], $key)->assertOk(), '/game-runs/{runId}/finish', 'post');
})->with([
    'accepted' => [['reported_duration_ms' => 30000, 'reported_score' => 1000, 'reported_run_paws' => 50], 'accepted'],
    'flagged' => [['reported_duration_ms' => 3000, 'reported_score' => 100, 'reported_run_paws' => 5], 'flagged'],
    'rejected' => [['reported_duration_ms' => 0, 'reported_score' => 0, 'reported_run_paws' => 0], 'rejected'],
    'rejected out of domain' => [['reported_duration_ms' => 30000, 'reported_score' => -5, 'reported_run_paws' => 0], 'rejected'],
]);

it('conforms: progression, tutorial and the session projection', function (): void {
    $user = User::factory()->create();

    assertConforms(withHeaders(sessionFor($user))->getJson('/api/v1/progression')->assertOk(), '/progression', 'get');
    assertConforms(withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk(), '/progression/tutorial', 'post');
    assertConforms(withHeaders(sessionFor($user))->getJson('/api/v1/auth/me')->assertOk(), '/auth/me', 'get');
});

it('declares the problem codes the run endpoints can return', function (): void {
    $spec = (string) file_get_contents(base_path('docs/api/openapi.draft.yaml'));

    foreach (['run_not_active', 'idempotency_key_reused', 'character_unavailable'] as $code) {
        expect($spec)->toContain($code);
    }
});

it('catches drift: an undeclared member is a violation', function (): void {
    $violations = OpenApiContract::violations([
        'lifetime_paws' => 0,
        'loli_cycle_paws' => 0,
        'loli_threshold' => 200,
        'best_score' => 0,
        'run_count' => 0,
        'tutorial_completed' => false,
        'lifetime_loli_activations' => 3,
    ], 'Progression');

    expect($violations)->toContain("$: undeclared member 'lifetime_loli_activations'");
});
