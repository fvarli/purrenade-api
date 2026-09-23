<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Progression\ProgressionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/**
 * GET /progression, and the M9 relocation of tutorial completion onto
 * `player_progression` (expand → backfill → verify; the drop is a later,
 * separate deployment and is asserted *not* to have happened).
 */
function progressionMigration(): object
{
    return require database_path('migrations/2026_09_23_100100_create_player_progression_table.php');
}

// ---------------------------------------------------------------------------
// GET /progression
// ---------------------------------------------------------------------------

it('reads a player with no row as zeros, and writes nothing', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->getJson('/api/v1/progression')
        ->assertOk()
        ->assertExactJson(['data' => [
            'lifetime_paws' => 0,
            'loli_cycle_paws' => 0,
            'loli_threshold' => 200,
            'best_score' => 0,
            'run_count' => 0,
            'tutorial_completed' => false,
        ]]);

    expect(progressionRow($user))->toBeNull();
});

it('reflects accepted runs', function (): void {
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    Pest\Laravel\travel(31)->seconds();
    finishRun($user, $runId, plausibleTelemetry(), (string) Str::uuid())->assertOk();

    forgetAuthGuards();

    withHeaders(sessionFor($user))
        ->getJson('/api/v1/progression')
        ->assertOk()
        ->assertJsonPath('data.lifetime_paws', 50)
        ->assertJsonPath('data.loli_cycle_paws', 50)
        ->assertJsonPath('data.best_score', 1000)
        ->assertJsonPath('data.run_count', 1);
});

it('exposes no ANTI-6 counter and no queued Loli field', function (): void {
    $user = User::factory()->create();

    $data = withHeaders(sessionFor($user))->getJson('/api/v1/progression')->assertOk()->json('data');

    expect(array_keys($data))->toEqualCanonicalizing([
        'lifetime_paws', 'loli_cycle_paws', 'loli_threshold', 'best_score', 'run_count', 'tutorial_completed',
    ]);
});

it('requires an authenticated, verified caller', function (): void {
    getJson('/api/v1/progression')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

    $unverified = User::factory()->unverified()->create();

    withHeaders(sessionFor($unverified))
        ->getJson('/api/v1/progression')
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified');
});

// ---------------------------------------------------------------------------
// Relocation: backfill
// ---------------------------------------------------------------------------

it('backfills every player with their exact completion timestamp', function (): void {
    $done = User::factory()->create();
    $notDone = User::factory()->create();
    DB::table('users')->where('id', $done->id)->update(['tutorial_completed_at' => '2026-09-21 18:04:07']);

    $migration = progressionMigration();
    $migration->down();
    $migration->up();

    $rows = DB::table('player_progression')->orderBy('user_id')->get()->keyBy('user_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$done->id]->tutorial_completed_at)->toBe('2026-09-21 18:04:07')
        ->and($rows[$notDone->id]->tutorial_completed_at)->toBeNull()
        ->and((int) $rows[$done->id]->lifetime_paws)->toBe(0)
        ->and((int) $rows[$done->id]->run_count)->toBe(0);
});

it('refuses to commit a backfill that differs from the source', function (): void {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['tutorial_completed_at' => '2026-09-21 18:04:07']);

    $migration = progressionMigration();
    $migration->down();
    $migration->up();

    DB::table('player_progression')->where('user_id', $user->id)->update(['tutorial_completed_at' => '2026-09-21 18:04:08']);

    expect(function () use ($migration): void {
        $migration->assertBackfill();
    })->toThrow(RuntimeException::class, 'backfill mismatch');
});

it('refuses to commit a backfill that misses a player', function (): void {
    User::factory()->create();

    $migration = progressionMigration();
    $migration->down();
    $migration->up();

    DB::table('player_progression')->delete();

    expect(function () use ($migration): void {
        $migration->assertBackfill();
    })->toThrow(RuntimeException::class, 'backfill mismatch');
});

