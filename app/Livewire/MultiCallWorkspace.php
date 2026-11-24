<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Message;
use App\Models\OperatorCall;
use App\Models\User;
use App\Services\Operator\ClientCallBrief;
use App\Services\Operator\ClientSearch;
use App\Services\Operator\MultiCallDesk;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Multi-call workspace: every call on the operator's desk as a card in
 * the pending-calls strip, and a tab per call holding that call's own
 * client, greeting, notes and message draft. Switching tabs or answering
 * the next caller never clears another call's draft.
 *
 * Mounted two ways:
 *   - Live, in the operator Workspace when the "Multi-call workspace"
 *     platform setting is on. Tabs follow the softphone (see
 *     followSoftphone) and Answer / Hold / Hang up drive it.
 *   - Trial, on the admin "Multi-call trial" page. Calls are simulated:
 *     the buttons only change state, and a saved message is never
 *     written to the client's inbox.
 */
class MultiCallWorkspace extends Component
{
    public bool $trial = false;

    public ?int $selectedId = null;

    /** @var array{caller_name: ?string, caller_phone: ?string, reason: ?string, urgency: string} */
    public array $draft = [];

    public string $clientSearch = '';

    public function mount(bool $trial = false): void
    {
        $this->trial = $trial;
        $this->selectDefault();
    }

    /** The trial page's header actions changed the desk. */
    #[On('multi-call-desk-updated')]
    public function refreshDesk(?int $select = null): void
    {
        if ($select && $this->call($select)) {
            $this->select($select);

            return;
        }

        if (! $this->selectedId || ! $this->call($this->selectedId)) {
            $this->selectDefault();
        }
    }

    public function select(int $callId): void
    {
        $call = $this->call($callId);
        if (! $call) {
            return;
        }

        $this->persistDraft();
        $this->selectedId = $call->id;
        $this->loadDraft($call);
        $this->clientSearch = '';
        $this->resetValidation();
    }

    public function answer(int $callId): void
    {
        $call = $this->call($callId);
        if (! $call) {
            return;
        }

        $this->persistDraft();

        if (! $this->trial) {
            // The softphone holds a single SIP session, so the only call
            // it can pick up is the one ringing on it right now. Its
            // `answered` event then marks the call Connected.
            if ($call->sip_call_id) {
                $this->dispatch('orbital-softphone-command', action: 'answer');
            }
            $this->select($call->id);

            return;
        }

        app(MultiCallDesk::class)->answer($call);
        $this->select($call->id);
    }

    public function hold(int $callId): void
    {
        $call = $this->call($callId);
        if (! $call) {
            return;
        }

        if (! $this->trial && $call->sip_call_id) {
            $this->dispatch('orbital-softphone-command', action: 'hold');
        }

        app(MultiCallDesk::class)->hold($call);
    }

    public function resume(int $callId): void
    {
        $call = $this->call($callId);
        if (! $call) {
            return;
        }

        if (! $this->trial && $call->sip_call_id) {
            $this->dispatch('orbital-softphone-command', action: 'resume');
        }

        $this->persistDraft();
        app(MultiCallDesk::class)->answer($call);
        $this->select($call->id);
    }

    /**
     * Trial: the caller hangs up. Live: the operator ends the call on
     * the softphone, whose `ended` event marks it.
     */
    public function hangUp(int $callId): void
    {
        $call = $this->call($callId);
        if (! $call) {
            return;
        }

        if (! $this->trial) {
            $this->dispatch('orbital-softphone-command', action: 'hangup');
        }

        app(MultiCallDesk::class)->hangUp($call);
    }

    public function closeCall(int $callId): void
    {
        $call = $this->call($callId);
        if (! $call) {
            return;
        }

        if (! $this->trial && in_array($call->status, [OperatorCall::STATUS_LIVE, OperatorCall::STATUS_HELD, OperatorCall::STATUS_RINGING], true)) {
            Notification::make()
                ->title('Hang up first')
                ->body('This call is still on the softphone.')
                ->warning()
                ->send();

            return;
        }

        if ($call->id === $this->selectedId) {
            $this->persistDraft();
            $this->selectedId = null;
        }

        app(MultiCallDesk::class)->close($call);
        $this->selectDefault();
    }

    public function attachClient(int $teamId): void
    {
        $call = $this->selectedCall();
        if (! $call) {
            return;
        }

        if (! app(MultiCallDesk::class)->attachClient($call, $teamId)) {
            Notification::make()->title('Client not found')->danger()->send();

            return;
        }

        $this->clientSearch = '';
    }

    public function updatedDraft(): void
    {
        $this->persistDraft();
    }

