<?php

declare(strict_types=1);

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\User;
use App\Services\Progression\ProgressionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

/**
 * POST /game-runs/{runId}/finish — the client proposes, the server decides.
 *
 * The properties that carry the weight: only an **accepted** run changes
 * anything beyond its own row; a flagged or rejected run changes nothing else
 * at all (S6); a retry with the same key is answered from the stored result and
 * writes nothing (GR-3); and the protocol accepts JSON integers and nothing
 * that merely looks like one (C-7).
 *
 * Time is frozen at a millisecond-exact instant, so the server window is an
 * exact number and every bound can be tested at ±1.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-23 12:00:00.000', 'UTC'));
});

/**
 * Start a run now, then move the clock on by `$windowMs`.
 *
 * @return array{0: User, 1: Run}
 */
function runWithWindow(int $windowMs, ?User $user = null): array
{
    $user ??= User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');

    travel($windowMs)->milliseconds();

    return [$user, Run::query()->findOrFail($runId)];
}

/** Everything a finish could possibly change for this player. */
function worldOf(User $user): array
{
    return [
        'progression' => (array) progressionRow($user),
        'ledger' => DB::table('paw_ledger')->where('user_id', $user->id)->count(),
        'accepted_runs' => DB::table('runs')->where('user_id', $user->id)->where('status', 'accepted')->count(),
    ];
}

/** POST a raw JSON body, byte-for-byte, so non-PHP number forms can be sent. */
function finishRaw(User $user, string $runId, string $json, string $key): TestResponse
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => $key,
    ];

    forgetAuthGuards();

    foreach (sessionFor($user) as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return call('POST', "/api/v1/game-runs/{$runId}/finish", [], [], [], $server, $json);
}

// ---------------------------------------------------------------------------
// Accepted
// ---------------------------------------------------------------------------

it('accepts a plausible run and applies it exactly once', function (): void {
    [$user, $run] = runWithWindow(31_000);

    $response = finishRun($user, $run->id, plausibleTelemetry(30_000, 1000, 50), (string) Str::uuid())->assertOk();

    expect($response->json('data'))->toBe([
        'run_id' => $run->id,
        'status' => 'accepted',
        'score' => 1000,
        'run_paws' => 50,
        'is_personal_best' => true,
        'previous_best_score' => 0,
        'reasons' => [],
        'progression' => [
            'lifetime_paws' => 50,
            'loli_cycle_paws' => 50,
            'loli_threshold' => 200,
            'best_score' => 1000,
            'run_count' => 1,
            'tutorial_completed' => false,
        ],
        'achievements_unlocked' => [],
        'characters_unlocked' => [],
    ]);

    $run->refresh();
    $row = progressionRow($user);

    expect($run->status)->toBe(RunStatus::Accepted)
        ->and($run->score)->toBe(1000)
        ->and($run->run_paws)->toBe(50)
        ->and($run->duration_ms)->toBe(30_000)
        ->and($run->finished_at?->format('Y-m-d H:i:s.v'))->toBe('2026-09-23 12:00:31.000')
        ->and($run->validation_meta)->toEqual(['v' => 1, 'window_ms' => 31_000, 'rules' => []])
        ->and((int) $row->lifetime_paws)->toBe(50)
        ->and((int) $row->loli_cycle_paws)->toBe(50)
        ->and((int) $row->best_score)->toBe(1000)
        ->and((int) $row->run_count)->toBe(1);

    $ledger = DB::table('paw_ledger')->where('run_id', $run->id)->first();

    expect((int) $ledger->delta)->toBe(50)
        ->and((int) $ledger->resulting_cycle)->toBe(50)
        ->and((int) $ledger->bonuses_triggered)->toBe(0)
        ->and((int) $ledger->user_id)->toBe($user->id);
});

