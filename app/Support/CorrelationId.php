<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The request correlation identifier.
 *
 * One value follows a request from the browser, through the BFF, into this
 * service, into its logs, and back out in the response — so a player quoting an
 * id from an error screen gives support a single key to search on.
 *
 * The header is `X-Correlation-Id`.
 *
 * **An inbound value is accepted only if it matches a strict shape.** That is
 * not cosmetic: this value is echoed in a response header and written into log
 * lines, so accepting arbitrary bytes would allow header injection through CR/LF
 * and log forging through newlines. The allowed alphabet contains neither.
 */
final class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    /** Wide enough for a UUID or a ULID, narrow enough to keep logs readable. */
    private const SHAPE = '/^[A-Za-z0-9_-]{8,64}$/';

    public static function generate(): string
    {
        // A ULID rather than a UUID: same uniqueness guarantee, but
        // lexicographically sortable by creation time, which makes a log
        // ordered by correlation id also ordered by when requests arrived.
        return (string) Str::ulid();
    }

    public static function isAcceptable(?string $value): bool
    {
        return $value !== null && preg_match(self::SHAPE, $value) === 1;
    }

    /**
     * Take the caller's value if it is well-formed, otherwise mint one.
     */
    public static function resolve(?string $inbound): string
    {
        return self::isAcceptable($inbound) ? (string) $inbound : self::generate();
    }
}
