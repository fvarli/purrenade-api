<?php

declare(strict_types=1);

use App\Enums\RunStatus;
use App\Models\Character;
use App\Models\Run;
use App\Models\User;
use App\Services\Progression\ProgressionService;
use App\Services\Runs\RunSeedGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

/**
 * POST /game-runs — the server creates the run before gameplay (ADR-0006).
 *
 * The contract the C-11 cases pin down: **shape first** (a malformed
 * `character_id` is a 422 whatever the run state), then **recovery beats
 * availability** (a non-stale active run is resumed with its own character,
 * whatever was requested), and availability is asked **only** when a new run
 * must be created — so an unavailable request never consumes a stale run.
 */
function activeRunFor(User $user): Run
{
    $response = startRun($user)->assertCreated();

    return Run::query()->findOrFail($response->json('data.run_id'));
}

/** A snapshot of every column, for "unchanged" to be a real assertion. */
function runSnapshot(Run $run): array
{
    return (array) DB::table('runs')->where('id', $run->id)->first();
}

// ---------------------------------------------------------------------------
// Creation
// ---------------------------------------------------------------------------

it('creates a run for an available character, storing the internal id and returning the key (C-11 #7)', function (): void {
    $user = User::factory()->create();

    $response = startRun($user, 'aysenur')->assertCreated();

    $aysenur = Character::query()->where('key', 'aysenur')->firstOrFail();
    $run = Run::query()->findOrFail($response->json('data.run_id'));

    expect($response->json('data.character_id'))->toBe('aysenur')
        ->and($run->character_id)->toBe($aysenur->id)
        ->and($run->status)->toBe(RunStatus::Active)
        ->and($run->user_id)->toBe($user->id)
        ->and($run->finished_at)->toBeNull();

    // The internal bigint never leaves the server.
    expect(json_encode($response->json()))->not->toContain('"character_id":'.$aysenur->id);
});

