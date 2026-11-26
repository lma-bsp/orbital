<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\LiveKitConfigService;
use App\Services\Telephony\RtpengineService;
use App\Support\DefaultCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * `orbital:status` is what SECURITY.md sends operators to before go-live.
 * It has to name every secret still at a value that was once committed
 * to the repository, not just the .env.example defaults.
 */
class StatusReportsCommittedSecretsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AsteriskAmiService::class, fn (MockInterface $m) => $m->shouldReceive('getActiveChannels')->andReturn([]));
        $this->mock(LiveKitConfigService::class, fn (MockInterface $m) => $m->shouldReceive('healthCheck')->andReturn(true));
        $this->mock(RtpengineService::class);
    }

    public function test_status_flags_each_committed_secret(): void
    {
        $committed = DefaultCredentials::committedSecrets();

        config([
            'services.agent_worker.token' => $committed['Agent worker token (AGENT_WORKER_TOKEN)'],
            'telephony.asterisk.ami.secret' => $committed['Asterisk AMI secret (ASTERISK_AMI_SECRET)'],
            'telephony.livekit.local.api_key' => $committed['LiveKit API key (LIVEKIT_API_KEY)'],
            'telephony.livekit.local.api_secret' => $committed['LiveKit API secret (LIVEKIT_API_SECRET)'],
            'database.redis.default.password' => $committed['Valkey password (REDIS_PASSWORD)'],
        ]);

        $command = $this->artisan('orbital:status');

        foreach (array_keys($committed) as $label) {
            $command->expectsOutputToContain($label);
        }

        $command->assertSuccessful();
    }

    public function test_status_is_clean_when_they_are_rotated(): void
    {
        config([
            'database.connections.pgsql.password' => 'rotated',
            'services.agent_worker.token' => 'rotated-token',
            'telephony.asterisk.ami.secret' => 'rotated-ami',
            'telephony.livekit.local.api_key' => 'rotated-key',
            'telephony.livekit.local.api_secret' => 'rotated-secret',
            'database.redis.default.password' => 'rotated-valkey',
        ]);

        $this->artisan('orbital:status')
            ->doesntExpectOutputToContain('Agent worker token')
            ->assertSuccessful();
    }
}
