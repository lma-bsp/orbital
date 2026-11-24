{{--
    Multi-call workspace. See App\Livewire\MultiCallWorkspace.
    Scoped .mcw-* styles on Filament's CSS variables so it follows the
    panel palette and dark mode without a custom theme.
--}}
<div
    class="mcw"
    wire:poll.15s
    @unless ($trial)
        x-on:orbital-call.window="$wire.followSoftphone($event.detail)"
    @endunless
    x-data="{ now: Math.floor(Date.now() / 1000), tick: null }"
    x-init="tick = setInterval(() => now = Math.floor(Date.now() / 1000), 1000)"
    x-on:livewire:navigating.window="clearInterval(tick)"
>
    <style>
        .mcw { display: flex; flex-direction: column; gap: 1rem; }
        .mcw-panel { background: white; border: 1px solid var(--gray-200); border-radius: 0.75rem; }
        .dark .mcw-panel { background: var(--gray-900); border-color: rgb(255 255 255 / 0.1); }
        .mcw-muted { color: var(--gray-500); }
        .dark .mcw-muted { color: var(--gray-400); }
        .mcw-strip { padding: 1rem 1.25rem 1.25rem; }
        .mcw-strip-head { display: flex; align-items: baseline; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
        .mcw-h { margin: 0; font-size: 0.9375rem; font-weight: 600; }
        .mcw-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); gap: 0.75rem; }
        .mcw-card { display: flex; flex-direction: column; gap: 0.75rem; padding: 0.875rem 1rem; border-radius: 0.625rem; border: 1px solid var(--gray-200); cursor: pointer; text-align: left; background: transparent; color: inherit; font: inherit; }
        .dark .mcw-card { border-color: rgb(255 255 255 / 0.1); }
        .mcw-card:hover { border-color: var(--gray-400); }
        .mcw-card.is-selected { border: 2px solid var(--gray-900); padding: calc(0.875rem - 1px) calc(1rem - 1px); }
        .dark .mcw-card.is-selected { border-color: var(--gray-100); }
        .mcw-card.is-ringing { background: var(--primary-50); }
        .dark .mcw-card.is-ringing { background: rgb(255 255 255 / 0.04); }
        .mcw-row { display: flex; align-items: center; gap: 0.5rem; }
        .mcw-badge { display: inline-flex; align-items: center; height: 1.375rem; padding: 0 0.5rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; white-space: nowrap; }
        .mcw-badge.ringing { background: #DBEAFE; color: #1E3A8A; }
        .mcw-badge.queued { background: var(--gray-100); color: var(--gray-700); }
        .mcw-badge.held { background: #FFEDD5; color: #9A3412; }
        .mcw-badge.live { background: #DCFCE7; color: #14532D; }
        .mcw-badge.ended, .mcw-badge.missed { background: var(--gray-100); color: var(--gray-700); }
        .mcw-dot { width: 0.5rem; height: 0.5rem; border-radius: 50%; flex: none; }
        .mcw-dot.ringing { background: #1D4ED8; } .mcw-dot.queued { background: var(--gray-500); }
        .mcw-dot.held { background: #C2410C; } .mcw-dot.live { background: #15803D; } .mcw-dot.ended, .mcw-dot.missed { background: var(--gray-400); }
        .mcw-timer { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8125rem; font-weight: 500; font-variant-numeric: tabular-nums; }
        .mcw-tabs { display: flex; gap: 2px; flex-wrap: wrap; padding: 0 0.25rem; margin-bottom: -1px; position: relative; }
        .mcw-tab { display: flex; align-items: center; gap: 0.5rem; min-height: 2.75rem; padding: 0 1rem; border: 1px solid transparent; border-bottom: none; border-radius: 0.625rem 0.625rem 0 0; font-size: 0.8125rem; cursor: pointer; background: transparent; color: inherit; font-family: inherit; }
        .mcw-tab:hover { background: var(--gray-100); }
        .dark .mcw-tab:hover { background: rgb(255 255 255 / 0.05); }
        .mcw-tab[aria-selected="true"] { background: white; border-color: var(--gray-200); }
        .dark .mcw-tab[aria-selected="true"] { background: var(--gray-900); border-color: rgb(255 255 255 / 0.1); }
        .mcw-body { border-radius: 0 0.75rem 0.75rem 0.75rem; padding: 1.5rem; display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start; }
        .mcw-main { flex: 999 1 34rem; min-width: 0; display: flex; flex-direction: column; gap: 1.25rem; }
        .mcw-side { flex: 1 1 18rem; min-width: 0; display: flex; flex-direction: column; gap: 1rem; }
        .mcw-box { border: 1px solid var(--gray-200); border-radius: 0.625rem; padding: 0.875rem 1rem; }
        .dark .mcw-box { border-color: rgb(255 255 255 / 0.1); }
        .mcw-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .mcw-greeting { background: var(--primary-50); border-radius: 0.625rem; padding: 1rem 1.125rem; }
        .dark .mcw-greeting { background: rgb(255 255 255 / 0.05); }
        .mcw-field { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.75rem; font-weight: 500; }
        .mcw-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0.75rem; }
        .mcw-trial { display: flex; gap: 0.5rem; align-items: center; padding: 0.625rem 1rem; border-radius: 0.625rem; background: #FEF3C7; color: #78350F; font-size: 0.8125rem; }
        .mcw-result { display: block; width: 100%; text-align: left; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; background: transparent; border: 0; color: inherit; cursor: pointer; font-family: inherit; }
        .mcw-result:hover { background: var(--gray-100); }
        .dark .mcw-result:hover { background: rgb(255 255 255 / 0.05); }
    </style>

    @if ($trial)
        <div class="mcw-trial" role="note">
            <x-filament::icon icon="heroicon-o-beaker" style="width: 1.125rem; height: 1.125rem; flex: none;" />
            <span><strong>Trial desk.</strong> These calls are simulated: nothing rings, and a saved message stays on the trial call. Nothing reaches the client's inbox.</span>
        </div>
    @endif

    {{-- Pending calls --}}
    <section class="mcw-panel mcw-strip" aria-label="Calls on your desk">
        <div class="mcw-strip-head">
            <h2 class="mcw-h">Calls on your desk</h2>
            @php($waiting = $calls->filter(fn ($c) => $c->isWaiting() || $c->status === \App\Models\OperatorCall::STATUS_HELD)->count())
            <span class="mcw-muted" style="font-size: 0.8125rem;">
                @if ($calls->isEmpty())
                    None right now.
                @else
                    {{ $waiting }} waiting for you
                    @php($clients = $calls->pluck('team_id')->filter()->unique())
                    @if ($clients->count() === 1 && $calls->first()->team)
                        · all for <strong>{{ $calls->first()->team->name }}</strong>
                    @endif
                @endif
            </span>
            <span class="mcw-muted" style="margin-left: auto; font-size: 0.75rem;">Oldest first. Answering a call puts the connected one on hold.</span>
        </div>

        @if ($calls->isEmpty())
            <div class="mcw-muted" style="text-align: center; padding: 1.5rem 0; font-size: 0.875rem;">
                @if ($trial)
                    Start a trial with <strong>Load 3-call scenario</strong> or <strong>Simulate a call</strong> above.
                @else
                    A call that rings on your softphone opens here with its client loaded.
                @endif
            </div>
        @else
            <div class="mcw-cards">
                @foreach ($calls as $call)
                    <div
                        wire:key="card-{{ $call->id }}"
                        class="mcw-card is-{{ $call->status }} {{ $call->id === $selectedId ? 'is-selected' : '' }}"
                        wire:click="select({{ $call->id }})"
                    >
                        <div class="mcw-row">
                            <span class="mcw-badge {{ $call->status }}">{{ $call->statusLabel() }}</span>
                            <span class="mcw-muted" style="font-size: 0.75rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $call->line_label ?? $call->team?->name ?? 'Unknown line' }}</span>
                            <span
                                class="mcw-timer"
                                style="margin-left: auto; {{ $call->status === 'held' ? 'color: #C2410C;' : '' }}"
                                x-data="{ from: {{ $call->timerStartedAt()->timestamp }} }"
                                x-text="(() => { const s = Math.max(0, now - from); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); })()"
                            ></span>
                        </div>
                        <div class="mcw-row" style="gap: 0.75rem;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 0.9375rem; font-weight: 600;">{{ $call->caller_name ?: 'Unknown caller' }}</div>
                                <div class="mcw-muted" style="font-size: 0.8125rem;">
                                    {{ $call->caller_number ?? 'No number' }}@if ($call->called_number) → {{ $call->called_number }}@endif
                                </div>
                            </div>
                            @if ($call->isWaiting())
                                <x-filament::button color="success" icon="heroicon-m-phone" wire:click.stop="answer({{ $call->id }})">Answer</x-filament::button>
                            @elseif ($call->status === 'held')
                                <x-filament::button color="warning" outlined wire:click.stop="resume({{ $call->id }})">Resume</x-filament::button>
                            @elseif ($call->status === 'live')
                                <x-filament::button color="gray" wire:click.stop="hold({{ $call->id }})">Hold</x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    @if ($selected)
        {{-- One tab per call --}}
        <div>
            <div class="mcw-tabs" role="tablist" aria-label="Open calls">
                @foreach ($calls as $call)
                    <button
                        type="button"
                        role="tab"
                        class="mcw-tab"
                        wire:key="tab-{{ $call->id }}"
                        aria-selected="{{ $call->id === $selectedId ? 'true' : 'false' }}"
                        wire:click="select({{ $call->id }})"
                    >
                        <span class="mcw-dot {{ $call->status }}"></span>
                        <span style="font-weight: 600;">{{ $call->team?->name ?? 'Unidentified' }}</span>
                        <span class="mcw-muted">· {{ $call->caller_name ?: ($call->caller_number ?? 'Unknown') }}</span>
                    </button>
                @endforeach
            </div>

            <div class="mcw-panel mcw-body" wire:key="body-{{ $selected->id }}">
                <div class="mcw-main">
                    {{-- Heading --}}
                    <div class="mcw-row" style="align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 15rem;">
                            <div class="mcw-label mcw-muted">
                                @if ($selected->team?->account_number) Account #{{ $selected->team->account_number }} · @endif
                                {{ $selected->line_label ?? 'Line unknown' }}@if ($selected->called_number) · {{ $selected->called_number }}@endif
                            </div>
                            <h1 style="margin: 0.25rem 0 0; font-size: 1.375rem; font-weight: 700;">{{ $selected->team?->name ?? 'Which client is this call for?' }}</h1>
                            @if ($selected->team)
                                <div class="mcw-muted" style="font-size: 0.8125rem; margin-top: 0.25rem;">{{ $selected->team->displayTimezone() }}</div>
                            @endif
                        </div>
                        <div class="mcw-row" style="flex-wrap: wrap;">
                            @if ($selected->isWaiting())
                                <x-filament::button color="success" icon="heroicon-m-phone" wire:click="answer({{ $selected->id }})">Answer</x-filament::button>
                            @elseif ($selected->status === 'held')
                                <x-filament::button color="warning" outlined wire:click="resume({{ $selected->id }})">Resume</x-filament::button>
                            @elseif ($selected->status === 'live')
                                <x-filament::button color="gray" wire:click="hold({{ $selected->id }})">Hold</x-filament::button>
                            @endif
                            @if (! in_array($selected->status, ['ended', 'missed'], true))
                                <x-filament::button color="danger" outlined icon="heroicon-m-phone-x-mark" wire:click="hangUp({{ $selected->id }})">
                                    {{ $trial ? 'Caller hangs up' : 'Hang up' }}
                                </x-filament::button>
                            @endif
                            <x-filament::button color="gray" icon="heroicon-m-x-mark" wire:click="closeCall({{ $selected->id }})"
                                wire:confirm="{{ $selected->message_saved_at ? 'Close this call?' : 'Close this call without saving a message?' }}">
                                Close
                            </x-filament::button>
                        </div>
                    </div>

                    {{-- Client not known yet --}}
                    @unless ($selected->team)
                        <div class="mcw-box">
                            <label class="mcw-field" for="mcw-client-search">
                                <span class="mcw-muted">This call didn't say which number was dialled. Find the client:</span>
                            </label>
                            <x-filament::input.wrapper style="margin-top: 0.5rem;">
                                <x-filament::input id="mcw-client-search" type="search" wire:model.live.debounce.300ms="clientSearch" placeholder="Name, account number, or old account number" autocomplete="off" />
                            </x-filament::input.wrapper>
                            @if ($clientResults)
                                <div style="margin-top: 0.5rem; display: flex; flex-direction: column;">
                                    @foreach ($clientResults as $teamId => $label)
                                        <button type="button" class="mcw-result" wire:key="res-{{ $teamId }}" wire:click="attachClient({{ $teamId }})">{{ $label }}</button>
                                    @endforeach
                                </div>
                            @elseif (mb_strlen(trim($clientSearch)) >= 2)
                                <div class="mcw-muted" style="margin-top: 0.5rem; font-size: 0.8125rem;">No clients match.</div>
                            @endif
                        </div>
                    @endunless

                    {{-- Caller + this call --}}
                    <div class="mcw-grid2">
                        <div class="mcw-box">
                            <div class="mcw-label mcw-muted">Caller</div>
                            <div style="font-size: 1rem; font-weight: 600; margin-top: 0.375rem;">{{ $selected->caller_name ?: 'Unknown caller' }}</div>
                            <div class="mcw-muted" style="font-size: 0.8125rem; margin-top: 0.125rem;">{{ $selected->caller_number ?? 'No caller ID' }}</div>
                        </div>
                        <div class="mcw-box">
                            <div class="mcw-label mcw-muted">This call</div>
                            <div class="mcw-row" style="margin-top: 0.375rem;">
                                <span class="mcw-badge {{ $selected->status }}">{{ $selected->statusLabel() }}</span>
                                <span class="mcw-timer" x-data="{ from: {{ $selected->timerStartedAt()->timestamp }} }"
                                    x-text="(() => { const s = Math.max(0, now - from); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); })()"></span>
                            </div>
                            <div class="mcw-muted" style="font-size: 0.8125rem; margin-top: 0.375rem;">
                                Arrived {{ $selected->arrived_at->timezone(auth()->user()->displayTimezone())->format('g:i:s a') }}
                                @if ($selected->answered_at) · answered {{ $selected->answered_at->timezone(auth()->user()->displayTimezone())->format('g:i:s a') }} @endif
                            </div>
                        </div>
                    </div>

                    {{-- Greeting --}}
                    @if ($brief && $brief['greeting'])
                        <div class="mcw-greeting">
                            <div class="mcw-label" style="color: var(--primary-700);">Answer with</div>
                            <p style="margin: 0.5rem 0 0; font-size: 1rem; line-height: 1.5;">{{ $brief['greeting'] }}</p>
                        </div>
                    @endif

                    {{-- Message for this call only --}}
                    @if ($selected->team)
                        <form wire:submit="saveMessage" class="mcw-box" style="padding: 1.125rem; display: flex; flex-direction: column; gap: 0.875rem;" aria-label="Message for this call">
                            <div class="mcw-row" style="flex-wrap: wrap;">
                                <h3 class="mcw-h">Message</h3>
                                <span class="mcw-muted" style="font-size: 0.75rem;">Kept with this call. Other tabs keep their own drafts.</span>
                            </div>

                            @if ($selected->message_saved_at)
                                <div style="display: flex; gap: 0.5rem; align-items: center; font-size: 0.8125rem; color: var(--success-700);">
                                    <x-filament::icon icon="heroicon-m-check-circle" style="width: 1rem; height: 1rem;" />
                                    {{ $trial ? 'Trial message saved at' : 'Message saved at' }} {{ $selected->message_saved_at->timezone(auth()->user()->displayTimezone())->format('g:i a') }}.
                                    {{ $trial ? 'Nothing was sent to the client.' : '' }}
                                </div>
                            @endif

                            <div class="mcw-grid2">
                                <label class="mcw-field">
                                    <span class="mcw-muted">Caller name <span style="color: var(--danger-600);">*</span></span>
                                    <x-filament::input.wrapper :valid="! $errors->has('draft.caller_name')">
                                        <x-filament::input type="text" wire:model.blur="draft.caller_name" placeholder="Full name" />
                                    </x-filament::input.wrapper>
                                    @error('draft.caller_name') <span style="color: var(--danger-600);">{{ $message }}</span> @enderror
                                </label>
                                <label class="mcw-field">
                                    <span class="mcw-muted">Callback number</span>
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="tel" wire:model.blur="draft.caller_phone" placeholder="Callback number" />
                                    </x-filament::input.wrapper>
                                </label>
                            </div>

                            <label class="mcw-field">
                                <span class="mcw-muted">Message / reason for call <span style="color: var(--danger-600);">*</span></span>
                                <x-filament::input.wrapper :valid="! $errors->has('draft.reason')">
                                    <textarea
                                        wire:model.blur="draft.reason"
                                        rows="4"
                                        placeholder="What are they calling about?"
                                        style="width: 100%; box-sizing: border-box; resize: vertical; border: none; background: transparent; padding: 0.5rem 0.75rem; font-size: 0.875rem; font-family: inherit; color: inherit; outline: none;"
                                    ></textarea>
                                </x-filament::input.wrapper>
                                @error('draft.reason') <span style="color: var(--danger-600);">{{ $message }}</span> @enderror
                            </label>

                            <div class="mcw-row" style="flex-wrap: wrap; border-top: 1px solid var(--gray-100); padding-top: 0.875rem;">
                                <label class="mcw-row" style="font-size: 0.8125rem;">
                                    Urgency
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model.live="draft.urgency">
                                            <option value="normal">Normal</option>
                                            <option value="urgent">Urgent</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </label>
                                <span style="margin-left: auto;"></span>
                                @if ($keepsPartials)
                                    <x-filament::button type="button" color="warning" outlined icon="heroicon-o-phone-x-mark" wire:click="savePartialMessage">Save partial</x-filament::button>
                                @endif
                                <x-filament::button type="submit" color="success">{{ $selected->message_saved_at ? 'Save again' : 'Save message' }}</x-filament::button>
                            </div>
                        </form>
                    @endif
                </div>

                {{-- Side: what to know about this client --}}
                <aside class="mcw-side" aria-label="Client notes">
                    @if ($brief && ($brief['operator_note'] || $brief['passcode'] || $brief['aliases']))
                        <div class="mcw-box" style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.875rem;">
                            <h3 class="mcw-h">Call handling</h3>
                            @if ($brief['operator_note'])
                                <div><x-filament::badge color="warning" icon="heroicon-o-exclamation-triangle" size="lg">{{ $brief['operator_note'] }}</x-filament::badge></div>
                            @endif
                            @if ($brief['passcode'])
                                <div x-data="{ shown: false }" wire:key="pass-{{ $selected->id }}">
                                    <div class="mcw-muted" style="font-size: 0.75rem; font-weight: 500;">Passcode</div>
                                    <span x-show="! shown" style="font-family: monospace;">••••••</span>
                                    <span x-show="shown" x-cloak style="font-family: monospace; font-weight: 600;">{{ $brief['passcode'] }}</span>
                                    <x-filament::link tag="button" size="sm" x-on:click="shown = ! shown" style="margin-left: 0.5rem;">
                                        <span x-text="shown ? 'Hide' : 'Show'">Show</span>
                                    </x-filament::link>
                                </div>
                            @endif
                            @if ($brief['aliases'])
                                <div>
                                    <div class="mcw-muted" style="font-size: 0.75rem; font-weight: 500;">Also answers to account</div>
                                    <div>{{ collect($brief['aliases'])->map(fn ($n) => '#'.$n)->implode(', ') }}</div>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($selected->team)
                        <div class="mcw-box">
                            <h3 class="mcw-h" style="margin-bottom: 0.5rem;">Recent messages</h3>
                            @forelse ($recentMessages as $m)
                                <div wire:key="msg-{{ $m->id }}" style="padding: 0.5rem 0; border-top: 1px solid var(--gray-100); font-size: 0.8125rem;">
                                    <div class="mcw-row">
                                        <strong>{{ $m->caller_name ?: 'Unknown' }}</strong>
                                        @if ($m->urgency === 'urgent') <x-filament::badge color="danger" size="sm">Urgent</x-filament::badge> @endif
                                        @if ($m->is_partial) <x-filament::badge color="warning" size="sm">Incomplete</x-filament::badge> @endif
                                        <span class="mcw-muted" style="margin-left: auto; font-size: 0.75rem;">{{ $m->created_at->timezone(auth()->user()->displayTimezone())->format('M j, g:i a') }}</span>
                                    </div>
                                    <div class="mcw-muted" style="margin-top: 0.125rem;">{{ \Illuminate\Support\Str::limit($m->reason, 90) }}</div>
                                </div>
                            @empty
                                <div class="mcw-muted" style="font-size: 0.8125rem;">No messages for this client yet.</div>
                            @endforelse
                        </div>
                    @endif

                    @if ($brief && $brief['info_pages'])
                        <details class="mcw-box">
                            <summary class="mcw-h" style="cursor: pointer;">Info pages (Amtelco)</summary>
                            <pre style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.6875rem; line-height: 1.35; white-space: pre; overflow-x: auto; margin: 0.75rem 0 0;">{{ $brief['info_pages'] }}</pre>
                        </details>
                    @endif
                </aside>
            </div>
        </div>
    @endif
</div>
