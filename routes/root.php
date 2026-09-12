<?php

declare(strict_types=1);

use App\Http\Controllers\RootController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root route — /
|--------------------------------------------------------------------------
|
| The web root of an API-only service. Separate from routes/api.php because it
| sits above the /api prefix, and separate from a `web:` routes file because
| this application has no web middleware group to belong to: no sessions, no
| CSRF token, no cookie state. It is registered through withRouting(then:) in
| bootstrap/app.php.
|
| Nothing else ever goes in this file. Product endpoints are versioned from the
| first one and live in routes/api.php — see docs/api/api-conventions.md §1.
|
| Like every other route, it must exist in docs/api/openapi.draft.yaml; CI
| scans this file as well.
|
*/

Route::middleware('api')->group(function (): void {
    Route::get('/', RootController::class)->name('root');
});
