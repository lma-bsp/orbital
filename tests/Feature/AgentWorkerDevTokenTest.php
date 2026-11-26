<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgentPersona;
use App\Models\Extension;
use App\Models\Team;
use App\Models\User;
use App\Support\AgentWorkerToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Helm chart used to default agentWorker.token to a public string.
 * With it, anyone who reached the ingress could pull the AI provider keys
 * and every tenant's prompts. Outside local/testing that value must be
 * treated as no token at all, even when it is what's configured.
 */
class AgentWorkerDevTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.agent_worker.token' => AgentWorkerToken::DEV_TOKEN,
            'services.anthropic.api_key' => 'sk-ant-test',
        ]);

        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $persona = AgentPersona::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Ava', 'role' => 'receptionist']);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $team->id, 'number' => '1001', 'type' => 'ai_agent',
            'assignable_type' => AgentPersona::class, 'assignable_id' => $persona->id, 'is_active' => true,
        ]);
    }

    private function inEnvironment(string $env): void
    {
        $this->app['env'] = $env;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deployedEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    #[DataProvider('deployedEnvironments')]
    public function test_the_middleware_rejects_the_dev_token_when_deployed(string $env): void
    {
        $this->inEnvironment($env);

        $this->getJson('/api/agent-personas/by-extension/1001', ['Authorization' => 'Bearer '.AgentWorkerToken::DEV_TOKEN])
            ->assertUnauthorized()
            ->assertJsonMissingPath('provider_keys');
    }

    public function test_the_heartbeat_rejects_the_dev_token_when_deployed(): void
    {
        $this->inEnvironment('production');

        $this->postJson('/api/agent-worker/heartbeat', [], ['Authorization' => 'Bearer '.AgentWorkerToken::DEV_TOKEN])
            ->assertUnauthorized();
    }

    public function test_the_dev_token_still_works_in_local(): void
    {
        $this->inEnvironment('local');

        $this->getJson('/api/agent-personas/by-extension/1001', ['Authorization' => 'Bearer '.AgentWorkerToken::DEV_TOKEN])
            ->assertOk()
            ->assertJsonPath('provider_keys.anthropic', 'sk-ant-test');
    }

    public function test_a_real_token_works_when_deployed(): void
    {
        $this->inEnvironment('production');
        config(['services.agent_worker.token' => 'a-real-random-token']);

        $this->getJson('/api/agent-personas/by-extension/1001', ['Authorization' => 'Bearer a-real-random-token'])
            ->assertOk()
            ->assertJsonPath('provider_keys.anthropic', 'sk-ant-test');
    }

    public function test_an_empty_token_matches_nothing(): void
    {
        config(['services.agent_worker.token' => '']);

        $this->assertFalse(AgentWorkerToken::matches(''));
        $this->assertFalse(AgentWorkerToken::matches(null));
    }
}