it('keeps the personal best and reports the previous one', function (): void {
    [$user, $run] = runWithWindow(31_000);
    finishRun($user, $run->id, plausibleTelemetry(30_000, 1500, 50), (string) Str::uuid())->assertOk();

    [, $second] = runWithWindow(31_000, $user);

    finishRun($user, $second->id, plausibleTelemetry(30_000, 1200, 50), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.is_personal_best', false)
        ->assertJsonPath('data.previous_best_score', 1500)
        ->assertJsonPath('data.progression.best_score', 1500)
        ->assertJsonPath('data.progression.run_count', 2)
        ->assertJsonPath('data.progression.lifetime_paws', 100);
});

it('writes no ledger row for an accepted run with zero paws, but still counts it', function (): void {
    [$user, $run] = runWithWindow(31_000);

    finishRun($user, $run->id, plausibleTelemetry(30_000, 1000, 0), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.progression.run_count', 1);

    expect(DB::table('paw_ledger')->count())->toBe(0);
});

it('carries paw overflow across the threshold, counting crossings but never activations', function (int $cycle, int $paws, int $expectedCycle, int $crossings): void {
    $user = User::factory()->create();
    app(ProgressionService::class)->ensure($user->id);
    DB::table('player_progression')->where('user_id', $user->id)->update(['loli_cycle_paws' => $cycle, 'lifetime_paws' => 1_000_000]);

    // Long enough that the paws are plausible (≤ 3/s) and the score is inside
    // every bound (≥ 10 per paw, 5..80 per second).
    [, $run] = runWithWindow(200_000, $user);

    finishRun($user, $run->id, plausibleTelemetry(200_000, max(1000, 10 * $paws), $paws), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.progression.loli_cycle_paws', $expectedCycle)
        ->assertJsonPath('data.progression.lifetime_paws', 1_000_000 + $paws);

    $ledger = DB::table('paw_ledger')->where('run_id', $run->id)->first();

    expect((int) $ledger->resulting_cycle)->toBe($expectedCycle)
        ->and((int) $ledger->bonuses_triggered)->toBe($crossings);
})->with([
    'no crossing' => [0, 150, 150, 0],
    'exactly to the threshold' => [150, 50, 0, 1],
    '198 + 5' => [198, 5, 3, 1],
    '199 + 1' => [199, 1, 0, 1],
    '150 + 450: three thresholds in one delta' => [150, 450, 0, 3],
    '199 + 599' => [199, 599, 198, 3],
]);

it('applies an accepted paw delta beyond the smallint range the cycle column stores', function (int $cycle, int $paws, int $expectedCycle, int $crossings): void {
    // `loli_cycle_paws` is a smallint, and PostgreSQL types an untyped
    // parameter added to it as a smallint too. A delta — or cycle plus delta —
    // past 32767 is still a well-formed, plausible, accepted run once the
    // window is long enough (≤ 3 paws/s), so it must be applied, not a 500.
    $user = User::factory()->create();
    app(ProgressionService::class)->ensure($user->id);
    DB::table('player_progression')->where('user_id', $user->id)->update(['loli_cycle_paws' => $cycle]);

    [, $run] = runWithWindow(14_400_000, $user);

    finishRun($user, $run->id, plausibleTelemetry(14_400_000, 10 * $paws, $paws), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.progression.loli_cycle_paws', $expectedCycle)
        ->assertJsonPath('data.progression.lifetime_paws', $paws);

    $ledger = DB::table('paw_ledger')->where('run_id', $run->id)->first();

    expect((int) $ledger->delta)->toBe($paws)
        ->and((int) $ledger->resulting_cycle)->toBe($expectedCycle)
        ->and((int) $ledger->bonuses_triggered)->toBe($crossings);
})->with([
    'a delta above 32767' => [0, 40_000, 0, 200],
    'a cycle plus delta above 32767' => [199, 32_700, 99, 164],
]);

// ---------------------------------------------------------------------------
// Flagged and rejected change nothing but their own row (S6)
// ---------------------------------------------------------------------------

it('flags a run below the PROPOSED minimum duration and mutates nothing', function (): void {
    [$user, $run] = runWithWindow(10_000);
    app(ProgressionService::class)->ensure($user->id);
    $before = worldOf($user);

    finishRun($user, $run->id, plausibleTelemetry(3_999, 100, 5), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'flagged')
        ->assertJsonPath('data.reasons', ['duration_below_minimum'])
        ->assertJsonPath('data.score', 100)
        ->assertJsonPath('data.run_paws', 5)
        ->assertJsonPath('data.is_personal_best', false)
        ->assertJsonPath('data.progression.run_count', 0)
        ->assertJsonPath('data.progression.lifetime_paws', 0);

    $run->refresh();

    expect(worldOf($user))->toBe($before)
        ->and($run->status)->toBe(RunStatus::Flagged)
        ->and($run->score)->toBe(100)
        ->and($run->validation_meta['rules'])->toEqual([['code' => 'duration_below_minimum', 'observed' => 3999, 'bound' => 4000]]);
});

it('rejects a structurally impossible run, keeps only the attempt, and mutates nothing', function (): void {
    [$user, $run] = runWithWindow(31_000);
    app(ProgressionService::class)->ensure($user->id);
    $before = worldOf($user);

    finishRun($user, $run->id, plausibleTelemetry(30_000, 499, 50), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.reasons', ['score_below_paw_floor'])
        ->assertJsonPath('data.score', null)
        ->assertJsonPath('data.run_paws', null)
        ->assertJsonPath('data.is_personal_best', false);

    $run->refresh();

    expect(worldOf($user))->toBe($before)
        ->and($run->status)->toBe(RunStatus::Rejected)
        ->and($run->score)->toBeNull()
        ->and($run->run_paws)->toBeNull()
        ->and($run->duration_ms)->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->validation_meta['rules'][0])->toEqual(['code' => 'score_below_paw_floor', 'observed' => 499, 'bound' => 500]);
});

it('classifies each structural and plausibility rule at its bound', function (int $window, array $telemetry, string $status, array $reasons): void {
    [$user, $run] = runWithWindow($window);

    finishRun($user, $run->id, ['telemetry' => $telemetry], (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.reasons', $reasons);
})->with([
    'duration = window + tolerance' => [10_000, ['reported_duration_ms' => 15_000, 'reported_score' => 1000, 'reported_run_paws' => 20], 'accepted', []],
    'duration = window + tolerance + 1' => [10_000, ['reported_duration_ms' => 15_001, 'reported_score' => 1000, 'reported_run_paws' => 20], 'rejected', ['duration_exceeds_server_window']],
    'duration zero' => [10_000, ['reported_duration_ms' => 0, 'reported_score' => 0, 'reported_run_paws' => 0], 'rejected', ['duration_non_positive']],
    'score = 10 × paws' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => 500, 'reported_run_paws' => 50], 'accepted', []],
    'negative score' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => -1, 'reported_run_paws' => 0], 'rejected', ['value_out_of_domain']],
    'int4 max + 1' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => 2_147_483_648, 'reported_run_paws' => 0], 'rejected', ['value_out_of_domain']],
    'huge but int64' => [31_000, ['reported_duration_ms' => PHP_INT_MAX, 'reported_score' => PHP_INT_MAX, 'reported_run_paws' => PHP_INT_MAX], 'rejected', ['value_out_of_domain']],
    'score rate 80/s exactly' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => 2400, 'reported_run_paws' => 50], 'accepted', []],
    'score rate above 80/s' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => 2401, 'reported_run_paws' => 50], 'flagged', ['score_rate_high']],
    'paw rate 3/s exactly' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => 900, 'reported_run_paws' => 90], 'accepted', []],
    'paw rate above 3/s' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => 910, 'reported_run_paws' => 91], 'flagged', ['paw_rate_high']],
    'score floor 5/s exactly' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => 150, 'reported_run_paws' => 0], 'accepted', []],
    'score below 5/s' => [31_000, ['reported_duration_ms' => 30_000, 'reported_score' => 149, 'reported_run_paws' => 0], 'flagged', ['score_below_duration_floor']],
    'duration 4000 exactly' => [10_000, ['reported_duration_ms' => 4000, 'reported_score' => 100, 'reported_run_paws' => 5], 'accepted', []],
]);

