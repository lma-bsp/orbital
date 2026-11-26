<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Jobs\ProcessEmailWithAgentJob;
use App\Jobs\ProcessInboundEmailJob;
use App\Jobs\ProcessMessageWithAgentJob;
use App\Models\AgentPersona;
use App\Models\ConversationActivity;
use App\Models\EmailMessage;
use App\Models\EmailRoutingRule;
use App\Models\EmailThread;
use App\Models\MessageEntry;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\Team;
use App\Services\Mail\InboundRouter;
use App\Support\AiReplyHandback;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * With no Anthropic key, an email or text routed to AI goes to a human
 * with a note saying why, instead of retrying and going quiet.
 */
class AiReplyHandbackTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private AgentPersona $persona;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.anthropic.api_key', '');
        config()->set('messaging.providers.log.secret', 'test-secret');

        $this->team = Team::factory()->create(['personal_team' => false]);
        $this->persona = AgentPersona::create([
            'team_id' => $this->team->id,
            'name' => 'Ava',
            'role' => 'receptionist',
            'is_active' => true,
        ]);
    }

    // ── Email: dispatch guard ───────────────────────────────────────

    public function test_inbound_email_without_a_key_skips_the_ai_job_and_notes_the_thread_once(): void
    {
        Bus::fake([ProcessEmailWithAgentJob::class]);

        $this->receiveEmail('<one@example.com>');
        $this->receiveEmail('<two@example.com>', inReplyTo: '<one@example.com>');

        Bus::assertNotDispatched(ProcessEmailWithAgentJob::class);

        $thread = EmailThread::withoutGlobalScope('team')->sole();
        $this->assertSame([AiReplyHandback::NO_ANTHROPIC_KEY], $this->notes($thread));
        $this->assertNull($thread->assigned_operator_id);
    }

    public function test_inbound_email_with_a_key_dispatches_the_ai_job(): void
    {
        Bus::fake([ProcessEmailWithAgentJob::class]);
        config()->set('services.anthropic.api_key', 'sk-ant-test');

        $this->receiveEmail('<one@example.com>');

        Bus::assertDispatched(ProcessEmailWithAgentJob::class);
        $this->assertSame([], $this->notes(EmailThread::withoutGlobalScope('team')->sole()));
    }

    // ── Email: inside the AI job ────────────────────────────────────

    public function test_email_job_without_a_key_fails_once_without_retrying_and_hands_back(): void
    {
        Http::fake();
        $thread = $this->emailThread();

        $job = (new ProcessEmailWithAgentJob($thread->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertFailed();
        $job->assertNotReleased();
        Http::assertNothingSent();

        // Through a real (sync) queue job, fail() runs failed().
        ProcessEmailWithAgentJob::dispatch($thread->id);

        $this->assertSame([AiReplyHandback::NO_ANTHROPIC_KEY], $this->notes($thread));
        Http::assertNothingSent();
    }

    public function test_email_job_failed_after_retries_hands_back_with_the_error(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');
        $thread = $this->emailThread();

        (new ProcessEmailWithAgentJob($thread->id))->failed(new RuntimeException('Anthropic email reply call failed: 529'));

        $activity = ConversationActivity::query()->where('subject_id', $thread->id)->sole();
        $this->assertSame(AiReplyHandback::KIND_FAILED, $activity->metadata['kind']);
        $this->assertStringContainsString('529', $activity->metadata['error']);
    }

    // ── SMS: dispatch guard ─────────────────────────────────────────

    public function test_inbound_text_without_a_key_skips_the_ai_job_only_when_a_persona_would_answer(): void
    {
        config()->set('messaging.auto_reply_enabled', true);
        Bus::fake([ProcessMessageWithAgentJob::class]);
        $this->endpoint();

        // Human-worked thread: nothing to skip, nothing to note.
        $this->deliverText('Is anyone there?');
        $thread = MessageThread::withoutGlobalScopes()->sole();
        $this->assertSame([], $this->notes($thread));

        $thread->forceFill(['assigned_agent_persona_id' => $this->persona->id])->save();

        $this->deliverText('Hello?');
        $this->deliverText('Hello??');

        Bus::assertNotDispatched(ProcessMessageWithAgentJob::class);
        $this->assertSame([AiReplyHandback::NO_ANTHROPIC_KEY], $this->notes($thread));
    }

    // ── SMS: inside the AI job ──────────────────────────────────────

    public function test_message_job_without_a_key_fails_once_and_hands_back(): void
    {
        Http::fake();
        $endpoint = $this->endpoint();
        $thread = MessageThread::create([
            'team_id' => $this->team->id,
            'messaging_endpoint_id' => $endpoint->id,
            'remote_address' => '+15551110000',
            'protocol' => 'sms',
            'status' => MessageThread::STATUS_NEW,
            'assigned_agent_persona_id' => $this->persona->id,
            'last_message_at' => now(),
        ]);
        MessageEntry::create([
            'message_thread_id' => $thread->id,
            'team_id' => $this->team->id,
            'direction' => MessageEntry::DIRECTION_INBOUND,
            'from_address' => '+15551110000',
            'to_address' => '+15559990000',
            'body' => 'Hi',
            'provider' => 'log',
            'delivery_status' => MessageEntry::STATUS_RECEIVED,
            'occurred_at' => now(),
        ]);

        $job = (new ProcessMessageWithAgentJob($thread->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertFailed();

        ProcessMessageWithAgentJob::dispatch($thread->id);

        Http::assertNothingSent();
        $this->assertSame([AiReplyHandback::NO_ANTHROPIC_KEY], $this->notes($thread));
        $this->assertSame(MessageThread::STATUS_NEW, $thread->fresh()->status);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * @return array<int, string>
     */
    private function notes(Model $thread): array
    {
        return ConversationActivity::query()
            ->where('subject_type', $thread->getMorphClass())
            ->where('subject_id', $thread->getKey())
            ->where('action', 'note')
            ->get()
            ->map(fn (ConversationActivity $a): string => (string) $a->metadata['note'])
            ->all();
    }

    private function emailThread(): EmailThread
    {
        $thread = EmailThread::withoutGlobalScope('team')->create([
            'team_id' => $this->team->id,
            'subject' => 'Help',
            'assigned_agent_persona_id' => $this->persona->id,
        ]);

        EmailMessage::create([
            'team_id' => $this->team->id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
            'raw_storage_path' => 'x',
            'received_at' => now(),
            'routing_status' => 'routed',
            'from_address' => 'customer@example.com',
            'subject' => 'Help',
            'body_text' => 'Can someone call me back?',
        ]);

        return $thread;
    }

    private function receiveEmail(string $messageId, ?string $inReplyTo = null): void
    {
        Storage::fake('s3');

        $headers = "From: Customer <customer@example.com>\r\nTo: 1001@mail.test\r\nSubject: Help\r\nMessage-ID: {$messageId}\r\n";
        if ($inReplyTo) {
            $headers .= "In-Reply-To: {$inReplyTo}\r\nReferences: {$inReplyTo}\r\n";
        }
        Storage::disk('s3')->put('raw/'.md5($messageId).'.eml', $headers."Content-Type: text/plain\r\n\r\nCan someone call me back?\r\n");

        $message = EmailMessage::create([
            'direction' => 'inbound',
            'raw_storage_path' => 'raw/'.md5($messageId).'.eml',
            'received_at' => now(),
            'routing_status' => 'pending',
        ]);

        $this->mock(InboundRouter::class)->shouldReceive('route')->andReturn([
            'team_id' => $this->team->id,
            'destination_type' => EmailRoutingRule::DESTINATION_AGENT_PERSONA,
            'destination_id' => $this->persona->id,
            'matched_rule_id' => null,
            'function' => null,
            'status' => 'routed',
        ]);

        app()->call([new ProcessInboundEmailJob($message->id), 'handle']);
    }

    private function endpoint(): MessagingEndpoint
    {
        return MessagingEndpoint::create([
            'team_id' => $this->team->id,
            'address' => '+15559990000',
            'protocol' => 'sms',
            'provider' => 'log',
            'is_active' => true,
        ]);
    }

    private function deliverText(string $body): void
    {
        $this->withToken('test-secret')
            ->postJson('/api/messaging/inbound/log', [
                'from' => '+15551110000',
                'to' => '+15559990000',
                'body' => $body,
            ])
            ->assertOk();
    }
}
