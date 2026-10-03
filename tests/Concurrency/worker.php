<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Concurrency worker
|--------------------------------------------------------------------------
|
| One HTTP request through the real application, in its own process and on its
| own PostgreSQL connection — the unit of true concurrency the transactional
| feature suite cannot provide. Started by Tests\Support\RunWorkers; prints one
| JSON line: {"status": int, "body": mixed}.
|
| The connection is tagged with `application_name` so the orchestrator can see,
| in pg_stat_activity, the moment this request is waiting on a lock — and so a
| test-only trigger can pause exactly this request at a chosen write.
|
| Argument 1 is a JSON object: {tag, method, uri, headers, body, replay}.
|
| With `replay` set, the unit of work is not an HTTP request but the ANTI-6
| replay path: `{run_id, answer}` processes one pending replay with a fake
| runner answering `answer` (the real process is not under test here; its
| commit transaction is), `{sweep: true}` runs the sweeper once, and
| `{invalidate: run_id}` flips a run to rejected under M13's opening lock order.
|
*/

use App\Services\Replay\ReplayEvidenceService;
use App\Services\Replay\ReplayRunner;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeReplayRunner;

require __DIR__.'/../../vendor/autoload.php';

/** @var array{tag: string, method: string, uri: string, headers: array<string, string>, body: array<string, mixed>|null, replay: array<string, mixed>|null} $spec */
$spec = json_decode($argv[1], true, 64, JSON_THROW_ON_ERROR);

$app = require __DIR__.'/../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

DB::select("SELECT set_config('application_name', ?, false)", [$spec['tag']]);

// Fail rather than hang if an interleaving the test did not foresee blocks.
DB::select("SELECT set_config('lock_timeout', '20s', false)");

if (is_array($spec['replay'] ?? null)) {
    $replay = $spec['replay'];

    if (($replay['sweep'] ?? false) === true) {
        $status = $app->make(ConsoleKernel::class)->call('replay:sweep');
    } elseif (isset($replay['invalidate'])) {
        // Stands in for M13 invalidation's opening locks, RUN → PROGRESSION.
        DB::transaction(function () use ($replay): void {
            $userId = DB::scalar('SELECT user_id FROM runs WHERE id = ? FOR UPDATE', [$replay['invalidate']]);
            DB::select('SELECT 1 FROM player_progression WHERE user_id = ? FOR UPDATE', [$userId]);
            DB::update("UPDATE runs SET status = 'rejected' WHERE id = ?", [$replay['invalidate']]);
        });
        $status = 0;
    } else {
        $app->instance(ReplayRunner::class, new FakeReplayRunner((string) $replay['answer']));
        $app->make(ReplayEvidenceService::class)->process((string) $replay['run_id']);
        $status = 0;
    }

    fwrite(STDOUT, json_encode(['status' => $status, 'body' => null], JSON_THROW_ON_ERROR).PHP_EOL);

    return;
}

$server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

foreach ($spec['headers'] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}

$request = Request::create(
    $spec['uri'],
    $spec['method'],
    server: $server,
    content: $spec['body'] === null ? null : json_encode($spec['body'], JSON_THROW_ON_ERROR),
);

$response = $kernel->handle($request);

fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
], JSON_THROW_ON_ERROR).PHP_EOL);

$kernel->terminate($request, $response);
