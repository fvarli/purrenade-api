<?php

declare(strict_types=1);

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\User;
use App\Support\RateLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\travel;

/**
 * The submission limiter (S5): user-scoped, one bucket per route, and never in
 * the way of a legitimate retry.
 */
it('refuses the start after the per-minute allowance, with retry_after', function (): void {
    $user = User::factory()->create();

    foreach (range(1, RateLimits::GAME_RUN_PER_IDENTIFIER) as $attempt) {
        startRun($user)->assertSuccessful();
    }

    $refused = startRun($user)
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');

    expect($refused->json('retry_after'))->toBeInt()->toBeGreaterThan(0)
        ->and(Run::query()->count())->toBe(1);

    travel(61)->seconds();

    startRun($user)->assertOk();
});

it('keeps the finish bucket independent of the start bucket', function (): void {
    $user = User::factory()->create();

    foreach (range(1, RateLimits::GAME_RUN_PER_IDENTIFIER) as $attempt) {
        $runId = startRun($user)->assertSuccessful()->json('data.run_id');
    }

    startRun($user)->assertStatus(429);

    travel(31)->seconds();

    finishRun($user, $runId, plausibleTelemetry(), (string) Str::uuid())->assertOk();
});

it('scopes the buckets per player', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    foreach (range(1, RateLimits::GAME_RUN_PER_IDENTIFIER) as $attempt) {
        startRun($alice)->assertSuccessful();
    }

    startRun($alice)->assertStatus(429);
    startRun($bob)->assertCreated();
});

it('does not let a 429 consume the idempotency slot: the retry applies exactly once', function (): void {
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31)->seconds();

    $key = (string) Str::uuid();

    // Spend the finish allowance on malformed finishes, which count toward the
    // limit but write nothing.
    foreach (range(1, RateLimits::GAME_RUN_PER_IDENTIFIER) as $attempt) {
        finishRun($user, $runId, ['telemetry' => []], $key)->assertStatus(422);
    }

    finishRun($user, $runId, plausibleTelemetry(), $key)->assertStatus(429);

    expect(Run::query()->findOrFail($runId)->status)->toBe(RunStatus::Active);

    travel(61)->seconds();

    finishRun($user, $runId, plausibleTelemetry(), $key)->assertOk()->assertJsonPath('data.status', 'accepted');
    finishRun($user, $runId, plausibleTelemetry(), $key)->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(DB::table('paw_ledger')->count())->toBe(1)
        ->and((int) progressionRow($user)->run_count)->toBe(1);
});

it('counts replays toward the limit without ever duplicating state', function (): void {
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31)->seconds();
    $key = (string) Str::uuid();

    foreach (range(1, RateLimits::GAME_RUN_PER_IDENTIFIER) as $attempt) {
        finishRun($user, $runId, plausibleTelemetry(), $key)->assertOk();
    }

    finishRun($user, $runId, plausibleTelemetry(), $key)->assertStatus(429);

    expect(DB::table('paw_ledger')->count())->toBe(1)
        ->and((int) progressionRow($user)->lifetime_paws)->toBe(50);
});
