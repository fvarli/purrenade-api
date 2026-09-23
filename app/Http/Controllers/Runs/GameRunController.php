<?php

declare(strict_types=1);

namespace App\Http\Controllers\Runs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Runs\FinishRunRequest;
use App\Http\Requests\Runs\StartRunRequest;
use App\Http\Resources\RunResultResource;
use App\Http\Resources\StartedRunResource;
use App\Models\User;
use App\Services\Runs\RunLifecycleService;
use App\Support\CorrelationId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The run lifecycle endpoints. Thin: shape validation in the form requests,
 * every decision in `RunLifecycleService`.
 *
 * The actor is always the bearer of the token. Nothing in either body names a
 * user, and a run id that belongs to someone else is answered exactly like one
 * that does not exist.
 */
final class GameRunController extends Controller
{
    public function __construct(
        private readonly RunLifecycleService $runs,
    ) {}

    /** POST /game-runs — 201 for a new run, 200 when the active run is resumed. */
    public function start(StartRunRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $started = $this->runs->start($user, $request->characterKey());

        return StartedRunResource::make($started)
            ->response()
            ->setStatusCode($started->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** POST /game-runs/{runId}/finish — 200 for every classified outcome. */
    public function finish(FinishRunRequest $request, string $runId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $outcome = $this->runs->finish($user, $runId, $request->telemetry(), $request->idempotencyKey());

        // After commit, and only identifiers and codes: no email, no IP, no
        // telemetry values (data-protection §5).
        Log::info($outcome->replayed ? 'run.finish.replayed' : 'run.finished', [
            'run_id' => $outcome->result['run_id'] ?? null,
            'status' => $outcome->result['status'] ?? null,
            'reasons' => $outcome->result['reasons'] ?? [],
            'correlation_id' => CorrelationId::resolve($request->header(CorrelationId::HEADER)),
        ]);

        return (new RunResultResource($outcome->result))->response();
    }
}
