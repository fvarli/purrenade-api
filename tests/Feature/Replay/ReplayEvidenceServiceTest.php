<?php

declare(strict_types=1);

use App\Jobs\Runs\ReplayRunEvidence;
use App\Models\User;
use App\Services\Replay\ReplayBundle;
use App\Services\Replay\ReplayBundles;
use App\Services\Replay\ReplayEvidenceService;
use App\Services\Replay\ReplayProcessResult;
use App\Services\Replay\ReplayRunner;
use App\Services\Replay\ReplayRunnerFailure;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

use Tests\Support\FakeReplayRunner;

/**
 * ANTI-6 P3: replaying a pending input and committing its outcome.
 *
 * The properties that carry the weight: only a consistent replay establishes
 * evidence, and it establishes the whole triple; every other outcome is
 * terminal with the input cleared; **no outcome ever changes the run's status
 * or its Loli evidence**; expired input never establishes evidence, checked
 * before the replay and again at commit; a stale pin fails closed; a transient
 * failure is retried and only exhaustion ends it; `replay_inconsistent` is a
 * security signal and nothing more; a duplicate is a no-op.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-10-03 12:00:00.000', 'UTC'));

    // Capture real log records: the application channel and `security`.
    config([
        'logging.default' => 'replay_capture',
        'logging.channels.replay_capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class],
    ]);
    app('log')->forgetChannel('replay_capture');
    app('log')->forgetChannel('security');
});

function replayLogs(string $channel = 'replay_capture'): TestHandler
{
    return evidenceLogHandler($channel);
}

/** The TestHandler behind a channel configured with one. */
function evidenceLogHandler(string $channel): TestHandler
{
    $logger = app('log')->channel($channel);
    $monolog = $logger instanceof Illuminate\Log\Logger ? $logger->getLogger() : null;
    $handler = $monolog instanceof Logger ? ($monolog->getHandlers()[0] ?? null) : null;

    if (! $handler instanceof TestHandler) {
        throw new RuntimeException("Channel {$channel} is not capturing.");
    }

    return $handler;
}

/** An accepted run with a pending work row: 30 000 ms, 1 000 points, 50 paws. */
function pendingReplayRun(?User $user = null): string
{
    Queue::fake();

    $user ??= User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31_000)->milliseconds();

    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => [
        'format_version' => 1,
        'domain_version' => '1',
        'total_steps' => 3000,
        'events' => [[0, 9], [100, 1], [40, 2]],
    ]], (string) Str::uuid())->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(DB::table('run_replay_inputs')->where('run_id', $runId)->value('state'))->toBe('pending');

    return $runId;
}

function useReplayRunner(ReplayRunner $runner): ReplayRunner
{
    app()->instance(ReplayRunner::class, $runner);

    return $runner;
}

function consistentRunner(array $facts = []): FakeReplayRunner
{
    /** @var FakeReplayRunner */
    return useReplayRunner(FakeReplayRunner::completed(1000, 50, 30000, 3000, $facts));
}

function processReplay(string $runId): void
{
    app(ReplayEvidenceService::class)->process($runId);
}

function replayWork(string $runId): object
{
    return DB::table('run_replay_inputs')->where('run_id', $runId)->first();
}

/** The run's status and Loli evidence, which nothing here may change. */
function untouchable(string $runId): array
{
    return [
        DB::table('runs')->where('id', $runId)->value('status'),
        DB::table('run_loli_evidence')->where('run_id', $runId)->value('loli_activations'),
    ];
}

it('establishes the whole triple from a consistent replay, and clears the input', function (): void {
    $runId = pendingReplayRun();
    $before = untouchable($runId);
    $runner = consistentRunner(['cone_safe_passes' => 7, 'near_misses' => 4, 'slayyy_activations' => 2]);

    processReplay($runId);

    $evidence = DB::table('run_replay_evidence')->where('run_id', $runId)->first();
    $work = replayWork($runId);

    expect($evidence->cone_safe_passes)->toBe(7)
        ->and($evidence->near_misses)->toBe(4)
        ->and($evidence->slayyy_activations)->toBe(2)
        ->and($evidence->evidence_version)->toBe(1)
        ->and($evidence->domain_version)->toBe('1')
        ->and($work->state)->toBe('terminal')
        ->and($work->outcome_code)->toBe('established')
        ->and($work->input)->toBeNull()
        ->and($work->attempts)->toBe(1)
        ->and(untouchable($runId))->toBe($before);

    // The document is built from the server's run, not from the client.
    $run = DB::table('runs')->where('id', $runId)->first();
    expect($runner->documents[0])->toBe([
        'protocol' => 1,
        'domain_version' => '1',
        'seed' => (int) $run->seed,
        'start_loli_cycle_paws' => (int) $run->start_loli_cycle_paws,
        'mode' => 'run',
        'input' => ['format_version' => 1, 'domain_version' => '1', 'total_steps' => 3000, 'events' => [[0, 9], [100, 1], [40, 2]]],
    ]);
});

