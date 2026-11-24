# Plan 18: Dispatch list and operator follow-ups

**Status:** proposed · part of [IS parity](README.md) · depends on 05
(dispatch integration), 02 (script-created follow-ups); phase 1 depends
on nothing and can ship first · expands survey item **L** in
[07](07-additional-features.md)

## Problem

### What IS does

In Intelligent Series, a message that still needs work after the call
ends goes on the **Dispatch List** (IS "pending dispatch" / "message
dispatch" screens):

- The item belongs to the operator who took the call. It carries an
  **expiration** ("page Dr. Smith, if no callback in 15 minutes call
  the backup").
- As expiry nears the owning operator is **prompted** (pop-up, list
  row turns colour, audible cue). They can complete it, extend it, or
  pass it on.
- If the owner is logged out, not at their station, or ignores the
  prompt, the item **expires to a shared list** that supervisors and any
  free operator work.
- Operators see "my pending" items alongside their call screen and
  **hand them over** at shift end instead of leaving them orphaned.
- Operators can also add **manual follow-ups** that are not tied to an
  automatic dispatch: "call the plumber back if he hasn't confirmed by
  10:30", "re-page the on-call if no answer in 15 min".

### What Orbital has today (verified)

Nothing in the codebase represents pending work after a call:

- **No follow-up, task or dispatch model.** `app/Services/` has no
  `Dispatch` directory; `grep -ri follow_up app database/migrations`
  finds nothing. Plan 05's `dispatches` / `delivery_attempts` and its
  "dispatcher queue" are not built yet.
- **Messages are fire-and-forget.** `Message` (`app/Models/Message.php:29-37`)
  has only `new / read / archived` and `normal / urgent`. The
  single-call Workspace creates one and resets the form
  (`app/Filament/Operator/Pages/Workspace.php:183-204`); the multi-call
  desk does the same through `MultiCallDesk::saveMessage()`
  (`app/Services/Operator/MultiCallDesk.php:189-215`), then stamps
  `message_saved_at` on the `OperatorCall`. Nothing records that the
  operator still owes the message anything.
- **The originating operator is known.** `messages.created_by_user_id`
  (`Message.php:41`, migration `create_messages_table` line 16) and
  `operator_calls.user_id` (`app/Models/OperatorCall.php:46`) give us an
  owner to hang follow-ups on.
- **Catalogue entries exist only as prompt text.** `enter_dispatcher_queue`
  (`database/seeders/IntakeGoalLibrarySeeder.php:791`) and
  `schedule_callback` (`IntakeGoalLibrarySeeder.php:973`) are compiled to
  LLM hints (`app/Services/Flows/AgentFlowCompiler.php:585-587`); nothing
  executes them. `trigger_outbound_phone` has a `followup` purpose option
  (`IntakeGoalLibrarySeeder.php:1317`) and no runtime.
- **Presence is partial.**
  - Availability: `users.availability_status` + `availability_changed_at`
    (`app/Models/User.php:50-51`), vocabulary in `AvailabilityReason`
    with `blocks_voice` / `blocks_non_voice`
    (`app/Models/AvailabilityReason.php:67-94`), changed by
    `AvailabilitySelector::updatedStatus()`
    (`app/Livewire/AvailabilitySelector.php:48-99`), reset to `unavailable` on every login by
    `ResetAvailabilityOnLogin` (`app/Listeners/ResetAvailabilityOnLogin.php`).
  - Sign-out: the operator panel replaces the stock logout with a modal
    that writes a `UserLogoutEvent` (`app/Providers/Filament/OperatorPanelProvider.php:144-215`,
    model `app/Models/UserLogoutEvent.php`, reasons in `LogoutReason`).
  - **No "online" signal.** There is no `last_seen_at`, heartbeat or
    presence channel for users. Sessions are in the `sessions` table
    (`config/session.php:21` default `database`; `user_id`,
    `last_activity` in `create_users_table` lines 55-59), but a closed
    browser leaves the session row alive until `SESSION_LIFETIME`
    (120 min, `config/session.php:35`). Logging out does not reset
    availability, so an operator who signs out stays `available`
    until their next login.
- **Real-time plumbing exists.** Reverb + Echo are wired
  (`resources/js/bootstrap.js:13-45`, `.env.example:333`
  `BROADCAST_CONNECTION=reverb`, but `config/broadcasting.php:18`
  defaults to `null`). There is a private per-user channel pattern
  (`routes/channels.php` `operator.drain.{id}`,
  `app/Events/AsteriskDrainInitiated.php:28-41`) and a Livewire listener
  for it (`app/Livewire/AsteriskDrainNotice.php:45-52`). The operator
  panel has database notifications polled every 5 s
  (`OperatorPanelProvider.php:79-80`).
- **Polling surfaces.** `MessageInbox` polls 20 s and caches a nav badge
  15 s (`app/Filament/Operator/Pages/MessageInbox.php:72-94,164`); the
  multi-call desk polls 15 s
  (`resources/views/livewire/multi-call-workspace.blade.php:8`).
- **Claim races are already solved once.** `MessageThread::claimFor()` is
  a conditional UPDATE and the inbox handles losing the race
  (`MessageInbox.php:248-265`). Follow-ups reuse that pattern.
- **Audit pattern exists.** `ConversationActivity::log($subject, $action,
  $metadata, $user)` is a polymorphic, append-only activity log
  (`app/Models/ConversationActivity.php:63-79`). TODO.md "Operator
  activity tracking" (TODO.md:455-466) asks for exactly this kind of
  operator timeline.
- **Scheduler and queues.** Every scheduled task uses `everyMinute()` +
  `onOneServer()` (`routes/console.php:22-30`). Horizon runs `default`,
  `inbound-mail`, `inbound-messages` (`config/horizon.php:203-264`);
  plan 05 adds a high-priority `dispatch` queue.
- **Roles.** `operator` and a stub `supervisor` role (same permissions
  as operator) are team-less Spatie roles
  (`database/seeders/SuperAdminRoleSeeder.php:40-80`). The operator panel
  admits any team-less role (`User::canAccessPanel`, `User.php:251-259`).

## Goal

Every message or call that still needs operator action after the caller
hangs up becomes a **follow-up** with an owner and a deadline. The owner
sees it on the workspace, is prompted before it expires, and it moves to
a **shared dispatch list** automatically when it expires or the owner is
gone. Operators can add manual follow-ups, and hand theirs over at shift
end. Plan 05's dispatcher queue *is* this shared list — one worklist, not
two.

Non-goals: client-facing task management; scheduled outbound
automation (plan 19); message check-in / hold-for-pickup (plan 12).

## Design

### Relationship to plans 05 and 02

Plan 05 defines dispatch policies with tiers and says exhausted
dispatches "land in the **dispatcher queue**: an operator worklist".
This plan supplies that worklist's model and UI, and adds two things
plan 05 doesn't have:

1. **Owned items before exhaustion.** A policy tier may be
   `operator_assisted` (new tier flag): "operator pages the doctor and
   waits for the callback". When that tier starts, the dispatch engine
   creates a follow-up owned by the originating operator, expiring at
   the tier's `wait_minutes`. Ack on any channel (plan 05
   `acknowledgements`) closes it; expiry advances the tier as plan 05
   already describes **and** moves the follow-up to the shared list if
   the next tier is also operator work.
2. **Exhaustion.** When plan 05 marks a dispatch `exhausted`, it creates
   (or re-pools) a shared follow-up, priority from message urgency.
   This replaces plan 05's separate "dispatcher queue" table — plan 05
   needs only to call `FollowUpService::forDispatch()`.

Plan 02's script runner gets a `create_follow_up` step handler (plan 01
handler interface), and `schedule_callback` writes a callback follow-up
when the client wants operators to call back (rather than only storing
the window on the message).

