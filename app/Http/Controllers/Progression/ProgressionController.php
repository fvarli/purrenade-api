<?php

declare(strict_types=1);

namespace App\Http\Controllers\Progression;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgressionResource;
use App\Models\User;
use App\Services\Progression\ProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /progression — the caller's own durable progression.
 *
 * Read-only: a player with no row yet reads as zeros, and nothing is written.
 * The actor is the bearer of the token, so there is no id to aim elsewhere.
 */
final class ProgressionController extends Controller
{
    public function __construct(
        private readonly ProgressionService $progression,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new ProgressionResource(
            $this->progression->current($user),
            $user->hasCompletedTutorial(),
        ))->response();
    }
}
