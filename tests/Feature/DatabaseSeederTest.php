<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\DisplayName;

use function Pest\Laravel\artisan;

/**
 * The seeder is local convenience, and it must at least be valid.
 *
 * It sat broken for a milestone: it wrote `name`, a column the auth migration
 * had renamed, and factories build inside `Model::unguarded()` so nothing
 * stopped the stale key reaching PostgreSQL. Nobody noticed because nothing
 * runs it — no test, no CI step, no Composer script. This is that test.
 *
 * It asserts schema validity and nothing about the seed data's shape, so it
 * does not turn a convenience into a contract.
 */
it('runs against the current schema', function (): void {
    artisan('db:seed')->assertExitCode(0);

    $user = User::query()->where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->display_name)->toBe('TestUser')
        ->and($user->display_name_normalized)->toBe(DisplayName::normalize('TestUser'));
});

it('seeds an account with no privileges', function (): void {
    // The seeded credential is a constant in the factory. If this ever became an
    // administrator, a published password would own the admin surface of any
    // database it was run against.
    artisan('db:seed')->assertExitCode(0);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->isAdmin())->toBeFalse();
});
