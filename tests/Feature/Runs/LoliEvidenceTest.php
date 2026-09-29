<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\Run;
use App\Models\RunLoliEvidence;
use App\Models\User;
use App\Services\Progression\ProgressionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

/**
 * ANTI-6 P1: the start cycle a run records, and the Loli evidence its
 * acceptance establishes (Option B).
 *
 * The properties that carry the weight: ABSENT is a missing row and never
 * zero; an accepted run with zero paws is PRESENT 0; the derivation reads the
 * cycle the run **started** from, never the one progression holds at finish;
 * nothing but an accepted run ever gets a row; and once written, a row can be
 * neither changed nor removed — by the application or by a hand-typed query.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-29 12:00:00.000', 'UTC'));
});

/** Set the player's persistent cycle, creating the row if needed. */
function setLoliCycle(User $user, int $cycle): void
{
    app(ProgressionService::class)->ensure($user->id);
    DB::table('player_progression')->where('user_id', $user->id)->update(['loli_cycle_paws' => $cycle]);
}

/** Start a run through the endpoint, then move the clock on by `$windowMs`. */
function startLoliRun(User $user, int $windowMs = 31_000): Run
{
    $runId = startRun($user)->assertCreated()->json('data.run_id');

    travel($windowMs)->milliseconds();

    return Run::query()->findOrFail($runId);
}

/** The run's evidence row, read straight from the table, or null when ABSENT. */
function loliEvidenceRow(string $runId): ?object
{
    return DB::table('run_loli_evidence')->where('run_id', $runId)->first();
}

/** A run in `$status`, written straight to `runs`, for the schema tests. */
function loliSchemaRun(User $user, string $status): string
{
    if ($status !== 'active') {
        return insertFinishedRun($user, 1000, 30_000, '2026-09-29 11:00:00.000', '2026-09-29 11:01:00.000', $status);
    }

    $id = (string) Str::uuid7();

    DB::table('runs')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'character_id' => Character::query()->where('key', 'aysenur')->value('id'),
        'status' => 'active',
        'seed' => 1,
        'start_loli_cycle_paws' => 0,
        'started_at' => '2026-09-29 11:00:00.000',
        'created_at' => '2026-09-29 11:00:00.000',
        'updated_at' => '2026-09-29 11:00:00.000',
    ]);

    return $id;
}

function insertLoliEvidence(string $runId, array $overrides = []): void
{
    DB::table('run_loli_evidence')->insert([
        'run_id' => $runId,
        'loli_activations' => 0,
        'evidence_version' => 1,
        'established_at' => '2026-09-29 12:00:00.000',
        ...$overrides,
    ]);
}

/**
 * The exception a statement raises, run inside a savepoint so the refusal does
 * not poison the test's surrounding transaction.
 */
function loliRefusal(Closure $statement): ?Throwable
{
    $refusal = null;

    DB::beginTransaction();

    try {
        $statement();
    } catch (Throwable $e) {
        $refusal = $e;
    }

    DB::rollBack();

    return $refusal;
}

// ---------------------------------------------------------------------------
// Start records the cycle (F1)
// ---------------------------------------------------------------------------

it('records the cycle a new run starts from, and answers with that same value', function (int $cycle): void {
    $user = User::factory()->create();
    setLoliCycle($user, $cycle);

    $data = startRun($user)->assertCreated()->assertJsonPath('data.loli_cycle_paws', $cycle)->json('data');

    expect(DB::table('runs')->where('id', $data['run_id'])->value('start_loli_cycle_paws'))->toBe($cycle);
})->with([0, 1, 137, 199]);

it('records zero for a player with no progression row yet', function (): void {
    $user = User::factory()->create();

    $runId = startRun($user)->assertCreated()->json('data.run_id');

    expect(DB::table('runs')->where('id', $runId)->value('start_loli_cycle_paws'))->toBe(0);
});

it('keeps the recorded start cycle when the run is resumed after progression moved', function (): void {
    $user = User::factory()->create();
    setLoliCycle($user, 120);
    $run = startLoliRun($user, 1_000);

    setLoliCycle($user, 5);
    startRun($user)->assertOk()->assertJsonPath('data.run_id', $run->id);

    expect($run->refresh()->start_loli_cycle_paws)->toBe(120);
});

