<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Leaderboards\LeaderboardProjector;
use App\Support\RateLimits;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;
use function Pest\Laravel\withHeaders;

use Tests\Support\OpenApiContract;

/**
 * GET /leaderboards — the read side of M10.
 *
 * Boards are built from crafted accepted runs merged through the projector,
 * so every ordering case is exact. Time is frozen on Wednesday 2026-09-23
 * 15:00 Istanbul, in the week of 2026-09-21.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-23 12:00:00.000', 'UTC'));
});

/** @param  array<string, mixed>  $query */
function leaderboard(User $user, array $query): TestResponse
{
    forgetAuthGuards();

    return withHeaders(sessionFor($user))->getJson('/api/v1/leaderboards?'.http_build_query($query));
}

/**
 * One accepted run per player, projected. Scores from a small set so every
 * tie level occurs; a block of complete (score, time, duration) ties so the
 * run id decides.
 *
 * @return list<User>
 */
function seedBoard(int $players, string $startedAt = '2026-09-22 10:00:00.000'): array
{
    $users = User::factory()->count($players)->create()->all();
    $base = CarbonImmutable::parse($startedAt, 'UTC');

    foreach ($users as $index => $user) {
        $tied = $index % 5 === 0;
        $finished = $tied ? $base->addMinutes(10) : $base->addMinutes(10 + $index % 3);

        insertFinishedRun(
            $user,
            $tied ? 5000 : [1000, 2000, 3000][$index % 3],
            $tied ? 30000 : [20000, 30000][$index % 2],
            $base->format('Y-m-d H:i:s.v'),
            $finished->format('Y-m-d H:i:s.v'),
        );
    }

    app(LeaderboardProjector::class)->mergeAcceptedRuns();

    return $users;
}

/**
 * The full board in ORDER, as display names — computed in PHP from the
 * projection rows with the independent comparator.
 *
 * @return list<string>
 */
function expectedBoard(string $table = 'leaderboard_all_time', ?string $weekStart = null): array
{
    $rows = DB::table($table)
        ->when($weekStart !== null, fn ($q) => $q->where('week_start', $weekStart))
        ->get()
        ->map(fn (object $row): array => normaliseLeaderboardRow([
            'user_id' => (int) $row->user_id, 'run_id' => (string) $row->run_id, 'score' => (int) $row->score,
            'achieved_at' => (string) $row->achieved_at, 'duration_ms' => (int) $row->duration_ms,
        ]))
        ->all();

    usort($rows, 'leaderboardOrder');

    $names = User::query()->pluck('display_name', 'id');

    return array_map(fn (array $row): string => (string) $names[$row['user_id']], $rows);
}

/**
 * Page through a window to the end.
 *
 * @return list<array{rank: int, display_name: string, score: int, is_self: bool}>
 */
function traverse(User $viewer, string $window, int $limit, ?Closure $betweenPages = null): array
{
    $served = [];
    $cursor = null;
    $pages = 0;

    do {
        $response = leaderboard($viewer, array_filter(['window' => $window, 'limit' => $limit, 'cursor' => $cursor]))->assertOk();
        $served = [...$served, ...$response->json('data')];
        $cursor = $response->json('meta.next_cursor');

        expect($response->json('meta.has_more'))->toBe($cursor !== null);

        if ($betweenPages !== null && $cursor !== null) {
            $betweenPages(++$pages);
        }
    } while ($cursor !== null);

    return $served;
}

// ---------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------

it('refuses an unauthenticated caller', function (): void {
    getJson('/api/v1/leaderboards?window=weekly')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('requires a verified address', function (): void {
    $user = User::factory()->unverified()->create();

    leaderboard($user, ['window' => 'weekly'])->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
});

it('sits in the verified group on the normal limiter', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r): bool => $r->uri() === 'api/v1/leaderboards');

    expect($route->methods())->toContain('GET')
        ->and($route->gatherMiddleware())->toContain('auth:sanctum', 'verified', 'throttle:'.RateLimits::NORMAL);
});

