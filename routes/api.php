<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — /api/v1
|--------------------------------------------------------------------------
|
| Versioned from the first endpoint, never retrofitted. See
| docs/api/api-conventions.md §1.
|
| Product endpoints are NOT implemented in the bootstrap milestone. The
| contract for them lives in docs/api/openapi.draft.yaml and is built out from
| M2 onward.
|
| Every endpoint that exists here must also exist in the OpenAPI document —
| an undocumented endpoint is invisible to the only mechanism keeping the two
| repositories in step.
|
| Routes point at controllers rather than closures so `php artisan route:cache`
| keeps working: a closure route cannot be serialised, and the failure would
| only appear during a production deploy.
|
*/

Route::prefix('v1')->group(function (): void {
    // Readiness: the process is up AND can reach PostgreSQL. Returns 503 when
    // it cannot. See App\Http\Controllers\HealthController.
    Route::get('/health', HealthController::class)->name('health');
});
