<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * Refuse an unauthenticated request. Never redirect.
 *
 * The framework's middleware computes a redirect target when the request does
 * not ask for JSON — `Illuminate\Auth\Middleware\Authenticate::redirectTo()`
 * calls `route('login')` unconditionally in that case. There is no `login`
 * route in an API-only application, so the call throws
 * `RouteNotFoundException` *from inside the middleware*, before the exception
 * handler can map it. The caller receives **500 instead of 401**.
 *
 * That is not a cosmetic difference. It means the error contract was not
 * universal after all: every client that omits `Accept: application/json` — a
 * native client, `curl`, a monitoring probe — got a server error where an
 * authentication failure belonged, and would reasonably retry or page somebody.
 *
 * It is also easy to miss in tests, because Laravel's `getJson()` helper always
 * sets the header. `ErrorContractTest` therefore exercises the other values
 * explicitly.
 *
 * Throwing `ApiProblem` rather than returning null from `redirectTo()` makes the
 * intent explicit and routes the refusal through the one renderer, so the shape
 * matches every other error in the API.
 */
final class Authenticate extends Middleware
{
    /**
     * @param  array<int, string|null>  $guards
     */
    protected function unauthenticated($request, array $guards): never
    {
        throw ApiProblem::of(
            ProblemCode::Unauthenticated,
            'This endpoint requires an authenticated session.'
        );
    }

    /**
     * There is no login page to redirect to, at any Accept header.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