    public function saveMessage(): void
    {
        $call = $this->selectedCall();
        if (! $call || ! $call->team_id) {
            return;
        }

        $this->validate([
            'draft.caller_name' => 'required|string|max:255',
            'draft.caller_phone' => 'nullable|string|max:64',
            'draft.reason' => 'required|string',
        ], [], [
            'draft.caller_name' => 'caller name',
            'draft.reason' => 'message',
        ]);

        $this->persistDraft();
        app(MultiCallDesk::class)->saveMessage($call->refresh(), $this->operator());

        Notification::make()
            ->title($this->trial ? 'Trial message saved' : 'Message saved')
            ->body($this->trial ? 'Kept on this trial call only. Nothing was sent to the client.' : null)
            ->success()
            ->send();
    }

    public function savePartialMessage(): void
    {
        $call = $this->selectedCall();
        if (! $call) {
            return;
        }

        $this->persistDraft();
        $result = app(MultiCallDesk::class)->savePartialMessage($call->refresh(), $this->operator());

        match ($result) {
            'saved' => Notification::make()
                ->title($this->trial ? 'Trial partial message saved' : 'Partial message saved')
                ->body($this->trial ? 'Kept on this trial call only. Nothing was sent to the client.' : 'Marked incomplete so the client knows the caller dropped off.')
                ->success()
                ->send(),
            'not_kept' => Notification::make()
                ->title('This client does not keep partial messages')
                ->warning()
                ->send(),
            default => Notification::make()
                ->title('Not enough to save')
                ->body('A partial message needs a callback number, or a name and something they said.')
                ->warning()
                ->send(),
        };
    }

    /**
     * Softphone events, forwarded from the browser by the view. Only a
     * live desk follows the softphone; a trial desk ignores it.
     *
     * @param  array<string, mixed>  $event
     */
    public function followSoftphone(array $event): void
    {
        if ($this->trial) {
            return;
        }

        $call = app(MultiCallDesk::class)->followSoftphone($this->operator(), $event);

        if ($call && ($event['phase'] ?? null) === 'incoming') {
            // Load the ringing call's tab, as the empty state promises,
            // unless the operator is mid-call on another tab.
            $current = $this->selectedCall();
            if (! $current || $current->status !== OperatorCall::STATUS_LIVE) {
                $this->select($call->id);
            }
        } elseif ($call && ($event['phase'] ?? null) === 'answered') {
            $this->select($call->id);
        }
    }

    public function render()
    {
        $desk = app(MultiCallDesk::class);
        $calls = $desk->openCalls($this->operator(), $this->trial);
        $selected = $calls->firstWhere('id', $this->selectedId);

        $team = $selected?->team;

        return view('livewire.multi-call-workspace', [
            'calls' => $calls,
            'selected' => $selected,
            'brief' => $team ? app(ClientCallBrief::class)->for($team) : null,
            'recentMessages' => $team
                ? Message::query()->withoutGlobalScopes()->where('team_id', $team->id)->latest('created_at')->limit(5)->get(['id', 'caller_name', 'reason', 'urgency', 'is_partial', 'created_at'])
                : collect(),
            'clientResults' => $selected && ! $team && mb_strlen(trim($this->clientSearch)) >= 2
                ? app(ClientSearch::class)->search($this->clientSearch, 8)
                : [],
            'keepsPartials' => $selected ? $desk->keepsPartials($selected) : false,
        ]);
    }

    private function operator(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function call(int $callId): ?OperatorCall
    {
        return app(MultiCallDesk::class)->find($this->operator(), $this->trial, $callId);
    }

    private function selectedCall(): ?OperatorCall
    {
        return $this->selectedId ? $this->call($this->selectedId) : null;
    }

    /** The connected call, else the oldest one on the desk. */
    private function selectDefault(): void
    {
        $calls = app(MultiCallDesk::class)->openCalls($this->operator(), $this->trial);
        $pick = $calls->firstWhere('status', OperatorCall::STATUS_LIVE) ?? $calls->first();

        $this->selectedId = $pick?->id;
        $this->loadDraft($pick);
    }

    private function loadDraft(?OperatorCall $call): void
    {
        $draft = $call?->draft ?? [];

        $this->draft = [
            'caller_name' => $draft['caller_name'] ?? null,
            'caller_phone' => $draft['caller_phone'] ?? null,
            'reason' => $draft['reason'] ?? null,
            'urgency' => $draft['urgency'] ?? Message::URGENCY_NORMAL,
        ];
    }

    private function persistDraft(): void
    {
        $call = $this->selectedCall();
        if ($call) {
            app(MultiCallDesk::class)->saveDraft($call, $this->draft);
        }
    }
}