### Data model

`follow_ups` (`BelongsToTeam`; the client the work is for):

| column | notes |
|---|---|
| `id`, `team_id` | FK teams, cascade delete |
| `kind` | `manual`, `callback`, `dispatch` (plan 05 tier), `dispatch_exhausted`, `script` |
| `message_id` | nullable FK messages, `nullOnDelete` |
| `dispatch_id` | nullable FK plan 05 `dispatches` (added in phase 3) |
| `operator_call_id` | nullable FK operator_calls — the desk call it came from |
| `call_log_id` | nullable FK call_logs |
| `title` | short line shown in lists ("Re-page Dr. Smith") |
| `instructions` | text: what to do, who to call, numbers |
| `callback_number` | nullable; click-to-dial via softphone |
| `priority` | `normal` / `urgent` (defaults from `messages.urgency`) |
| `pool` | `owner` or `shared` |
| `owner_user_id` | nullable FK users, `nullOnDelete`; null when `pool = shared` and unclaimed |
| `claimed_by_user_id` | nullable — who took it off the shared list |
| `created_by_user_id` / `created_by_persona_id` | operator or AI that created it |
| `status` | `open`, `done`, `cancelled` (escalation is `pool`, not a status) |
| `due_at` | when to act (prompt fires here); defaults to `expires_at` |
| `warn_at` | computed: `expires_at − warn_lead` (default 3 min, per-kind config) |
| `expires_at` | when it leaves the owner |
| `prompted_at`, `escalated_at` | idempotency stamps for the timer |
| `escalation_reason` | `expired`, `owner_offline`, `owner_signed_out`, `handover_released`, `handover_declined`, `manual`, `owner_removed` |
| `snooze_count` | extensions used; capped (default 3, urgent 1) |
| `outcome` | on close: `reached`, `left_voicemail`, `no_answer_escalated`, `acknowledged_elsewhere`, `not_needed`, `other` |
| `outcome_note` | text |
| `completed_at`, `completed_by_user_id` | |
| `lock_version` | int, bumped by every state change (conditional UPDATEs) |
| `timestamps` | |

