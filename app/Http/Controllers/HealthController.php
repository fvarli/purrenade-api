<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\DatabaseHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/health — readiness.
 *
 * This endpoint reports whether the service can actually do its job, which for
 * an API whose every meaningful response comes out of PostgreSQL means checking
 * PostgreSQL. A green light from a process that cannot reach its database is
 * worse than no light at all.
 *
 * Scope is deliberately one dependency. Cache, queue, mail and Redis are not
 * adopted by this project, and a probe that checks things the service does not
 * use is an observability system pretending to be a health check.
 *
 * Nothing here is privileged: no version, no hostname, no path, no credential,
 * no SQL, no stack. See docs/architecture/observability.md §7.
 */
final class HealthController extends Controller
{
    public function __invoke(DatabaseHealth $database): JsonResponse
    {
        $databaseIsReachable = $database->isReachable();

        return response()->json([
            'success' => $databaseIsReachable,
            'status' => $databaseIsReachable ? 'ok' : 'degraded',
            'service' => config('service.name'),
            'environment' => config('app.env'),
            'timestamp' => Carbon::now('UTC')->toIso8601String(),
            'checks' => [
                // If this code is running at all, the application booted, the
                // container resolved and routing dispatched.
                'application' => 'ok',
                'database' => $databaseIsReachable ? 'ok' : 'error',
            ],
        ], $databaseIsReachable
            ? Response::HTTP_OK
            // 503, not 500: the service is temporarily unable to serve, which
            // is what a load balancer or uptime monitor needs to hear in order
            // to take this instance out of rotation rather than page a human.
            : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