it('rate-limits reads as a normal endpoint, with Retry-After', function (): void {
    $user = User::factory()->create();

    foreach (range(1, RateLimits::NORMAL_PER_IDENTIFIER) as $attempt) {
        leaderboard($user, ['window' => 'all_time'])->assertOk();
    }

    $refused = leaderboard($user, ['window' => 'all_time'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');

    expect($refused->json('retry_after'))->toBeInt()->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('refuses a malformed query with a stable code per field', function (array $query, string $field, string $code): void {
    $user = User::factory()->create();

    leaderboard($user, $query)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath("errors.{$field}.0.code", $code)
        ->assertJsonCount(1, "errors.{$field}");
})->with([
    'no window' => [[], 'window', 'required'],
    'unknown window' => [['window' => 'daily'], 'window', 'value_not_allowed'],
    'window as an array' => [['window' => ['weekly']], 'window', 'type_invalid'],
    'limit 0' => [['window' => 'weekly', 'limit' => '0'], 'limit', 'out_of_range'],
    'limit 101' => [['window' => 'weekly', 'limit' => '101'], 'limit', 'out_of_range'],
    'limit 1.5' => [['window' => 'weekly', 'limit' => '1.5'], 'limit', 'type_invalid'],
    'limit abc' => [['window' => 'weekly', 'limit' => 'abc'], 'limit', 'type_invalid'],
    'limit +5' => [['window' => 'weekly', 'limit' => '+5'], 'limit', 'format_invalid'],
    'cursor too long' => [['window' => 'weekly', 'cursor' => str_repeat('a', 513)], 'cursor', 'too_long'],
    'cursor charset' => [['window' => 'weekly', 'cursor' => 'abc+def'], 'cursor', 'format_invalid'],
    'cursor garbage' => [['window' => 'weekly', 'cursor' => 'abcdef'], 'cursor', 'cursor_invalid'],
]);

it('defaults to 25 entries and accepts the bounds 1 and 100', function (): void {
    [$viewer] = seedBoard(30);

    expect(leaderboard($viewer, ['window' => 'all_time'])->assertOk()->json('data'))->toHaveCount(25)
        ->and(leaderboard($viewer, ['window' => 'all_time', 'limit' => 1])->assertOk()->json('data'))->toHaveCount(1)
        ->and(leaderboard($viewer, ['window' => 'all_time', 'limit' => 100])->assertOk()->json('data'))->toHaveCount(30);
});

// ---------------------------------------------------------------------------
// Shape and privacy
// ---------------------------------------------------------------------------

it('answers exactly the documented shape and nothing internal', function (string $window): void {
    $users = seedBoard(3);
    $viewer = $users[1];

    $response = leaderboard($viewer, ['window' => $window])->assertOk();
    $body = $response->json();

    expect(array_keys($body))->toBe(['window', 'period', 'data', 'own_entry', 'meta'])
        ->and(array_keys($body['data'][0]))->toBe(['rank', 'display_name', 'score', 'is_self'])
        ->and(array_keys($body['own_entry']))->toBe(['rank', 'display_name', 'score', 'is_self'])
        ->and(array_keys($body['meta']))->toBe(['next_cursor', 'has_more'])
        ->and(OpenApiContract::violationsAgainst($body, OpenApiContract::responseSchema('/leaderboards', 'get', 200)))->toBe([]);

    $raw = (string) $response->getContent();
    $runIds = DB::table('runs')->pluck('id')->all();

    foreach ($users as $user) {
        expect($raw)->not->toContain($user->email)
            ->and($raw)->not->toContain('"user_id"');
    }

    foreach ($runIds as $runId) {
        expect($raw)->not->toContain($runId);
    }

    expect($raw)->not->toMatch('/achieved_at|duration_ms|run_id|player_id|validation|email/');
})->with(['weekly', 'all_time']);

it('gives the weekly period in UTC and none for all-time', function (): void {
    $user = User::factory()->create();

    expect(leaderboard($user, ['window' => 'weekly'])->json('period'))->toBe([
        'starts_at' => '2026-09-20T21:00:00.000Z',
        'ends_at' => '2026-09-27T21:00:00.000Z',
    ])->and(leaderboard($user, ['window' => 'all_time'])->json('period'))->toBeNull();
});

it('answers an empty board with no entries, no own entry and no cursor', function (): void {
    $user = User::factory()->create();

    leaderboard($user, ['window' => 'weekly'])->assertOk()->assertExactJson([
        'window' => 'weekly',
        'period' => ['starts_at' => '2026-09-20T21:00:00.000Z', 'ends_at' => '2026-09-27T21:00:00.000Z'],
        'data' => [],
        'own_entry' => null,
        'meta' => ['next_cursor' => null, 'has_more' => false],
    ]);
});

it('reads display names live, so a rename shows at once', function (): void {
    [$user] = seedBoard(1);
    $user->forceFill(['display_name' => 'renamed_one'])->save();

    expect(leaderboard($user, ['window' => 'all_time'])->json('data.0.display_name'))->toBe('renamed_one');
});

it('marks only the caller\'s own entry as is_self', function (): void {
    $users = seedBoard(5);
    $viewer = $users[2];

    $data = leaderboard($viewer, ['window' => 'all_time'])->json('data');

    expect(array_values(array_filter($data, fn (array $e): bool => $e['is_self'])))->toHaveCount(1)
        ->and(collect($data)->firstWhere('is_self', true)['display_name'])->toBe($viewer->display_name);
});

// ---------------------------------------------------------------------------
// Order and ranks
// ---------------------------------------------------------------------------

it('orders by score, then earlier achievement, then shorter duration, then run id', function (): void {
    [$a, $b, $c, $d, $e] = User::factory()->count(5)->create()->all();
    $start = '2026-09-22 10:00:00.000';

    insertFinishedRun($a, 900, 30000, $start, '2026-09-22 10:05:00.000');
    insertFinishedRun($b, 1000, 30000, $start, '2026-09-22 10:06:00.000');
    insertFinishedRun($c, 1000, 30000, $start, '2026-09-22 10:05:00.000', id: '0192f000-0000-7000-8000-00000000000b');
    insertFinishedRun($d, 1000, 30000, $start, '2026-09-22 10:05:00.000', id: '0192f000-0000-7000-8000-00000000000a');
    insertFinishedRun($e, 1000, 25000, $start, '2026-09-22 10:05:00.000');
    app(LeaderboardProjector::class)->mergeAcceptedRuns();

    $data = leaderboard($a, ['window' => 'all_time'])->json('data');

    expect(array_column($data, 'display_name'))->toBe([$e->display_name, $d->display_name, $c->display_name, $b->display_name, $a->display_name])
        ->and(array_column($data, 'rank'))->toBe([1, 2, 3, 4, 5]);
});

it('pages a static board with no duplicate and no skip, at every page size', function (string $window, int $limit): void {
    $users = seedBoard(41);

    // Rows in other weeks, which the weekly board must not serve.
    insertFinishedRun($users[0], 99999, 30000, '2026-09-14 10:00:00.000', '2026-09-14 10:05:00.000');
    app(LeaderboardProjector::class)->mergeAcceptedRuns();

    $expected = $window === 'weekly'
        ? expectedBoard('leaderboard_weekly', '2026-09-21')
        : expectedBoard();

    $served = traverse($users[3], $window, $limit);

    expect(array_column($served, 'display_name'))->toBe($expected)
        ->and(array_column($served, 'rank'))->toBe(range(1, count($expected)))
        ->and(count($expected))->toBe(41);
})->with(['weekly', 'all_time'])->with([1, 7, 25, 100]);

it('ends a traversal exactly at the tail', function (): void {
    [$viewer] = seedBoard(10);

    $first = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5])->assertOk();
    $second = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5, 'cursor' => $first->json('meta.next_cursor')])->assertOk();

    expect($first->json('meta.has_more'))->toBeTrue()
        ->and($second->json('data'))->toHaveCount(5)
        ->and($second->json('meta'))->toBe(['next_cursor' => null, 'has_more' => false])
        ->and($second->json('data.0.rank'))->toBe(6);
});

