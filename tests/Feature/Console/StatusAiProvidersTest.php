<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AgentPersona;
use App\Models\EmailRoutingRule;
use App\Models\Team;
use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\LiveKitConfigService;
use App\Support\AiProviderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusAiProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(AiProviderReadiness::PROVIDERS) as $provider) {
            config()->set("services.{$provider}.api_key", '');
        }
        config()->set('telephony.recording.disclosure_message', '');

        $this->mock(AsteriskAmiService::class)->shouldReceive('getActiveChannels')->andReturn([]);
        $this->mock(LiveKitConfigService::class)->shouldReceive('healthCheck')->andReturn(true);
    }

    public function test_no_ai_configured_is_informational_and_strict_passes(): void
    {
        $this->artisan('orbital:status', ['--strict' => true])
            ->expectsOutputToContain('AI providers: no AI configured')
            ->assertExitCode(0);
    }

    public function test_the_ai_block_lists_missing_providers(): void
    {
        $this->routeEmailToAi();

        $this->artisan('orbital:status')
            ->expectsOutputToContain('! Anthropic (DOWN, missing): needed for email replies')
            ->assertExitCode(0);
    }

    public function test_strict_exits_one_when_a_key_is_missing(): void
    {
        $this->routeEmailToAi();

        $this->artisan('orbital:status', ['--strict' => true])->assertExitCode(1);
    }

    public function test_strict_exits_zero_once_the_key_is_set(): void
    {
        $this->routeEmailToAi();
        config()->set('services.anthropic.api_key', 'sk-ant-test');

        $this->artisan('orbital:status', ['--strict' => true])
            ->expectsOutputToContain('AI providers: every needed key is set (Anthropic)')
            ->assertExitCode(0);
    }

    private function routeEmailToAi(): void
    {
        $team = Team::factory()->create(['personal_team' => false]);
        $persona = AgentPersona::create([
            'team_id' => $team->id,
            'name' => 'Ava',
            'role' => 'receptionist',
            'is_active' => false,
        ]);

        EmailRoutingRule::create([
            'team_id' => $team->id,
            'name' => 'AI',
            'match_type' => EmailRoutingRule::MATCH_DEFAULT,
            'destination_type' => EmailRoutingRule::DESTINATION_AGENT_PERSONA,
            'destination_id' => $persona->id,
            'is_active' => true,
        ]);
    }
}
