<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/overview — the minimum administrative surface.
 *
 * This endpoint exists to make the access-control decisions *testable*, and for
 * nothing else. The approved admin capability set (user lookup, suspension, run
 * inspection and invalidation, name moderation, audit inspection) is M13 work;
 * building any of it now would be gameplay-adjacent scope with no
 * authorization question left to answer.
 *
 * What it does prove, on every request, through the route group's middleware:
 * the caller is an administrator, their address is verified, they have enrolled
 * a second factor, and **this session actually passed a two-factor challenge**.
 * Four conditions, four negative tests.
 *
 * The counts are aggregate and contain no personal data, so an endpoint that
 * exists for a test does not become a data-exposure surface of its own.
 */
final class OverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        return response()->json([
            'data' => [
                'administrator' => [
                    'id' => $admin->id,
                    'display_name' => $admin->display_name,
                ],
                'accounts' => [
                    'total' => User::query()->count(),
                    'verified' => User::query()->whereNotNull('email_verified_at')->count(),
                    'administrators' => User::query()->where('role', UserRole::Admin->value)->count(),
                    'with_two_factor' => User::query()->whereNotNull('two_factor_confirmed_at')->count(),
                ],
                'capabilities' => [
                    // Named so the frontend can render an honest "not built yet"
                    // rather than an empty console. The approved set is recorded
                    // in docs/api/endpoints/admin.md; AD-5 contracts it at M13.
                    'available' => [],
                    'planned_milestone' => 'M13',
                ],
            ],
        ]);
    }
}