// ---------------------------------------------------------------------------
// Own entry
// ---------------------------------------------------------------------------

it('returns the caller\'s own entry with its true rank when it is outside the page', function (): void {
    $users = seedBoard(40);
    $board = expectedBoard();
    $viewer = collect($users)->firstWhere('display_name', $board[29]);

    $response = leaderboard($viewer, ['window' => 'all_time', 'limit' => 10])->assertOk();

    expect($response->json('own_entry'))->toBe([
        'rank' => 30,
        'display_name' => $viewer->display_name,
        'score' => (int) DB::table('leaderboard_all_time')->where('user_id', $viewer->id)->value('score'),
        'is_self' => true,
    ])->and(array_column($response->json('data'), 'is_self'))->not->toContain(true);
});

it('returns a null own entry for a player with no run in the window', function (): void {
    seedBoard(3);
    $newcomer = User::factory()->create();

    // An accepted run in an earlier week: all-time has it, this week does not.
    insertFinishedRun($newcomer, 500, 30000, '2026-09-15 10:00:00.000', '2026-09-15 10:05:00.000');
    app(LeaderboardProjector::class)->mergeAcceptedRuns();

    expect(leaderboard($newcomer, ['window' => 'weekly'])->json('own_entry'))->toBeNull()
        ->and(leaderboard($newcomer, ['window' => 'all_time'])->json('own_entry.rank'))->toBe(4);
});

