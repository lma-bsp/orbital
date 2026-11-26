<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Support\AiProviderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentWorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.agent_worker.token', 'worker-token');
    }

    public function test_rejects_a_missing_or_wrong_token(): void
    {
        $this->postJson('/api/agent-worker/heartbeat')->assertUnauthorized();
        $this->withToken('nope')->postJson('/api/agent-worker/heartbeat')->assertUnauthorized();

        $this->assertNull(Cache::get('agent_worker:heartbeat'));
    }

    public function test_an_empty_body_is_still_a_liveness_ping(): void
    {
        $this->withToken('worker-token')->postJson('/api/agent-worker/heartbeat')->assertOk()->assertJson(['ok' => true]);

        $this->assertNotNull(Cache::get('agent_worker:heartbeat'));
        $this->assertSame([], AiProviderReadiness::workerReports());
    }

    public function test_stores_the_latest_key_report_per_worker(): void
    {
        $this->withToken('worker-token')->postJson('/api/agent-worker/heartbeat', [
            'worker' => 'agent-worker-7d9f',
            'keys' => ['anthropic' => true, 'elevenlabs' => false],
        ])->assertOk();

        $this->withToken('worker-token')->postJson('/api/agent-worker/heartbeat', [
            'worker' => 'agent-worker-7d9f',
            'keys' => ['anthropic' => true, 'elevenlabs' => true],
        ])->assertOk();

        $reports = AiProviderReadiness::workerReports();
        $this->assertSame(['agent-worker-7d9f'], array_keys($reports));
        $this->assertSame(['anthropic' => true, 'elevenlabs' => true], $reports['agent-worker-7d9f']['keys']);
        $this->assertTrue(AiProviderReadiness::workerHas('elevenlabs'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'a key value instead of a boolean' => [['keys' => ['anthropic' => 'sk-ant-secret']]],
            'a stringy boolean' => [['keys' => ['anthropic' => 'true']]],
            'an integer boolean' => [['keys' => ['anthropic' => 1]]],
            'an unknown provider' => [['keys' => ['acme' => true]]],
            'keys not an object' => [['keys' => 'anthropic']],
            'a worker id with odd characters' => [['worker' => 'w1; rm -rf', 'keys' => []]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_refuses_anything_but_booleans_for_known_providers(array $payload): void
    {
        $this->withToken('worker-token')
            ->postJson('/api/agent-worker/heartbeat', $payload)
            ->assertUnprocessable();

        $this->assertSame([], AiProviderReadiness::workerReports());
        $this->assertStringNotContainsString('sk-ant-secret', serialize(Cache::get(AiProviderReadiness::WORKER_KEYS_CACHE_KEY)));
    }
}
