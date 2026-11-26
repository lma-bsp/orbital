<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AgentWorkerHeartbeatRequest;
use App\Support\AiProviderReadiness;
use Illuminate\Support\Facades\Cache;

/**
 * Agent worker liveness heartbeat, plus which provider keys the
 * worker's own env holds (AiProviderReadiness counts those, since the
 * worker falls back to its env for any key Laravel doesn't send).
 */
class AgentWorkerHeartbeatController extends Controller
{
    /**
     * @return array{ok: bool}
     */
    public function __invoke(AgentWorkerHeartbeatRequest $request): array
    {
        Cache::put('agent_worker:heartbeat', now(), now()->addSeconds(120));

        $keys = $request->validated('keys');

        if (is_array($keys)) {
            $worker = (string) ($request->validated('worker') ?: $request->ip() ?: 'unknown');
            AiProviderReadiness::recordWorkerKeys($worker, $keys);
        }

        return ['ok' => true];
    }
}
