<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ProblemCode;
use RuntimeException;

/**
 * The one way application code signals an error response.
 *
 * Controllers throw this; a single handler turns it into RFC 9457. Nothing in
 * the application builds an error response by hand, which is what keeps the
 * contract from drifting endpoint by endpoint — the failure mode that makes a
 * client write per-endpoint parsing.
 */
final class ApiProblem extends RuntimeException
{
    /**
     * `$problemCode`, not `$code`: `Exception` already declares a mutable int
     * `$code`, and PHP refuses to redeclare it as a readonly property of another
     * type. That refusal is fatal and lands only when the class is first
     * autoloaded — which is the moment something first throws. So the collision
     * would have broken every error path in the application while every success
     * path kept working.
     *
     * @param  array<string, mixed>  $extensions  Additional RFC 9457 members, e.g. `retry_after`.
     * @param  array<string, list<array{code: string, message: string}>>  $errors  Field-level errors.
     */
    public function __construct(
        public readonly ProblemCode $problemCode,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
        public readonly array $errors = [],
    ) {
        parent::__construct($detail ?? $problemCode->title());
    }

    public static function of(ProblemCode $code, ?string $detail = null): self
    {
        return new self($code, $detail);
    }

    /**
     * @param  array<string, mixed>  $extensions
     */
    public static function with(ProblemCode $code, array $extensions, ?string $detail = null): self
    {
        return new self($code, $detail, $extensions);
    }

    /**
     * A rate-limit style problem carrying the wait in whole seconds.
     *
     * `retry_after` is echoed as an extension member **and** as a `Retry-After`
     * header: the header is what a proxy or an HTTP client library honours, and
     * the member is what the UI needs to render a countdown without parsing
     * headers it may not be able to read.
     */
    public static function retryAfter(ProblemCode $code, int $seconds, ?string $detail = null): self
    {
        return new self($code, $detail, ['retry_after' => max(0, $seconds)]);
    }

    public function status(): int
    {
        return $this->problemCode->status();
    }
}