it('shows a just-accepted finish in the own entry immediately', function (): void {
    $user = User::factory()->create();
    expect(leaderboard($user, ['window' => 'weekly'])->json('own_entry'))->toBeNull();

    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31)->seconds();
    finishRun($user, $runId, plausibleTelemetry(30_000, 1234, 50), (string) Str::uuid())->assertOk();

    expect(leaderboard($user, ['window' => 'weekly'])->json('own_entry'))->toBe([
        'rank' => 1, 'display_name' => $user->display_name, 'score' => 1234, 'is_self' => true,
    ])->and(leaderboard($user, ['window' => 'all_time'])->json('own_entry.score'))->toBe(1234);
});

it('never ranks a flagged run, even for its own player', function (): void {
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31)->seconds();
    finishRun($user, $runId, plausibleTelemetry(30_000, 9000, 50), (string) Str::uuid())->assertJsonPath('data.status', 'flagged');

    expect(leaderboard($user, ['window' => 'all_time'])->json())->toMatchArray(['data' => [], 'own_entry' => null]);
});

// ---------------------------------------------------------------------------
// The live-board consistency contract
// ---------------------------------------------------------------------------

it('omits an entry that lands above the cursor, skips its rank, and shows it after a refresh', function (): void {
    $users = seedBoard(20);
    $viewer = $users[0];

    $page1 = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5])->assertOk();

    $newcomer = User::factory()->create();
    projectStoredRun(insertFinishedRun($newcomer, 99999, 30000, '2026-09-23 11:00:00.000', '2026-09-23 11:05:00.000'));

    $page2 = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5, 'cursor' => $page1->json('meta.next_cursor')])->assertOk();

    $names = [...array_column($page1->json('data'), 'display_name'), ...array_column($page2->json('data'), 'display_name')];

    expect($names)->not->toContain($newcomer->display_name)
        ->and(count(array_unique($names)))->toBe(count($names))
        // Ranks skip by exactly the number of entries that moved above.
        ->and($page2->json('data.0.rank'))->toBe($page1->json('data.4.rank') + 1 + 1);

    expect(leaderboard($viewer, ['window' => 'all_time', 'limit' => 5])->json('data.0.display_name'))->toBe($newcomer->display_name);
});

it('never re-serves an entry that improves after it was served', function (): void {
    $users = seedBoard(20);
    $viewer = $users[0];

    $page1 = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5])->assertOk();
    $servedName = $page1->json('data.3.display_name');
    $served = collect($users)->firstWhere('display_name', $servedName);

    projectStoredRun(insertFinishedRun($served, 99999, 30000, '2026-09-23 11:00:00.000', '2026-09-23 11:05:00.000'));

    $rest = [];
    $cursor = $page1->json('meta.next_cursor');
    while ($cursor !== null) {
        $page = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5, 'cursor' => $cursor])->assertOk();
        $rest = [...$rest, ...array_column($page->json('data'), 'display_name')];
        $cursor = $page->json('meta.next_cursor');
    }

    expect($rest)->not->toContain($servedName)
        ->and(count($rest) + 5)->toBe(20);
});

