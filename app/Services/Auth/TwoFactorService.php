<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use App\Models\PersonalAccessToken;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use App\Support\AuthLog;
use App\Support\KeyedHash;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Exceptions\Google2FAException;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment, verification and recovery codes.
 *
 * Built on Fortify's primitives — its `TwoFactorAuthenticationProvider` for
 * secret generation and the `otpauth://` URI, its `RecoveryCode` generator, and
 * `pragmarx/google2fa` for the TOTP arithmetic. None of that is reimplemented
 * here: hand-rolling TOTP or a recovery-code format is exactly the "unnecessary
 * custom crypto" worth avoiding.
 *
 * Two places deliberately depart from Fortify:
 *
 *  - **Replay prevention is per user, not per code.** Fortify caches a
 *    `md5(code)` key globally, so two accounts whose authenticators emit the
 *    same six digits in the same window interfere with each other, and a cache
 *    flush forgets every used code. Here the highest accepted time step lives on
 *    the user row, which is per-account, durable, and atomically comparable.
 *
 *  - **Recovery codes live in their own table**, hashed, one row per code. See
 *    the migration for why an encrypted JSON array does not satisfy the APPROVED
 *    rules in docs/security/two-factor.md §4.
 */
final readonly class TwoFactorService
{
    public function __construct(
        private TwoFactorAuthenticationProvider $provider,
        private Google2FA $google2fa,
    ) {}

    // ---------------------------------------------------------------------
    // Enrolment
    // ---------------------------------------------------------------------

    /**
     * Begin enrolment: generate a secret and the data needed to display it.
     *
     * 2FA is **not** active after this call. The secret is stored but
     * `two_factor_confirmed_at` stays null until `confirm()` proves the player
     * can actually produce a code — because enabling 2FA against a secret the
     * player never successfully scanned locks them out of their own account,
     * and that is a self-inflicted outage with no recovery path short of
     * support.
     *
     * Calling it again before confirming replaces the secret. A player who
     * abandoned a half-finished enrolment and started over should not be
     * verified against the abandoned secret.
     *
     * @return array{secret: string, otpauth_uri: string, qr_code: string}
     */
    public function beginEnrolment(User $user): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw ApiProblem::of(
                ProblemCode::TwoFactorAlreadyEnabled,
                'Two-factor authentication is already enabled for this account.'
            );
        }

        $secret = $this->provider->generateSecretKey();

        DB::transaction(function () use ($user, $secret): void {
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_timestep' => null,
            ])->save();

            // Any codes from a previous enrolment are meaningless against a new
            // secret, and leaving them would let an old printout bypass the new
            // second factor.
            $user->twoFactorRecoveryCodes()->delete();

            $this->rotateCredentialGeneration($user);
        });

        $uri = $this->provider->qrCodeUrl(
            config()->string('service.name'),
            $user->email,
            $secret,
        );

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_code' => $this->qrCodeDataUri($uri),
        ];
    }

    /**
     * Finish enrolment by proving possession, and issue recovery codes.
     *
     * @return list<string> The plaintext codes — shown exactly once, never retrievable again.
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw ApiProblem::of(
                ProblemCode::TwoFactorAlreadyEnabled,
                'Two-factor authentication is already enabled for this account.'
            );
        }

        if (! $user->hasTwoFactorPending()) {
            throw ApiProblem::of(
                ProblemCode::TwoFactorNotPending,
                'Start two-factor enrolment before confirming it.'
            );
        }

        if (! $this->verifyTotp($user, $code)) {
            throw ApiProblem::of(
                ProblemCode::TwoFactorCodeInvalid,
                'That code is not correct. Check your authenticator app and try again.'
            );
        }

        $user->forceFill(['two_factor_confirmed_at' => Carbon::now()])->save();

        return $this->replaceRecoveryCodes($user);
    }

    /**
     * Turn two-factor authentication off.
     *
     * Refused for administrators: 2FA is mandatory for that role
     * (docs/security/two-factor.md §5), so an admin cannot opt out of it. The
     * check lives here as well as in the controller, so a future caller cannot
     * reach the destructive path without passing it.
     */
    public function disable(User $user): void
    {
        if ($user->role->requiresTwoFactor()) {
            throw ApiProblem::of(
                ProblemCode::AdminTwoFactorMandatory,
                'Two-factor authentication cannot be disabled on an administrator account.'
            );
        }

        if (! $user->hasTwoFactorEnabled() && ! $user->hasTwoFactorPending()) {
            throw ApiProblem::of(
                ProblemCode::TwoFactorNotEnabled,
                'Two-factor authentication is not enabled for this account.'
            );
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_timestep' => null,
            ])->save();

            $user->twoFactorRecoveryCodes()->delete();

            $this->rotateCredentialGeneration($user);
        });
    }

    // ---------------------------------------------------------------------
    // Recovery codes
    // ---------------------------------------------------------------------

    /**
     * Issue a fresh set, invalidating the previous one.
     *
     * Regeneration always discards the old set. A player regenerating codes is
     * saying "the old list is compromised or lost"; leaving it valid would
     * answer a security action by doubling the number of live bypasses.
     *
     * @return list<string>
     */
    public function replaceRecoveryCodes(User $user): array
    {
        $codes = [];

        for ($i = 0; $i < TwoFactorRecoveryCode::COUNT; $i++) {
            $codes[] = RecoveryCode::generate();
        }

        DB::transaction(function () use ($user, $codes): void {
            // Take the user row first, and hold it for the transaction. Without
            // it two concurrent regenerations each delete the set the other has
            // not yet inserted and then insert their own, leaving sixteen live
            // codes — the exact opposite of what regenerating is for. The
            // uniqueness index cannot help here: the two sets do not collide.
            $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();

            $user->twoFactorRecoveryCodes()->delete();

            $now = Carbon::now();

            $user->twoFactorRecoveryCodes()->insert(array_map(
                fn (string $code): array => [
                    'user_id' => $user->id,
                    'code_hash' => KeyedHash::make($code),
                    'used_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $codes,
            ));

            $this->rotateCredentialGeneration($user);
        });

        return $codes;
    }

    /**
     * Advance the second factor's generation and evict every other session.
     *
     * Called from each of the four changes that alter what the second factor
     * *is*: beginning enrolment, confirming it, disabling it, and replacing the
     * recovery codes. Each one invalidates what an existing session proved.
     *
     * Two effects, and both are needed.
     *
     * The eviction is the visible one: someone who rotates a second factor is
     * usually responding to a lost or stolen authenticator, and leaving the
     * other sessions signed in answers that by doing nothing. This matches what
     * an authenticated password change already does — a credential-affecting
     * action ends every session except the one performing it.
     *
     * The counter is the one that cannot be skipped. The caller's own session
     * survives by design, and a Sanctum ability cannot be withdrawn from an
     * issued token, so without a generation the surviving token would keep
     * asserting a challenge it passed against a secret that no longer exists.
     * Disable-then-re-enable would hand it back its privileges.
     *
     * Must be called inside a transaction: the increment and the eviction are
     * one change to the account's security state.
     */
    private function rotateCredentialGeneration(User $user): void
    {
        $user->increment('two_factor_version');

        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $user->tokens()
            ->when($currentId !== null, fn ($query) => $query->whereKeyNot($currentId))
            ->delete();
    }

    /**
     * Spend one recovery code.
     *
     * The whole operation is a single conditional `UPDATE` guarded by
     * `used_at IS NULL`, and the affected-row count is the authority. Reading
     * the row and then updating it would leave a window in which two concurrent
     * requests both see an unused code and both succeed — for a credential whose
     * entire contract is "single use".
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $affected = TwoFactorRecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', KeyedHash::make($code))
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);

        return $affected === 1;
    }

    public function unusedRecoveryCodeCount(User $user): int
    {
        return $user->twoFactorRecoveryCodes()->whereNull('used_at')->count();
    }

    // ---------------------------------------------------------------------
    // Verification
    // ---------------------------------------------------------------------

    /**
     * Verify a TOTP code, rejecting replays.
     *
     * `verifyKeyNewer` returns the matched time step and refuses anything at or
     * below the last one accepted, so a code intercepted inside its 30-second
     * window cannot be used a second time. The accepted step is then persisted,
     * which is what makes the guarantee outlive a cache flush or a restart.
     *
     * A window of one step either side tolerates real clock drift between the
     * player's phone and the server without widening the window meaningfully.
     *
     * The persistence is a **conditional** update, and its affected-row count is
     * the authority, exactly as for recovery codes. `verifyKeyNewer` compares in
     * PHP against a value read into the model earlier in the request, so reading
     * and then writing unconditionally would leave two concurrent submissions of
     * one intercepted code both comparing against the same old step, both
     * passing, and both writing the same new one — the replay guard failing
     * under precisely the condition it exists for. The database decides instead.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret === null) {
            return false;
        }

        // Digits only, and exactly six. Google2FA would otherwise spend the
        // comparison on input that cannot possibly be a code.
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        try {
            $timestep = $this->google2fa->verifyKeyNewer(
                $secret,
                $code,
                $user->two_factor_last_used_timestep,
                self::WINDOW,
            );
        } catch (Google2FAException $e) {
            // A stored secret that is not decodable base32 — a truncated column,
            // a restore from a dump taken under a different APP_KEY, a bad
            // manual edit. Google2FA throws, and the throw was reaching the
            // caller as a 500 on the login path: an account that cannot be
            // signed into *and* looks like a server fault, which is the least
            // useful pair of facts to give either the player or the operator.
            //
            // Refuse the code and record it. The account is broken and needs
            // support, but that is a support problem, not a stack trace.
            AuthLog::forUser(AuthLog::TWO_FACTOR_SECRET_UNREADABLE, $user, null, [
                'reason' => class_basename($e),
            ]);

            return false;
        }

        if ($timestep === false) {
            return false;
        }

        // `verifyKeyNewer` returns `true` rather than a step when no previous
        // step was recorded, so resolve it to the current one.
        $accepted = $timestep === true
            ? (int) $this->google2fa->getTimestamp()
            : (int) $timestep;

        $advanced = $user->newQuery()
            ->whereKey($user->getKey())
            ->where(fn ($query) => $query
                ->whereNull('two_factor_last_used_timestep')
                ->orWhere('two_factor_last_used_timestep', '<', $accepted))
            ->update(['two_factor_last_used_timestep' => $accepted]);

        if ($advanced !== 1) {
            // Another request already claimed this step or a later one. That is
            // a replay, whoever sent it.
            return false;
        }

        // Keep the in-memory model in step with the row this request just wrote,
        // so a second verification later in the same request sees it.
        $user->setAttribute('two_factor_last_used_timestep', $accepted)
            ->syncOriginalAttribute('two_factor_last_used_timestep');

        return true;
    }

    /** Steps of tolerance either side of the current one. */
    private const WINDOW = 1;

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    /**
     * The provisioning QR code as a `data:` URI.
     *
     * A data URI for an `<img>`, not raw SVG markup. The frontend would have to
     * render raw markup with `v-html`, which is an XSS sink kept alive purely for
     * convenience; an `<img src="data:image/svg+xml;base64,…">` cannot execute
     * script in any browser. Rendering server-side also avoids shipping a QR
     * library to every client for one screen.
     */
    private function qrCodeDataUri(string $uri): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(
                    size: 220,
                    margin: 1,
                    fill: Fill::uniformColor(
                        // Brand tokens: cream ground, ink modules.
                        // docs/architecture/design-tokens.md §1.1.
                        new Rgb(255, 246, 233),
                        new Rgb(51, 39, 42),
                    ),
                ),
                new SvgImageBackEnd,
            )
        ))->writeString($uri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
