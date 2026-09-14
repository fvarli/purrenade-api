<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\SessionIssuer;
use App\Services\Auth\TwoFactorChallengeService;
use App\Support\AuthLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grant the administrator role to an account that already exists.
 *
 * The first administrator has to come from somewhere, and until now the only
 * written procedure was a `tinker --execute` one-liner that wrote the column
 * directly. That works, which is the problem: it is a privilege change with no
 * precondition, no audit line, and no consequence for the sessions the account
 * already holds.
 *
 * What this command deliberately does **not** do is as important as what it
 * does. It does not create an account, set or reset a password, mark an address
 * verified, generate or touch a second factor, or grant anything in the game.
 * The person becomes an administrator the same way they became a player —
 * through the public flow, with their own password, which no operator ever
 * sees. This only changes one column afterwards.
 *
 * ### Why it revokes sessions
 *
 * Authorization is re-read from the row on every request, so a role change
 * takes effect immediately. For a player with no second factor that is
 * harmless: the admin gate refuses them at the enrolment check either way.
 *
 * The case that is not harmless is a player who *already* has confirmed 2FA and
 * is holding a token minted by the challenge endpoint. That token carries the
 * `two-factor` ability and a matching `two_factor_version`, so the instant the
 * role flips, all four conditions in `EnsureAdministrator` are true — a session
 * that proved possession as a player becomes an administrative session with no
 * further act by anyone. Revoking makes the new privilege begin at a sign-in
 * somebody performed knowing what the account had become.
 *
 * Pending challenges go with them: a challenge opened before the promotion is a
 * half-authenticated handle that would otherwise redeem into an admin session
 * after the revocation, which is the same hole one step removed.
 *
 * The `two_factor_version` counter is **not** bumped. Its documented meaning is
 * the generation of the account's second factor; a role change does not
 * invalidate the factor, and overloading the counter would make
 * `satisfiesTwoFactorFor()` mean something its name no longer says.
 *
 * ### Why it does not demand a second factor first
 *
 * `docs/security/two-factor.md` §"Promoting a player to admin requires 2FA to
 * be enabled first, or forces enrolment before any admin capability works" is
 * **PROPOSED**, and only its second half is implemented. Refusing to promote an
 * unenrolled player would silently decide the open half. Mandatory admin 2FA is
 * already enforced structurally — an administrator without a confirmed factor
 * is denied on every admin endpoint — so promoting first and enrolling second is
 * a supported state, not a gap. The command says so rather than deciding it.
 */
final class PromoteAdministratorCommand extends Command
{
    protected $signature = 'purrenade:admin:promote
                            {email : The address of an existing, verified account}
                            {--force : Skip the confirmation prompt, for controlled automation}';

    protected $description = 'Grant the administrator role to an existing verified account';

    public function handle(SessionIssuer $sessions, TwoFactorChallengeService $challenges): int
    {
        /*
         * Normalised here because an Artisan argument reaches no FormRequest.
         *
         * Every HTTP entry point lower-cases and trims in `prepareForValidation`
         * and the lookup is then a byte-exact `where('email', ...)`. Without the
         * same treatment, `Ada@Example.com` would report "no such account" for a
         * row that is sitting right there — the worst failure mode an operator
         * tool can have, because it reads as a missing user rather than a
         * mistyped argument.
         */
        $email = Str::lower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            // Naming the address is safe here and useful: this is a local
            // console, not a public endpoint, so it reveals nothing to anyone
            // who could not already read the table.
            $this->error("No account exists for {$email}.");
            $this->line('Register through the application first, then verify the address.');

            return self::FAILURE;
        }

        if ($user->email_verified_at === null) {
            $this->error("The account for {$email} has not verified its email address.");
            $this->line('Verification is a precondition, not a formality: the admin gate refuses');
            $this->line('an unverified administrator anyway, so promoting now would produce an');
            $this->line('account with a role it cannot use. Verify through the application first.');

            return self::FAILURE;
        }

        if ($user->isAdmin()) {
            // Idempotent by design: re-running after a partial deploy, or twice
            // by hand, must not revoke a working administrator's sessions.
            $this->info("{$email} is already an administrator. Nothing to do.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Grant the administrator role to {$email}?")) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        $revoked = DB::transaction(function () use ($user, $sessions, $challenges): int {
            // `forceFill`, because `$guarded = ['*']` blocks mass assignment on
            // this model by design — privilege columns are written by name.
            $user->forceFill(['role' => UserRole::Admin])->save();

            $count = $sessions->revokeAll($user);
            $challenges->purgeForUser($user->id);

            return $count;
        });

        AuthLog::forUser(AuthLog::ADMIN_ROLE_GRANTED, $user, null, [
            'actor' => 'cli',
            'sessions_revoked' => $revoked,
        ]);

        $this->info("{$email} is now an administrator.");
        $this->line("Signed-out devices: {$revoked}.");
        $this->newLine();

        if ($user->requiresTwoFactorEnrolment()) {
            $this->warn('Two-factor authentication is mandatory for administrators, and this');
            $this->warn('account has not enrolled. Every admin endpoint will refuse it until');
            $this->warn('it does. This is expected immediately after a promotion.');
            $this->newLine();
        }

        $this->line('Next, as the account holder and not as the operator:');
        $this->line('  1. Sign in again — the previous sessions were revoked.');
        $this->line('  2. Enrol two-factor authentication under account security.');
        $this->line('  3. Sign in once more, completing the two-factor challenge.');

        return self::SUCCESS;
    }
}