it('omits an unserved entry that improves past the cursor until a refresh', function (): void {
    $users = seedBoard(20);
    $viewer = $users[0];
    $board = expectedBoard();

    $page1 = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5])->assertOk();

    // The last entry on the board jumps to the top.
    $climber = collect($users)->firstWhere('display_name', $board[19]);
    projectStoredRun(insertFinishedRun($climber, 99999, 30000, '2026-09-23 11:00:00.000', '2026-09-23 11:05:00.000'));

    $rest = [];
    $cursor = $page1->json('meta.next_cursor');
    while ($cursor !== null) {
        $page = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5, 'cursor' => $cursor])->assertOk();
        $rest = [...$rest, ...array_column($page->json('data'), 'display_name')];
        $cursor = $page->json('meta.next_cursor');
    }

    expect($rest)->not->toContain($climber->display_name)
        ->and(traverse($viewer, 'all_time', 5)[0]['display_name'])->toBe($climber->display_name);
});

it('serves a new entry below the cursor exactly once', function (): void {
    $users = seedBoard(20);
    $viewer = $users[0];
    $newcomer = User::factory()->create();

    $served = traverse($viewer, 'all_time', 5, function (int $pages) use ($newcomer): void {
        if ($pages === 1) {
            // Below everything on the board.
            projectStoredRun(insertFinishedRun($newcomer, 1, 30000, '2026-09-23 11:00:00.000', '2026-09-23 11:05:00.000'));
        }
    });

    $names = array_column($served, 'display_name');

    expect(array_count_values($names)[$newcomer->display_name] ?? 0)->toBe(1)
        ->and(count(array_unique($names)))->toBe(21)
        ->and(end($names))->toBe($newcomer->display_name);
});

// ---------------------------------------------------------------------------
// Cursors
// ---------------------------------------------------------------------------

it('round-trips a cursor it minted', function (): void {
    [$viewer] = seedBoard(6);
    $cursor = leaderboard($viewer, ['window' => 'weekly', 'limit' => 3])->json('meta.next_cursor');

    expect($cursor)->toBeString()->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(strlen($cursor))->toBeLessThanOrEqual(512)
        ->and(leaderboard($viewer, ['window' => 'weekly', 'limit' => 3, 'cursor' => $cursor])->assertOk()->json('data.0.rank'))->toBe(4);
});

it('refuses a cursor that was tampered with, reused on the other window, or is not version 1', function (Closure $mangle, string $window): void {
    [$viewer] = seedBoard(6);
    $cursor = leaderboard($viewer, ['window' => 'weekly', 'limit' => 3])->json('meta.next_cursor');

    leaderboard($viewer, ['window' => $window, 'cursor' => $mangle($cursor)])
        ->assertStatus(422)
        ->assertJsonPath('errors.cursor.0.code', 'cursor_invalid');
})->with([
    'a flipped character' => [fn (string $c): string => substr($c, 0, 40).($c[40] === 'A' ? 'B' : 'A').substr($c, 41), 'weekly'],
    'truncated' => [fn (string $c): string => substr($c, 0, -8), 'weekly'],
    'the other window' => [fn (string $c): string => $c, 'all_time'],
    'version 2' => [fn (): string => mintCursor(['v' => 2, 'w' => 'weekly', 'p' => '2026-09-21', 's' => 1, 'a' => 1, 'd' => 1, 'r' => '0192f000-0000-7000-8000-000000000001']), 'weekly'],
    'a week that is not a Monday' => [fn (): string => mintCursor(['v' => 1, 'w' => 'weekly', 'p' => '2026-09-22', 's' => 1, 'a' => 1, 'd' => 1, 'r' => '0192f000-0000-7000-8000-000000000001']), 'weekly'],
    'a string score' => [fn (): string => mintCursor(['v' => 1, 'w' => 'weekly', 'p' => '2026-09-21', 's' => '1', 'a' => 1, 'd' => 1, 'r' => '0192f000-0000-7000-8000-000000000001']), 'weekly'],
    'an extra member' => [fn (): string => mintCursor(['v' => 1, 'w' => 'weekly', 'p' => '2026-09-21', 's' => 1, 'a' => 1, 'd' => 1, 'r' => '0192f000-0000-7000-8000-000000000001', 'u' => 1]), 'weekly'],
    'an all-time cursor with a week' => [fn (): string => mintCursor(['v' => 1, 'w' => 'all_time', 'p' => '2026-09-21', 's' => 1, 'a' => 1, 'd' => 1, 'r' => '0192f000-0000-7000-8000-000000000001']), 'all_time'],
    'not JSON' => [fn (): string => rtrim(strtr(Crypt::encryptString('not json'), '+/', '-_'), '='), 'weekly'],
]);

