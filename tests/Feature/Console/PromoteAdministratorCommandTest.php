<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\Auth\TwoFactorChallengeService;
use App\Support\AuthLog;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * `purrenade:admin:promote` — the only supported route to the admin role.
 *
 * The command is small; what it must never do is the larger half of it, so most
 * of this file is negative. A bootstrap tool that can create an account, set a
 * password, verify an address or fabricate a second factor is a privilege
 * escalation primitive sitting in the repository, and the way to know it is not
 * one is to assert every field it must leave alone.
 */
/**
 * Record every event written to the security channel for the rest of the test.
 *
 * Returns a closure yielding what has been captured so far. Deliberately
 * permissive, unlike the strict expectations in "the audit trail" above: these
 * tests make real HTTP requests after the command, and the admin gate logs its
 * own authorization events on the same channel. The question here is only
 * whether a grant was recorded for a grant that never happened.
 *
 * @return callable(): list<array{0: string, 1: array<string, mixed>}>
 */
function captureSecurityLog(): callable
{
    $captured = [];

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->andReturnUsing(
        function (string $event, array $context = []) use (&$captured): void {
            $captured[] = [$event, $context];
        }
    );
    Log::shouldReceive('debug', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency')
        ->andReturnNull();

    return fn (): array => $captured;
}

describe('preconditions', function (): void {
    it('refuses an address that does not exist', function (): void {
        artisan('purrenade:admin:promote', ['email' => 'nobody@example.test', '--force' => true])
            ->assertExitCode(1);

        expect(User::query()->count())->toBe(0);
    });

    it('refuses an account that has not verified its email', function (): void {
        $user = User::factory()->unverified()->create();

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(1);

        expect($user->refresh()->role)->toBe(UserRole::Player)
            ->and($user->email_verified_at)->toBeNull();
    });

    it('never creates an account for an unknown address', function (): void {
        // The command takes an address and no other account material, so there
        // is nothing for it to create one from — asserted rather than assumed,
        // because "promote" tools that quietly upsert are a known shape.
        artisan('purrenade:admin:promote', ['email' => 'ghost@example.test', '--force' => true])
            ->assertExitCode(1);

        expect(User::query()->where('email', 'ghost@example.test')->exists())->toBeFalse();
    });
});

describe('promotion', function (): void {
    it('promotes a verified player', function (): void {
        $user = User::factory()->create();

        expect($user->role)->toBe(UserRole::Player);

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        expect($user->refresh()->role)->toBe(UserRole::Admin)
            ->and($user->isAdmin())->toBeTrue();
    });

    it('changes the role and nothing else', function (): void {
        $user = User::factory()->withTwoFactor()->create();

        $before = $user->only([
            'display_name', 'display_name_normalized', 'email', 'email_verified_at',
            'password', 'two_factor_secret', 'two_factor_confirmed_at',
            'two_factor_version', 'two_factor_last_used_timestep',
        ]);

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        $after = $user->refresh()->only(array_keys($before));

        // Every privilege column except `role`, byte for byte. A password left
        // alone is the point of the whole bootstrap model: the operator never
        // learns, sets or resets the administrator's credential.
        expect($after)->toEqual($before);
    });

    it('is idempotent and leaves a working administrator alone', function (): void {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $headers = sessionFor($admin, twoFactorSatisfied: true);

        artisan('purrenade:admin:promote', ['email' => $admin->email, '--force' => true])
            ->assertExitCode(0);

        expect($admin->refresh()->role)->toBe(UserRole::Admin)
            // Re-running must not sign a working administrator out. A no-op
            // that revokes sessions is not a no-op.
            ->and($admin->tokens()->count())->toBe(1);

        withHeaders($headers)->getJson('/api/v1/admin/overview')->assertOk();
    });

    it('matches the address the way every other entry point does', function (): void {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        // An Artisan argument reaches no FormRequest, so the normalisation the
        // four auth requests perform has to be repeated by the command. Without
        // it this reports "no such account" for a row that is plainly there.
        artisan('purrenade:admin:promote', ['email' => '  Ada@Example.TEST  ', '--force' => true])
            ->assertExitCode(0);

        expect($user->refresh()->role)->toBe(UserRole::Admin);
    });
});

describe('the credentials the account already held', function (): void {
    it('revokes every existing session', function (): void {
        $user = User::factory()->create();

        sessionFor($user);
        sessionFor($user, device: 'Firefox on Windows');

        expect($user->tokens()->count())->toBe(2);

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        expect($user->refresh()->tokens()->count())->toBe(0);
    });

    it('stops a second factor that was satisfied as a player from being admin-grade', function (): void {
        /*
         * The escalation this command exists to close.
         *
         * A player with confirmed 2FA, holding a token minted by the challenge
         * endpoint, satisfies three of the admin gate's four conditions already.
         * Flip the role and the fourth becomes true too — a session that proved
         * possession as a player would become an administrative session with no
         * further act by anyone. Revoking makes the privilege begin at a
         * sign-in somebody performed knowingly.
         */
        $user = User::factory()->withTwoFactor()->create();
        $headers = sessionFor($user, twoFactorSatisfied: true);

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        expect($user->refresh()->role)->toBe(UserRole::Admin);

        withHeaders($headers)->getJson('/api/v1/admin/overview')->assertUnauthorized();
    });

    it('purges a challenge opened before the promotion', function (): void {
        // Otherwise the revocation above is one step from complete: a
        // half-authenticated handle issued to a player would redeem into a
        // session on an account that is now an administrator.
        $user = User::factory()->withTwoFactor()->create();

        app(TwoFactorChallengeService::class)->start($user->id, 'Chrome on Linux');

        expect(TwoFactorChallenge::query()->where('user_id', $user->id)->exists())->toBeTrue();

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        expect(TwoFactorChallenge::query()->where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('does not touch the second factor generation counter', function (): void {
        // A role change does not invalidate the factor itself. Bumping the
        // counter would make `satisfiesTwoFactorFor()` mean something its name
        // no longer says.
        $user = User::factory()->withTwoFactor()->create();
        $version = $user->two_factor_version;

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        expect($user->refresh()->two_factor_version)->toBe($version);
    });
});

describe('mandatory administrator two-factor survives promotion', function (): void {
    it('promotes a player who has no second factor, and says so', function (): void {
        // Deliberately permitted: "requires 2FA to be enabled first" is PROPOSED
        // in docs/security/two-factor.md, and refusing here would decide it.
        $user = User::factory()->create();

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->expectsOutputToContain('mandatory')
            ->assertExitCode(0);

        expect($user->refresh()->role)->toBe(UserRole::Admin)
            ->and($user->requiresTwoFactorEnrolment())->toBeTrue();
    });

    it('still refuses the admin surface until a factor is enrolled and satisfied', function (): void {
        $user = User::factory()->create();

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        $user->refresh();

        // A fresh sign-in is not enough; the gate wants an enrolled factor.
        withHeaders(sessionFor($user))
            ->getJson('/api/v1/admin/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'admin_two_factor_required');
    });

    it('cannot be given a second factor by the promotion itself', function (): void {
        $user = User::factory()->create();

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        $user->refresh();

        expect($user->two_factor_secret)->toBeNull()
            ->and($user->two_factor_confirmed_at)->toBeNull()
            ->and($user->hasTwoFactorEnabled())->toBeFalse()
            ->and($user->twoFactorRecoveryCodes()->count())->toBe(0);
    });
});

describe('confirmation', function (): void {
    it('changes nothing when the operator declines', function (): void {
        $user = User::factory()->create();

        artisan('purrenade:admin:promote', ['email' => $user->email])
            ->expectsConfirmation("Grant the administrator role to {$user->email}?", 'no')
            ->assertExitCode(1);

        expect($user->refresh()->role)->toBe(UserRole::Player);
    });

    it('promotes when the operator confirms', function (): void {
        $user = User::factory()->create();

        artisan('purrenade:admin:promote', ['email' => $user->email])
            ->expectsConfirmation("Grant the administrator role to {$user->email}?", 'yes')
            ->assertExitCode(0);

        expect($user->refresh()->role)->toBe(UserRole::Admin);
    });
});

describe('the audit trail', function (): void {
    it('records the grant on the security channel without the address', function (): void {
        $user = User::factory()->create();
        $captured = [];

        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(
            function (string $event, array $context) use (&$captured): void {
                $captured[] = [$event, $context];
            }
        );

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        $grants = array_values(array_filter(
            $captured,
            fn (array $entry): bool => $entry[0] === AuthLog::ADMIN_ROLE_GRANTED,
        ));

        expect($grants)->toHaveCount(1);

        [, $context] = $grants[0];

        expect($context['user_id'])->toBe($user->id)
            ->and($context['role'])->toBe(UserRole::Admin->value)
            ->and($context['actor'])->toBe('cli')
            // observability.md §3.1 is APPROVED: never an address beside an id.
            ->and($context)->not->toHaveKey('email');
    });

    it('records nothing when the promotion is a no-op', function (): void {
        $admin = User::factory()->admin()->create();

        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('info')->never();

        artisan('purrenade:admin:promote', ['email' => $admin->email, '--force' => true])
            ->assertExitCode(0);
    });
});

/*
 * The adversarial pass.
 *
 * Everything above asserts the command does what it says. These try to make it
 * do something else: redeem a half-authenticated handle across the privilege
 * boundary, leave an account promoted when the revocation it depends on failed,
 * and write a column nobody asked it to write.
 */
describe('adversarial: a challenge cannot be redeemed across the promotion', function (): void {
    it('refuses a challenge token issued before the role changed', function (): void {
        /*
         * The end-to-end form of the purge test.
         *
         * Asserting the row is gone proves the delete ran; it does not prove the
         * handle is dead, which is the property that matters. So this takes the
         * real `challenge_token` the login endpoint issued, promotes underneath
         * it, and tries to spend it — the exact move an operator-adjacent
         * attacker would make, having started a login and then waited for the
         * promotion they knew was coming.
         */
        $user = User::factory()->withTwoFactor()->create();

        $challengeToken = postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ])->assertOk()->json('meta.challenge_token');

        expect($challengeToken)->toBeString();
        expect($challengeToken)->not->toBeEmpty();

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => currentTotpCode($user),
        ])->assertStatus(401);

        // Refused at redemption, not merely at the gate: no credential exists at
        // all, so there is nothing to replay against the admin surface later.
        expect($user->refresh()->role)->toBe(UserRole::Admin)
            ->and($user->tokens()->count())->toBe(0);
    });

    it('leaves a challenge opened after the promotion alone', function (): void {
        // The mirror case, and intentionally not symmetrical. A challenge an
        // administrator opened for themselves is not a stale player handle;
        // redeeming it yields a session that passed a challenge as an admin,
        // which is exactly how an admin is supposed to sign in. A second,
        // no-op promotion must not break a sign-in already in progress.
        $user = User::factory()->withTwoFactor()->create();

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        $challengeToken = postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ])->assertOk()->json('meta.challenge_token');

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        $headers = ['Authorization' => 'Bearer '.postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => currentTotpCode($user),
        ])->assertOk()->json('meta.token')];

        withHeaders($headers)->getJson('/api/v1/admin/overview')->assertOk();
    });
});