Indexes: `(pool, status, expires_at)`, `(owner_user_id, status,
expires_at)`, `(team_id, status)`, `(message_id)`, partial unique
`(dispatch_id) where status = 'open'` so a dispatch never has two open
follow-ups.

`follow_up_handovers` (shift-end handovers; small):
`from_user_id`, `to_user_id` (nullable = released to shared),
`follow_up_ids` (JSON), `status` (`pending/accepted/declined/expired`),
`expires_at`, timestamps. Not tenant-scoped (an operator's handover
spans clients); each follow-up row remains `BelongsToTeam`.

`users.last_seen_at` (nullable timestamp) — see Presence.

Model `FollowUp`: `BelongsToTeam`, relations to message, owner,
claimer, operator call, call log; scopes `open()`, `mine(User)`,
`shared()`, `nearingExpiry()`; helpers `isOverdue()`, `timeLeft()`.

### Services (`app/Services/FollowUps/`)

- **`FollowUpService`** — all writes go through it.
  - `create(array $attrs, User|AgentPersona $creator)` — validates team,
    computes `warn_at`, refuses `expires_at` in the past (clamps to
    now + 1 min for system-created items), never creates for
    `OperatorCall.is_trial` calls (mirrors `MultiCallDesk::saveMessage`
    not writing messages for trials).
  - `forMessage(Message, preset)`, `forDispatch(Dispatch, tier)`,
    `forCallback(Message, window)`.
  - `complete(FollowUp, User, outcome, note)`, `cancel(...)`.
  - `snooze(FollowUp, User, minutes)` — respects `snooze_count` cap.
  - `escalate(FollowUp, reason, ?User $actor)` — sets `pool = shared`,
    keeps `owner_user_id` for history in the event metadata, clears it
    on the row.
  - `claim(FollowUp, User): bool` — conditional UPDATE
    `where pool='shared' and claimed_by_user_id is null and status='open'`,
    same race semantics as `MessageThread::claimFor()`. Claiming moves
    the item back to `pool = owner` with a fresh `expires_at`
    (claim TTL, default 10 min) so a claimed item can't be abandoned.
  - `reassign(FollowUp, User $to, User $by)` — supervisor action.
  - Every write: bump `lock_version`, write an audit event, broadcast.