it('answers with server-owned facts only: run id, key, integer seed, millisecond start, cycle paws', function (): void {
    $user = User::factory()->create();

    $data = startRun($user)->assertCreated()->json('data');

    expect(array_keys($data))->toEqualCanonicalizing(['run_id', 'character_id', 'seed', 'started_at', 'loli_cycle_paws'])
        ->and($data['run_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($data['seed'])->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(4294967295)
        ->and($data['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and($data['loli_cycle_paws'])->toBe(0);
});

it('refuses an unknown key with no active run, creating nothing (C-11 #6)', function (): void {
    $user = User::factory()->create();

    startRun($user, 'zzz_unknown')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.character_id.0.code', 'character_unavailable');

    expect(Run::query()->count())->toBe(0);
});

it('refuses a locked, artwork-less character with no active run, creating nothing (C-11 #5)', function (string $key): void {
    $user = User::factory()->create();

    startRun($user, $key)
        ->assertStatus(422)
        ->assertJsonPath('errors.character_id.0.code', 'character_unavailable');

    expect(Run::query()->count())->toBe(0);
})->with(['buso', 'ogito', 'sero']);

it('decides availability from the catalogue rule, not from a name in code', function (): void {
    $user = User::factory()->create();

    // Give Büşo artwork but keep him locked: still not a starter, still refused.
    Character::query()->where('key', 'buso')->update(['artwork_available' => true]);
    startRun($user, 'buso')->assertStatus(422);

    // Make him a starter with artwork: the same generic rule now admits him.
    Character::query()->where('key', 'buso')->update(['is_starter' => true]);
    startRun($user, 'buso')->assertCreated()->assertJsonPath('data.character_id', 'buso');
});

// ---------------------------------------------------------------------------
// Shape — always first
// ---------------------------------------------------------------------------

it('refuses a malformed character_id with 422 and leaves the active run untouched (C-11 #4)', function (mixed $body): void {
    $user = User::factory()->create();
    $run = activeRunFor($user);
    $before = runSnapshot($run);

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/game-runs', $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['character_id']]);

    expect(runSnapshot($run))->toBe($before)
        ->and(Run::query()->count())->toBe(1);
})->with([
    'missing' => [[]],
    'integer' => [['character_id' => 123]],
    'null' => [['character_id' => null]],
    'path-like' => [['character_id' => '../../foo']],
    'upper-case / non-ASCII' => [['character_id' => 'BÜŞO']],
    'upper-case ASCII' => [['character_id' => 'Aysenur']],
    'overlength' => [['character_id' => str_repeat('a', 33)]],
    'empty' => [['character_id' => '']],
    'padded' => [['character_id' => ' aysenur ']],
    'trailing newline' => [['character_id' => "aysenur\n"]],
    'boolean' => [['character_id' => true]],
    'object' => [['character_id' => ['key' => 'aysenur']]],
]);

it('refuses a malformed character_id when no run exists too', function (): void {
    $user = User::factory()->create();

    startRun($user, 42)->assertStatus(422)->assertJsonPath('errors.character_id.0.code', 'type_invalid');
    startRun($user, 'a-b')->assertStatus(422)->assertJsonPath('errors.character_id.0.code', 'format_invalid');

    expect(Run::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Recovery — beats availability
// ---------------------------------------------------------------------------

it('resumes the same run for the same character (C-11 #1)', function (): void {
    $user = User::factory()->create();
    $first = startRun($user)->assertCreated()->json('data');
    $run = Run::query()->findOrFail($first['run_id']);
    $before = runSnapshot($run);

    travel(5)->minutes();

    $second = startRun($user, 'aysenur')->assertOk()->json('data');

    expect($second)->toBe($first)
        ->and(runSnapshot($run))->toBe($before)
        ->and(Run::query()->count())->toBe(1);
});

it('resumes the original Ayşenur run when a locked character is requested (C-11 #2)', function (string $key): void {
    $user = User::factory()->create();
    $first = startRun($user)->assertCreated()->json('data');
    $before = runSnapshot(Run::query()->findOrFail($first['run_id']));

    $second = startRun($user, $key)->assertOk()->json('data');

    expect($second)->toBe($first)
        ->and($second['character_id'])->toBe('aysenur')
        ->and(runSnapshot(Run::query()->findOrFail($first['run_id'])))->toBe($before)
        ->and(Run::query()->count())->toBe(1);
})->with(['buso', 'ogito', 'sero']);

it('resumes the original run when a well-formed unknown key is requested (C-11 #3)', function (): void {
    $user = User::factory()->create();
    $first = startRun($user)->assertCreated()->json('data');
    $before = runSnapshot(Run::query()->findOrFail($first['run_id']));

    $second = startRun($user, 'zzz_unknown')->assertOk()->json('data');

    expect($second)->toBe($first)
        ->and(runSnapshot(Run::query()->findOrFail($first['run_id'])))->toBe($before);
});

it('resumes just inside the stale threshold', function (): void {
    $user = User::factory()->create();
    $first = startRun($user)->assertCreated()->json('data');

    travel(86400 - 1)->seconds();

    expect(startRun($user)->assertOk()->json('data'))->toBe($first);
});

// ---------------------------------------------------------------------------
// Stale replacement — only when a replacement can be created
// ---------------------------------------------------------------------------

it('keeps a stale run active when the requested character is unavailable (C-11 #8)', function (string $key): void {
    $user = User::factory()->create();
    $run = activeRunFor($user);
    $before = runSnapshot($run);

    travel(86400 + 1)->seconds();

    startRun($user, $key)
        ->assertStatus(422)
        ->assertJsonPath('errors.character_id.0.code', 'character_unavailable');

    $after = runSnapshot($run);

    expect($after)->toBe($before)
        ->and($after['status'])->toBe('active')
        ->and($after['finished_at'])->toBeNull()
        ->and((string) $after['validation_meta'])->not->toContain('run_stale_replaced')
        ->and(Run::query()->count())->toBe(1);
})->with(['buso', 'zzz_unknown']);

it('replaces a stale run with a new one, atomically, for an available character (C-11 #9)', function (): void {
    $user = User::factory()->create();
    $old = activeRunFor($user);

    travel(86400 + 1)->seconds();

    $new = startRun($user)->assertCreated()->json('data');

    $old->refresh();

    expect($new['run_id'])->not->toBe($old->id)
        ->and($old->status)->toBe(RunStatus::Rejected)
        ->and($old->finished_at)->not->toBeNull()
        ->and($old->validation_meta['rules'][0]['code'])->toBe('run_stale_replaced')
        ->and($old->idempotency_key)->toBeNull()
        ->and($old->result)->toBeNull()
        ->and(Run::query()->where('user_id', $user->id)->where('status', 'active')->pluck('id')->all())->toBe([$new['run_id']]);
});

it('treats exactly 24 hours as stale', function (): void {
    $user = User::factory()->create();
    $old = activeRunFor($user);

    travel(86400)->seconds();

    startRun($user)->assertCreated();

    expect($old->refresh()->status)->toBe(RunStatus::Rejected);
});

it('rolls back the stale replacement when the new run cannot be created (C-11 #9, forced failure)', function (): void {
    // Bound before the first request: the router caches the controller — and
    // the service graph it was built with — for the rest of the test.
    $seeds = new class extends RunSeedGenerator
    {
        public bool $fail = false;

        public function next(): int
        {
            if ($this->fail) {
                throw new RuntimeException('seed source unavailable');
            }

            return parent::next();
        }
    };
    app()->instance(RunSeedGenerator::class, $seeds);

    $user = User::factory()->create();
    $old = activeRunFor($user);
    $before = runSnapshot($old);

    travel(86400 + 1)->seconds();

    // The seed is drawn inside the insert step, after the stale run was marked
    // replaced. Failing there proves the two writes are one unit.
    $seeds->fail = true;

    startRun($user)->assertStatus(500)->assertJsonPath('code', 'server_error');

    expect(runSnapshot($old))->toBe($before)
        ->and($old->refresh()->status)->toBe(RunStatus::Active)
        ->and(Run::query()->count())->toBe(1);
});

it('never expires a run on time alone: an untouched run stays active and still accepts its finish', function (): void {
    $user = User::factory()->create();
    $run = activeRunFor($user);

    travel(72)->hours();

    expect($run->refresh()->status)->toBe(RunStatus::Active);

    finishRun($user, $run->id, plausibleTelemetry(), (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');
});

// ---------------------------------------------------------------------------
// Seed, progression read, access
// ---------------------------------------------------------------------------

it('issues integer seeds within the uint32 domain', function (): void {
    $generator = new RunSeedGenerator;

    foreach (range(1, 1000) as $_) {
        $seed = $generator->next();

        expect($seed)->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(4294967295)
            ->and(json_encode(['seed' => $seed]))->toBe('{"seed":'.$seed.'}');
    }
});

it('stores and serialises the uint32 boundary seeds exactly', function (int $seed): void {
    $user = User::factory()->create();

    app()->instance(RunSeedGenerator::class, new class($seed) extends RunSeedGenerator
    {
        public function __construct(private readonly int $fixed) {}

        public function next(): int
        {
            return $this->fixed;
        }
    });

    $response = startRun($user)->assertCreated();

    expect($response->json('data.seed'))->toBe($seed)
        ->and((int) DB::table('runs')->value('seed'))->toBe($seed)
        ->and($response->getContent())->toContain('"seed":'.$seed.',');
})->with([0, 1, 2147483647, 2147483648, 4294967295]);

it('reports the persistent loli cycle the run starts from', function (): void {
    $user = User::factory()->create();
    app(ProgressionService::class)->ensure($user->id);
    DB::table('player_progression')->where('user_id', $user->id)->update(['loli_cycle_paws' => 137]);

    startRun($user)->assertCreated()->assertJsonPath('data.loli_cycle_paws', 137);
});

it('lets a player with no progression row start (legacy or fresh)', function (): void {
    $user = User::factory()->create();

    expect(progressionRow($user))->toBeNull();

    startRun($user)->assertCreated()->assertJsonPath('data.loli_cycle_paws', 0);

    expect(progressionRow($user))->not->toBeNull();
});

it('refuses an unauthenticated caller', function (): void {
    postJson('/api/v1/game-runs', ['character_id' => 'aysenur'])
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    expect(Run::query()->count())->toBe(0);
});

it('requires a verified address', function (): void {
    $user = User::factory()->unverified()->create();

    startRun($user)->assertStatus(403)->assertJsonPath('code', 'email_not_verified');

    expect(Run::query()->count())->toBe(0);
});

it('ignores any identity, seed or start time in the body', function (): void {
    $actor = User::factory()->create();
    $victim = User::factory()->create();

    $data = withHeaders(sessionFor($actor))->postJson('/api/v1/game-runs', [
        'character_id' => 'aysenur',
        'user_id' => $victim->id,
        'seed' => 7,
        'started_at' => '2020-01-01T00:00:00Z',
    ])->assertCreated()->json('data');

    $run = Run::query()->findOrFail($data['run_id']);

    expect($run->user_id)->toBe($actor->id)
        ->and($data['started_at'])->not->toStartWith('2020-')
        ->and(Run::query()->where('user_id', $victim->id)->count())->toBe(0);
});

it('never takes the progression lock when starting (lock order C-1)', function (): void {
    $user = User::factory()->create();

    DB::enableQueryLog();

    startRun($user)->assertCreated();
    startRun($user)->assertOk();

    $locking = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'player_progression') && stripos($sql, 'for update') !== false);

    expect($locking)->toBeEmpty();
});
