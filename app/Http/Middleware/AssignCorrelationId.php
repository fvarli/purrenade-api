<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give every request a correlation id, and put it everywhere it is needed.
 *
 * Three destinations, one value:
 *
 *   1. `Context`, so every structured log line in the request carries it
 *      without any call site having to remember;
 *   2. the request headers, so the value the problem renderer echoes is the
 *      same one that was logged;
 *   3. the response header, so the caller can quote it.
 *
 * Runs first in the API group. A correlation id assigned after something has
 * already failed is of no use to anybody.
 */
final class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = CorrelationId::resolve($request->header(CorrelationId::HEADER));

        // Normalise, so downstream code never has to decide whether to trust the
        // inbound header or the resolved value: after this line they are equal.
        $request->headers->set(CorrelationId::HEADER, $correlationId);

        Context::add('correlation_id', $correlationId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(CorrelationId::HEADER, $correlationId);

        return $response;
    }
}
