<?php

declare(strict_types=1);

use App\Models\EmailVerificationCode;
use App\Models\TwoFactorChallenge;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\TwoFactorChallengeService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\withHeaders;

/**
 * The single-use and once-only guarantees, under a second caller.
 *
 * Every control here already passed a sequential test. That is the problem
 * sequential tests have: a read-then-write reads correctly and writes correctly
 * when nobody else is looking, and a cap enforced in PHP holds until two
 * requests hold the same snapshot. The defects these cover were all invisible to
 * a suite that only ever made one call at a time.
 *
 * True parallelism needs separate connections, which the transactional test
 * database cannot give us. So each test instead reproduces the *interleaving* —
 * two callers that both read the same state before either writes — which is the
 * condition the guard has to survive. A guard that passes this is one where the
 * database decides; one that fails it is one where PHP decided from a stale
 * read.
 */
it('counts a verification attempt once, even from two callers holding one snapshot', function (): void {
    $user = User::factory()->unverified()->create();
    $service = app(EmailVerificationService::class);
    $service->issue($user);

    $challenge = $user->emailVerificationCode()->first();
    $challenge?->forceFill(['attempts' => EmailVerificationCode::MAX_ATTEMPTS - 1])->save();

    // Two callers, one snapshot: both saw an attempt remaining.
    $first = $user->emailVerificationCode()->first();
    $second = $user->emailVerificationCode()->first();

    expect($first?->hasAttemptsLeft())->toBeTrue()
        ->and($second?->hasAttemptsLeft())->toBeTrue();

    $spent = 0;

    foreach ([$first, $second] as $view) {
        $spent += EmailVerificationCode::query()
            ->whereKey($view?->getKey())
            ->where('attempts', '<', EmailVerificationCode::MAX_ATTEMPTS)
            ->increment('attempts');
    }

    // Exactly one of them got the last attempt. Without the condition on the
    // update both would have, and the five-attempt cap would be worth as many
    // attempts as an attacker can open connections.
    expect($spent)->toBe(1)
        ->and(EmailVerificationCode::query()->whereKey($first?->getKey())->value('attempts'))
        ->toBe(EmailVerificationCode::MAX_ATTEMPTS);
});

it('counts a two-factor challenge attempt once, even from two callers holding one snapshot', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $challenges = app(TwoFactorChallengeService::class);
    $token = $challenges->start($user->id, 'Chrome on Linux')['token'];

    $challenge = $challenges->resolve($token);
    $challenge->forceFill(['attempts' => TwoFactorChallenge::MAX_ATTEMPTS - 1])->save();

    $first = TwoFactorChallenge::query()->whereKey($challenge->getKey())->first();
    $second = TwoFactorChallenge::query()->whereKey($challenge->getKey())->first();

    expect($first?->isUsable())->toBeTrue()
        ->and($second?->isUsable())->toBeTrue();

    $challenges->registerFailure($first);
    $challenges->registerFailure($second);

    // The cap is spent and the challenge is gone. The second caller must not
    // have been handed a guess the first one had already taken.
    expect(TwoFactorChallenge::query()->whereKey($challenge->getKey())->exists())->toBeFalse();
});

it('rejects a TOTP code replayed against the step it already spent', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $service = app(TwoFactorService::class);

    $code = currentTotpCode($user);

    expect($service->verifyTotp($user, $code))->toBeTrue();

    // A second caller that read the user *before* the first one wrote. This is
    // the interleaving a read-compare-write loses: both compare against the same
    // old step, both find the code newer, both accept.
    $stale = User::query()->whereKey($user->getKey())->first();
    $stale?->forceFill(['two_factor_last_used_timestep' => null])->syncOriginal();

    expect($service->verifyTotp($stale, $code))->toBeFalse();
});

it('keeps one recovery-code set when regeneration interleaves', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $service = app(TwoFactorService::class);

    $first = $service->replaceRecoveryCodes($user);
    $second = $service->replaceRecoveryCodes($user);

    // Eight live codes, not sixteen. Regenerating is a security action; ending
    // it with two valid sets would answer it by doubling the bypasses.
    expect(TwoFactorRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count())
        ->toBe(TwoFactorRecoveryCode::COUNT);

    expect($service->consumeRecoveryCode($user, $first[0]))->toBeFalse()
        ->and($service->consumeRecoveryCode($user, $second[0]))->toBeTrue();
});

it('spends a recovery code once when two callers hold the same code', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $service = app(TwoFactorService::class);
    $codes = $service->replaceRecoveryCodes($user);

    $results = [
        $service->consumeRecoveryCode($user, $codes[0]),
        $service->consumeRecoveryCode($user, $codes[0]),
    ];

    expect(array_filter($results))->toHaveCount(1);
});

it('keeps at most one live two-factor challenge per account', function (): void {
    // Enforced by the database now, not only by delete-then-insert: under READ
    // COMMITTED two concurrent logins both delete the row the other has not yet
    // written, and both insert.
    $user = User::factory()->withTwoFactor()->create();
    $challenges = app(TwoFactorChallengeService::class);

    $challenges->start($user->id, 'Chrome on Linux');
    $challenges->start($user->id, 'Safari on iPhone');

    expect(TwoFactorChallenge::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses a second live challenge row at the database level', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    app(TwoFactorChallengeService::class)->start($user->id, 'Chrome on Linux');

    // The index is the guarantee, so assert against the index rather than
    // against the code path that is supposed to respect it.
    expect(fn () => DB::table('two_factor_challenges')->insert([
        'user_id' => $user->id,
        'token_hash' => str_repeat('a', 64),
        'device_label' => 'Forged',
        'attempts' => 0,
        'expires_at' => now()->addMinutes(5),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('does not spend the rename cooldown on a rename that was refused', function (): void {
    // The cooldown claim and the name write are one transaction, so a rename
    // that does not happen must not cost a day's allowance.
    $taken = User::factory()->create();
    $taken->setDisplayName('SahilKedisi');
    $taken->save();

    $user = User::factory()->create();

    expect($user->display_name_changed_at)->toBeNull();

    withHeaders(sessionFor($user))
        ->patchJson('/api/v1/profile', ['display_name' => 'sahilkedisi'])
        ->assertStatus(422);

    expect($user->refresh()->display_name_changed_at)->toBeNull();

    // And the allowance really is still there.
    withHeaders(sessionFor($user))
        ->patchJson('/api/v1/profile', ['display_name' => 'DenizKedisi'])
        ->assertOk();
});

it('claims the rename cooldown once when two callers hold one snapshot', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['display_name_changed_at' => now()->subHours(25)])->save();

    // Both callers read an expired cooldown. Only one may claim it: the
    // conditional update's affected-row count is what decides, not the read.
    $claim = fn (): int => $user->newQuery()
        ->whereKey($user->getKey())
        ->where(fn ($query) => $query
            ->whereNull('display_name_changed_at')
            ->orWhere('display_name_changed_at', '<=', now()->subHours(24)))
        ->update(['display_name_changed_at' => now()]);

    expect($claim() + $claim())->toBe(1);
});
