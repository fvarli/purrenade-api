<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * GET / — the only thing this service says at its web root.
 *
 * There is no browser-facing surface here: no Blade, no view, no HTML error
 * page. Without this route the root returns a bare framework 404, which tells
 * an operator nothing and looks like a misconfigured deployment.
 *
 * `success: false` is honest — the caller asked for something this service does
 * not serve. The status is 200 because the *route* is not an error: it answers
 * exactly as designed, and 404-ing a deliberate signpost only obscures it in
 * monitoring.
 *
 * Every field is a constant or a safe descriptor. No paths, no versions, no
 * configuration, no environment beyond the tier name.
 */
final class RootController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This is an API-only application. No web access allowed.',
            'service' => config('service.name'),
            'environment' => config('app.env'),
            'api_base' => config('service.api_base'),
            'health' => config('service.health_path'),
            'documentation' => config('service.documentation_url'),
        ]);
    }
}
