<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Jobs\ReloadServicesAfterCertRenewalJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The shared-secret webhooks (worker heartbeat, inbound mail, TLS
 * renewal) accept only the exact configured token, and refuse everyone
 * when no token is configured.
 */
class SharedSecretWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_accepts_only_the_worker_token(): void
    {
        config(['services.agent_worker.token' => 'worker-secret']);

        $this->postJson('/api/agent-worker/heartbeat', [], ['Authorization' => 'Bearer worker-secre'])->assertUnauthorized();
        $this->postJson('/api/agent-worker/heartbeat')->assertUnauthorized();
        $this->assertNull(Cache::get('agent_worker:heartbeat'));

        $this->postJson('/api/agent-worker/heartbeat', [], ['Authorization' => 'Bearer worker-secret'])
            ->assertOk()
            ->assertJson(['ok' => true]);
        $this->assertNotNull(Cache::get('agent_worker:heartbeat'));
    }

    public function test_heartbeat_refuses_everyone_without_a_configured_token(): void
    {
        config(['services.agent_worker.token' => '']);

        $this->postJson('/api/agent-worker/heartbeat', [], ['Authorization' => 'Bearer '])->assertUnauthorized();
    }

    public function test_inbound_mail_accepts_only_the_mail_token(): void
    {
        config(['services.inbound_mail.token' => 'mail-secret']);

        $this->getJson('/api/mail/validate-recipient?address=x@example.com', ['Authorization' => 'Bearer wrong'])
            ->assertUnauthorized();
        $this->getJson('/api/mail/validate-recipient?address=x@example.com')->assertUnauthorized();

        // Past the token check: unknown recipient is a 404, not a 401.
        $this->getJson('/api/mail/validate-recipient?address=x@example.com', ['Authorization' => 'Bearer mail-secret'])
            ->assertNotFound();
    }

    public function test_inbound_mail_refuses_everyone_without_a_configured_token(): void
    {
        config(['services.inbound_mail.token' => '']);

        $this->getJson('/api/mail/validate-recipient?address=x@example.com', ['Authorization' => 'Bearer '])
            ->assertUnauthorized();
    }

    public function test_tls_webhook_accepts_only_the_edge_token(): void
    {
        Queue::fake();
        config(['tls.webhook_token' => 'edge-secret']);

        $this->postJson('/api/tls/renewed', [], ['Authorization' => 'Bearer edge-secre'])->assertUnauthorized();
        $this->postJson('/api/tls/renewed')->assertUnauthorized();
        Queue::assertNothingPushed();

        $this->postJson('/api/tls/renewed', [], ['Authorization' => 'Bearer edge-secret'])->assertStatus(202);
        Queue::assertPushed(ReloadServicesAfterCertRenewalJob::class);
    }

    public function test_tls_webhook_refuses_everyone_without_a_configured_token(): void
    {
        Queue::fake();
        config(['tls.webhook_token' => '']);

        $this->postJson('/api/tls/renewed', [], ['Authorization' => 'Bearer '])->assertUnauthorized();
        Queue::assertNothingPushed();
    }
}