it('keeps the legacy column: users.tutorial_completed_at is not dropped in M9', function (): void {
    expect(Schema::hasColumn('users', 'tutorial_completed_at'))->toBeTrue()
        ->and(Schema::hasColumn('player_progression', 'tutorial_completed_at'))->toBeTrue();

    $shapes = [];

    foreach (['player_progression', 'users'] as $table) {
        $column = DB::selectOne("
            SELECT data_type, datetime_precision FROM information_schema.columns
            WHERE column_name = 'tutorial_completed_at' AND table_schema = current_schema() AND table_name = ?
        ", [$table]);

        $shapes[] = [(string) $column->data_type, (int) $column->datetime_precision];
    }

    // Same type and precision, so the copy is exact rather than merely close.
    expect($shapes)->toBe([['timestamp without time zone', 0], ['timestamp without time zone', 0]]);
});

// ---------------------------------------------------------------------------
// Relocation: dual-write and read-either
// ---------------------------------------------------------------------------

it('writes completion to both columns with the same instant', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    $legacy = DB::table('users')->where('id', $user->id)->value('tutorial_completed_at');
    $current = DB::table('player_progression')->where('user_id', $user->id)->value('tutorial_completed_at');

    expect($current)->not->toBeNull()->toBe($legacy);
});

it('reads completion written only to the legacy column (the pre-reload window)', function (): void {
    $user = User::factory()->create();
    app(ProgressionService::class)->ensure($user->id);

    // What the previous release does between the migration and the reload.
    DB::table('users')->where('id', $user->id)->update(['tutorial_completed_at' => Carbon::now()]);

    withHeaders(sessionFor($user))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tutorial_completed', true);
    withHeaders(sessionFor($user))->getJson('/api/v1/progression')->assertOk()->assertJsonPath('data.tutorial_completed', true);
});

it('reads completion written only to the new column', function (): void {
    $user = User::factory()->create();
    app(ProgressionService::class)->ensure($user->id);
    DB::table('player_progression')->where('user_id', $user->id)->update(['tutorial_completed_at' => Carbon::now()]);

    withHeaders(sessionFor($user))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tutorial_completed', true);
});

it('does not re-stamp either column on a replay', function (): void {
    $user = User::factory()->create();
    withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    $legacy = DB::table('users')->where('id', $user->id)->value('tutorial_completed_at');
    $current = DB::table('player_progression')->where('user_id', $user->id)->value('tutorial_completed_at');

    Pest\Laravel\travel(1)->days();

    withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('tutorial_completed_at'))->toBe($legacy)
        ->and(DB::table('player_progression')->where('user_id', $user->id)->value('tutorial_completed_at'))->toBe($current);
});

it('fills the missing half when only the legacy column was set', function (): void {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['tutorial_completed_at' => '2026-09-20 10:00:00']);

    withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    // The legacy timestamp stands; the new column gets this completion's.
    expect(DB::table('users')->where('id', $user->id)->value('tutorial_completed_at'))->toBe('2026-09-20 10:00:00')
        ->and(DB::table('player_progression')->where('user_id', $user->id)->value('tutorial_completed_at'))->not->toBeNull();
});

it('does not touch progression counters or runs when the tutorial completes', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    $row = progressionRow($user);

    expect((int) $row->lifetime_paws)->toBe(0)
        ->and((int) $row->loli_cycle_paws)->toBe(0)
        ->and((int) $row->best_score)->toBe(0)
        ->and((int) $row->run_count)->toBe(0)
        ->and(DB::table('runs')->count())->toBe(0)
        ->and(DB::table('paw_ledger')->count())->toBe(0);
});

it('creates the progression row at registration', function (): void {
    Pest\Laravel\postJson('/api/v1/auth/register', [
        'display_name' => 'NewPlayer',
        'email' => 'new-player@example.test',
        'password' => 'a-long-enough-passphrase-42',
        'password_confirmation' => 'a-long-enough-passphrase-42',
    ])->assertCreated();

    $user = User::query()->where('email', 'new-player@example.test')->firstOrFail();

    expect(progressionRow($user))->not->toBeNull();
});