/** A cursor-shaped token with an arbitrary payload, encrypted under the real key. */
function mintCursor(array $payload): string
{
    return rtrim(strtr(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
}

it('keeps paging the original week after a Monday rollover', function (): void {
    // Sunday 23:00 Istanbul, week of 2026-09-21.
    travelTo(CarbonImmutable::parse('2026-09-27 20:00:00.000', 'UTC'));
    [$viewer] = seedBoard(6, '2026-09-26 10:00:00.000');

    $first = leaderboard($viewer, ['window' => 'weekly', 'limit' => 3])->assertOk();

    // Monday 01:00 Istanbul: a new week, empty so far.
    travelTo(CarbonImmutable::parse('2026-09-27 22:00:00.000', 'UTC'));

    $continued = leaderboard($viewer, ['window' => 'weekly', 'limit' => 3, 'cursor' => $first->json('meta.next_cursor')])->assertOk();
    $fresh = leaderboard($viewer, ['window' => 'weekly', 'limit' => 3])->assertOk();

    expect($continued->json('period.starts_at'))->toBe('2026-09-20T21:00:00.000Z')
        ->and($continued->json('data'))->toHaveCount(3)
        ->and($continued->json('data.0.rank'))->toBe(4)
        ->and($continued->json('own_entry'))->not->toBeNull()
        ->and($fresh->json('period.starts_at'))->toBe('2026-09-27T21:00:00.000Z')
        ->and($fresh->json('data'))->toBe([])
        ->and($fresh->json('own_entry'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Cost
// ---------------------------------------------------------------------------

it('reads a page in at most four leaderboard statements, whatever the page size', function (): void {
    [$viewer] = seedBoard(60);
    $cursor = leaderboard($viewer, ['window' => 'all_time', 'limit' => 5])->json('meta.next_cursor');

    $count = function (array $query): array {
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        leaderboard(User::query()->first(), $query)->assertOk();

        return [
            count(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'leaderboard_'))),
            count($statements),
        ];
    };

    [$small, $smallTotal] = $count(['window' => 'all_time', 'limit' => 1, 'cursor' => $cursor]);
    [$large, $largeTotal] = $count(['window' => 'all_time', 'limit' => 100, 'cursor' => $cursor]);

    // The page, the own row, and one count yielding both ranks.
    expect($small)->toBe(3)
        ->and($large)->toBe(3)
        ->and($largeTotal)->toBe($smallTotal);
});

it('puts only an ORDER position in the cursor, never a user id', function (): void {
    [$viewer] = seedBoard(4);
    $cursor = leaderboard($viewer, ['window' => 'weekly', 'limit' => 2])->json('meta.next_cursor');

    $encrypted = strtr($cursor, '-_', '+/');
    $payload = json_decode(Crypt::decryptString($encrypted.str_repeat('=', (4 - strlen($encrypted) % 4) % 4)), true);

    expect(array_keys($payload))->toBe(['v', 'w', 'p', 's', 'a', 'd', 'r'])
        ->and($payload['w'])->toBe('weekly')
        ->and($payload['p'])->toBe('2026-09-21')
        ->and(DB::table('leaderboard_weekly')->where('run_id', $payload['r'])->exists())->toBeTrue();
});
