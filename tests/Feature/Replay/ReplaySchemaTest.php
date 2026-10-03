<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database's own guarantees for ANTI-6 P3, proven with hand-written SQL —
 * the application's checks are not what is under test here.
 */
function replaySchemaRun(string $status = 'accepted'): string
{
    return insertFinishedRun(User::factory()->create(), 1000, 30_000, '2026-10-03 11:00:00.000', '2026-10-03 11:01:00.000', $status);
}

function insertWorkRow(string $runId, array $overrides = []): void
{
    // Datasets are built before the application boots; `raw:` marks SQL.
    $overrides = array_map(fn (mixed $v): mixed => is_string($v) && str_starts_with($v, 'raw:') ? DB::raw(substr($v, 4)) : $v, $overrides);

    DB::table('run_replay_inputs')->insert([
        'run_id' => $runId,
        'state' => 'pending',
        'outcome_code' => null,
        'attempts' => 0,
        'input' => DB::raw("'\\x0102'::bytea"),
        'input_expires_at' => '2026-10-04 12:00:00.000',
        'created_at' => '2026-10-03 12:00:00.000',
        'updated_at' => '2026-10-03 12:00:00.000',
        ...$overrides,
    ]);
}

function insertReplayEvidence(string $runId, array $overrides = []): void
{
    DB::table('run_replay_evidence')->insert([
        'run_id' => $runId,
        'cone_safe_passes' => 1,
        'near_misses' => 2,
        'slayyy_activations' => 3,
        'evidence_version' => 1,
        'domain_version' => '1',
        'established_at' => '2026-10-03 12:00:00.000',
        ...$overrides,
    ]);
}

// --- run_replay_inputs ---------------------------------------------------------

it('admits a work row only for an accepted run', function (string $status): void {
    $runId = replaySchemaRun($status);

    expectRefused(fn () => insertWorkRow($runId));
})->with(['flagged', 'rejected']);

it('holds the pending/terminal shape', function (array $row): void {
    $runId = replaySchemaRun();

    expectRefused(fn () => insertWorkRow($runId, $row));
})->with([
    'pending with an outcome' => [['outcome_code' => 'established']],
    'pending without input' => [['input' => null]],
    'pending without expiry' => [['input_expires_at' => null]],
    'terminal with input' => [['state' => 'terminal', 'outcome_code' => 'established']],
    'terminal without outcome' => [['state' => 'terminal', 'input' => null]],
    'unknown state' => [['state' => 'running']],
    'unknown outcome' => [['state' => 'terminal', 'outcome_code' => 'rejected', 'input' => null]],
    'negative attempts' => [['attempts' => -1]],
    'empty input' => [['input' => "raw:''::bytea"]],
]);

it('makes a terminal row final', function (): void {
    $runId = replaySchemaRun();
    insertWorkRow($runId, ['state' => 'terminal', 'outcome_code' => 'established', 'input' => null, 'input_expires_at' => null]);

    expectRefused(fn () => DB::update("UPDATE run_replay_inputs SET outcome_code = 'result_invalid' WHERE run_id = ?", [$runId]));
    expectRefused(fn () => DB::update("UPDATE run_replay_inputs SET state = 'pending', input = '\\x01'::bytea WHERE run_id = ?", [$runId]));
});

it('lets a pending row become terminal only by clearing its input', function (): void {
    $runId = replaySchemaRun();
    insertWorkRow($runId);

    expectRefused(fn () => DB::update("UPDATE run_replay_inputs SET state = 'terminal', outcome_code = 'established' WHERE run_id = ?", [$runId]));

    DB::update("UPDATE run_replay_inputs SET state = 'terminal', outcome_code = 'established', input = NULL WHERE run_id = ?", [$runId]);
    expect(DB::table('run_replay_inputs')->where('run_id', $runId)->value('state'))->toBe('terminal');
});

it('refuses to delete a work row', function (): void {
    $runId = replaySchemaRun();
    insertWorkRow($runId);

    expectRefused(fn () => DB::table('run_replay_inputs')->where('run_id', $runId)->delete());
});

it('holds at most one work row per run', function (): void {
    $runId = replaySchemaRun();
    insertWorkRow($runId);

    expectRefused(fn () => insertWorkRow($runId));
});

// --- run_replay_evidence ---------------------------------------------------------

it('establishes evidence only for an accepted run (E1)', function (string $status): void {
    $runId = replaySchemaRun($status);

    expectRefused(fn () => insertReplayEvidence($runId));
})->with(['flagged', 'rejected']);

it('refuses negative facts and malformed provenance', function (array $row): void {
    $runId = replaySchemaRun();

    expectRefused(fn () => insertReplayEvidence($runId, $row));
})->with([
    [['cone_safe_passes' => -1]],
    [['near_misses' => -1]],
    [['slayyy_activations' => -1]],
    [['evidence_version' => 0]],
    [['domain_version' => '1-dirty']],
    [['domain_version' => '']],
]);

it('is insert-only (E2) and one row per run (E3)', function (): void {
    $runId = replaySchemaRun();
    insertReplayEvidence($runId);

    expectRefused(fn () => DB::table('run_replay_evidence')->where('run_id', $runId)->update(['near_misses' => 99]));
    expectRefused(fn () => DB::table('run_replay_evidence')->where('run_id', $runId)->delete());
    expectRefused(fn () => insertReplayEvidence($runId));
});

it('keeps evidence when the run is later invalidated, excluded by status rather than erased', function (): void {
    $runId = replaySchemaRun();
    insertReplayEvidence($runId);

    DB::table('runs')->where('id', $runId)->update(['status' => 'rejected']);

    expect(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeTrue();
});

it('rolls both migrations back and forward cleanly', function (): void {
    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

    expect(Schema::hasTable('run_replay_inputs'))->toBeFalse()
        ->and(Schema::hasTable('run_replay_evidence'))->toBeFalse()
        ->and(DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'run_replay_%'"))->toBe(0);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('run_replay_inputs'))->toBeTrue()
        ->and(Schema::hasTable('run_replay_evidence'))->toBeTrue();
});
