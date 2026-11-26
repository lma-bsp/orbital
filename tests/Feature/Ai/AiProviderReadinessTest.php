<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentPersona;
use App\Models\EmailRoutingRule;
use App\Models\KnowledgeStore;
use App\Models\MessageQueue;
use App\Models\Team;
use App\Support\AiProviderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(AiProviderReadiness::PROVIDERS) as $provider) {
            config()->set("services.{$provider}.api_key", '');
        }
        config()->set('messaging.auto_reply_enabled', false);
        config()->set('telephony.recording.disclosure_message', '');
    }

    public function test_nothing_is_required_with_no_ai_configured(): void
    {
        $this->assertSame([], AiProviderReadiness::requirements());

        foreach (AiProviderReadiness::report() as $row) {
            $this->assertSame(AiProviderReadiness::NOT_NEEDED, $row['status']);
        }
    }

    public function test_a_default_provider_persona_needs_anthropic_and_elevenlabs(): void
    {
        $this->persona();

        $requirements = AiProviderReadiness::requirements();

        $this->assertArrayHasKey('anthropic', $requirements);
        $this->assertArrayHasKey('elevenlabs', $requirements);
        // The AI fallback prompt renders with OpenAI by default.
        $this->assertArrayHasKey('openai', $requirements);
        $this->assertArrayNotHasKey('deepgram', $requirements);

        $report = AiProviderReadiness::report();
        $this->assertSame(AiProviderReadiness::MISSING, $report['anthropic']['status']);
        // A voice persona fails to the fallback prompt, not silently.
        $this->assertSame(AiProviderReadiness::LEVEL_WARN, $report['anthropic']['level']);
    }

    public function test_empty_and_unknown_persona_providers_mirror_the_worker_defaults(): void
    {
        $this->persona(['llm_provider' => '', 'tts_provider' => 'cartesia', 'stt_provider' => '']);

        $requirements = AiProviderReadiness::requirements();

        $this->assertArrayHasKey('anthropic', $requirements);
        $this->assertArrayHasKey('elevenlabs', $requirements);
        $this->assertArrayNotHasKey('cartesia', $requirements);
    }

    public function test_local_llm_and_explicit_providers_map_to_their_keys(): void
    {
        config()->set('telephony.ai_fallback.voice_provider', 'elevenlabs');
        $this->persona(['llm_provider' => 'local', 'tts_provider' => 'openai', 'stt_provider' => 'deepgram']);

        $requirements = AiProviderReadiness::requirements();

        $this->assertArrayNotHasKey('anthropic', $requirements);
        $this->assertArrayHasKey('openai', $requirements);
        $this->assertArrayHasKey('deepgram', $requirements);
        $this->assertArrayHasKey('elevenlabs', $requirements); // fallback prompt
    }

    public function test_an_email_rule_routed_to_ai_needs_anthropic_and_is_down_without_it(): void
    {
        $team = Team::factory()->create(['personal_team' => false]);
        $persona = $this->persona(['team_id' => $team->id, 'is_active' => false]);

        EmailRoutingRule::create([
            'team_id' => $team->id,
            'name' => 'AI',
            'match_type' => EmailRoutingRule::MATCH_DEFAULT,
            'destination_type' => EmailRoutingRule::DESTINATION_AGENT_PERSONA,
            'destination_id' => $persona->id,
            'is_active' => true,
        ]);

        $this->assertArrayHasKey('anthropic', AiProviderReadiness::requirements());

        $row = AiProviderReadiness::report()['anthropic'];
        $this->assertSame(AiProviderReadiness::MISSING, $row['status']);
        $this->assertSame(AiProviderReadiness::LEVEL_DOWN, $row['level']);
    }

    public function test_sms_queues_count_only_when_auto_reply_is_on(): void
    {
        $team = Team::factory()->create(['personal_team' => false]);
        $persona = $this->persona(['team_id' => $team->id, 'is_active' => false]);
        MessageQueue::create([
            'team_id' => $team->id,
            'name' => 'SMS',
            'overflow_agent_persona_id' => $persona->id,
            'is_active' => true,
        ]);

        $this->assertArrayNotHasKey('anthropic', AiProviderReadiness::requirements());

        config()->set('messaging.auto_reply_enabled', true);

        $this->assertArrayHasKey('anthropic', AiProviderReadiness::requirements());
    }

    public function test_knowledge_store_embeddings_and_disclosure_need_openai_but_ollama_needs_nothing(): void
    {
        $team = Team::factory()->create(['personal_team' => false]);
        KnowledgeStore::create(['team_id' => $team->id, 'name' => 'Local', 'embedding_model' => 'ollama:nomic-embed-text', 'embedding_dims' => 768, 'is_active' => true]);

        $this->assertSame([], AiProviderReadiness::requirements());

        KnowledgeStore::create(['team_id' => $team->id, 'name' => 'Hosted', 'embedding_model' => 'openai:text-embedding-3-small', 'embedding_dims' => 1536, 'is_active' => true]);

        $this->assertSame(['openai'], array_keys(AiProviderReadiness::requirements()));

        config()->set('telephony.recording.disclosure_message', 'This call may be recorded.');
        $this->assertCount(2, AiProviderReadiness::requirements()['openai']);
    }

    public function test_a_key_set_via_config_counts_as_ok(): void
    {
        $this->persona();
        config()->set('services.anthropic.api_key', 'sk-ant-test');

        $this->assertSame(AiProviderReadiness::OK, AiProviderReadiness::report()['anthropic']['status']);
        $this->assertSame(['settings'], AiProviderReadiness::report()['anthropic']['set_in']);
    }

    public function test_a_worker_heartbeat_key_covers_voice_reasons_only(): void
    {
        $this->persona();
        AiProviderReadiness::recordWorkerKeys('worker-1', ['anthropic' => true, 'elevenlabs' => true]);

        $report = AiProviderReadiness::report();
        $this->assertSame(AiProviderReadiness::OK, $report['anthropic']['status']);
        $this->assertSame(['worker'], $report['anthropic']['set_in']);
        $this->assertSame(AiProviderReadiness::OK, $report['elevenlabs']['status']);

        // Email replies run in Laravel: the worker's env can't help.
        $team = Team::factory()->create(['personal_team' => false]);
        EmailRoutingRule::create([
            'team_id' => $team->id,
            'name' => 'AI',
            'match_type' => EmailRoutingRule::MATCH_DEFAULT,
            'destination_type' => EmailRoutingRule::DESTINATION_AGENT_PERSONA,
            'destination_id' => 1,
            'is_active' => true,
        ]);

        $row = AiProviderReadiness::report()['anthropic'];
        $this->assertSame(AiProviderReadiness::MISSING, $row['status']);
        $this->assertCount(1, $row['reasons']);
        $this->assertStringContainsString('email', $row['reasons'][0]);
    }

    public function test_every_fresh_worker_must_have_the_key_and_stale_reports_expire(): void
    {
        AiProviderReadiness::recordWorkerKeys('worker-1', ['anthropic' => true]);
        AiProviderReadiness::recordWorkerKeys('worker-2', ['anthropic' => false]);

        $this->assertFalse(AiProviderReadiness::workerHas('anthropic'));

        Cache::forget(AiProviderReadiness::WORKER_KEYS_CACHE_KEY);
        AiProviderReadiness::recordWorkerKeys('worker-1', ['anthropic' => true]);
        $this->assertTrue(AiProviderReadiness::workerHas('anthropic'));

        $this->travel(AiProviderReadiness::WORKER_REPORT_TTL + 5)->seconds();
        $this->assertFalse(AiProviderReadiness::workerHas('anthropic'));
    }

    public function test_probe_reports_a_rejected_key_as_invalid(): void
    {
        $this->persona(['llm_provider' => 'anthropic']);
        config()->set('services.anthropic.api_key', 'sk-ant-bad');
        config()->set('services.elevenlabs.api_key', 'el-good');
        config()->set('services.openai.api_key', 'sk-down');

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'invalid x-api-key'], 401),
            'api.elevenlabs.io/*' => Http::response(['subscription' => []], 200),
            'api.openai.com/*' => Http::response('', 503),
        ]);

        $report = AiProviderReadiness::report(probe: true);

        $this->assertSame(AiProviderReadiness::INVALID, $report['anthropic']['status']);
        $this->assertSame(AiProviderReadiness::OK, $report['elevenlabs']['status']);
        // A 503 says nothing about the key.
        $this->assertSame(AiProviderReadiness::OK, $report['openai']['status']);

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'sk-ant-bad'));
    }

    public function test_report_does_not_probe_by_default(): void
    {
        Http::fake();
        $this->persona();
        config()->set('services.anthropic.api_key', 'sk-ant-test');

        AiProviderReadiness::report();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persona(array $attributes = []): AgentPersona
    {
        return AgentPersona::create(array_merge([
            'team_id' => $attributes['team_id'] ?? Team::factory()->create(['personal_team' => false])->id,
            'name' => 'Ava',
            'role' => 'receptionist',
            'is_active' => true,
        ], $attributes));
    }
}