it('accepts a finish however late it arrives while the run is still active', function (): void {
    [$user, $run] = runWithWindow(3 * 86_400_000);

    finishRun($user, $run->id, plausibleTelemetry(), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.reasons', []);
});

// ---------------------------------------------------------------------------
// Protocol (C-7) — 422, nothing stored, run still active
// ---------------------------------------------------------------------------

it('refuses a telemetry member that is not a JSON integer', function (string $member, string $literal): void {
    [$user, $run] = runWithWindow(31_000);
    $key = (string) Str::uuid();

    $members = ['reported_duration_ms' => '30000', 'reported_score' => '1000', 'reported_run_paws' => '50'];
    $members[$member] = $literal;

    $json = '{"telemetry":{'.implode(',', array_map(
        fn (string $name, string $value): string => '"'.$name.'":'.$value,
        array_keys($members),
        $members,
    )).'}}';

    finishRaw($user, $run->id, $json, $key)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ["telemetry.{$member}"]]);

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Active)
        ->and($run->idempotency_key)->toBeNull()
        ->and(DB::table('runs')->where('idempotency_key', $key)->exists())->toBeFalse();
})->with(['reported_duration_ms', 'reported_score', 'reported_run_paws'])->with([
    'numeric string' => ['"12"'],
    'float with zero fraction' => ['12.0'],
    'float' => ['12.5'],
    'exponent' => ['1e3'],
    'true' => ['true'],
    'null' => ['null'],
    'object' => ['{}'],
    'array' => ['[]'],
    'beyond int64' => ['9223372036854775808'],
    'huge' => ['1e20'],
]);

