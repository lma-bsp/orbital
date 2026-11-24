<?php

declare(strict_types=1);

namespace App\Services\Operator;

use App\Models\ClientDid;
use App\Models\Message;
use App\Models\OperatorCall;
use App\Models\Team;
use App\Models\User;
use App\Services\Messages\PartialMessagePolicy;
use App\Services\Telephony\DidFormats;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The calls on one operator's desk in the multi-call workspace, and
 * what can happen to them: arrive, answer, hold, hang up, take a
 * message, close.
 *
 * Two kinds of desk share this code. A live desk follows the operator's
 * softphone. A trial desk (is_trial) holds simulated calls started from
 * the admin trial page: nothing rings, and a saved message is recorded
 * on the call only, never written to the client's inbox.
 *
 * Every lookup is by (user, trial), so one operator can never act on
 * another's calls and a trial never touches live rows.
 */
final class MultiCallDesk
{
    public function __construct(private readonly PartialMessagePolicy $partials) {}

    /**
     * Calls still on the desk, oldest first.
     *
     * @return Collection<int, OperatorCall>
     */
    public function openCalls(User $user, bool $trial): Collection
    {
        if (! $trial) {
            $this->markMissedTestCalls($user);
        }

        return OperatorCall::query()
            ->open()
            ->where('user_id', $user->id)
            ->where('is_trial', $trial)
            ->with('team')
            ->orderBy('arrived_at')
            ->orderBy('id')
            ->get();
    }

    public function find(User $user, bool $trial, int $callId): ?OperatorCall
    {
        return OperatorCall::query()
            ->open()
            ->where('user_id', $user->id)
            ->where('is_trial', $trial)
            ->find($callId);
    }

    /**
     * A call arrives on the desk. The client is taken from the number
     * the caller dialled when the call carries one.
     *
     * @param  array{team_id?: ?int, sip_call_id?: ?string, caller_number?: ?string, caller_name?: ?string, called_number?: ?string, line_label?: ?string, status?: string, arrived_at?: mixed}  $attributes
     */
    public function receive(User $user, bool $trial, array $attributes): OperatorCall
    {
        $did = $this->didFor($attributes['called_number'] ?? null);

        return OperatorCall::create([
            'user_id' => $user->id,
            'is_trial' => $trial,
            'team_id' => $attributes['team_id'] ?? $did?->team_id,
            'sip_call_id' => $attributes['sip_call_id'] ?? null,
            'caller_number' => $attributes['caller_number'] ?? null,
            'caller_name' => $attributes['caller_name'] ?? null,
            'called_number' => $attributes['called_number'] ?? null,
            'line_label' => $attributes['line_label'] ?? $did?->label,
            'status' => $attributes['status'] ?? OperatorCall::STATUS_RINGING,
            'arrived_at' => $attributes['arrived_at'] ?? now(),
            'draft' => [
                'caller_name' => $attributes['caller_name'] ?? null,
                'caller_phone' => $attributes['caller_number'] ?? null,
                'reason' => null,
                'urgency' => Message::URGENCY_NORMAL,
            ],
        ]);
    }

    /**
     * Pick up a waiting call or take one off hold. Whoever was on the
     * line goes on hold: an operator talks to one caller at a time.
     */
    public function answer(OperatorCall $call): OperatorCall
    {
        if (in_array($call->status, [OperatorCall::STATUS_ENDED, OperatorCall::STATUS_MISSED], true)) {
            return $call;
        }

        DB::transaction(function () use ($call) {
            OperatorCall::query()
                ->open()
                ->where('user_id', $call->user_id)
                ->where('is_trial', $call->is_trial)
                ->where('status', OperatorCall::STATUS_LIVE)
                ->whereKeyNot($call->id)
                ->update(['status' => OperatorCall::STATUS_HELD, 'held_at' => now()]);

            $call->forceFill([
                'status' => OperatorCall::STATUS_LIVE,
                'answered_at' => $call->answered_at ?? now(),
                'held_at' => null,
            ])->save();
        });

        return $call;
    }

