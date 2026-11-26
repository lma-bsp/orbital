<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agent worker authenticates with the shared secret in
 * `services.agent_worker.token` (the chart's agentWorker.token). Nothing
 * else gets in: the controllers behind this middleware are not
 * team-scoped (the worker serves every client), so a Sanctum token or a
 * session would hand one client's user every client's personas,
 * extensions and call logs. A missing or empty secret refuses everyone.
 */
class AuthenticateAgentWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.agent_worker.token');

        if ($expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            abort(401);
        }

        $request->attributes->set('agent_worker', true);

        return $next($request);
    }
}