- **`FollowUpQuery`** — read side for operator surfaces. Operator pages
  are cross-client, so it removes the `team` global scope explicitly
  (`withoutGlobalScope('team')`, as `MultiCallWorkspace.php:290` does for
  messages) and scopes by owner / permission instead. It must **not**
  use `BelongsToTeam::withoutTeamScope()`, which aborts for
  non-super-admins (`app/Models/Concerns/BelongsToTeam.php:61-67`).
  Portal reads keep the global scope.
- **`OperatorPresence`** — one answer to "is this operator here?":
  - `signedIn(User)`: a `sessions` row with that `user_id` whose
    `last_activity` is within `session.lifetime`, and no
    `UserLogoutEvent` after it.
  - `active(User)`: `last_seen_at` within `presence_timeout` (default
    90 s). `last_seen_at` is written at most every 30 s from the
    follow-up panel's poll / Echo heartbeat (cache-gated write, so a
    15 s poll is not a DB write each time).
  - `away(User)`: signed in and active, but current `AvailabilityReason`
    `blocksAllWork()` (`AvailabilityReason.php:91-94`).
  - Returns an enum `Present | Away | Idle | SignedOut`.

### Escalation rules

Evaluated by the timer (below) for each open owner-pool item:

| Owner state | Before `warn_at` | `warn_at` → `expires_at` | At `expires_at` |
|---|---|---|---|
| Present | nothing | prompt (toast + panel row amber + sound) once | escalate `expired` |
| Away (lunch, break) | nothing | prompt queued for return | escalate `expired` |
| Idle (tab closed / no heartbeat > 90 s) | after `idle_grace` (2 min; urgent: 0) escalate `owner_offline` | same | escalate |
| SignedOut | escalate `owner_signed_out` immediately | — | — |

Urgent items prompt at `warn_at` *and* at expiry−1 min. Escalation to
the shared list notifies holders of `follow_up.view_shared` (toast on
the shared channel; database notification for supervisors).

**Shared list exhaustion.** A shared item unclaimed for
`shared_timeout` (default 10 min, urgent 3 min) re-notifies supervisors
and raises a `follow_ups_shared_overdue` gauge for the existing
Telephony dashboard (pushgateway pattern from plan 05 "Metrics"). It
never disappears on its own.

### Jobs and timers

- **`FollowUpTimerJob`** (queue `dispatch` from plan 05; until 05
  ships, `default`): dispatched with `delay` to `warn_at` and to
  `expires_at` on create / snooze / claim. Carries `(id, lock_version,
  phase)`; a stale `lock_version` makes it a no-op, so snoozes don't
  need job cancellation.
- **`follow-ups:sweep`** scheduled `everyMinute()->onOneServer()
  ->withoutOverlapping()` in `routes/console.php` (same as the existing
  tasks at lines 22-30): catches anything whose `warn_at`/`expires_at`
  passed without a `prompted_at`/`escalated_at` stamp (worker died,
  Redis flushed) and runs the presence checks for `Idle`/`SignedOut`
  owners. Each transition is a conditional UPDATE on
  `(status, pool, lock_version)` → exactly-once under concurrent sweeper
  and job.
- **Sign-out hook.** The logout action
  (`OperatorPanelProvider.php:193-215`) dispatches a new
  `OperatorSignedOut` event after writing `UserLogoutEvent`. Listener
  `ReleaseFollowUpsOnSignOut` escalates anything the operator didn't
  explicitly hand over (see Handover).
- **Ack hook (phase 3).** Plan 05's acknowledgement recorder calls
  `FollowUpService::complete(..., outcome: acknowledged_elsewhere)` for
  the dispatch's open follow-up, and notifies whoever owns/claimed it
  ("Dr. Smith acknowledged by SMS — no call needed").

### Real-time UI

Broadcast event **`FollowUpChanged`** (`ShouldBroadcastNow`, like
`AsteriskDrainInitiated`; payload: id, team id, pool, owner id, status,
priority, `expires_at`, title — **no message body / PHI**):

