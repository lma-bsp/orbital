<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\IngestLivekitEgressJob;
use App\Models\CallLog;
use App\Models\CallRecording;
use App\Models\Team;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The LiveKit webhook must be signed with the active API key/secret,
 * be current (exp/nbf) and name that key as its issuer; an egress
 * result may only register a file under the egress prefix for its room.
 */
class LivekitEgressAndWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'APItestkey';

    private const SECRET = 'test-secret-that-is-long-enough-for-hs256';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'telephony.livekit.mode' => 'local',
            'telephony.livekit.local.api_key' => self::KEY,
            'telephony.livekit.local.api_secret' => self::SECRET,
        ]);
    }

    // ── Webhook verification ────────────────────────────────────

    public function test_a_valid_webhook_is_accepted(): void
    {
        Queue::fake();
        $body = $this->egressBody();

        $this->postWebhook($body, $this->token($body))->assertOk();

        Queue::assertPushed(IngestLivekitEgressJob::class);
    }

    public function test_an_expired_webhook_is_rejected(): void
    {
        Queue::fake();
        $body = $this->egressBody();

        $this->postWebhook($body, $this->token($body, ['exp' => time() - 3600, 'nbf' => time() - 3900]))
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_webhook_without_exp_is_rejected(): void
    {
        $body = $this->egressBody();

        $this->postWebhook($body, $this->token($body, ['exp' => null]))->assertUnauthorized();
    }

    public function test_a_not_yet_valid_webhook_is_rejected(): void
    {
        $body = $this->egressBody();

        $this->postWebhook($body, $this->token($body, ['nbf' => time() + 3600, 'exp' => time() + 7200]))
            ->assertUnauthorized();
    }

    public function test_a_webhook_from_another_issuer_is_rejected(): void
    {
        $body = $this->egressBody();

        $this->postWebhook($body, $this->token($body, ['iss' => 'APIsomeoneelse']))->assertUnauthorized();
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        Queue::fake();
        $body = $this->egressBody();

        $this->postWebhook($body, $this->token($body, [], 'not-the-secret-but-long-enough-to-sign'))
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_body_that_does_not_match_the_hash_is_rejected(): void
    {
        $body = $this->egressBody();
        $tampered = str_replace('EG_1', 'EG_2', $body);

        $this->postWebhook($tampered, $this->token($body))->assertUnauthorized();
    }

    // ── Egress storage path ─────────────────────────────────────

    public function test_egress_under_the_room_prefix_is_registered(): void
    {
        $call = $this->makeCall('room-abc');

        dispatch_sync(new IngestLivekitEgressJob($this->egressInfo('room-abc', 'livekit/room-abc/room-abc-1.ogg')));

        $this->assertDatabaseHas('call_recordings', [
            'call_log_id' => $call->id,
            'source' => CallRecording::SOURCE_LIVEKIT_EGRESS,
            'storage_path' => 'livekit/room-abc/room-abc-1.ogg',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function badPaths(): array
    {
        return [
            'traversal' => ['livekit/room-abc/../../clients/9/2026/mix.wav'],
            'other prefix' => ['clients/9/2026/01/mix.wav'],
            'other room' => ['livekit/room-other/room-other-1.ogg'],
            'absolute' => ['/livekit/room-abc/x.ogg'],
            'url' => ['https://s3.example/livekit/room-abc/x.ogg'],
            'backslash' => ['livekit/room-abc/..\\..\\x.ogg'],
            'double slash' => ['livekit//room-abc/x.ogg'],
        ];
    }

    #[DataProvider('badPaths')]
    public function test_egress_outside_the_room_prefix_is_ignored(string $path): void
    {
        $this->makeCall('room-abc');

        dispatch_sync(new IngestLivekitEgressJob($this->egressInfo('room-abc', $path)));

        $this->assertDatabaseCount('call_recordings', 0);
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function postWebhook(string $body, string $token): TestResponse
    {
        return $this->call('POST', '/api/livekit/webhook', [], [], [], [
            'HTTP_AUTHORIZATION' => $token,
            'CONTENT_TYPE' => 'application/webhook+json',
        ], $body);
    }

    /** @param array<string, mixed> $overrides */
    private function token(string $body, array $overrides = [], string $secret = self::SECRET): string
    {
        $claims = array_merge([
            'iss' => self::KEY,
            'nbf' => time() - 5,
            'exp' => time() + 300,
            // LiveKit sends standard (padded) base64.
            'sha256' => base64_encode(hash('sha256', $body, true)),
        ], $overrides);

        return JWT::encode(array_filter($claims, fn ($v) => $v !== null), $secret, 'HS256');
    }

    private function egressBody(): string
    {
        return (string) json_encode([
            'event' => 'egress_ended',
            'egressInfo' => $this->egressInfo('room-abc', 'livekit/room-abc/room-abc-1.ogg'),
        ]);
    }

    /** @return array<string, mixed> */
    private function egressInfo(string $room, string $path): array
    {
        return [
            'egress_id' => 'EG_1',
            'room_name' => $room,
            'file_results' => [['filename' => $path, 'size' => 10, 'duration' => 2_000_000_000]],
        ];
    }

    private function makeCall(string $sipCallId): CallLog
    {
        $owner = User::factory()->create();
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Egress Client '.uniqid(),
            'personal_team' => false,
        ]);

        return CallLog::create([
            'team_id' => $team->id,
            'unique_id' => 'test-'.uniqid(),
            'sip_call_id' => $sipCallId,
            'direction' => 'inbound',
            'status' => 'completed',
            'from_number' => '+15555550100',
            'to_number' => '+15555550200',
        ]);
    }
}
