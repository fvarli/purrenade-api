<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders every error as RFC 9457 Problem Details.
 *
 * One renderer, no exceptions. A client that can parse this response can parse
 * every error this API produces, which is the entire reason for choosing a
 * standard shape over a bespoke envelope (API-1).
 *
 * ```json
 * {
 *   "type": "urn:purrenade:error:validation_failed",
 *   "title": "Validation failed",
 *   "status": 422,
 *   "detail": "The given data was invalid.",
 *   "instance": "/api/v1/auth/register",
 *   "code": "validation_failed",
 *   "correlation_id": "01K5...",
 *   "errors": { "email": [{ "code": "taken", "message": "..." }] }
 * }
 * ```
 *
 * `code` duplicates the tail of `type` on purpose. RFC 9457 makes `type` the
 * identifier, and `docs/api/api-conventions.md` §3 makes a stable `code` the
 * value clients branch on; carrying both satisfies the standard and keeps the
 * ergonomic field the frontend actually uses. One is derived from the other, so
 * they cannot disagree.
 *
 * **Never present:** stack traces, SQL, class names, file paths, framework
 * versions. That holds with `APP_DEBUG=true` as well — a developer gets the
 * detail from the log, and a response shape that changes between environments is
 * a response shape nobody can test.
 */
final class ProblemResponse
{
    public const CONTENT_TYPE = 'application/problem+json';

    public static function fromProblem(ApiProblem $problem, Request $request): JsonResponse
    {
        return self::build(
            code: $problem->problemCode,
            detail: $problem->detail,
            request: $request,
            errors: $problem->errors,
            extensions: $problem->extensions,
        );
    }

    /**
     * Map any throwable onto the contract.
     *
     * The default arm is the important one: anything unrecognised becomes a bare
     * 500 with no detail borrowed from the exception message. Exception messages
     * routinely contain connection strings, file paths and SQL.
     */
    public static function fromThrowable(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiProblem => self::fromProblem($e, $request),

            $e instanceof ValidationException => self::build(
                code: ProblemCode::ValidationFailed,
                detail: 'The given data was invalid.',
                request: $request,
                errors: ValidationCodes::build(
                    $e->validator->failed(),
                    $e->validator->errors()->messages(),
                ),
            ),

            $e instanceof AuthenticationException => self::build(
                code: ProblemCode::Unauthenticated,
                detail: 'This endpoint requires an authenticated session.',
                request: $request,
            ),

            $e instanceof AuthorizationException => self::build(
                code: ProblemCode::Forbidden,
                detail: 'You are not permitted to perform this action.',
                request: $request,
            ),

            // A missing model must not disclose which model or which id.
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::build(
                code: ProblemCode::NotFound,
                detail: 'The requested resource does not exist.',
                request: $request,
            ),

            $e instanceof MethodNotAllowedHttpException => self::build(
                code: ProblemCode::MethodNotAllowed,
                detail: 'That method is not supported by this endpoint.',
                request: $request,
            ),

            $e instanceof TooManyRequestsHttpException => self::build(
                code: ProblemCode::RateLimited,
                detail: 'Too many requests. Please wait before trying again.',
                request: $request,
                extensions: self::retryAfterFrom($e),
            ),

            // Any other HTTP exception keeps its own status rather than being
            // flattened to 500. Without this arm a 503 from maintenance mode or
            // a 415 from content negotiation would be reported as a server
            // fault, and a client would retry something it should not.
            $e instanceof HttpExceptionInterface => self::build(
                code: self::codeForStatus($e->getStatusCode()),
                detail: 'The request could not be completed.',
                request: $request,
                extensions: self::retryAfterFrom($e),
            ),

            default => self::build(
                code: ProblemCode::ServerError,
                detail: 'An unexpected error occurred. Quote the correlation id when reporting it.',
                request: $request,
            ),
        };
    }

    /**
     * @param  array<string, list<array{code: string, message: string}>>  $errors
     * @param  array<string, mixed>  $extensions
     */
    public static function build(
        ProblemCode $code,
        ?string $detail,
        Request $request,
        array $errors = [],
        array $extensions = [],
    ): JsonResponse {
        $payload = [
            'type' => $code->type(),
            'title' => $code->title(),
            'status' => $code->status(),
            'detail' => $detail ?? $code->title(),
            'instance' => '/'.ltrim($request->path(), '/'),
            'code' => $code->value,
            'correlation_id' => CorrelationId::resolve($request->header(CorrelationId::HEADER)),
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        $payload = [...$payload, ...$extensions];

        $response = new JsonResponse($payload, $code->status(), [
            'Content-Type' => self::CONTENT_TYPE,
        ]);

        // Retry-After as a real header too: that is what HTTP clients and
        // proxies honour, and honouring it is the behaviour we want from them.
        if (isset($extensions['retry_after']) && is_int($extensions['retry_after'])) {
            $response->headers->set('Retry-After', (string) $extensions['retry_after']);
        }

        return $response;
    }

    /**
     * Map a bare HTTP status onto the closest stable code.
     *
     * Deliberately conservative: anything unrecognised becomes a server error
     * rather than being given a code that suggests a cause nobody verified.
     */
    private static function codeForStatus(int $status): ProblemCode
    {
        return match ($status) {
            400 => ProblemCode::ValidationFailed,
            401 => ProblemCode::Unauthenticated,
            403 => ProblemCode::Forbidden,
            404 => ProblemCode::NotFound,
            405 => ProblemCode::MethodNotAllowed,
            409 => ProblemCode::Conflict,
            422 => ProblemCode::ValidationFailed,
            429 => ProblemCode::RateLimited,
            503 => ProblemCode::ServiceUnavailable,
            default => ProblemCode::ServerError,
        };
    }

    /**
     * @return array<string, int>
     */
    private static function retryAfterFrom(HttpExceptionInterface $e): array
    {
        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

        return is_numeric($retryAfter) ? ['retry_after' => (int) $retryAfter] : [];
    }
}