- `PrivateChannel("operator.follow-ups.{userId}")` for the owner and
  claimer;
- `PrivateChannel("follow-ups.shared")` authorized in
  `routes/channels.php` for users with `follow_up.view_shared`.

Because `config/broadcasting.php:18` defaults to `null`, every
component also polls (30 s) — the same "Echo when present, poll
always" approach the panel already takes with notifications.

Components:

1. **`MyFollowUps` Livewire component** — "My pending follow-ups".
   - Single-call Workspace: first section of the right-hand column
     (`resources/views/filament/operator/pages/workspace.blade.php:107`),
     above "Call handling", and in the empty state beside "Recent"
     (blade 164-179) so an idle operator sees it too.
   - Multi-call desk: a strip under the call cards in
     `multi-call-workspace.blade.php`; selecting an item that belongs to
     a client not on the desk opens the client brief read-only.
   - Rows sorted by `expires_at`, countdown chip (green → amber at
     `warn_at` → red overdue), client name + account number, title,
     callback number (click-to-dial via `orbital-softphone-command`, as
     `MultiCallWorkspace.php:91` does), linked message preview.
   - Row actions: **Done** (outcome + note modal), **Extend** (5/10/15
     min, disabled at cap), **Send to shared list**, **Hand to…**
     (operator picker of present operators), **Open message**.
   - Listens with `getListeners()` exactly like
     `AsteriskDrainNotice.php:45-52`; each refresh touches
     `OperatorPresence::heartbeat()`.
2. **Expiry prompt** — sent as a Filament database notification
   (`->sendToDatabase()`; the bell polls every 5 s) **and** pushed via
   Echo for instant toast + optional audio cue. The notification has
   the same actions as the row (Done / Extend / Send to shared).
3. **Nav badge** — "Follow-ups (n)" on a new `FollowUps` operator page,
   cached 15 s per user like `MessageInbox::getNavigationBadge()`;
   badge colour `danger` when any is overdue.
4. **`DispatchList` page** (operator panel, `follow_up.view_shared`) —
   the shared list and plan 05's dispatcher queue: tabs *Shared*,
   *All open* (supervisor), *Done today*. Columns: priority, client,
   title, escalation reason, original owner, waiting since, expiry.
   Claim column like `MessageInbox.php:237-280`. Supervisor bulk
   actions: reassign, extend, cancel with note. Can later be embedded
   in the live call board (`docs/plans/live-call-monitoring.md` Phase 1).
5. **Add follow-up** action:
   - Workspace header and per-message row action
     (`Workspace.php:411-424`) — form with presets: "Call back in 15
     min if no answer", "Re-page on-call in 10 min", "Confirm at
     <time>", custom (due time in **client** timezone, shown alongside
     the operator's, since `Team::displayTimezone()` and
     `User::displayTimezone()` can differ).
   - Multi-call: "Add follow-up" button next to Save Message; when the
     message is saved later, the follow-up is linked to it.
   - Message save form gets an optional "Needs follow-up" toggle
     (preset + minutes) so the common case is one keystroke.

### Handover at shift end

- The logout modal (`OperatorPanelProvider.php:144-192`) gains a section
  when the operator owns open follow-ups: count, nearest expiry, and a
  choice — **Release all to shared list** (default), or **Hand to**
  a present operator. Handover creates a `follow_up_handovers` row;
  the recipient gets a prompt with Accept / Decline; unanswered after
  2 min (or on decline) the items go to shared with reason
  `handover_declined`. Sign-out proceeds immediately either way — the
  operator is never trapped by the modal (same principle as the
  existing empty-reasons fallback in that action).
- A **"Hand over"** button on `MyFollowUps` does the same mid-shift
  (going to lunch).
- Session expiry / closed browser is covered by the Idle/SignedOut
  rules — no handover modal needed for an item to be safe.

### Permissions and tenant isolation

New Spatie permissions (team-less, added to `SuperAdminRoleSeeder`):

