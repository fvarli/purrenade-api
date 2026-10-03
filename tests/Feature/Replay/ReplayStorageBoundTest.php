<?php

declare(strict_types=1);

use App\Models\Run;
use App\Models\User;
use App\Services\Replay\ReplayInputParser;
use App\Services\Replay\ReplayInputStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The stored-input bound is a measurement, not a guess (owner refinement R2).
 *
 * The largest input the boundary admits is built explicitly, encrypted with
 * the real encrypter, and must fit the database CHECK; the CHECK must equal
 * the derived constant; one byte more must be refused. If the caps, the
 * canonical encoding or the encrypter ever grow the representation, this
 * fails before a real finish does.
 */

/**
 * The largest canonical input the parser accepts: 20 000 events whose gaps
 * maximise digits subject to Σgap ≤ total_steps = 432 000 (two digits each,
 * then as many three-digit gaps as the remaining budget buys), and the longest
 * domain version.
 *
 * @return array<string, mixed>
 */
function maximalReplayInput(): array
{
    $events = array_fill(0, ReplayInputParser::MAX_EVENTS, [10, 9]);
    $budget = ReplayInputParser::MAX_STEPS - 10 * ReplayInputParser::MAX_EVENTS;

    for ($i = 0; $i < ReplayInputParser::MAX_EVENTS && $budget >= 90; $i++) {
        $events[$i][0] = 100;
        $budget -= 90;
    }

    return [
        'format_version' => 1,
        'domain_version' => '12345678',
        'total_steps' => ReplayInputParser::MAX_STEPS,
        'events' => $events,
    ];
}

it('admits the maximal input at the boundary', function (): void {
    $candidate = (new ReplayInputParser)->parse(maximalReplayInput());

    expect($candidate->isUsable())->toBeTrue()
        ->and(strlen($candidate->input->canonicalJson()))->toBe(142_657);
});

it('measures the maximal encrypted envelope exactly at the documented bound', function (): void {
    $plaintext = (new ReplayInputParser)->parse(maximalReplayInput())->input->canonicalJson();

    // Deterministic: the envelope's length depends only on the plaintext's.
    $sizes = collect(range(1, 50))->map(fn (): int => strlen((string) base64_decode(Crypt::encryptString($plaintext), true)))->unique();

    expect($sizes->all())->toBe([ReplayInputStore::MAX_STORED_BYTES]);
});

it('cannot be pushed past the bound by any input the boundary admits', function (): void {
    // Every other admitted input is no longer in canonical form: more digits
    // need a larger Σgap, which the parser refuses.
    $over = maximalReplayInput();
    $over['events'][19_999][0] = 1000;

    expect((new ReplayInputParser)->parse($over)->isUsable())->toBeFalse();
});

it('keeps the database CHECK equal to the derived constant', function (): void {
    $definition = DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'run_replay_inputs_input_size_check'");

    expect($definition)->toContain('(octet_length(input) <= '.ReplayInputStore::MAX_STORED_BYTES.')');
});

it('stores the real encrypted maximal input through the store, at exactly the bound', function (): void {
    Queue::fake();
    $runId = insertFinishedRun(User::factory()->create(), 1000, 30_000, '2026-10-03 11:00:00.000', '2026-10-03 11:01:00.000');
    DB::table('runs')->where('id', $runId)->update(['start_loli_cycle_paws' => 0]);
    // The pinned domain is `1`, so this is the largest input a real finish can
    // store today: the 8-digit worst case minus 7 bytes of domain version.
    $maximal = maximalReplayInput();
    $maximal['domain_version'] = '1';
    $candidate = (new ReplayInputParser)->parse($maximal);
    $n = strlen($candidate->input->canonicalJson());
    expect($n)->toBe(142_650);

    app(ReplayInputStore::class)->record(
        Run::query()->findOrFail($runId), $candidate, 3_700_000, 5_000, CarbonImmutable::parse('2026-10-03 12:00:00.000', 'UTC'),
    );

    $stored = (int) DB::scalar('SELECT octet_length(input) FROM run_replay_inputs WHERE run_id = ?', [$runId]);

    // AES-256-CBC envelope: 126 fixed bytes + base64 of the PKCS#7-padded ciphertext.
    expect(DB::table('run_replay_inputs')->where('run_id', $runId)->value('state'))->toBe('pending')
        ->and($stored)->toBe(126 + 4 * intdiv(16 * (intdiv($n, 16) + 1) + 2, 3))
        ->and($stored)->toBeLessThanOrEqual(ReplayInputStore::MAX_STORED_BYTES);
});

it('stores the maximal envelope and refuses one byte more', function (): void {
    $runId = insertFinishedRun(User::factory()->create(), 1000, 30_000, '2026-10-03 11:00:00.000', '2026-10-03 11:01:00.000');
    $row = fn (int $bytes): array => [
        'run_id' => $runId,
        'state' => 'pending',
        'attempts' => 0,
        'input' => DB::raw("decode(repeat('ab', {$bytes}), 'hex')"),
        'input_expires_at' => '2026-10-04 12:00:00.000',
        'created_at' => '2026-10-03 12:00:00.000',
        'updated_at' => '2026-10-03 12:00:00.000',
    ];

    expectRefused(fn () => DB::table('run_replay_inputs')->insert($row(ReplayInputStore::MAX_STORED_BYTES + 1)));

    DB::table('run_replay_inputs')->insert($row(ReplayInputStore::MAX_STORED_BYTES));

    expect(DB::scalar('SELECT octet_length(input) FROM run_replay_inputs WHERE run_id = ?', [$runId]))
        ->toBe(ReplayInputStore::MAX_STORED_BYTES);
});