it('records the current cycle for the run that replaces a stale one', function (): void {
    $user = User::factory()->create();
    setLoliCycle($user, 40);
    $stale = startLoliRun($user, 1_000);

    travel(86_400)->seconds();
    setLoliCycle($user, 160);

    $runId = startRun($user)->assertCreated()->json('data.run_id');

    expect(DB::table('runs')->where('id', $runId)->value('start_loli_cycle_paws'))->toBe(160)
        ->and($stale->refresh()->start_loli_cycle_paws)->toBe(40);
});

// ---------------------------------------------------------------------------
// Acceptance establishes the evidence
// ---------------------------------------------------------------------------

it('establishes PRESENT 0 for an accepted run with zero paws', function (): void {
    $user = User::factory()->create();
    $run = startLoliRun($user);

    finishRun($user, $run->id, plausibleTelemetry(30_000, 1000, 0), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    $row = loliEvidenceRow($run->id);

    expect($row)->not->toBeNull()
        ->and((int) $row->loli_activations)->toBe(0)
        ->and((int) $row->evidence_version)->toBe(RunLoliEvidence::EVIDENCE_VERSION)
        ->and($row->established_at)->toBe('2026-09-29 12:00:31')
        ->and(DB::table('paw_ledger')->count())->toBe(0);
});

it('derives activations from the start cycle and the accepted paws', function (int $cycle, int $paws, int $activations): void {
    $user = User::factory()->create();
    setLoliCycle($user, $cycle);
    $run = startLoliRun($user, 200_000);

    finishRun($user, $run->id, plausibleTelemetry(200_000, max(1000, 10 * $paws), $paws), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    expect((int) loliEvidenceRow($run->id)->loli_activations)->toBe($activations);
})->with([
    'below the threshold' => [0, 150, 0],
    '199 + 0' => [199, 0, 0],
    '199 + 1' => [199, 1, 1],
    'exactly to the threshold' => [150, 50, 1],
    '1 + 199' => [1, 199, 1],
    '0 + 400' => [0, 400, 2],
    '199 + 599' => [199, 599, 3],
]);

it('derives from the cycle the run started from, never the one progression holds at finish', function (): void {
    $user = User::factory()->create();
    setLoliCycle($user, 137);
    $run = startLoliRun($user);

    // Progression moves while the run is active (a recomputation, for
    // example). The evidence must still describe the run the client played.
    setLoliCycle($user, 10);

    finishRun($user, $run->id, plausibleTelemetry(30_000, 1000, 70), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    // floor((137 + 70) / 200) = 1; the finish-time cycle would give 0.
    expect((int) loliEvidenceRow($run->id)->loli_activations)->toBe(1)
        // Accounting is unchanged: the ledger still reads the locked cycle.
        ->and((int) DB::table('paw_ledger')->where('run_id', $run->id)->value('bonuses_triggered'))->toBe(0);
});

it('leaves the evidence ABSENT for a run started before the start cycle was recorded', function (): void {
    $user = User::factory()->create();
    setLoliCycle($user, 190);
    $run = startLoliRun($user);

    // A run started before the deploy has no recorded cycle.
    DB::table('runs')->where('id', $run->id)->update(['start_loli_cycle_paws' => null]);

    finishRun($user, $run->id, plausibleTelemetry(30_000, 1000, 50), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    // Accepted in every other respect — and nothing fabricated for Loli.
    expect(loliEvidenceRow($run->id))->toBeNull()
        ->and(DB::table('paw_ledger')->where('run_id', $run->id)->count())->toBe(1)
        ->and((int) progressionRow($user)->run_count)->toBe(1);
});

it('establishes nothing for a flagged or a rejected run', function (array $telemetry, int $window, string $status): void {
    $user = User::factory()->create();
    $run = startLoliRun($user, $window);

    finishRun($user, $run->id, ['telemetry' => $telemetry], (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', $status);

    expect(DB::table('run_loli_evidence')->count())->toBe(0);
})->with([
    'flagged' => [['reported_duration_ms' => 3_999, 'reported_score' => 100, 'reported_run_paws' => 5], 10_000, 'flagged'],
    'rejected' => [['reported_duration_ms' => 30_000, 'reported_score' => 499, 'reported_run_paws' => 50], 31_000, 'rejected'],
]);

it('establishes nothing for a stale run a later start replaced', function (): void {
    $user = User::factory()->create();
    $stale = startLoliRun($user, 1_000);

    travel(86_400)->seconds();
    startRun($user)->assertCreated();

    finishRun($user, $stale->id, plausibleTelemetry(), (string) Str::uuid())->assertStatus(409);

    expect(DB::table('run_loli_evidence')->count())->toBe(0);
});

it('writes nothing new when the finish is retried with the same key', function (): void {
    $user = User::factory()->create();
    setLoliCycle($user, 180);
    $run = startLoliRun($user);
    $key = (string) Str::uuid();

    finishRun($user, $run->id, plausibleTelemetry(), $key)->assertOk();
    $first = loliEvidenceRow($run->id);

    travel(10)->minutes();

    finishRun($user, $run->id, plausibleTelemetry(), $key)->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(DB::table('run_loli_evidence')->count())->toBe(1)
        ->and(loliEvidenceRow($run->id))->toEqual($first)
        ->and((int) $first->loli_activations)->toBe(1);
});

it('does not change the finish result', function (): void {
    $user = User::factory()->create();
    $run = startLoliRun($user);

    $data = finishRun($user, $run->id, plausibleTelemetry(), (string) Str::uuid())->assertOk()->json('data');

    expect(array_keys($data))->toBe([
        'run_id', 'status', 'score', 'run_paws', 'is_personal_best', 'previous_best_score',
        'reasons', 'progression', 'achievements_unlocked', 'characters_unlocked',
    ])->and(json_encode($data))->not->toContain('loli_activations');
});

it('rolls the whole acceptance back when the evidence insert fails, and then applies it once', function (): void {
    $user = User::factory()->create();
    setLoliCycle($user, 199);
    $run = startLoliRun($user);
    $before = [
        'progression' => (array) progressionRow($user),
        'ledger' => DB::table('paw_ledger')->count(),
        'all_time' => DB::table('leaderboard_all_time')->count(),
        'weekly' => DB::table('leaderboard_weekly')->count(),
    ];

    // Fails the evidence insert itself — the last write of the transaction,
    // after progression, the ledger, both leaderboards and the run update.
    DB::unprepared("
        CREATE FUNCTION purrenade_test_fail_evidence() RETURNS trigger AS \$\$
        BEGIN RAISE EXCEPTION 'injected failure'; END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER purrenade_test_fail_evidence AFTER INSERT ON run_loli_evidence
            FOR EACH ROW EXECUTE FUNCTION purrenade_test_fail_evidence();
    ");

    $key = (string) Str::uuid();

    finishRun($user, $run->id, plausibleTelemetry(), $key)->assertStatus(500);

    expect([
        'progression' => (array) progressionRow($user),
        'ledger' => DB::table('paw_ledger')->count(),
        'all_time' => DB::table('leaderboard_all_time')->count(),
        'weekly' => DB::table('leaderboard_weekly')->count(),
    ])->toBe($before)
        ->and($run->refresh()->status->value)->toBe('active')
        ->and($run->result)->toBeNull()
        ->and(DB::table('run_loli_evidence')->count())->toBe(0);

    DB::unprepared('DROP TRIGGER purrenade_test_fail_evidence ON run_loli_evidence; DROP FUNCTION purrenade_test_fail_evidence();');

    finishRun($user, $run->id, plausibleTelemetry(), $key)->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(DB::table('run_loli_evidence')->count())->toBe(1)
        ->and((int) loliEvidenceRow($run->id)->loli_activations)->toBe(1)
        ->and(DB::table('paw_ledger')->count())->toBe(1);
});

it('leaves no evidence when an earlier write of the acceptance fails', function (): void {
    $user = User::factory()->create();
    $run = startLoliRun($user);

    DB::unprepared("
        CREATE FUNCTION purrenade_test_fail_finish() RETURNS trigger AS \$\$
        BEGIN RAISE EXCEPTION 'injected failure'; END \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER purrenade_test_fail_finish BEFORE UPDATE ON runs
            FOR EACH ROW WHEN (NEW.status = 'accepted') EXECUTE FUNCTION purrenade_test_fail_finish();
    ");

    finishRun($user, $run->id, plausibleTelemetry(), (string) Str::uuid())->assertStatus(500);

    expect(DB::table('run_loli_evidence')->count())->toBe(0)
        ->and($run->refresh()->status->value)->toBe('active');

    DB::unprepared('DROP TRIGGER purrenade_test_fail_finish ON runs; DROP FUNCTION purrenade_test_fail_finish();');
});

// ---------------------------------------------------------------------------
// The database's half (E1, E2, E3)
// ---------------------------------------------------------------------------

it('admits evidence only for an accepted run (E1)', function (string $status): void {
    $user = User::factory()->create();
    $runId = loliSchemaRun($user, $status);

    $refusal = loliRefusal(fn () => insertLoliEvidence($runId));

    expect($refusal)->toBeInstanceOf(QueryException::class)
        ->and($refusal->getMessage())->toContain('may only be established for an accepted run');
})->with(['active', 'flagged', 'rejected']);

it('admits evidence for an accepted run written directly', function (): void {
    $user = User::factory()->create();
    $runId = loliSchemaRun($user, 'accepted');

    insertLoliEvidence($runId, ['loli_activations' => 2]);

    expect((int) loliEvidenceRow($runId)->loli_activations)->toBe(2);
});

it('refuses to update or delete established evidence (E2)', function (Closure $change, string $operation): void {
    $user = User::factory()->create();
    $runId = loliSchemaRun($user, 'accepted');
    insertLoliEvidence($runId, ['loli_activations' => 1]);

    $refusal = loliRefusal(fn () => $change($runId));

    expect($refusal)->toBeInstanceOf(QueryException::class)
        ->and($refusal->getMessage())->toContain("insert-only: {$operation} refused")
        ->and((int) loliEvidenceRow($runId)->loli_activations)->toBe(1);
})->with([
    'update the count' => [fn (string $id) => DB::table('run_loli_evidence')->where('run_id', $id)->update(['loli_activations' => 5]), 'UPDATE'],
    'update to zero' => [fn (string $id) => DB::table('run_loli_evidence')->where('run_id', $id)->update(['loli_activations' => 0]), 'UPDATE'],
    'update the version' => [fn (string $id) => DB::table('run_loli_evidence')->where('run_id', $id)->update(['evidence_version' => 2]), 'UPDATE'],
    'delete the row' => [fn (string $id) => DB::table('run_loli_evidence')->where('run_id', $id)->delete(), 'DELETE'],
    'delete every row' => [fn (string $id) => DB::table('run_loli_evidence')->delete(), 'DELETE'],
]);

it('refuses a second row for the same run (E3)', function (): void {
    $user = User::factory()->create();
    $runId = loliSchemaRun($user, 'accepted');
    insertLoliEvidence($runId);

    expectRefused(fn () => insertLoliEvidence($runId, ['loli_activations' => 3]), UniqueConstraintViolationException::class);
});

it('refuses to delete a run that has evidence', function (): void {
    $user = User::factory()->create();
    $runId = loliSchemaRun($user, 'accepted');
    insertLoliEvidence($runId);

    expectRefused(fn () => DB::table('runs')->where('id', $runId)->delete());
});

it('refuses impossible evidence values', function (array $values): void {
    $user = User::factory()->create();
    $runId = loliSchemaRun($user, 'accepted');

    expectRefused(fn () => insertLoliEvidence($runId, $values));
})->with([
    'negative activations' => [['loli_activations' => -1]],
    'version zero' => [['evidence_version' => 0]],
    'null activations' => [['loli_activations' => null]],
    'null version' => [['evidence_version' => null]],
    'null established_at' => [['established_at' => null]],
]);

it('refuses a start cycle outside the cycle\'s own domain, and keeps NULL for ABSENT', function (): void {
    $user = User::factory()->create();
    $runId = loliSchemaRun($user, 'active');

    foreach ([200, -1] as $cycle) {
        expectRefused(fn () => DB::table('runs')->where('id', $runId)->update(['start_loli_cycle_paws' => $cycle]));
    }

    DB::table('runs')->where('id', $runId)->update(['start_loli_cycle_paws' => null]);

    expect(DB::table('runs')->where('id', $runId)->value('start_loli_cycle_paws'))->toBeNull();
});

it('rolls the P1 migrations back and forward cleanly', function (): void {
    $migrations = collect([
        '2026_09_29_100100_create_run_loli_evidence_table.php',
        '2026_09_29_100000_add_start_loli_cycle_paws_to_runs_table.php',
    ])->map(fn (string $file): object => require database_path('migrations/'.$file));

    $functions = fn (): int => (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'run_loli_evidence_%' AND pronamespace = current_schema()::regnamespace");

    $migrations->each(fn (object $m) => $m->down());

    expect(Schema::hasTable('run_loli_evidence'))->toBeFalse()
        ->and(Schema::hasColumn('runs', 'start_loli_cycle_paws'))->toBeFalse()
        ->and($functions())->toBe(0);

    $migrations->reverse()->each(fn (object $m) => $m->up());

    expect(Schema::hasTable('run_loli_evidence'))->toBeTrue()
        ->and(Schema::hasColumn('runs', 'start_loli_cycle_paws'))->toBeTrue()
        ->and($functions())->toBe(2);
});