    public function hold(OperatorCall $call): OperatorCall
    {
        if ($call->status === OperatorCall::STATUS_LIVE) {
            $call->forceFill(['status' => OperatorCall::STATUS_HELD, 'held_at' => now()])->save();
        }

        return $call;
    }

    /** The caller is gone. The tab stays so the message can be finished. */
    public function hangUp(OperatorCall $call): OperatorCall
    {
        if (! in_array($call->status, [OperatorCall::STATUS_ENDED, OperatorCall::STATUS_MISSED], true)) {
            $call->forceFill(['status' => OperatorCall::STATUS_ENDED, 'ended_at' => now(), 'held_at' => null])->save();
        }

        return $call;
    }

    /** Take the call off the desk. */
    public function close(OperatorCall $call): void
    {
        $call->forceFill([
            'closed_at' => now(),
            'status' => $call->status === OperatorCall::STATUS_MISSED ? OperatorCall::STATUS_MISSED : OperatorCall::STATUS_ENDED,
            'ended_at' => $call->ended_at ?? now(),
        ])->save();
    }

    public function attachClient(OperatorCall $call, int $teamId): bool
    {
        $team = Team::query()->where('personal_team', false)->find($teamId);
        if (! $team) {
            return false;
        }

        $call->forceFill(['team_id' => $team->id])->save();

        return true;
    }

    /**
     * @param  array{caller_name?: ?string, caller_phone?: ?string, reason?: ?string, urgency?: ?string}  $draft
     */
    public function saveDraft(OperatorCall $call, array $draft): void
    {
        $urgency = ($draft['urgency'] ?? null) === Message::URGENCY_URGENT
            ? Message::URGENCY_URGENT
            : Message::URGENCY_NORMAL;

        $call->forceFill(['draft' => [
            'caller_name' => $this->clean($draft['caller_name'] ?? null, 255),
            'caller_phone' => $this->clean($draft['caller_phone'] ?? null, 64),
            'reason' => $this->clean($draft['reason'] ?? null, 10000),
            'urgency' => $urgency,
        ]])->save();
    }

    /**
     * File the call's draft as a message for its client. A trial call
     * records that it was saved and stops there: trial messages never
     * reach a client's inbox.
     *
     * Validation of required fields is the caller's job; this only
     * refuses a call without a client.
     */
    public function saveMessage(OperatorCall $call, User $operator): ?Message
    {
        if (! $call->team_id) {
            return null;
        }

        $message = null;

        if (! $call->is_trial) {
            $draft = $call->draft ?? [];
            $message = Message::create([
                'team_id' => $call->team_id,
                'created_by_user_id' => $operator->id,
                'caller_name' => $draft['caller_name'] ?? null,
                'caller_phone' => $draft['caller_phone'] ?? null,
                'reason' => $draft['reason'] ?? null,
                'urgency' => $draft['urgency'] ?? Message::URGENCY_NORMAL,
                'status' => Message::STATUS_NEW,
                'is_partial' => false,
            ]);
        }

        $call->forceFill(['message_id' => $message?->id, 'message_saved_at' => now()])->save();

        return $message;
    }

    /**
     * Keep what was typed when the caller dropped mid-intake, for
     * clients whose policy keeps partial messages.
     *
     * @return 'saved'|'not_kept'|'too_little'
     */
    public function savePartialMessage(OperatorCall $call, User $operator): string
    {
        $team = $call->team;
        if (! $team || ! $this->partials->keepsPartials($team)) {
            return 'not_kept';
        }

        $draft = $call->draft ?? [];
        $captured = [
            'caller_name' => $draft['caller_name'] ?? null,
            'caller_phone' => $draft['caller_phone'] ?? null,
            'reason' => $draft['reason'] ?? null,
        ];

        if (! $this->partials->isWorthKeeping($captured)) {
            return 'too_little';
        }

        $message = null;
        if (! $call->is_trial) {
            // array_merge, not `+`: the operator's urgency has to beat
            // the policy's default.
            $message = Message::create(array_merge(
                $this->partials->attributesFor($captured, PartialMessagePolicy::REASON_OPERATOR_SAVED),
                [
                    'team_id' => $team->id,
                    'created_by_user_id' => $operator->id,
                    'urgency' => $draft['urgency'] ?? Message::URGENCY_NORMAL,
                ],
            ));
        }

        $call->forceFill(['message_id' => $message?->id, 'message_saved_at' => now()])->save();

        return 'saved';
    }

