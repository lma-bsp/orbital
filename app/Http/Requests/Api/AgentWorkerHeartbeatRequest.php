<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Support\AiProviderReadiness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The agent worker's heartbeat.
 *
 * An empty body is still a valid liveness ping (older images POST
 * nothing). When the worker reports which provider keys its own env
 * holds, the report is booleans only and only for providers Orbital
 * knows: a key value, or a field nobody reads, is refused rather than
 * stored.
 */
class AgentWorkerHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The worker isn't a Sanctum user; it holds a shared secret.
        // 401 rather than FormRequest's default 403, as before.
        $expected = (string) config('services.agent_worker.token');

        abort_if($expected === '' || ! hash_equals($expected, (string) $this->bearerToken()), 401);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'worker' => ['sometimes', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'keys' => ['sometimes', 'array:'.implode(',', array_keys(AiProviderReadiness::PROVIDERS))],
            'keys.*' => ['boolean:strict'],
        ];
    }
}
