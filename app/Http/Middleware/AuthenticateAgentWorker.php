<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AgentWorkerToken;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agent worker authenticates with the shared secret in
 * `services.agent_worker.token` (the chart's agentWorker.token), not a
 * Sanctum token. Accept that secret; otherwise fall back to Sanctum so
 * personal access tokens keep working. Only the worker secret marks the
 * request as `agent_worker`, which is what releases provider keys.
 * The old public dev token is refused outside local/testing
 * ({@see AgentWorkerToken}).
 */
class AuthenticateAgentWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AgentWorkerToken::matches($request->bearerToken())) {
            $request->attributes->set('agent_worker', true);

            return $next($request);
        }

        return app(Authenticate::class)->handle($request, $next, 'sanctum');
    }
}