    public function keepsPartials(OperatorCall $call): bool
    {
        return $call->team !== null && $this->partials->keepsPartials($call->team);
    }

    /**
     * Follow the softphone. It handles one SIP session at a time, so a
     * live desk mirrors that session: a tab when it rings, Connected
     * when answered, hung up when it ends.
     *
     * @param  array{phase?: string, callId?: string, from?: string, fromName?: string, did?: string, testCall?: string}  $event
     */
    public function followSoftphone(User $user, array $event): ?OperatorCall
    {
        $sipCallId = $this->clean($event['callId'] ?? null, 255);
        if (! $sipCallId) {
            return null;
        }

        $call = OperatorCall::query()
            ->where('user_id', $user->id)
            ->where('is_trial', false)
            ->where('sip_call_id', $sipCallId)
            ->latest('id')
            ->first();

        if (! $call && ($event['phase'] ?? null) === 'incoming') {
            $call = $this->claimTestCall($user, $sipCallId, $event);
        }

        return match ($event['phase'] ?? null) {
            'incoming' => $call ?? $this->receive($user, false, [
                'sip_call_id' => $sipCallId,
                'caller_number' => $this->clean($event['from'] ?? null, 64),
                'caller_name' => $this->clean($event['fromName'] ?? null, 255),
                'called_number' => $this->clean($event['did'] ?? null, 64),
            ]),
            'answered' => $call ? $this->answer($call) : null,
            'ended' => $call ? $this->hangUp($call) : null,
            default => $call,
        };
    }

    /**
     * A test call from the admin page was recorded before it rang. Tie
     * the ringing SIP call to that record, by the token in its
     * X-Orbital-Test-Call header, so the tab opens with the client that
     * was chosen for it. Only this operator's own unclaimed records
     * qualify.
     *
     * @param  array<string, mixed>  $event
     */
    private function claimTestCall(User $user, string $sipCallId, array $event): ?OperatorCall
    {
        $unclaimed = OperatorCall::query()
            ->open()
            ->where('user_id', $user->id)
            ->where('is_trial', false)
            ->whereNull('sip_call_id')
            ->whereNotNull('test_token');

        $token = $this->clean($event['testCall'] ?? null, 40);
        $from = preg_replace('/\D+/', '', (string) ($event['from'] ?? ''));

        $call = match (true) {
            $token !== null => (clone $unclaimed)->where('test_token', $token)->first(),
            // No header (stripped on the way): the caller number we
            // placed it with, while it can still be ringing.
            $from !== '' => (clone $unclaimed)
                ->where('caller_number', $from)
                ->where('status', OperatorCall::STATUS_RINGING)
                ->where('arrived_at', '>=', now()->subSeconds(TestCallPlacer::RING_SECONDS + 15))
                ->latest('arrived_at')
                ->first(),
            default => null,
        };

        $call?->forceFill(['sip_call_id' => $sipCallId])->save();

        return $call;
    }

    /**
     * Test calls that never reached the softphone (it was busy, or
     * Asterisk gave up ringing) would otherwise sit on the desk as
     * Ringing forever.
     */
    private function markMissedTestCalls(User $user): void
    {
        OperatorCall::query()
            ->open()
            ->where('user_id', $user->id)
            ->where('is_trial', false)
            ->whereNotNull('test_token')
            ->whereNull('sip_call_id')
            ->where('status', OperatorCall::STATUS_RINGING)
            ->where('arrived_at', '<', now()->subSeconds(TestCallPlacer::RING_SECONDS + 15))
            ->update(['status' => OperatorCall::STATUS_MISSED, 'ended_at' => now()]);
    }

