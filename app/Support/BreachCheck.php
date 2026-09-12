<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\NotPwnedVerifier;
use Throwable;

/**
 * The compromised-password check, with an explicit failure policy.
 *
 * Extends Laravel's `NotPwnedVerifier` and overrides only `search()`, its single
 * network seam. The k-anonymity mechanism is the framework's and is deliberately
 * not reimplemented: the password never leaves the process and neither does its
 * full hash — only the first five characters of its SHA-1 are sent, and the
 * returned suffixes are matched locally. Five hex characters cover roughly one in
 * a million passwords, which is not an identification. `Add-Padding` is sent so
 * response size does not leak the bucket either.
 *
 * Two things the framework leaves implicit, and this makes explicit.
 *
 * **Fail open, deliberately.** A password confirmed breached is rejected; a
 * provider that cannot be reached is not permitted to reject anything. Breach
 * checking is defence in depth — the boundaries are a 12-character minimum,
 * argon2id, two-dimensional throttling and the second factor — and failing
 * closed would hand a third party the power to take registration, password reset
 * and password change offline together. An administrator unable to recover an
 * account mid-incident because someone else's service is down is the worse
 * outcome.
 *
 * **But never silently.** The parent catches its own transport errors, reports
 * to the default channel and returns an empty result set, which reads
 * identically to "this password appears in no breach". That makes an outage
 * indistinguishable from a clean answer, and a control that stops running
 * without saying so has stopped being a control. Every degraded check is
 * recorded on the security channel, with no password material of any kind —
 * not the value, not the hash, not the prefix.
 *
 * **Bounded.** The parent's default timeout is 30 seconds, in-band, on three
 * endpoints reachable without authentication: a denial-of-service amplifier
 * aimed at our own workers through a service we do not control. The timeout is
 * configuration (`auth.breach_check.timeout`) and defaults to 3 seconds. There
 * is no retry — a retry in the request path multiplies the same problem.
 */
final class BreachCheck extends NotPwnedVerifier
{
    /**
     * @param  string  $hashPrefix  The first five characters of the SHA-1, and
     *                              the only thing about the password that leaves
     *                              this process.
     * @return Collection<int, non-falsy-string> the response lines that carry a
     *                                           suffix and a count
     */
    protected function search($hashPrefix)
    {
        try {
            $response = $this->factory
                ->withHeaders(['Add-Padding' => true])
                ->timeout($this->timeout)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);

            if (! $response->successful()) {
                return $this->degraded('http_'.$response->status());
            }

            $body = $response->body();
        } catch (Throwable $e) {
            return $this->degraded(class_basename($e));
        }

        // Split the body as received. Trimming first would narrow the element
        // type to a non-empty string, which the empty collection `degraded()`
        // returns could not satisfy — and `Collection`'s value template is
        // invariant, so the two branches have to agree exactly.
        $lines = preg_split('/\R/', $body);

        return (new Collection($lines === false ? [] : $lines))
            ->reject(fn (string $line): bool => ! str_contains($line, ':'))
            ->values();
    }

    /**
     * The provider could not answer. Record it, and return nothing — which the
     * parent reads as "no match", i.e. accept.
     *
     * @return Collection<int, non-falsy-string>
     */
    private function degraded(string $reason): Collection
    {
        Log::channel('security')->warning(self::EVENT, [
            'event' => self::EVENT,
            'reason' => $reason,
            'policy' => 'fail_open',
            'detail' => 'The compromised-password provider could not be reached. The password was accepted without a breach check.',
        ]);

        return new Collection;
    }

    /** The event name a log search or an alert rule keys on. */
    public const EVENT = 'breach_check_unavailable';
}
