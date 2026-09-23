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
| Argument 1 is a JSON object: {tag, method, uri, headers, body}.
|
*/

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

/** @var array{tag: string, method: string, uri: string, headers: array<string, string>, body: array<string, mixed>|null} $spec */
$spec = json_decode($argv[1], true, 64, JSON_THROW_ON_ERROR);

$app = require __DIR__.'/../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

DB::select("SELECT set_config('application_name', ?, false)", [$spec['tag']]);

// Fail rather than hang if an interleaving the test did not foresee blocks.
DB::select("SELECT set_config('lock_timeout', '20s', false)");

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
