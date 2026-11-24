<?php

declare(strict_types=1);

namespace App\Services\Operator;

use App\Models\Extension;
use App\Models\Message;
use App\Models\OperatorCall;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\PlatformExtensionAllocator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ring an operator's own softphone with a real test call for a client.
 *
 * Asterisk places a genuine SIP call to the operator's extension, so it
 * rings, can be answered, held and hung up like any call, and the caller
 * side plays hold music. The call is recorded on the operator's desk
 * first, with the chosen client; the X-Orbital-Test-Call header carries
 * the record's token so the ringing call finds it (MultiCallDesk::
 * followSoftphone). It is a live call: a message saved on it is filed
 * for the client like any other.
 *
 * It rings the extension directly rather than through the client's
 * number, so it works for clients with no numbers or routing set up.
 */
final class TestCallPlacer
{
    /** How long the softphone rings before Asterisk gives up. */
    public const RING_SECONDS = 30;

    /** How long the caller stays on once answered. */
    public const MAX_CALL_SECONDS = 900;

    public function __construct(
        private readonly AsteriskAmiService $ami,
        private readonly PlatformExtensionAllocator $extensions,
    ) {}

    /**
     * @return array{extension: ?string, registered: bool}
     */
    public function softphoneStatus(User $operator): array
    {
        $extension = $this->extensions->extensionFor($operator);

        return [
            'extension' => $extension?->number,
            'registered' => $extension !== null && $this->ami->endpointIsRegistered($this->endpoint($extension)),
        ];
    }

    /**
     * @throws RuntimeException with a message fit to show the operator
     */
    public function place(User $operator, Team $team, ?string $callerName, ?string $callerNumber): OperatorCall
    {
        $extension = $this->extensions->extensionFor($operator);
        if (! $extension || ! $extension->is_active) {
            throw new RuntimeException('You have no softphone extension. Use "Give me a softphone" first.');
        }

        $endpoint = $this->endpoint($extension);
        if (! $this->ami->endpointIsRegistered($endpoint)) {
            throw new RuntimeException("Your softphone (ext {$extension->number}) isn't logged in. Open /operator/workspace in another tab, wait for it to connect, then try again.");
        }

        $callerName = trim((string) $callerName) ?: null;
        $digits = preg_replace('/\D+/', '', (string) $callerNumber) ?: '555010'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $token = Str::lower(Str::random(24));

        $call = OperatorCall::create([
            'user_id' => $operator->id,
            'team_id' => $team->id,
            'is_trial' => false,
            'test_token' => $token,
            'caller_name' => $callerName,
            'caller_number' => $digits,
            'line_label' => 'Test call',
            'status' => OperatorCall::STATUS_RINGING,
            'arrived_at' => now(),
            'draft' => [
                'caller_name' => $callerName,
                'caller_phone' => $digits,
                'reason' => null,
                'urgency' => Message::URGENCY_NORMAL,
            ],
        ]);

        // Caller ID is quoted for Asterisk; strip what would break it.
        $displayName = str_replace(['"', '<', '>', ',', "\r", "\n"], '', $callerName ?? 'Test caller');

        $placed = $this->ami->originateToApplication(
            channel: 'PJSIP/'.$endpoint,
            application: 'MusicOnHold',
            data: 'default,'.self::MAX_CALL_SECONDS,
            callerId: "\"{$displayName}\" <{$digits}>",
            variables: ['PJSIP_HEADER(add,X-Orbital-Test-Call)' => $token],
            timeoutMs: self::RING_SECONDS * 1000,
        );

        if (! $placed) {
            $call->forceFill(['status' => OperatorCall::STATUS_MISSED, 'ended_at' => now()])->save();

            throw new RuntimeException('Asterisk refused the call. Check System Status for Asterisk.');
        }

        return $call;
    }

    private function endpoint(Extension $extension): string
    {
        return (string) ($extension->sip_username ?: $extension->number);
    }
}