it('ends every deterministic failure terminal, with no evidence and the run untouched', function (string $stdout, string $outcome): void {
    $runId = pendingReplayRun();
    $before = untouchable($runId);
    useReplayRunner(new FakeReplayRunner($stdout));

    processReplay($runId);

    expect(replayWork($runId)->outcome_code)->toBe($outcome)
        ->and(replayWork($runId)->input)->toBeNull()
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(untouchable($runId))->toBe($before);
})->with([
    'invalid input' => ['{"protocol":1,"domain_version":"1","status":"invalid_input"}', 'input_malformed'],
    'unsupported version' => ['{"protocol":1,"domain_version":"1","status":"unsupported_version"}', 'version_unsupported'],
    'garbage' => ['not json', 'result_invalid'],
    'not ended' => [str_replace('"ended":true', '"ended":false', FakeReplayRunner::completed(1000, 50, 30000, 3000)->run(new ReplayBundle('1', '', '', '', '', 24, '', ''), [])->stdout), 'result_invalid'],
]);

it('is replay_inconsistent — a security signal, no evidence, and nothing else', function (): void {
    $runId = pendingReplayRun();
    $before = untouchable($runId);
    useReplayRunner(FakeReplayRunner::completed(999, 50, 30001, 3000));

    processReplay($runId);

    expect(replayWork($runId)->outcome_code)->toBe('replay_inconsistent')
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeFalse()
        ->and(untouchable($runId))->toBe($before);

    $records = replayLogs('security')->getRecords();
    expect($records)->toHaveCount(1)
        ->and($records[0]->message)->toBe('run.replay_inconsistent')
        ->and(array_keys($records[0]->context))->toBe(['event', 'run_id', 'user_id', 'reasons', 'correlation_id'])
        ->and($records[0]->context['run_id'])->toBe($runId)
        ->and($records[0]->context['reasons'])->toBe(['score', 'duration']);
});

it('emits no security signal for any other outcome', function (): void {
    $runId = pendingReplayRun();
    useReplayRunner(new FakeReplayRunner('not json'));

    processReplay($runId);

    expect(replayLogs('security')->getRecords())->toBe([]);
});

it('rethrows a transient failure, leaving the input pending for a retry', function (): void {
    $runId = pendingReplayRun();
    useReplayRunner(FakeReplayRunner::failing(ReplayRunnerFailure::TIMED_OUT));

    expect(fn () => processReplay($runId))->toThrow(ReplayRunnerFailure::class, 'replay_timed_out');

    expect(replayWork($runId)->state)->toBe('pending')
        ->and(replayWork($runId)->input)->not->toBeNull()
        ->and(replayWork($runId)->attempts)->toBe(1);
});

it('ends attempts_exhausted when the job finally fails', function (): void {
    $runId = pendingReplayRun();
    $before = untouchable($runId);

    (new ReplayRunEvidence($runId))->failed(ReplayRunnerFailure::because(ReplayRunnerFailure::CRASHED));

    expect(replayWork($runId)->outcome_code)->toBe('attempts_exhausted')
        ->and(replayWork($runId)->input)->toBeNull()
        ->and(untouchable($runId))->toBe($before);
});

it('ends run_not_accepted, without replaying, once the run is no longer accepted', function (): void {
    $runId = pendingReplayRun();
    $runner = consistentRunner();
    DB::table('runs')->where('id', $runId)->update(['status' => 'rejected']);

    processReplay($runId);

    expect(replayWork($runId)->outcome_code)->toBe('run_not_accepted')
        ->and(replayWork($runId)->input)->toBeNull()
        ->and($runner->documents)->toBe([]);
});

it('treats the input as usable until the last millisecond of its 24 hours', function (): void {
    $runId = pendingReplayRun();
    consistentRunner();
    travelTo(CarbonImmutable::parse(replayWork($runId)->input_expires_at, 'UTC')->subMillisecond());

    processReplay($runId);

    expect(replayWork($runId)->outcome_code)->toBe('established');
});

it('treats the input as unusable from the instant of its expiry, before any replay', function (): void {
    $runId = pendingReplayRun();
    $runner = consistentRunner();
    travelTo(CarbonImmutable::parse(replayWork($runId)->input_expires_at, 'UTC'));

    processReplay($runId);

    expect(replayWork($runId)->outcome_code)->toBe('input_expired')
        ->and(replayWork($runId)->input)->toBeNull()
        ->and($runner->documents)->toBe([]);
});

