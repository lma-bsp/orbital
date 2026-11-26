<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestLivekitEgressJob;
use App\Jobs\RunPostCallActionsJob;
use App\Models\CallSessionState;
use App\Services\Messages\PartialMessagePolicy;
use App\Services\Messages\SessionMessageWriter;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives LiveKit server-side webhook events at
 * `POST /api/livekit/webhook`. Two events are acted on:
 *
 *   - `egress_ended` — a per-participant track file has been
 *     finalized in the configured S3 bucket and is ready to
 *     register as a `CallRecording` row.
 *   - `room_finished` — the call is definitively over. Finalises
 *     the matching call session so a partial message capture can
 *     be written for clients whose policy keeps them, even if the
 *     agent worker died without reporting the end itself.
 *
 * Every other event passes through with a 200; LiveKit retries
 * on non-2xx so we'd rather log-and-accept unknown event types
 * than churn its retry queue.
 *
 * Auth: LiveKit signs every webhook payload with the configured
 * API key/secret as a JWT in the `Authorization` header. The
 * raw body is the JWT's `sha256` claim — we verify both before
 * handing off to the queue.
 */
class LivekitWebhookController extends Controller
{
    private const CLOCK_LEEWAY_SECONDS = 60;

    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $authHeader = $request->header('Authorization', '');

        if (! $this->verify($body, (string) $authHeader)) {
            Log::warning('livekit webhook: signature verification failed', [
                'remote' => $request->ip(),
            ]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $payload = json_decode($body, true) ?: [];
        $event = (string) ($payload['event'] ?? '');

        if ($event === 'egress_ended') {
            // Hand the EgressInfo struct to the queue so the
            // controller returns fast even if SeaweedFS is slow.
            IngestLivekitEgressJob::dispatch($payload['egressInfo'] ?? []);
        }

        if ($event === 'room_finished') {
            $this->finaliseSession($payload['room']['name'] ?? null);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The room closed, so the call is definitively over.
     *
     * The agent worker normally reports this itself via
     * POST /api/call-sessions/{key}/end, and that path is the fast one.
     * This is the backstop for a worker that crashed, was OOM-killed, or
     * lost its connection — without it, a session whose worker died
     * would sit un-ended forever and any partial capture the client
     * asked us to keep would never be written.
     *
     * markEnded() is idempotent, so the two paths racing is fine and
     * expected: whichever arrives first sets the reason.
     */
    protected function finaliseSession(?string $roomName): void
    {
        if (! $roomName) {
            return;
        }

        // The LiveKit room name IS the session key — that's the contract
        // the agent worker follows when it opens a session.
        $state = CallSessionState::where('session_key', $roomName)->first();

        if (! $state) {
            return;
        }

        $state->markEnded(PartialMessagePolicy::REASON_SESSION_ABANDONED);

        app(SessionMessageWriter::class)->write($state->fresh());
        RunPostCallActionsJob::dispatch($state->id);
    }

    /**
     * Verify the webhook's `Authorization: <jwt>` against the active
     * LiveKit API key/secret: an HS256 signature, `iss` equal to the
     * API key, a current `exp` (and `nbf` when present), and a `sha256`
     * claim equal to the base64 SHA-256 of the raw body. Without the
     * time and issuer checks a captured webhook could be replayed
     * forever.
     */
    protected function verify(string $body, string $authHeader): bool
    {
        $mode = (string) config('telephony.livekit.mode', 'local');
        $creds = (array) config("telephony.livekit.{$mode}", config('telephony.livekit.local', []));
        $apiKey = (string) ($creds['api_key'] ?? '');
        $secret = (string) ($creds['api_secret'] ?? '');

        $token = trim((string) preg_replace('/^Bearer\s+/i', '', $authHeader));
        if ($apiKey === '' || $secret === '' || $token === '') {
            return false;
        }

        $previousLeeway = JWT::$leeway;
        try {
            // Checks the signature, alg, exp and nbf (with leeway).
            JWT::$leeway = self::CLOCK_LEEWAY_SECONDS;
            $claims = (array) JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable) {
            return false;
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (! isset($claims['exp']) || ! hash_equals($apiKey, (string) ($claims['iss'] ?? ''))) {
            return false;
        }

        // LiveKit sends standard base64; tolerate the URL-safe form too.
        $normalise = fn (string $b64) => rtrim(strtr($b64, '-_', '+/'), '=');
        $claimedHash = $normalise((string) ($claims['sha256'] ?? ''));
        $bodyHash = $normalise(base64_encode(hash('sha256', $body, true)));

        return $claimedHash !== '' && hash_equals($bodyHash, $claimedHash);
    }
}