| Permission | operator | supervisor |
|---|---|---|
| `follow_up.create` | ✓ | ✓ |
| `follow_up.view_own` | ✓ | ✓ |
| `follow_up.view_shared` | ✓ (config: `orbital.follow_ups.operators_see_shared`, default on) | ✓ |
| `follow_up.claim` | ✓ | ✓ |
| `follow_up.reassign` / `follow_up.cancel_any` / `follow_up.view_all` | — | ✓ |

This is the first real differentiation of the stub supervisor role.

Isolation rules:

- `follow_ups.team_id` is always set from the message / call / dispatch,
  never from the user's current team (operators are platform staff;
  `BelongsToTeam`'s `creating` hook would otherwise fill it from
  `currentTeam` — `BelongsToTeam.php:38-47`). The service sets it
  explicitly and rejects a `message_id` whose team differs.
- Operator reads go through `FollowUpQuery` (owner or permission
  based). Portal users only ever reach follow-ups through the message's
  plan 05 timeline, which shows "Operator following up" with no
  operator name and no internal notes; follow-ups are not otherwise
  visible to clients.
- Broadcast payloads carry no message content; the component re-reads
  through `FollowUpQuery`.
- Policy class `FollowUpPolicy` enforces owner/claimer for Done/Extend/
  Hand over, `reassign` for supervisors; server re-checks every
  Livewire action (UI visibility is convenience only, as the Workspace
  partial-save comment states at `Workspace.php:226-228`).

### Audit

Every transition writes `ConversationActivity::log($followUp, $action,
$metadata, $user)` — actions `created`, `prompted`, `snoozed`,
`escalated` (metadata: reason, previous owner, presence state),
`claimed`, `reassigned`, `handed_over`, `handover_accepted`,
`handover_declined`, `completed`, `cancelled`, `auto_closed_by_ack`.
Reusing the polymorphic log satisfies part of TODO.md:455-466 and gives
supervisors a per-operator timeline for free. When plan 01's
`script_events` exist, follow-ups created from a script also write a
`follow_up_created` script event; when plan 05 exists, the message
timeline shows follow-up events beside delivery attempts.

## Edge cases

- **Owner on a live call at expiry.** Still escalates at `expires_at`
  (the doctor's patient can't wait on a long call), but the prompt
  fires at `warn_at` regardless of call state and the item stays in
  the owner's panel marked "moved to shared" until someone claims it,
  so the owner can still claim it back.
- **Ack arrives after escalation/claim.** Auto-close with
  `acknowledged_elsewhere`; the claimer gets a toast so they don't
  phone a doctor who already answered.
- **Two operators claim at once.** Conditional UPDATE; loser sees
  "Already claimed" (same UX as `MessageInbox.php:255-264`).
- **Snooze and timer race.** Snooze bumps `lock_version`; the in-flight
  `FollowUpTimerJob` no-ops; sweeper uses the same guard.
- **Expiry already in the past** (backdated manual item, long-running
  script): clamp to now + 1 min and prompt immediately.
- **Owner deleted / role removed.** `owner_user_id` nullOnDelete →
  sweeper finds `pool = owner` with null owner → escalate
  `owner_removed`.
- **Message deleted / client removed.** `message_id` nullOnDelete keeps
  the task; team delete cascades (consistent with `messages.team_id`
  cascade).
- **Trial calls** (`OperatorCall.is_trial`, `MultiCallDesk::startTrialScenario`)
  never create follow-ups; the trial desk shows a fake one locally.
- **AI-created follow-ups** (script step on an AI call) have no human
  owner: created straight into the shared pool with
  `escalation_reason = null`, `created_by_persona_id` set.
- **Plan 05 "held" messages** (waiting for business hours) don't create
  operator follow-ups until released.
- **Client quiet hours** — a callback follow-up due outside the client's
  allowed calling window (plan 06 schedule) shows a warning, not a block;
  operator judgement wins, and the event is audited.
- **DST / timezones.** All timestamps UTC; display in operator timezone
  with the client's local time beside it; presets are relative minutes
  so DST changes don't move them.
- **Away operator returns.** Queued prompts for items still owned fire
  once on return (`availability-updated` Livewire event, already
  dispatched by `AvailabilitySelector`).
