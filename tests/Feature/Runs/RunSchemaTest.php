<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\User;
use App\Services\Progression\ProgressionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The database's half of the run lifecycle: invariants that hold whatever the
 * application does (S10, D1, D7, D8).
 *
 * Every statement here is written straight to the table, bypassing the
 * service, because the point is that the database refuses it on its own.
 */
function insertRun(User $user, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('runs')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'character_id' => Character::query()->where('key', 'aysenur')->value('id'),
        'status' => 'active',
        'seed' => 1,
        'started_at' => '2026-09-23 12:00:00.000',
        'created_at' => '2026-09-23 12:00:00.000',
        'updated_at' => '2026-09-23 12:00:00.000',
        ...$overrides,
    ]);

    return $id;
}

/** A complete finished shape, for the rows that must carry one. */
function finishedShape(string $status): array
{
    return [
        'status' => $status,
        'finished_at' => '2026-09-23 12:01:00.000',
        'duration_ms' => 30000,
        'score' => 1000,
        'run_paws' => 50,
        'idempotency_key' => (string) Str::uuid(),
        'idempotency_fingerprint' => str_repeat('a', 64),
        'result' => '{}',
        'validation_meta' => '{}',
    ];
}

it('holds at most one active run per player (GR-4)', function (): void {
    $user = User::factory()->create();
    insertRun($user);

    expectRefused(fn () => insertRun($user), UniqueConstraintViolationException::class);

    // Another player is unaffected, and a finished run does not occupy the slot.
    insertRun(User::factory()->create());
    insertRun($user, finishedShape('accepted'));

    expect(DB::table('runs')->where('user_id', $user->id)->where('status', 'active')->count())->toBe(1);
});

it('infers the partial unique index as the ON CONFLICT arbiter', function (): void {
    $user = User::factory()->create();
    $existing = insertRun($user);

    $row = DB::selectOne(
        "INSERT INTO runs (id, user_id, character_id, status, seed, started_at, created_at, updated_at)
         VALUES (?, ?, ?, 'active', 2, now(), now(), now())
         ON CONFLICT (user_id) WHERE status = 'active' DO NOTHING RETURNING id",
        [(string) Str::uuid7(), $user->id, Character::query()->where('key', 'aysenur')->value('id')],
    );

    expect($row)->toBeNull()
        ->and(DB::table('runs')->where('user_id', $user->id)->pluck('id')->all())->toBe([$existing]);
});

it('holds one finish identity per player and key (GR-3)', function (): void {
    $user = User::factory()->create();
    $key = (string) Str::uuid();

    insertRun($user, [...finishedShape('accepted'), 'idempotency_key' => $key]);

    expectRefused(
        fn () => insertRun($user, [...finishedShape('flagged'), 'idempotency_key' => $key]),
        UniqueConstraintViolationException::class,
    );

    // The same key belongs to each player separately.
    insertRun(User::factory()->create(), [...finishedShape('accepted'), 'idempotency_key' => $key]);
});

it('lets a run credit the paw ledger only once', function (): void {
    $user = User::factory()->create();
    $run = insertRun($user, finishedShape('accepted'));

    $entry = ['user_id' => $user->id, 'run_id' => $run, 'delta' => 5, 'resulting_cycle' => 5, 'bonuses_triggered' => 0, 'created_at' => now()];
    DB::table('paw_ledger')->insert($entry);

    expectRefused(fn () => DB::table('paw_ledger')->insert($entry), UniqueConstraintViolationException::class);
});

it('refuses every run shape the lifecycle cannot produce', function (array $overrides): void {
    $user = User::factory()->create();

    expectRefused(fn () => insertRun($user, $overrides));
})->with([
    'unknown status' => [['status' => 'expired']],
    'seed below zero' => [['seed' => -1]],
    'seed above uint32' => [['seed' => 4294967296]],
    'active with finished_at' => [['finished_at' => '2026-09-23 12:01:00.000']],
    'active with a score' => [['score' => 10]],
    'active with a key' => [['idempotency_key' => (string) Str::uuid(), 'idempotency_fingerprint' => str_repeat('a', 64)]],
    'finished before started' => [[...finishedShape('accepted'), 'finished_at' => '2026-09-23 11:59:59.999']],
    'accepted without score' => [[...finishedShape('accepted'), 'score' => null]],
    'flagged without result' => [[...finishedShape('flagged'), 'result' => null]],
    'accepted without key' => [[...finishedShape('accepted'), 'idempotency_key' => null, 'idempotency_fingerprint' => null]],
    'key without fingerprint' => [[...finishedShape('rejected'), 'idempotency_fingerprint' => null]],
    'negative score' => [[...finishedShape('accepted'), 'score' => -1]],
    'zero duration' => [[...finishedShape('accepted'), 'duration_ms' => 0]],
    'terminal without finished_at' => [[...finishedShape('rejected'), 'finished_at' => null]],
]);