it('refuses a missing or malformed body', function (string $json): void {
    [$user, $run] = runWithWindow(31_000);

    finishRaw($user, $run->id, $json, (string) Str::uuid())->assertStatus(422);

    expect($run->refresh()->status)->toBe(RunStatus::Active);
})->with([
    'empty' => [''],
    'not JSON' => ['telemetry=1'],
    'no telemetry' => ['{}'],
    'telemetry not an object' => ['{"telemetry":5}'],
    'member missing' => ['{"telemetry":{"reported_duration_ms":30000,"reported_score":1000}}'],
    'top-level list' => ['[1,2,3]'],
]);

it('requires a UUID Idempotency-Key', function (?string $key): void {
    [$user, $run] = runWithWindow(31_000);

    finishRun($user, $run->id, plausibleTelemetry(), $key)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['idempotency_key']]);

    expect($run->refresh()->status)->toBe(RunStatus::Active);
})->with([
    'missing' => [null],
    'not a uuid' => ['retry-1'],
    'empty' => [''],
]);

it('does not let a body member stand in for the header', function (): void {
    [$user, $run] = runWithWindow(31_000);

    finishRun($user, $run->id, [...plausibleTelemetry(), 'idempotency_key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['idempotency_key']]);
});

// ---------------------------------------------------------------------------
// Ownership and lifecycle
// ---------------------------------------------------------------------------

it('answers 404 for another player\'s run, and leaves it untouched (S3)', function (): void {
    [, $run] = runWithWindow(31_000);
    $intruder = User::factory()->create();

    finishRun($intruder, $run->id, plausibleTelemetry(), (string) Str::uuid())
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    expect($run->refresh()->status)->toBe(RunStatus::Active);
});

it('answers 404 alike for an unknown run and a malformed id', function (string $id): void {
    $user = User::factory()->create();

    finishRun($user, $id, plausibleTelemetry(), (string) Str::uuid())
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
})->with([
    'unknown uuid' => ['01999999-9999-7999-8999-999999999999'],
    'not a uuid' => ['not-a-run'],
    'numeric' => ['12'],
]);

it('refuses a second finish under a different key with run_not_active', function (): void {
    [$user, $run] = runWithWindow(31_000);
    finishRun($user, $run->id, plausibleTelemetry(), (string) Str::uuid())->assertOk();
    $before = worldOf($user);

    finishRun($user, $run->id, plausibleTelemetry(), (string) Str::uuid())
        ->assertStatus(409)
        ->assertJsonPath('code', 'run_not_active');

    expect(worldOf($user))->toBe($before);
});

it('refuses to finish a run a later start replaced', function (): void {
    [$user, $run] = runWithWindow(86_400_001);

    startRun($user)->assertCreated();

    finishRun($user, $run->id, plausibleTelemetry(), (string) Str::uuid())
        ->assertStatus(409)
        ->assertJsonPath('code', 'run_not_active');
});

// ---------------------------------------------------------------------------
// Idempotency (GR-3)
// ---------------------------------------------------------------------------

it('replays the original result for the same key and body, writing nothing', function (): void {
    [$user, $run] = runWithWindow(31_000);
    $key = (string) Str::uuid();

    $first = finishRun($user, $run->id, plausibleTelemetry(), $key)->assertOk();
    $row = (array) DB::table('runs')->where('id', $run->id)->first();
    $before = worldOf($user);

    travel(10)->minutes();

    $second = finishRun($user, $run->id, plausibleTelemetry(), $key)->assertOk();

    // Semantic equality: same status, same decoded data. Not byte equality —
    // the stored copy is jsonb, which does not keep key order.
    expect($second->json())->toEqual($first->json())
        ->and((array) DB::table('runs')->where('id', $run->id)->first())->toBe($row)
        ->and(worldOf($user))->toBe($before);
});

it('replays a flagged and a rejected result too', function (array $telemetry, string $status): void {
    [$user, $run] = runWithWindow(31_000);
    $key = (string) Str::uuid();

    $first = finishRun($user, $run->id, ['telemetry' => $telemetry], $key)->assertOk()->assertJsonPath('data.status', $status);

    expect(finishRun($user, $run->id, ['telemetry' => $telemetry], $key)->assertOk()->json())->toEqual($first->json());
})->with([
    'flagged' => [['reported_duration_ms' => 3000, 'reported_score' => 100, 'reported_run_paws' => 5], 'flagged'],
    'rejected' => [['reported_duration_ms' => 0, 'reported_score' => 0, 'reported_run_paws' => 0], 'rejected'],
]);

it('normalises the key case, so an upper-cased retry is the same key', function (): void {
    [$user, $run] = runWithWindow(31_000);
    $key = (string) Str::uuid();

    $first = finishRun($user, $run->id, plausibleTelemetry(), strtoupper($key))->assertOk();

    expect(finishRun($user, $run->id, plausibleTelemetry(), $key)->assertOk()->json())->toEqual($first->json())
        ->and(DB::table('runs')->where('id', $run->id)->value('idempotency_key'))->toBe($key);
});

it('refuses the same key with a different body (409 idempotency_key_reused)', function (): void {
    [$user, $run] = runWithWindow(31_000);
    $key = (string) Str::uuid();

    finishRun($user, $run->id, plausibleTelemetry(30_000, 1000, 50), $key)->assertOk();
    $before = worldOf($user);

    finishRun($user, $run->id, plausibleTelemetry(30_000, 1001, 50), $key)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reused');

    expect(worldOf($user))->toBe($before);
});

it('refuses the same key on another run (409 idempotency_key_reused)', function (): void {
    [$user, $first] = runWithWindow(31_000);
    $key = (string) Str::uuid();
    finishRun($user, $first->id, plausibleTelemetry(), $key)->assertOk();

    [, $second] = runWithWindow(31_000, $user);

    finishRun($user, $second->id, plausibleTelemetry(), $key)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reused');

    expect($second->refresh()->status)->toBe(RunStatus::Active);
});

it('lets two players use the same key independently', function (): void {
    $key = (string) Str::uuid();
    [$alice, $aliceRun] = runWithWindow(0);
    [$bob, $bobRun] = runWithWindow(31_000);

    finishRun($alice, $aliceRun->id, plausibleTelemetry(), $key)->assertOk();
    finishRun($bob, $bobRun->id, plausibleTelemetry(), $key)->assertOk()->assertJsonPath('data.run_id', $bobRun->id);
});

it('ignores unknown members, including ANTI-6 counters, and they do not change the fingerprint', function (): void {
    [$user, $run] = runWithWindow(31_000);
    $key = (string) Str::uuid();

    $withExtras = plausibleTelemetry();
    $withExtras['telemetry'] += [
        'reported_loli_activations' => 99,
        'reported_near_miss_count' => 99,
        'reported_lane_blocking_passes' => 99,
        'reported_slayyy_activations' => 99,
        'reported_peak_queued_loli_bonuses' => 9,
    ];
    $withExtras['derived_facts'] = ['loli_activations' => 3];

    $first = finishRun($user, $run->id, $withExtras, $key)->assertOk();
    $second = finishRun($user, $run->id, plausibleTelemetry(), $key)->assertOk();

    $stored = (array) DB::table('runs')->where('id', $run->id)->first();

    expect($second->json())->toEqual($first->json())
        ->and(json_encode($first->json()))->not->toMatch('/loli_activation|near_miss|lane_blocking|slayyy|derived_facts/')
        ->and((string) $stored['validation_meta'].(string) $stored['result'])->not->toMatch('/loli_activation|near_miss|lane_blocking|slayyy/');
});

// ---------------------------------------------------------------------------
// Atomicity
// ---------------------------------------------------------------------------

it('rolls back progression and ledger when the final run update fails', function (): void {
    [$user, $run] = runWithWindow(31_000);
    app(ProgressionService::class)->ensure($user->id);
    $before = worldOf($user);

    // Fails the last write of the transaction — after progression and the
    // ledger have both been written — so everything before it must roll back.
    DB::unprepared("
        CREATE FUNCTION purrenade_test_fail_finish() RETURNS trigger AS \$\$
        BEGIN RAISE EXCEPTION 'injected failure'; END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER purrenade_test_fail_finish BEFORE UPDATE ON runs
            FOR EACH ROW WHEN (NEW.status = 'accepted') EXECUTE FUNCTION purrenade_test_fail_finish();
    ");

    $key = (string) Str::uuid();

    finishRun($user, $run->id, plausibleTelemetry(), $key)->assertStatus(500);

    expect(worldOf($user))->toBe($before)
        ->and($run->refresh()->status)->toBe(RunStatus::Active)
        ->and(DB::table('paw_ledger')->count())->toBe(0);

    DB::unprepared('DROP TRIGGER purrenade_test_fail_finish ON runs; DROP FUNCTION purrenade_test_fail_finish();');

    // And the same key then applies exactly once.
    finishRun($user, $run->id, plausibleTelemetry(), $key)->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(DB::table('paw_ledger')->count())->toBe(1)
        ->and((int) progressionRow($user)->run_count)->toBe(1);
});

it('reports tutorial completion inside the result progression', function (): void {
    [$user, $run] = runWithWindow(31_000);
    Pest\Laravel\withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    finishRun($user, $run->id, plausibleTelemetry(), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.progression.tutorial_completed', true);
});