    /**
     * Put three calls for one client on a trial desk, as in the design:
     * one ringing, one queued behind it, one already on hold with a
     * half-written message.
     *
     * @return Collection<int, OperatorCall>
     */
    public function startTrialScenario(User $user, Team $team): Collection
    {
        $lines = $this->trialLines($team);

        $ringing = $this->receive($user, true, [
            'team_id' => $team->id,
            'caller_name' => 'Maria Delgado',
            'caller_number' => '(555) 010-2231',
            'called_number' => $lines[0]['number'],
            'line_label' => $lines[0]['label'],
            'arrived_at' => now()->subSeconds(112),
        ]);

        $queued = $this->receive($user, true, [
            'team_id' => $team->id,
            'caller_number' => '(555) 010-7789',
            'called_number' => $lines[1]['number'],
            'line_label' => $lines[1]['label'],
            'status' => OperatorCall::STATUS_QUEUED,
            'arrived_at' => now()->subSeconds(41),
        ]);

        $held = $this->receive($user, true, [
            'team_id' => $team->id,
            'caller_name' => 'Tom Becker',
            'caller_number' => '(555) 010-3367',
            'called_number' => $lines[2]['number'],
            'line_label' => $lines[2]['label'],
            'arrived_at' => now()->subSeconds(420),
        ]);
        $held->forceFill([
            'status' => OperatorCall::STATUS_HELD,
            'answered_at' => now()->subSeconds(360),
            'held_at' => now()->subSeconds(185),
            'draft' => [
                'caller_name' => 'Tom Becker',
                'caller_phone' => '(555) 010-3367',
                'reason' => 'Wants to book an appointment next week. Asked for a call back this afternoon.',
                'urgency' => Message::URGENCY_NORMAL,
            ],
        ])->save();

        return collect([$held, $ringing, $queued]);
    }

    /** One more simulated call rings on a trial desk. */
    public function simulateTrialCall(User $user, Team $team, ?string $callerName, ?string $callerNumber, string $status): OperatorCall
    {
        $line = $this->trialLines($team)[0];

        return $this->receive($user, true, [
            'team_id' => $team->id,
            'caller_name' => $callerName,
            'caller_number' => $callerNumber ?: '(555) 010-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            'called_number' => $line['number'],
            'line_label' => $line['label'],
            'status' => $status === OperatorCall::STATUS_QUEUED ? OperatorCall::STATUS_QUEUED : OperatorCall::STATUS_RINGING,
        ]);
    }

    public function clearTrial(User $user): int
    {
        return OperatorCall::query()
            ->where('user_id', $user->id)
            ->where('is_trial', true)
            ->delete();
    }

    /**
     * The client's own numbers when it has some, so a trial shows real
     * line names; generic line names otherwise.
     *
     * @return array<int, array{number: ?string, label: string}>
     */
    private function trialLines(Team $team): array
    {
        $dids = ClientDid::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->orderBy('number')
            ->limit(3)
            ->get(['number', 'label']);

        $fallback = ['Main line', 'After-hours line', 'Second line'];
        $lines = [];
        for ($i = 0; $i < 3; $i++) {
            $did = $dids[$i] ?? $dids[0] ?? null;
            $lines[] = [
                'number' => $did?->number,
                'label' => $did?->label ?: $fallback[$i],
            ];
        }

        return $lines;
    }

    private function didFor(?string $number): ?ClientDid
    {
        $number = $this->clean($number, 64);
        if (! $number) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';
        $candidates = array_values(array_unique(array_filter([
            $number,
            $digits,
            $digits !== '' ? '+'.$digits : null,
            strlen($digits) === 10 ? '+1'.$digits : null,
            ...($digits !== '' ? DidFormats::alternatives('+'.$digits) : []),
        ])));

        return ClientDid::query()
            ->withoutGlobalScopes()
            ->whereIn('number', $candidates)
            ->where('is_active', true)
            ->first();
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
