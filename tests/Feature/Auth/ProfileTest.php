<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ProfileController;
use App\Models\User;
use App\Support\DisplayName;

use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

it('changes the display name', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->patchJson('/api/v1/profile', ['display_name' => 'Ayşenur'])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Ayşenur');

    $user->refresh();

    expect($user->display_name)->toBe('Ayşenur')
        ->and($user->display_name_normalized)->toBe(DisplayName::normalize('Ayşenur'))
        ->and($user->display_name_changed_at)->not->toBeNull();
});

it('refuses a name already taken, case-insensitively', function (): void {
    $taken = User::factory()->create();
    $taken->setDisplayName('SahilKedisi');
    $taken->save();

    withHeaders(sessionFor(User::factory()->create()))
        ->patchJson('/api/v1/profile', ['display_name' => 'sahilkedisi'])
        ->assertStatus(422)
        ->assertJsonPath('errors.display_name.0.code', 'taken');
});

it('lets a player re-submit their own name unchanged', function (): void {
    $user = User::factory()->create();
    $user->setDisplayName('Ayse');
    $user->save();

    // The uniqueness rule ignores the caller's own row, so a name is never
    // "taken" by its own owner.
    withHeaders(sessionFor($user))
        ->patchJson('/api/v1/profile', ['display_name' => 'Ayse'])
        ->assertOk();

    expect($user->fresh()?->display_name_changed_at)->toBeNull();
});

it('lets a player change only the casing of their own name', function (): void {
    $user = User::factory()->create();
    $user->setDisplayName('ayse');
    $user->save();

    withHeaders(sessionFor($user))
        ->patchJson('/api/v1/profile', ['display_name' => 'AYSE'])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'AYSE');
});

it('enforces the rename cooldown', function (): void {
    $user = User::factory()->create();
    $headers = sessionFor($user);

    withHeaders($headers)
        ->patchJson('/api/v1/profile', ['display_name' => 'FirstName'])
        ->assertOk();

    $response = withHeaders($headers)
        ->patchJson('/api/v1/profile', ['display_name' => 'SecondName'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'display_name_change_cooldown');

    // A concrete wait, so the UI can say when rather than just "no".
    expect($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0);

    expect($user->fresh()?->display_name)->toBe('FirstName');
});

it('allows a rename once the cooldown has elapsed', function (): void {
    $user = User::factory()->create();
    $headers = sessionFor($user);

    withHeaders($headers)
        ->patchJson('/api/v1/profile', ['display_name' => 'FirstName'])
        ->assertOk();

    travel(ProfileController::RENAME_COOLDOWN_HOURS + 1)->hours();

    withHeaders($headers)
        ->patchJson('/api/v1/profile', ['display_name' => 'SecondName'])
        ->assertOk();
});

it('survives a cache flush, because the cooldown lives on the row', function (): void {
    $user = User::factory()->create();
    $headers = sessionFor($user);

    withHeaders($headers)
        ->patchJson('/api/v1/profile', ['display_name' => 'FirstName'])
        ->assertOk();

    // A limiter bucket disappears when the cache does. A rename cooldown that a
    // cache restart clears is not a cooldown.
    cache()->flush();

    withHeaders($headers)
        ->patchJson('/api/v1/profile', ['display_name' => 'SecondName'])
        ->assertStatus(429);
});

it('refuses a malformed name', function (): void {
    withHeaders(sessionFor(User::factory()->create()))
        ->patchJson('/api/v1/profile', ['display_name' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('errors.display_name.0.code', 'format_invalid');
});

it('ignores fields the endpoint does not accept', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->patchJson('/api/v1/profile', [
            'display_name' => 'NewName',
            'role' => 'admin',
            'email' => 'somewhere-else@example.test',
            'email_verified_at' => null,
        ])
        ->assertOk();

    $user->refresh();

    expect($user->role->value)->toBe('player')
        ->and($user->email)->not->toBe('somewhere-else@example.test')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('requires a verified address', function (): void {
    withHeaders(sessionFor(User::factory()->unverified()->create()))
        ->patchJson('/api/v1/profile', ['display_name' => 'NewName'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified');
});