describe('adversarial: the argument cannot select an account it did not name', function (): void {
    it('treats a SQL wildcard as an ordinary character', function (): void {
        // The lookup is a bound, byte-exact equality after normalisation. If it
        // were ever rewritten as a `like`, this argument would promote whichever
        // account the driver matched first — and the operator would have typed
        // something that looks like a typo, not an attack.
        User::factory()->create(['email' => 'ada@example.test']);
        User::factory()->create(['email' => 'bea@example.test']);

        artisan('purrenade:admin:promote', ['email' => '%@example.test', '--force' => true])
            ->assertExitCode(1);

        expect(User::query()->where('role', UserRole::Admin)->count())->toBe(0);
    });

    it('does not match an address that merely contains the one given', function (): void {
        User::factory()->create(['email' => 'ada@example.test']);
        $lookalike = User::factory()->create(['email' => 'ada@example.test.other.test']);

        artisan('purrenade:admin:promote', ['email' => 'ada@example.test', '--force' => true])
            ->assertExitCode(0);

        expect($lookalike->refresh()->role)->toBe(UserRole::Player);
    });
});

describe('adversarial: the promotion is all-or-nothing', function (): void {
    it('rolls the role back when the session revocation fails', function (): void {
        /*
         * The state this must never produce is an account that is an
         * administrator while the sessions it held as a player are still live —
         * precisely the escalation the transaction exists to prevent. If the
         * revocation can fail while the role write survives, the command
         * manufactures that state itself.
         *
         * The failure is injected at the database layer rather than by doubling
         * `SessionIssuer`, which is `final`. That is the better simulation
         * anyway: it fails the actual statement the revocation depends on,
         * inside the real transaction, rather than a stubbed method call.
         */
        $user = User::factory()->withTwoFactor()->create();
        $headers = sessionFor($user, twoFactorSatisfied: true);

        $events = captureSecurityLog();

        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'delete from "personal_access_tokens"')) {
                throw new RuntimeException('revocation failed');
            }
        });

        expect(fn (): int => artisan('purrenade:admin:promote', [
            'email' => $user->email, '--force' => true,
        ])->run())->toThrow(RuntimeException::class);

        expect($user->refresh()->role)->toBe(UserRole::Player)
            ->and($user->tokens()->count())->toBe(1)
            // No audit line for a grant that did not happen.
            ->and(array_column($events(), 0))->not->toContain(AuthLog::ADMIN_ROLE_GRANTED);

        // Still a player's session, refused for the role rather than for a
        // token the failed revocation might have destroyed anyway.
        withHeaders($headers)->getJson('/api/v1/admin/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'admin_role_required');
    });

    it('rolls the role back when the challenge purge fails', function (): void {
        $user = User::factory()->withTwoFactor()->create();

        app(TwoFactorChallengeService::class)->start($user->id, 'Chrome on Linux');

        $events = captureSecurityLog();

        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'delete from "two_factor_challenges"')) {
                throw new RuntimeException('purge failed');
            }
        });

        expect(fn (): int => artisan('purrenade:admin:promote', [
            'email' => $user->email, '--force' => true,
        ])->run())->toThrow(RuntimeException::class);

        // Role unchanged and the challenge still present: a consistent state the
        // operator can simply retry, not a half-applied one.
        expect($user->refresh()->role)->toBe(UserRole::Player)
            ->and(TwoFactorChallenge::query()->where('user_id', $user->id)->exists())->toBeTrue()
            ->and(array_column($events(), 0))->not->toContain(AuthLog::ADMIN_ROLE_GRANTED);
    });
});

describe('adversarial: the write touches one column', function (): void {
    it('issues an update naming only the role', function (): void {
        // The byte-identity test above compares model attributes, which would
        // miss a column written back to the same value it already held. This
        // reads the statement itself.
        $user = User::factory()->withTwoFactor()->create();

        DB::enableQueryLog();

        artisan('purrenade:admin:promote', ['email' => $user->email, '--force' => true])
            ->assertExitCode(0);

        $updates = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_contains($query['query'], 'update "users"'),
        ));

        DB::disableQueryLog();

        expect($updates)->toHaveCount(1);

        // Only the SET clause: the WHERE names `id`, which is not a write.
        $set = Str::before(Str::after($updates[0]['query'], ' set '), ' where ');

        preg_match_all('/"(\w+)" = \?/', $set, $matches);

        expect($matches[1])->toEqualCanonicalizing(['role', 'updated_at']);
    });
});
