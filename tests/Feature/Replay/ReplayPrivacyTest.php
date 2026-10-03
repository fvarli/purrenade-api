<?php

declare(strict_types=1);

use App\Models\RunReplayInput;
use App\Models\User;
use App\Services\Replay\ReplayRunner;
use App\Services\Replay\ReplayRunnerFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

use Tests\Support\FakeReplayRunner;

/**
 * Canonical replay input is never logged, queued, kept in a failure record,
 * echoed, or stored anywhere but encrypted in its work row (data-protection §5;
 * ADR-0006 Amendment A).
 *
 * A distinctive stream is pushed through the whole real path — finish, the
 * database queue, a failing replay retried to exhaustion — and then every place
 * it could leak is searched for it.
 */
const REPLAY_MARKERS = ['1733,1', '1201,2'];

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-10-03 12:00:00.000', 'UTC'));

    config([
        'queue.default' => 'database',
        'logging.default' => 'privacy_capture',
        'logging.channels.privacy_capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class],
    ]);
    app('log')->forgetChannel('privacy_capture');
    app('log')->forgetChannel('security');
});

function expectNoReplayMarker(string $haystack, string $where): void
{
    foreach (REPLAY_MARKERS as $marker) {
        expect(str_contains($haystack, $marker))->toBeFalse("replay input leaked into {$where}");
    }
}

/** The TestHandler behind a channel configured with one. */
function privacyLogHandler(string $channel): TestHandler
{
    $logger = app('log')->channel($channel);
    $monolog = $logger instanceof Illuminate\Log\Logger ? $logger->getLogger() : null;
    $handler = $monolog instanceof Logger ? ($monolog->getHandlers()[0] ?? null) : null;

    if (! $handler instanceof TestHandler) {
        throw new RuntimeException("Channel {$channel} is not capturing.");
    }

    return $handler;
}

function capturedLogText(): string
{
    $text = '';

    foreach (['privacy_capture', 'security'] as $channel) {
        foreach (privacyLogHandler($channel)->getRecords() as $record) {
            $text .= $record->message.json_encode($record->context).json_encode($record->extra)."\n";
        }
    }

    return $text;
}

function workReplayQueueOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'replay', '--once' => true, '--stop-when-empty' => true]);
}

it('never lets the input reach a log, the queue, a failure record, the response or the run', function (): void {
    app()->instance(ReplayRunner::class, FakeReplayRunner::failing(ReplayRunnerFailure::CRASHED));

    $user = User::factory()->create();
    $runId = startRun($user)->assertCreated()->json('data.run_id');
    travel(31_000)->milliseconds();

    $response = finishRun($user, $runId, [...plausibleTelemetry(), 'replay_input' => [
        'format_version' => 1,
        'domain_version' => '1',
        'total_steps' => 3000,
        'events' => [[1733, 1], [1201, 2]],
    ]], (string) Str::uuid())->assertOk();

    expectNoReplayMarker((string) $response->getContent(), 'the finish response');

    $job = DB::table('jobs')->where('queue', 'replay')->first();
    expect($job)->not->toBeNull()
        ->and($job->payload)->toContain($runId);
    expectNoReplayMarker($job->payload, 'the job payload');

    // Three attempts, each a transient failure, to exhaustion.
    workReplayQueueOnce();
    travel(31)->seconds();
    workReplayQueueOnce();
    travel(121)->seconds();
    workReplayQueueOnce();

    $failed = DB::table('failed_jobs')->first();
    expect($failed)->not->toBeNull()
        ->and($failed->exception)->toContain('replay_crashed')
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('outcome_code'))->toBe('attempts_exhausted')
        ->and(DB::table('run_replay_inputs')->where('run_id', $runId)->value('input'))->toBeNull();
    expectNoReplayMarker($failed->payload, 'failed_jobs.payload');
    expectNoReplayMarker($failed->exception, 'failed_jobs.exception');

    $run = DB::table('runs')->where('id', $runId)->first();
    expectNoReplayMarker((string) $run->result, 'runs.result');
    expectNoReplayMarker((string) $run->validation_meta, 'runs.validation_meta');

    expect(capturedLogText())->toContain('run.replay.completed');
    expectNoReplayMarker(capturedLogText(), 'the logs');
});

it('keeps the input out of the model\'s serialised form', function (): void {
    expect((new RunReplayInput)->getHidden())->toBe(['input']);
});