it('never establishes evidence from input that expired while it was replaying', function (): void {
    $runId = pendingReplayRun();
    $expiresAt = CarbonImmutable::parse(replayWork($runId)->input_expires_at, 'UTC');

    useReplayRunner(new class($expiresAt) implements ReplayRunner
    {
        public function __construct(private CarbonImmutable $expiresAt) {}

        public function run(ReplayBundle $bundle, array $document): ReplayProcessResult
        {
            travelTo($this->expiresAt);

            return FakeReplayRunner::completed(1000, 50, 30000, 3000)->run($bundle, $document);
        }
    });
    travelTo($expiresAt->subSecond());

    processReplay($runId);

    expect(replayWork($runId)->outcome_code)->toBe('input_expired')
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeFalse();
});

it('ends an undecryptable input as input_expired — ABSENT, never a retry, run untouched', function (): void {
    $runId = pendingReplayRun();
    $before = untouchable($runId);
    $runner = consistentRunner();

    // Re-encrypted under a key this application does not hold.
    $foreign = (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('{"format_version":1}');
    DB::update("UPDATE run_replay_inputs SET input = decode(?, 'hex') WHERE run_id = ?", [bin2hex(base64_decode($foreign)), $runId]);

    processReplay($runId);

    expect(replayWork($runId)->outcome_code)->toBe('input_expired')
        ->and(replayWork($runId)->input)->toBeNull()
        ->and($runner->documents)->toBe([])
        ->and(untouchable($runId))->toBe($before);

    $completed = collect(replayLogs()->getRecords())->firstWhere('message', 'run.replay.completed');
    expect($completed->context['reason'])->toBe('input_unreadable');
});

it('fails closed on a pin whose bundle no longer matches its manifest', function (): void {
    $runId = pendingReplayRun();
    $runner = consistentRunner();

    $root = sys_get_temp_dir().'/replay-pin-'.Str::random(8);
    mkdir($root.'/domain-1', 0777, true);
    copy(resource_path('replay/domain-1/manifest.json'), $root.'/domain-1/manifest.json');
    copy(resource_path('replay/domain-1/golden.json'), $root.'/domain-1/golden.json');
    file_put_contents($root.'/domain-1/purrenade-replay.mjs', "// not the pinned bundle\n");
    app()->instance(ReplayBundles::class, new ReplayBundles($root));

    processReplay($runId);

    expect(replayWork($runId)->outcome_code)->toBe('version_unsupported')
        ->and($runner->documents)->toBe([]);

    array_map('unlink', glob($root.'/domain-1/*'));
    rmdir($root.'/domain-1');
    rmdir($root);
});

it('is a no-op for a duplicate delivery after the outcome', function (): void {
    $runId = pendingReplayRun();
    $runner = consistentRunner();

    processReplay($runId);
    processReplay($runId);

    expect($runner->documents)->toHaveCount(1)
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->count())->toBe(1);
});

it('reports a Loli divergence as a metric and never touches the Loli evidence', function (): void {
    $runId = pendingReplayRun();
    $frozen = DB::table('run_loli_evidence')->where('run_id', $runId)->value('loli_activations');
    consistentRunner(['loli_activations' => $frozen + 3]);

    processReplay($runId);

    expect(DB::table('run_loli_evidence')->where('run_id', $runId)->value('loli_activations'))->toBe($frozen)
        ->and(collect(replayLogs()->getRecords())->pluck('message'))->toContain('run.replay.loli_divergence');
});

it('runs end to end through the queue, dispatched only after the finish commits', function (): void {
    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31_000)->milliseconds();
    consistentRunner();

    // The real (sync, in tests) queue: the job runs once the finish commits.
    finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => [
        'format_version' => 1, 'domain_version' => '1', 'total_steps' => 3000, 'events' => [],
    ]], (string) Str::uuid())->assertOk()->assertJsonPath('data.status', 'accepted');

    expect(replayWork($runId)->outcome_code)->toBe('established')
        ->and(DB::table('run_replay_evidence')->where('run_id', $runId)->exists())->toBeTrue();
});

it('queues the job on the dedicated replay queue with only the run id', function (): void {
    $job = new ReplayRunEvidence('0199b000-0000-7000-8000-000000000001');

    expect($job->queue)->toBe('replay')
        ->and($job->uniqueId())->toBe('0199b000-0000-7000-8000-000000000001')
        ->and(array_keys(get_object_vars($job)))->not->toContain('input')
        ->and(serialize($job))->not->toContain('events');
});