- **Nobody holds `view_shared` and online.** Supervisor notification
  falls back to a database notification + metric; open question whether
  to page a platform on-call supervisor through plan 05.
- **Multiple tabs.** Heartbeat from any tab counts; prompts dedupe by
  `prompted_at`.
- **Flood** (e.g. paging provider down → every dispatch exhausts).
  Shared list groups by client and by dispatch failure reason; one
  supervisor alert per 5 min, not per item.

## Migration / rollout

1. **Phase 1 — manual follow-ups (no dependencies, ~1.5 wk).**
   Migrations (`follow_ups`, `follow_up_handovers`, `users.last_seen_at`),
   model, `FollowUpService`, `FollowUpQuery`, `OperatorPresence`, timer
   job + sweeper, `MyFollowUps` on both workspace layouts, `DispatchList`
   page, Add-follow-up action and message-save toggle, permissions,
   `FollowUpChanged` + channels. Behind
   `orbital.operator.follow_ups` (config + Platform Settings, as
   `multi_call_workspace` is at `config/orbital.php:203-209`).
2. **Phase 2 — presence and handover (~0.5–1 wk).** Idle / SignedOut
   escalation, logout-modal handover, `OperatorSignedOut` event; also
   fix sign-out leaving availability `available` (reset + resync queue
   members on logout — see "bugs noticed" below).
3. **Phase 3 — plan 05 integration (~0.5 wk after 05).**
   `operator_assisted` tier flag, `forDispatch()`, exhausted → shared,
   ack auto-close, timeline entries. Plan 05's "dispatcher queue UI"
   estimate shrinks accordingly — it is the `DispatchList` page.
4. **Phase 4 — scripts (~0.5 wk after 01/02).** `create_follow_up`
   handler, `schedule_callback` writing callback follow-ups, catalogue
   entry in `IntakeGoalLibrarySeeder`.

No backfill: existing messages have no pending work recorded.
Disabling the flag hides the UI; the sweeper keeps escalating anything
already open so nothing is stranded.

## Tests

Feature tests in `tests/Feature/FollowUps/`, using `travel()` and
`Queue::fake()` / real sync queue as noted; operators built with
`makeUserWithTeamlessRole()` (`tests/Concerns/CreatesOrbitalUsers.php:51`).

**Lifecycle and timers**
- Operator saves a message with "Call back in 15 min" → follow-up owned
  by them, `expires_at` = +15, `warn_at` = +12, linked to the message.
- `travel(12)->minutes()` → exactly one prompt notification; second
  sweep sends none.
- `travel(15)` with owner present → escalated `expired`, `pool = shared`,
  owner cleared, activity row with previous owner.
- Snooze at minute 11 → old timer job no-ops (stale `lock_version`),
  new expiry honoured; fourth snooze refused (cap 3), urgent cap 1.
- Sweeper and timer job run concurrently on the same item → one
  `escalated` event.
- Worker "dies": no timer job ever runs → sweeper escalates within one
  minute of expiry.

**Presence**
- Owner signs out via the logout modal choosing "release" → all their
  items shared with `owner_signed_out`; `UserLogoutEvent` written.
- Owner's `last_seen_at` 3 min stale → `owner_offline` before expiry;
  urgent item escalates with zero grace.
- Owner on "Lunch" (blocks all work) → no early escalation; escalates at
  expiry; queued prompt fires on return.
- Session row expired with no logout event → treated as SignedOut.

**Handover**
- Hand to present operator B, B accepts → owner = B, timers keep
  original expiry, `handed_over` + `handover_accepted` events.
- B declines / ignores 2 min → items shared `handover_declined`.
- Cannot hand to a signed-out or idle operator (picker excludes, server
  rejects).

**Shared list**
- Two operators claim the same item in parallel → one wins; loser gets
  "Already claimed".
- Claimed item gets claim TTL; claimer goes idle → back to shared.
- Supervisor reassigns; operator without `follow_up.reassign` gets 403
  on the Livewire action.
- Shared item unclaimed past `shared_timeout` → supervisor notification
  + metric; one alert per window under a 50-item flood.