it('accepts the rejected shapes the lifecycle does produce', function (): void {
    $user = User::factory()->create();

    // Stale-replaced: no key, no claimed values, no result.
    insertRun($user, ['status' => 'rejected', 'finished_at' => '2026-09-24 12:00:01.000', 'validation_meta' => '{}']);

    // Classified rejection: identity and result, claimed values null.
    insertRun($user, [...finishedShape('rejected'), 'score' => null, 'run_paws' => null, 'duration_ms' => null]);

    expect(DB::table('runs')->count())->toBe(2);
});

it('refuses impossible progression and ledger values', function (): void {
    $user = User::factory()->create();
    app(ProgressionService::class)->ensure($user->id);
    $run = insertRun($user, finishedShape('accepted'));

    foreach ([['loli_cycle_paws' => 200], ['loli_cycle_paws' => -1], ['lifetime_paws' => -1], ['best_score' => -1], ['run_count' => -1]] as $values) {
        expectRefused(fn () => DB::table('player_progression')->where('user_id', $user->id)->update($values));
    }

    foreach ([['delta' => 0], ['resulting_cycle' => 200], ['bonuses_triggered' => -1]] as $values) {
        expectRefused(fn () => DB::table('paw_ledger')->insert([
            'user_id' => $user->id, 'run_id' => $run, 'delta' => 5, 'resulting_cycle' => 5,
            'bonuses_triggered' => 0, 'created_at' => now(), ...$values,
        ]));
    }
});

it('refuses a character key outside the public grammar', function (): void {
    expectRefused(fn () => DB::table('characters')->insert(['key' => 'Bad-Key', 'display_order' => 9]));
});

it('ships the approved catalogue with only the starter selectable', function (): void {
    expect(Character::query()->orderBy('display_order')->pluck('key')->all())->toBe(['aysenur', 'buso', 'ogito', 'sero'])
        ->and(Character::query()->selectableForNewRun()->pluck('key')->all())->toBe(['aysenur']);
});

it('creates no ANTI-6 column, no queued Loli field, no run token and no event table (S9, D7, D8)', function (): void {
    foreach (['lifetime_loli_activations', 'lifetime_near_misses', 'lifetime_lane_blocking_passes', 'lifetime_slayyy_activations', 'owed_loli_bonuses', 'queued_loli_bonuses'] as $column) {
        expect(Schema::hasColumn('player_progression', $column))->toBeFalse();
    }

    foreach (['loli_activations', 'near_miss_count', 'lane_blocking_passes', 'slayyy_activations', 'run_token', 'input_log', 'events'] as $column) {
        expect(Schema::hasColumn('runs', $column))->toBeFalse();
    }

    expect(Schema::hasTable('run_events'))->toBeFalse()
        ->and(Schema::hasTable('idempotency_keys'))->toBeFalse();
});

it('documents bonuses_triggered as threshold accounting, not activation', function (): void {
    $comment = DB::scalar("SELECT col_description('paw_ledger'::regclass, (
        SELECT attnum FROM pg_attribute WHERE attrelid = 'paw_ledger'::regclass AND attname = 'bonuses_triggered'
    ))");

    expect($comment)->toContain('NOT Loli Bonus activations');
});

it('rolls the M9 migrations back and forward cleanly', function (): void {
    // Newest first, as a rollback runs: the M10 projection references `runs`,
    // so it goes before the table it depends on and comes back after it.
    $migrations = collect([
        '2026_09_24_100000_create_leaderboard_tables.php',
        '2026_09_23_100300_create_paw_ledger_table.php',
        '2026_09_23_100200_create_runs_table.php',
        '2026_09_23_100100_create_player_progression_table.php',
        '2026_09_23_100000_create_characters_table.php',
    ])->map(fn (string $file): object => require database_path('migrations/'.$file));

    $migrations->each(fn (object $m) => $m->down());

    foreach (['leaderboard_weekly', 'leaderboard_all_time', 'paw_ledger', 'runs', 'player_progression', 'characters'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasColumn('users', 'tutorial_completed_at'))->toBeTrue();

    $migrations->reverse()->each(fn (object $m) => $m->up());

    foreach (['leaderboard_weekly', 'leaderboard_all_time', 'paw_ledger', 'runs', 'player_progression', 'characters'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Character::query()->count())->toBe(4);
});
