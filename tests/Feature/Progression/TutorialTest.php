<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

/**
 * Tutorial completion, which is the one durable fact M8 writes.
 *
 * Two properties carry the weight here. It must be **idempotent** — completion
 * is a one-time fact, and replaying the tutorial from Settings must leave it
 * exactly as it was — and it must be **unaimable**: the actor comes from the
 * token, so there is no id a caller could substitute for somebody else's.
 */
it('records completion for the authenticated player', function (): void {
    $user = User::factory()->create();

    expect($user->tutorial_completed_at)->toBeNull();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/progression/tutorial')
        ->assertOk()
        ->assertJsonPath('data.tutorial_completed', true);

    $user->refresh();

    expect($user->tutorial_completed_at)->not->toBeNull();
});

it('does not re-stamp a completion that already happened', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    $user->refresh();

    $first = $user->tutorial_completed_at;

    /*
     * A full day later, so "unchanged" is a real assertion rather than two
     * writes landing in the same second and looking equal. This is the Settings
     * replay case, and the double-submit case, and the network-retry case —
     * they are all the same request arriving twice.
     */
    travel(1)->days();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/progression/tutorial')
        ->assertOk()
        ->assertJsonPath('data.tutorial_completed', true);

    $user->refresh();

    expect($user->tutorial_completed_at->equalTo($first))->toBeTrue();
});

it('reports completion on the session projection', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tutorial_completed', false);

    withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    withHeaders(sessionFor($user))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tutorial_completed', true);
});

it('tells the client whether, never when', function (): void {
    $user = User::factory()->create();

    $response = withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    // The timestamp is the stored fact and stays stored. A date in the browser
    // would be tutorial telemetry nobody asked for.
    expect($response->json('data'))->toBe(['tutorial_completed' => true]);

    withHeaders(sessionFor($user))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonMissingPath('data.tutorial_completed_at');
});

it('refuses an unauthenticated caller', function (): void {
    postJson('/api/v1/progression/tutorial')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    expect(User::query()->whereNotNull('tutorial_completed_at')->count())->toBe(0);
});

it('requires a verified address', function (): void {
    $user = User::factory()->unverified()->create();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/progression/tutorial')
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified');

    expect($user->refresh()->tutorial_completed_at)->toBeNull();
});

it('cannot be aimed at another player', function (): void {
    $actor = User::factory()->create();
    $victim = User::factory()->create();

    /*
     * There is no id in the contract, so the only way to try is to invent one.
     * The endpoint takes no body at all and resolves the actor from the token,
     * which is why this is unaimable by construction rather than by a check
     * somebody could forget to write.
     */
    withHeaders(sessionFor($actor))
        ->postJson('/api/v1/progression/tutorial', [
            'user_id' => $victim->id,
            'id' => $victim->id,
            'tutorial_completed_at' => '2020-01-01T00:00:00+00:00',
        ])
        ->assertOk();

    expect($actor->refresh()->tutorial_completed_at)->not->toBeNull()
        ->and($victim->refresh()->tutorial_completed_at)->toBeNull();
});

it('ignores fields the endpoint does not accept', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->postJson('/api/v1/progression/tutorial', [
            'role' => 'admin',
            'email' => 'somewhere-else@example.test',
            'display_name' => 'NotMyName',
        ])
        ->assertOk();

    $user->refresh();

    expect($user->role->value)->toBe('player')
        ->and($user->email)->not->toBe('somewhere-else@example.test')
        ->and($user->display_name)->not->toBe('NotMyName');
});

it('is not a run, and writes nothing that looks like one', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))->postJson('/api/v1/progression/tutorial')->assertOk();

    /*
     * domain-boundaries.md §3: *tutorial runs are not runs*. The only column
     * this endpoint may touch is the one it is named for — so every other
     * mutable field on the row must be exactly what the factory left.
     */
    $fresh = User::query()->findOrFail($user->getKey());

    expect($fresh->display_name)->toBe($user->display_name)
        ->and($fresh->display_name_changed_at)->toBeNull()
        ->and($fresh->two_factor_version)->toBe($user->two_factor_version)
        ->and($fresh->email_verified_at)->not->toBeNull();
});

it('defaults to not completed for a brand-new account', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tutorial_completed', false);
});

it('survives a fresh session on another device', function (): void {
    $user = User::factory()->create();

    withHeaders(sessionFor($user, device: 'Chrome on Linux'))
        ->postJson('/api/v1/progression/tutorial')
        ->assertOk();

    // A different token entirely — the point of persisting this server-side is
    // that it follows the account rather than the browser.
    withHeaders(sessionFor($user, device: 'Safari on iPhone'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tutorial_completed', true);
});
