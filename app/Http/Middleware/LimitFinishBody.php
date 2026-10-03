<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ProblemCode;
use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The finish route's authoritative body limit: `replay.max_body_bytes`,
 * 256 KiB (owner decision, 2026-10-03). Above it, 413 `payload_too_large`.
 *
 * ## Why 256 KiB
 *
 * The largest conforming body is about 220.2 KB: a compact `replay_input` at
 * the contract caps is at most 220 073 bytes (20 000 events of
 * `[gap≤6 digits,code],` plus a 74-byte wrapper), and the telemetry object adds
 * about 110. 256 KiB leaves ~41.9 KB of headroom. The cap on the log is the
 * client's to respect — it omits the log rather than exceed it — so a
 * conforming finish never meets this limit.
 *
 * ## Why the application, not only the proxy
 *
 * nginx's own ceiling is set **above** this one (288k), so every body in
 * (256 KiB, 288k] reaches Laravel and gets the canonical problem response; only
 * materially larger bodies are cut off by nginx before PHP. Both measure the
 * same thing — entity body bytes.
 *
 * Both the declared `Content-Length` and the received body are checked, so a
 * missing or understated length cannot slip past.
 */
final class LimitFinishBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('replay.max_body_bytes');
        $declared = $request->headers->get('Content-Length');

        if (($declared !== null && ctype_digit($declared) && (int) $declared > $limit)
            || strlen((string) $request->getContent()) > $limit
        ) {
            throw ApiProblem::of(
                ProblemCode::PayloadTooLarge,
                'The request body is larger than this endpoint accepts.',
            );
        }

        return $next($request);
    }
}
