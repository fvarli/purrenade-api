<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/**
 * The negative cases, which are the ones that matter
 * (docs/security/authorization-and-roles.md §8).
 *
 * Four conditions guard the admin surface, and each one is refused separately
 * with its own stable code:
 *
 *   role · verified address · enrolled second factor · this session passed a challenge
 *
 * The fourth is the one that is easy to get wrong: "has 2FA" is a property of
 * the account, "passed 2FA" is a property of the session.
 */
it('refuses an unauthenticated caller', function (): void {
    getJson('/api/v1/admin/overview')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});

it('refuses a player', function (): void {
    withHeaders(sessionFor(User::factory()->create(), twoFactorSatisfied: true))
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_role_required');
});

it('refuses a player who has 2FA and a challenged session', function (): void {
    // Having a second factor is not a route to privilege.
    withHeaders(sessionFor(User::factory()->withTwoFactor()->create(), twoFactorSatisfied: true))
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_role_required');
});

it('refuses an unverified admin', function (): void {
    $admin = User::factory()->admin()->unverified()->withTwoFactor()->create();

    withHeaders(sessionFor($admin, twoFactorSatisfied: true))
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified');
});

it('refuses an admin who has not enrolled a second factor', function (): void {
    // Reachable in practice: an operator promotes a player who has no 2FA.
    $admin = User::factory()->admin()->create();

    expect($admin->requiresTwoFactorEnrolment())->toBeTrue();

    withHeaders(sessionFor($admin, twoFactorSatisfied: true))
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_two_factor_required');
});

it('refuses an admin whose enrolment was never confirmed', function (): void {
    $admin = User::factory()->admin()->withPendingTwoFactor()->create();

    withHeaders(sessionFor($admin, twoFactorSatisfied: true))
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_two_factor_required');
});

it('refuses an enrolled admin whose session never passed a challenge', function (): void {
    // The account has 2FA; this *session* was minted by a password-only login.
    // Without this check, a token from before enrolment would keep working.
    $admin = User::factory()->admin()->withTwoFactor()->create();

    withHeaders(sessionFor($admin, twoFactorSatisfied: false))
        ->getJson('/api/v1/admin/overview')
        ->assertStatus(403)
        ->assertJsonPath('code', 'admin_two_factor_required');
});

it('admits a fully authenticated administrator', function (): void {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    withHeaders(sessionFor($admin, twoFactorSatisfied: true))
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.administrator.id', $admin->id)
        ->assertJsonPath('data.capabilities.planned_milestone', 'M13');
});

it('exposes only aggregate counts on the admin surface', function (): void {
    User::factory()->count(3)->create();
    User::factory()->unverified()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $data = withHeaders(sessionFor($admin, twoFactorSatisfied: true))
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->json('data.accounts');

    // An endpoint that exists to make access control testable must not become a
    // data-exposure surface of its own: counts, no rows, no addresses.
    expect($data)->toBe([
        'total' => 5,
        'verified' => 4,
        'administrators' => 1,
        'with_two_factor' => 1,
    ]);
});

it('gates every admin route structurally, not per route', function (): void {
    // The guarantee §5 asks for: enforcement at a level new routes inherit. If
    // an admin route ever appears without the middleware, this fails.
    $admin = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin'));

    expect($admin)->not->toBeEmpty();

    $admin->each(function ($route): void {
        expect($route->gatherMiddleware())
            ->toContain('admin')
            ->toContain('verified')
            ->toContain('auth:sanctum');
    });
});

it('gates every non-public route behind authentication', function (): void {
    // Deny by default, checked mechanically. A new endpoint that forgets its
    // group is caught here rather than in production.
    $public = [
        'api/v1/health',
        'api/v1/auth/register',
        'api/v1/auth/login',
        'api/v1/auth/2fa/challenge',
        'api/v1/auth/password/forgot',
        'api/v1/auth/password/reset',
    ];

    $ungated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->reject(fn ($route): bool => in_array($route->uri(), $public, true))
        ->reject(fn ($route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    expect($ungated)->toBe([]);
});

it('keeps the verified gate on everything except the unverified allowance', function (): void {
    // AUTH-1, checked mechanically: exactly four endpoints are reachable with an
    // unverified session.
    // Sorted, to match the comparison below — the set is what matters, not the
    // order routes happen to be registered in.
    $allowance = [
        'api/v1/auth/email/verify',
        'api/v1/auth/email/verify/resend',
        'api/v1/auth/logout',
        'api/v1/auth/me',
    ];

    $authenticatedRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true));

    $reachableWhileUnverified = $authenticatedRoutes
        ->reject(fn ($route): bool => in_array('verified', $route->gatherMiddleware(), true))
        ->map(fn ($route): string => $route->uri())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($reachableWhileUnverified)->toBe($allowance);
});

it('stores the role as a checked enum value', function (): void {
    $player = User::factory()->create();
    $admin = User::factory()->admin()->create();

    expect($player->role)->toBe(UserRole::Player)
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($admin->isAdmin())->toBeTrue()
        ->and($player->isAdmin())->toBeFalse();
});

it('refuses a role value the enum does not define', function (): void {
    $user = User::factory()->create();

    // A database check constraint as well as the PHP enum. A role column is a
    // privilege column: a stray write must fail loudly rather than produce an
    // account whose privileges depend on how some comparison happens to behave.
    expect(fn () => DB::table('users')
        ->where('id', $user->id)
        ->update(['role' => 'superuser']))
        ->toThrow(QueryException::class);
});