**Dispatch integration (phase 3, with plan 05 fake transports)**
- `operator_assisted` tier starts → owned follow-up; SMS ack "1" →
  follow-up closed `acknowledged_elsewhere`, claimer toast.
- No ack through all tiers → exactly one shared `dispatch_exhausted`
  follow-up (partial unique index holds under retry).
- Held routine message → no follow-up until the schedule boundary.

**Tenant isolation**
- Follow-up created by an operator whose `current_team_id` is some
  other team still gets the message's `team_id`.
- `message_id` from client B with `team_id` of client A → rejected.
- Portal user of client A cannot load client B's follow-ups by id, and
  sees no operator name or internal note on their own.
- `follow-ups.shared` channel auth refuses a user without
  `view_shared`; broadcast payload contains no `reason` / message text.

**UI (Livewire)**
- `MyFollowUps` renders only the actor's items, ordered by expiry,
  amber after `warn_at`, red when overdue.
- Click-to-dial dispatches `orbital-softphone-command` with the number.
- Multi-call desk: follow-up added on call 1 before the message is
  saved links to that message on save, not to call 2's.
- Trial desk never writes a `follow_ups` row.

**Scripts (phase 4)**
- Fixture orchestration with `create_follow_up` (15 min) run through the
  engine → follow-up with title/instructions from template; AI run of
  the same fixture → shared pool, `created_by_persona_id` set.

## Open questions

1. Default expirations and warn leads per kind — does NCI have IS
   values to copy (the IS dispatch list timers per account)?
2. Should regular operators see and claim the shared list, or only
   supervisors (IS lets sites choose)? Plan assumes a config switch,
   default on.
3. Is "Away" (lunch, break) treated like Present (current design) or
   Idle for urgent items?
4. Final fallback when nobody works the shared list (overnight single
   operator): page a platform on-call supervisor via plan 05 with a
   platform-level dispatch policy?
5. Do clients want to see "operator following up" on the portal, or is
   it internal only?
6. Audit sink: reuse `ConversationActivity` (proposed) or start the
   dedicated `operator_events` table TODO.md:455-466 mentions, and
   migrate both?
7. Audible prompt: browser audio needs a user gesture; is a visual
   prompt + bell enough for NCI floor staff?

## Size estimate

~3 weeks total: phase 1 ≈ 1.5 wk, phase 2 ≈ 0.5–1 wk, phase 3 ≈ 0.5 wk
(after plan 05; offsets 0.5 wk of plan 05's dispatcher-queue UI),
phase 4 ≈ 0.5 wk (after plans 01/02).

## Bugs noticed while surveying

- **Logout note is discarded.** The operator logout modal collects a
  `note` (`OperatorPanelProvider.php:174,187`) but `UserLogoutEvent::create`
  (line 197) doesn't save it and `user_logout_events` has no column for it.
- **Signing out leaves the operator `available`.** Only login resets
  availability (`ResetAvailabilityOnLogin`); the logout action doesn't
  reset it or call `QueueMemberSyncer::syncForOperator()`, so the
  operator's queue membership can stay unpaused while they're signed out.
- **Message inbox badge not busted on availability change.**
  `AvailabilitySelector` forgets only `email_inbox_badge`
  (`AvailabilitySelector.php:97`), not `message_inbox_badge`, unless the
  Message Inbox page itself is open.
- **Stale docblocks.** `AvailabilityReason` and `AvailabilitySelector`
  refer to a `blocks_new_work` column that doesn't exist (fields are
  `blocks_voice` / `blocks_non_voice`).
- **Possible scope bug in the single-call Workspace.** Its messages table
  uses `Message::query()` with the `BelongsToTeam` global scope on
  (`Workspace.php:359`). For a non-super-admin operator who has a
  `currentTeam`, the scope adds `team_id = currentTeam` and hides the
  loaded client's messages. The multi-call desk avoids this with
  `withoutGlobalScopes()` (`MultiCallWorkspace.php:290`). Worth a test
  with a plain `operator` that belongs to a team.
