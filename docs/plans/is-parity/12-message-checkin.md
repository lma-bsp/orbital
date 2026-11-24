# Plan 12: Message check-in (hold for pickup)

**Status:** proposed · part of [IS parity](README.md) · expands survey
item E in [07](07-additional-features.md) · depends on [03](03-message-model.md)
(status lifecycle, voice template, portal list) and
[05](05-delivery-and-escalation.md) (dispatch, attempts, acknowledgements,
dispatcher queue) · uses [04](04-on-call-scheduling.md) (contacts),
[06](06-schedules-and-routing.md) (when holding applies) and
[01](01-script-runtime.md) (AI check-in tools)

## Problem

### What IS does

Many answering-service clients don't want messages pushed to them. They
want them **held** and collected when it suits them: a small office
calls the service at 9am ("any messages for Dr. Smith?"), a property
manager checks in after lunch, a contractor rings between jobs. In IS
this is an ordinary delivery choice on the account: messages are marked
held/undelivered, the client calls a check-in line, the operator
verifies the caller and reads each undelivered message, and marking
them delivered as they are read is the delivery record. Clients that
use the web portal collect them there instead. Undelivered messages
that sit too long get a supervisor's attention.

NCI runs this today: the Amtelco account export includes in-house
"CHECK IN LINE" accounts, which the importer deliberately flags for a
person to decide on (`app/Services/Import/Amtelco/AccountFilter.php:74-80`).

### What Orbital has today (verified)

- **No delivery state on messages.** `Message` has only
  `new` / `read` / `archived` (`app/Models/Message.php:29-33`), with
  no delivered/held timestamps; the table is
  `team_id, …, status, urgency, notes` with one index on
  `(team_id, status, created_at)`
  (`database/migrations/2026_04_17_060000_create_messages_table.php:13-30`).
  Plan 03 widens the lifecycle and gives `held` the meaning "waiting for
  a dispatch window"; plan 05 adds dispatches, attempts and
  acknowledgements. Neither defines a check-in method — survey item E
  names `hold_for_checkin` but nothing specifies it.
- **Nothing is dispatched.** Saving a message notifies no one (plan 05
  §Problem). The operator saves directly with `Message::create`
  (`app/Filament/Operator/Pages/Workspace.php:183-204`,
  `app/Services/Operator/MultiCallDesk.php:189-215`) — no hook where a
  hold could be created.
- **A client passcode exists, for operators only.** `teams.passcode`
  is encrypted, *not hashed*, because operators read it back
  (`app/Models/Team.php:76-80`; migration
  `database/migrations/2026_11_24_100000_add_call_handling_fields_to_teams.php:22-25`).
  Admins set it on the client form (`app/Filament/Resources/ClientResource.php:179-184`),
  the Amtelco import fills it (`AmtelcoImporter.php:170,436`), and
  `ClientCallBrief` serves it per render so it stays out of Livewire
  snapshots (`app/Services/Operator/ClientCallBrief.php:11-19,37`).
  Both workspaces show it behind a reveal toggle
  (`resources/views/filament/operator/pages/workspace.blade.php:125-133`,
  `resources/views/livewire/multi-call-workspace.blade.php:316-320`).
  There is no per-person PIN and no machine-verifiable secret. The
  passcode can't be used for IVR/AI verification as is, because it has
  to stay readable for operators.
- **Operator surfaces list messages but can't deliver them.** The
  single-account Workspace table lists the client's messages with
  *Mark Read* / *Archive* only (`Workspace.php:353-445`, actions at
  `:411-424`). The multi-call desk shows the last five messages
  (`app/Livewire/MultiCallWorkspace.php:289-290`). Neither has a
  "client checking in" mode. `OperatorCall` statuses use `held` for
  *call on hold* (`app/Models/OperatorCall.php:29`), so this plan
  avoids "held" for messages in operator UI copy (see Design).
- **No check-in routing.** DID destinations are `extension`, `queue`,
  `voicemail` and `orchestration` only
  (`app/Services/Telephony/ClientDidRouting.php:20-22`). The trunk
  dispatcher switches on those (`resources/views/asterisk/from-trunk.blade.php:36-79`).
  There is no `Read()`, `VoiceMailMain` or `Authenticate` anywhere in
  the generated dialplan, and no ARI client in `app/Services/Telephony/`.
  Voicemail is deposit-only: `voicemail.conf` mailboxes exist
  (`resources/views/asterisk/voicemail.blade.php:54-61`) but recordings
  are only transcribed and emailed (`app/Models/Voicemail.php:12-24`).
- **Reusable pieces.** A TTS-to-WAV renderer for Asterisk prompts
  (`app/Services/Telephony/VoicemailGreetingRenderer.php:47,131,169`).
  The worker reports caller number and dialled DID at call start
  (`agent-worker/agent.py:234-267`). Cross-tenant DID lookup is already
  done deliberately and narrowly (`MultiCallDesk.php:462-483`, with
  `withoutGlobalScopes()`). Race-safe claims use a conditional UPDATE
  (`app/Models/MessageThread.php:123-140`). There is a polymorphic
  activity log (`app/Models/ConversationActivity.php:45-72`). Scheduler
  jobs use `onOneServer()` (`routes/console.php:22-30`). `PhoneNumber::toE164`
  (`app/Support/PhoneNumber.php:18`) normalises numbers.
- **Step catalogue only.** `verify_caller` (`database/seeders/IntakeGoalLibrarySeeder.php:401-419`)
  and `mark_message_delivered` (`:583-596`) exist as prompt-only
  catalogue entries with no executor (README feature map).
- **Portal.** `portal.view_messages` is granted by default
  (`app/Services/Clients/ClientProvisioner.php:52,67`; described at
  `app/Filament/Portal/Resources/RoleResource.php:235`), but the portal
  has no message page. Its pages are `Dashboard`, `CallHistory` and
  `TextConversations` (`app/Filament/Portal/Pages/`). Portal queries
  filter on `current_team_id` explicitly rather than trusting the
  global scope (`CallHistory.php:21-26,49`). This plan follows that.

## Goal

A client (or one of its staff) can choose to have messages **wait for
pickup**. They collect them in one of three ways:

1. **By phone with an operator.** They call a check-in number, the
   operator verifies them, reads each waiting message, and marks each
   one delivered as it is read.
2. **By phone with the AI.** Caller ID plus PIN, then the message text
   is read word for word by TTS, with keypress or spoken confirmation.
3. **In the portal.** Opening a message counts as pickup, and an
   explicit "Got it" counts as acknowledgement.

Every pickup is a plan 05 delivery attempt and acknowledgement on the
message's timeline. Messages that wait too long trigger reminders and,
if configured, switch to normal push delivery. Urgent messages follow
a per-mailbox rule that by default **does not** let them sit unseen.

## Design

### Vocabulary

- **Mailbox:** where messages wait. Each client gets a default mailbox,
  and can add one per contact (e.g. "Dr. Smith", "Maintenance").
- **Pickup:** the message was presented to a verified recipient. This
  is plan 05 attempt status `delivered`.
- **Acknowledge:** the recipient confirmed it. This is a plan 05
  acknowledgement. Phone check-in records both at once, because the
  operator or IVR reads the message and the caller confirms it.
- UI copy says "waiting for check-in", never "held", so it doesn't
  clash with call hold (`OperatorCall::STATUS_HELD`).

### Data model (all `BelongsToTeam`, all `declare(strict_types=1)`)

**`message_mailboxes`**

| column | notes |
|---|---|
| `team_id` | |
| `name` | "Office", "Dr. Smith" |
| `directory_entry_id` | nullable. Per-contact mailbox, linked to the Directory person (plan 04 contacts). |
| `is_default` | one per team (partial unique index `where is_default`) |
| `schedule_id` | nullable, plan 06. Holding applies only while this schedule is *active*. Example: hold during office hours for check-in, push after hours. Null means always hold. |
| `urgent_handling` | `push` (default), `hold_and_notify`, `hold`. See Urgent messages. |
| `remind_after_minutes`, `remind_every_minutes`, `max_reminders` | ageing reminders, nullable = off |
| `reminder_policy_id` | nullable, plan 05 `dispatch_policies`. Who gets the "you have N messages waiting" nudge, and how. |
| `release_after_minutes` | nullable. After this long unpicked, stop waiting and run the push policy. |
| `release_policy_id` | plan 05 policy used on release |
| `portal_open_counts_as_pickup` | bool, default true |
| `notify_on_arrival` | bool. Send a content-free "new message waiting" nudge. |
| `readout_order` | `urgent_first_then_oldest` (default) or `oldest_first` |
| `is_active` | |

**`checkin_identities`:** who may check in, and how they prove it.

| column | notes |
|---|---|
| `team_id`, `label` | "Front desk cell" |
| `phone_e164` | nullable, via `PhoneNumber::toE164`. Indexed, **not unique across teams**: one office manager can check in for several clients. |
| `pin_hash` | nullable, `Hash::make`. Never readable. Separate from `teams.passcode`, which has to stay readable for operators. |
| `mailbox_ids` | via pivot `checkin_identity_mailbox`. Empty means all of the team's mailboxes. |
| `directory_entry_id` | nullable |
| `user_id` | nullable. Links a portal user so portal pickups name the same person. |
| `failed_attempts`, `locked_until`, `last_checkin_at` | lockout |
| `is_active` | |

**`message_pickups`:** one row per message per mailbox it waits in.
This is the check-in side of a plan 05 `delivery_attempts` row
(method `checkin`).

| column | notes |
|---|---|
| `team_id`, `message_id`, `mailbox_id` | unique `(message_id, mailbox_id)` |
| `dispatch_id`, `delivery_attempt_id` | plan 05 links |
| `state` | `waiting` → `presented` → `picked_up`, or `released` (moved to push) / `superseded` (acknowledged elsewhere) / `cancelled` (message filed or closed) |
| `waiting_since` | |
| `presented_at` | read aloud or opened, but not yet confirmed |
| `picked_up_at`, `picked_up_via` | `operator`, `ai`, `portal`, `supervisor_override` |
| `picked_up_by_user_id` | operator or portal user |
| `checkin_session_id` | |
| `reminder_count`, `last_reminded_at`, `released_at` | |
| `revision_at_pickup` | plan 03 revision number read out, so a later amendment is detectable |

Index `(team_id, mailbox_id, state, waiting_since)` for the check-in
list and the ageing sweep.

**`checkin_sessions`:** one per check-in attempt.

- `team_id`: nullable until identified. The scope only applies when
  set. Unidentified failures go to `checkin_attempts` below.
- `channel` (`operator`, `ai`, `ivr`, `portal`), `caller_number`,
  `called_number`.
- `call_log_id`, `operator_call_id`, `user_id` (the operator).
- `checkin_identity_id`, `verified_by` (`caller_id_pin`, `pin_only`,
  `passcode_operator`, `operator_judgement`, `portal_login`).
- `started_at`, `verified_at`, `ended_at`, `presented_count`,
  `picked_up_count`.

**`checkin_attempts`:** platform-level and **not** `BelongsToTeam`,
because the caller is not yet tied to a client. It holds caller number,
called number, outcome (`no_identity`, `bad_pin`, `locked`, `ambiguous`)
and timestamp. It is used for brute-force detection and supervisor
review. It stores no message data.

**`message_checkin_settings` on teams:** none. Everything lives on the
mailbox, so a team without a mailbox row behaves exactly as today.

### How a message comes to wait (plan 05 integration)

- Plan 05 gets a transport `CheckinTransport` (method `checkin`,
  `app/Services/Dispatch/Transports/CheckinTransport.php`). It sends
  nothing. `send()` creates or refreshes the `message_pickups` row and
  returns `AttemptResult::waiting()`. A new attempt status, `waiting`,
  is added to plan 05's list: it is not a failure, and it doesn't
  advance the tier timer by itself.
- A dispatch policy tier can be **"Hold for check-in"**: recipients are
  mailboxes, `require_ack` is true, and `wait_minutes` is the
  mailbox's `release_after_minutes`. When the tier times out, the
  policy escalates to the next tier, so "release to push" is ordinary
  plan 05 escalation and not a separate mechanism.
- Selection is `CheckinRouter::mailboxesFor(Message)`:
  1. A script step `hold_for_checkin(mailbox)` (plan 01) names one
     explicitly.
  2. Otherwise the message is addressed to a directory entry that has
     a mailbox (plan 04 recipient resolution).
  3. Otherwise the team's default mailbox.
  4. Holding is then **gated by the mailbox schedule** (plan 06). If
     the schedule is inactive, the tier is skipped and the next tier
     (push) runs.
- Plan 03's `held` status (waiting for a dispatch window) is
  unchanged. A message waiting for check-in carries plan 03 status
  `dispatched`, and its dispatch has an open `waiting` attempt.
  `Message::scopeAwaitingPickup()` joins `message_pickups` where
  `state in (waiting, presented)`.
- Notification on arrival (optional): a content-free SMS or email
  ("You have 2 new messages at NCI, call 555-0100 to collect"), sent
  through the mailbox's `reminder_policy_id`. **No message content in
  nudges.** That keeps PHI out of SMS, matching the plan 03 redaction
  flags.

### Urgent messages

`urgent_handling` on the mailbox decides what happens:

| value | behaviour |
|---|---|
| `push` (default) | Urgent messages skip the check-in tier entirely and run the urgent policy (plan 05 tiers). A pickup row is still created, state `waiting`, so the client sees it on their next check-in. Any ack, by any channel, marks it `superseded`. |
| `hold_and_notify` | Waits, but sends an immediate nudge ("an URGENT message is waiting"). Uses a short `remind_after` (default 10 min) and a short release (default 30 min), then falls through to the urgent push tiers. |
| `hold` | Truly waits. The admin UI requires a confirmation and records who accepted it (audit). The ageing sweep still escalates to the **dispatcher queue** (plan 05) once `release_after_minutes` passes. |

Interplay rules:

- **Acknowledged anywhere means picked up everywhere.** When plan 05
  records an ack (SMS reply, portal button, voice callout keypress),
  `PickupService::supersede($message)` sets every open pickup row to
  `superseded`. A check-in afterwards still *lists* it under "already
  acknowledged by X at 14:02", so the operator can mention it, but
  doesn't count it as waiting.
- **Picked up stops escalation.** A phone check-in that reads an
  urgent message which is mid-escalation records the ack and cancels
  the remaining tier timers (plan 05's ack path). On-call staff then
  stop getting pages for something the office already has.
- **A pickup is not an ack while `presented`.** Escalation timers keep
  running until `picked_up`. A caller who hangs up halfway through a
  readout doesn't silence a page.

### Services (`app/Services/Checkin/`)

- **`CheckinIdentifier`** maps caller number (+ optional account
  number) to identity candidates. It is the **only** place that reads
  `checkin_identities` across tenants, using
  `withoutGlobalScope('team')` exactly as `MultiCallDesk::didFor` does
  (`MultiCallDesk.php:477-483`). It returns ids and team names only,
  never message data. It resolves account numbers through
  `ClientSearch` (`app/Services/Operator/ClientSearch.php`) so
  aliases work.
- **`PinVerifier`** does `Hash::check` plus a lockout: 5 failures lock
  the identity for 15 minutes, and per-caller-number throttling uses
  `RateLimiter` (`checkin:{e164}`, 10 per hour). Every outcome is
  written to `checkin_sessions` or `checkin_attempts`. It never says
  *which* part failed. Timing is constant: unknown identities are
  hashed against a dummy hash.
- **`PickupService`**:
  - `waitingFor(Team, ?Collection $mailboxes)` returns the ordered
    list.
  - `present(pickup, session)`.
  - `pickUp(pickup, session, via, ?User)` is idempotent. It runs a
    conditional UPDATE `where state in (waiting, presented)`, like
    `MessageThread::claimFor`, so two operators or the portal and the
    phone racing produce one pickup and one ack. It then calls plan
    05's `Acknowledgements::record(message, how: 'checkin_phone' |
    'checkin_ai' | 'portal', who)`.
  - `supersede(message)`, `release(pickup)`, `cancel(message)`.
- **`CheckinReadout`** renders the plan 03 **voice read-out template**
  for a message. Messages with no template use the built-in default.
  It adds the header "Message 2 of 5, urgent, taken Tuesday at 3:42pm",
  with the time in the client's zone via `Team::displayTimezone()`
  (`Team.php:268`). The operator panel and TTS use the same text, so
  what is read is identical on both paths.
- **`CheckinSessionManager`** handles start, verify and end. It owns
  the session row and emits timeline events.

### Jobs and scheduling

- **`SweepWaitingMessagesJob`** runs every 5 minutes in
  `routes/console.php` with `->onOneServer()->withoutOverlapping()`.
  Per mailbox with open pickups:
  - oldest `waiting_since + remind_after` passed and reminders left:
    send the reminder through `reminder_policy_id`, then bump
    `reminder_count` and `last_reminded_at`;
  - `release_after` passed: hand off to plan 05's tier escalation
    (idempotent, via the dispatch sweeper), mark `released`;
  - reminders respect quiet hours via the mailbox schedule (plan 06).
    Routine reminders don't go out at 3am, but urgent ones do.
- **`SendCheckinNudgeJob`**, on the `dispatch` queue, is a thin wrapper
  that renders the content-free nudge and goes through plan 05
  transports.
- **Daily summary hook** for plan 10: "3 messages still waiting for
  check-in" in the client's daily summary.
- Metrics (existing pushgateway collector): waiting count and oldest
  waiting age per team, and pickups by channel.

### Telephony: the check-in number

- **New DID destination `checkin`**, added to
  `ClientDidRouting::DESTINATION_TYPES` (`ClientDidRouting.php:22`).
  The DID usually belongs to the platform operator's own in-house team
  (NCI's "CHECK IN LINE"). Per-client check-in DIDs also work and
  pre-identify the team. `destination_id` points at a
  **`checkin_lines`** row (`BelongsToTeam`): `mode` (`operator`, `ai`,
  `operator_then_ai`), `queue_id` (operator mode), `orchestration_id`
  (AI mode), `max_pin_attempts`, `greeting`.
- **Dialplan** gets a new `@case('checkin')` in `from-trunk.blade.php`
  (beside `@case('voicemail')` at `:54`):
  - `Set(__ORBITAL_CHECKIN={{ line id }})` so a SIP header
    `X-Orbital-Checkin` is added on the way to the operator or LiveKit.
    This follows the `orbital-ai-headers` / `X-Orbital-DID` pattern
    (`agent.py:247-255`).
  - operator mode: `Goto(tenant ctx, queue-{id}, 1)` to the check-in
    queue;
  - AI mode: `Dial(PJSIP/{AiCallTarget}@livekit…)` as in the
    orchestration case (`from-trunk.blade.php:42-52`).
  - Recording uses the existing `mix-monitor` partial. Check-in calls
    are recorded like any other: the readout is the delivery evidence.
- **No classic Asterisk-only IVR in v1.** There's no ARI client today,
  and building `Read()` + `CURL()` PIN verification and per-message
  TTS WAV rendering in dialplan duplicates the AI path. The AI path
  with deterministic tools *is* the IVR (see below). A
  dialplan-only fallback (DTMF PIN, `Playback` of WAVs pre-rendered by
  a `VoicemailGreetingRenderer`-style job) is listed as phase 3 for
  installs without an LLM provider.

### Operator UI

**Single-account Workspace** (`Workspace.php`)

- New header action **"Client check-in"** next to *Fetch Account*
  (`:309-330`). It opens the same client search with a "Check-in"
  toggle. On a check-in line call it opens automatically (see below).
- **Check-in mode** (`public ?int $checkinSessionId`, with only the id
  in public state, like the brief at `:132-150`) replaces the message
  table and form with:
  1. **Verify panel.** It shows the caller-ID match ("Matches *Front
     desk cell* for Acme Dental"), or "No match, ask for account and
     passcode". It shows the passcode via `ClientCallBrief` (unchanged)
     and buttons *Verified by passcode* / *Verified (I know this
     caller)* / *Could not verify*. The choice is stored as
     `verified_by`. Messages are **not shown** until the operator
     records a verification. That's the IS discipline, and it's
     enforced server-side, not just hidden.
  2. **Waiting list**, a Filament table over
     `Message::scopeAwaitingPickup()` filtered explicitly by
     `team_id` (not the global scope). Columns: age, urgency, mailbox,
     summary line (plan 03), "amended" badge, "already acknowledged by
     …" badge. Order follows `readout_order`.
  3. **Readout pane.** Selecting a row shows `CheckinReadout` text in
     large type, which marks it `presented`. Buttons: **Read, mark
     delivered** (`pickUp`, via `operator`), *Skip, keep waiting*,
     *Caller wants it re-sent* (plan 05 resend to a chosen contact
     method).
  4. **"Mark all read as delivered"** only covers rows in `presented`.
     It never bulk-delivers unread messages.
  5. *End check-in* closes the session, with a summary toast ("4
     delivered, 1 left waiting").
- The existing table (`:353-445`) gets a "Waiting for check-in" filter
  and a pickup-state column. On **every** call for a client, the
  call-handling section (`workspace.blade.php:109-145`) shows a badge:
  "3 messages waiting, oldest 2h". Clients often call their own line
  to check in. The count comes from a new `ClientCallBrief::waiting()`
  method, separate from the passcode-bearing `for()` so it can be
  cached.

**Multi-call desk** (`MultiCallWorkspace.php`, `MultiCallDesk.php`)

- `operator_calls` gains `mode` (`answer` / `checkin`) and
  `checkin_session_id`. `MultiCallDesk::receive()` (`:70-92`) resolves
  the DID. If its destination is `checkin`, it sets `mode=checkin`,
  starts a session, and runs `CheckinIdentifier` on `caller_number`.
  On a single match it pre-attaches `team_id`. On several, the tab
  shows a picker ("This number checks in for: Acme Dental, Acme
  Ortho").
- A check-in tab renders the same verify / list / readout component as
  the Workspace. Build it as one Livewire child component,
  `CheckinPanel`, mounted in both, so the two workspaces can't drift.
  The draft form is hidden but kept: a check-in call can turn into
  "and take a message for Dr. Jones" with a *Take a message* button
  that switches the tab to `answer` mode on the same call.
- Trial calls (`OperatorCall.is_trial`) run the panel against real
  data **read-only**. `pickUp` refuses for trial sessions, consistent
  with "trial messages never reach a client's inbox"
  (`MultiCallDesk.php:180-188`).

**Supervisor**

- A "Waiting for check-in" board lists every client with open pickups,
  sorted by oldest age, with reminder count and released state. It can
  live beside plan 05's dispatcher queue. Actions: *Call client now*
  (softphone dial-out to the identity's phone), *Release to push now*,
  and *Mark picked up (override)*, which needs a reason and records
  `picked_up_via=supervisor_override`.

### Admin UI (platform staff)

- New ClientResource sub-page **Check-in**, alongside the existing
  sub-pages (e.g. `ManageClientDids`). It has:
  - **Mailboxes:** CRUD, schedule picker, urgent handling (`hold`
    needs a confirm modal that stores the accepting user and time),
    and reminder and release settings with a preview ("A routine
    message taken Fri 6pm waits until Mon 9am. First reminder Mon
    10am. Released to email Mon 1pm.").
  - **Check-in identities:** phone, label, mailbox access, linked
    directory entry or portal user. *Set PIN* and *Reset PIN* show the
    new PIN once on save and never again. *Unlock*. Last check-in
    time.
- Platform **Check-in lines** (Telephony section) holds the
  `checkin_lines` rows and binds DIDs via the existing DID manager
  (destination `checkin`).
- The client form's passcode field (`ClientResource.php:179-184`) gets
  helper text: "Used by operators on check-in calls. The phone and AI
  check-in uses per-person PINs on the Check-in page."

### Portal

- Builds on plan 03's portal **Messages** page. It adds a "Waiting for
  you" tab (default when non-empty), a count on the dashboard
  (`app/Filament/Portal/Pages/Dashboard.php`), and a nav badge.
- Opening a message detail calls `pickUp(via: portal)` when the
  mailbox has `portal_open_counts_as_pickup`. Otherwise it only
  `present`s. A **Got it** button records an explicit acknowledgement.
  Both are idempotent. Opening an already picked-up message records
  only a view event, not a second pickup.
- Per-contact mailboxes: users linked to an identity (`user_id`) see
  their own mailboxes first. Visibility of other mailboxes is
  controlled by the permissions below.
- Every query filters on `current_team_id` explicitly, like
  `CallHistory.php:49`.
- Self-service (permission-gated): portal users can manage their own
  check-in phone and PIN. Client admins can manage identities for
  their team.

### AI path

The AI runs check-in through the plan 01 engine as a **built-in
"Message check-in" orchestration** (platform-shared, `team_id` null),
bound to a check-in line in AI mode. The LLM handles conversation, but
the protocol is deterministic and **message text is never generated by
the LLM**.

- **Worker tools** go in a new `agent-worker/tools/checkin.py`,
  registered like `compiled_flow.py`'s dynamic tools
  (`agent-worker/tools/compiled_flow.py:29`). They call new endpoints
  under `/api/call-sessions/{sessionKey}/checkin/*`, using the same
  bearer-token check `CallSessionController` already does
  (`app/Http/Controllers/Api/CallSessionController.php:280-282`):
  - `checkin_identify(account_number?)` returns `{matched, choices:[{ref, name}]}`.
    The refs are opaque session-scoped tokens, never team ids.
  - `checkin_verify(pin)` returns `{ok, remaining_attempts | locked}`.
  - `checkin_next()` returns `{ref, header, text, urgent, remaining}`
    and marks the message `presented`.
  - `checkin_confirm(ref)` returns `{ok}` and marks it `picked_up`,
    via `ai`.
  - `checkin_repeat(ref)`, `checkin_skip(ref)`.
  - `checkin_to_operator()` reuses the `transfer_to_operator` handoff
    (`agent-worker/tools/handoff.py:26-34`), carrying the session so
    the operator's tab opens already verified.
- **Verbatim readout:** the tool returns `text`, and the worker speaks
  it with `session.say(text, allow_interruptions=True)` rather than
  feeding it to the LLM to phrase. `checkin_confirm` is accepted only
  after the say-handle finished playout *and* the caller confirmed
  ("yes", "got it", or DTMF 1). If playout was interrupted, the
  message stays `presented`.
- **DTMF:** the worker has no DTMF handling today (no `dtmf` anywhere
  in `agent-worker/`). Add a `sip_dtmf_received` listener that buffers
  digits for PIN entry and 1/2/3 (confirm/repeat/skip). Spoken PINs
  are accepted too, but the transcript stores `****`. `transcript.py`
  redacts any turn captured while the session state is
  `awaiting_pin`.
- **Server-side state machine:** the session row holds
  `unverified → verified → reading → done`. Every tool checks state
  server-side, so a prompt-injected "skip verification" can't reach
  `checkin_next`. That's the "deterministic first" principle from the
  README.
- **Caller ID alone is never enough on the AI path.** A PIN is always
  required, because caller ID is spoofable. An identity with no PIN
  set can only check in through an operator.

### Permissions

Platform roles `operator` and `supervisor` exist
(`database/seeders/SuperAdminRoleSeeder.php:42,64`). New platform
permissions, seeded by `PermissionCatalogSeeder`:

- `platform.checkin_deliver`: run check-in and mark picked up
  (operator, supervisor).
- `platform.checkin_override`: mark picked up without verification,
  and release (supervisor).
- `platform.checkin_manage`: mailboxes, identities, PIN reset, lines
  (super admin; optionally supervisor).

Portal permissions are added to `ClientProvisioner::DEFAULT_TENANT_ALLOW_LIST`
(`ClientProvisioner.php:49-56`) and described in `RoleResource`
(`:230-240`):

- `portal.acknowledge_messages`: the *Got it* button and
  open-counts-as-pickup. Default on for `client_user`. Without it, a
  view is only `presented`.
- `portal.view_all_mailboxes`: otherwise users see only mailboxes
  linked to their identity, plus the default mailbox. Default on for
  admin only.
- `portal.manage_checkin`: manage identities and PINs for the team.
  Admin only, on the allow-list.

### Tenant isolation

- Every new table except `checkin_attempts` uses `BelongsToTeam`.
- Two code paths cross tenants on purpose, and both are confined to
  one class each with a comment and a test:
  - `CheckinIdentifier` (number → candidate teams);
  - `SweepWaitingMessagesJob`, which runs with no auth user, so the
    global scope is a no-op (`BelongsToTeam.php:17-20`). It iterates
    mailboxes and passes `team_id` explicitly into every query.
- Operator and portal queries add `where team_id = ?` explicitly.
  They don't rely on the global scope, whose behaviour depends on the
  operator's `currentTeam` (`BelongsToTeam.php:30-35`).
- Pickup and acknowledgement APIs take a **pickup id plus session**,
  and verify `pickup.team_id === session.team_id` and that the mailbox
  is in the identity's allowed set. A guessed id from another client
  returns 404.
- The AI tool API never accepts or returns team ids. The session row
  is the only source of `team_id`.

### Audit

- `checkin_sessions` + `checkin_attempts` record who called, from
  where, how they were verified, by whom, and what they were read.
- Each pickup, supersede, release and override is written to the
  message timeline (plan 05). Plan 05 doesn't land a timeline table
  first, so use `ConversationActivity::log($message, 'checkin.picked_up', metadata:
  [...], user: …)`. The subject is polymorphic
  (`ConversationActivity.php:45-72`).
- Admin changes to mailboxes, `urgent_handling=hold` acceptance and
  PIN resets are logged with the acting user. The new PIN value is
  never logged.
- Recording of the check-in call (existing MixMonitor) links via
  `checkin_sessions.call_log_id`.

## Edge cases

- **One number, several clients** (an office manager for three
  practices): the identifier returns several candidates. The operator
  sees a picker. The AI asks "which account?" and accepts the account
  number or name, and a PIN is required per identity. One call can
  check in several clients in sequence: the session ends, and a new
  session starts on the same call.
- **Anonymous or withheld caller ID:** operator path uses account
  number, then passcode. AI path uses account number, then PIN, with
  `verified_by=pin_only`.
- **Spoofed caller ID:** the AI always requires a PIN. The operator
  path shows the match as a hint, but still needs a verification
  click.
- **Identity locked mid-call:** the AI offers transfer to an operator,
  who can override with supervisor permission and a reason.
- **Message amended after it started waiting** (plan 03 revisions):
  read the latest revision and flag it "amended". Amended *after*
  pickup: if the change touches summary or callback fields, the pickup
  reopens as `waiting` with an "updated since you heard it" badge.
  Otherwise it is only logged.
- **New message arrives during a check-in:** the list polls (or
  listens on the existing Reverb channel) and appends with a "just
  arrived" marker. The AI's `checkin_next` naturally includes it.
- **Caller hangs up mid-readout:** only `picked_up` rows count. A
  `presented` row stays waiting, and the session closes
  `ended_reason=hangup`.
- **Two operators, or portal and phone, at once:** the conditional
  UPDATE gives one winner. The loser's UI shows "already picked up by
  … via portal".
- **Partial messages** (`is_partial`, `Message.php:69-77`): read with
  "incomplete, the caller hung up before giving …" using
  `missingFieldLabels()` (`:83-95`).
- **Message filed or closed by staff while waiting:** pickups go to
  `cancelled` and drop out of the list.
- **Mailbox deleted or deactivated with open pickups:** blocked unless
  the admin picks a target mailbox to move them to.
- **Mailbox schedule boundary** (e.g. a message taken 4:59pm, office
  closes 5pm): the decision is made at dispatch time, and the message
  doesn't move later unless the release rule fires. The preview in the
  admin UI shows this.
- **DST and client timezone:** ages are computed in UTC. Readout times
  and reminder quiet hours use `Team::displayTimezone()`.
- **Suspended client** (`teams.suspended_at`): check-in still works,
  so they can collect what was taken. Nudges and reminders stop.
- **Client turns check-in off:** waiting pickups are released to the
  push policy immediately, with a confirm modal showing the count.
- **Voicemails:** `Voicemail` rows aren't `Message`s
  (`app/Models/Voicemail.php`). They're excluded in v1 (see Open
  questions).
- **PIN brute force across identities from one number:** per-number
  throttle in `checkin_attempts`, plus a supervisor alert when more
  than N failures happen in an hour.
- **Readout of PHI:** the voice template's `redact` flags (plan 03)
  apply to nudges only. Verified check-in reads full content, as IS
  does.

## Migration / rollout

1. **Migrations:**
   - `message_mailboxes`
   - `checkin_identities` and pivot
   - `message_pickups`
   - `checkin_sessions`
   - `checkin_attempts`
   - `checkin_lines`
   - `operator_calls.mode` and `checkin_session_id`

   All additive. Existing messages get no pickup rows, so nothing
   changes for current clients.
2. **Feature flag:** `config('orbital.checkin.enabled')`, exposed in
   Platform Settings via `SettingsRegistry`, the same pattern as
   `orbital.operator.multi_call_workspace` (`config/orbital.php:203-209`).
3. **Phase 1 (operator + portal):** mailboxes, identities, transport,
   `PickupService`, Workspace / multi-call `CheckinPanel`, portal
   pickup, `checkin` DID destination in operator mode, ageing sweep
   with reminders. Needs 03 (portal list, voice template, or the
   default template) and 05's dispatch, attempt and ack tables. If 05
   slips, a stop-gap `CheckinRouter` called from the message writers
   (`Workspace::saveMessage`, `MultiCallDesk::saveMessage`,
   `SessionMessageWriter`) can create pickups directly. Plan 05 then
   adopts the rows.
4. **Phase 2 (AI check-in):** tools, DTMF, built-in orchestration,
   transcript PIN redaction, handoff to an operator with session.
5. **Phase 3 (optional):** dialplan-only DTMF IVR for installs without
   an LLM provider. Voicemail inclusion.
6. **Amtelco import:** if the export carries per-account "hold" or
   check-in settings or check-in phone numbers, extend
   `AmtelcoImporter` to create mailboxes and identities. PINs can't be
   imported if Amtelco stores them hashed, so identities would arrive
   PIN-less, which means operator-only until reset. The in-house
   "CHECK IN LINE" accounts flagged by `AccountFilter` become
   `checkin_lines`, not clients.
7. **Cut-over:** at go-live, messages still undelivered in Amtelco are
   imported (if exported) as waiting pickups, so clients don't lose
   their queue.

## Tests

Written as answering-service scenarios, using fake transports and
`travel()` like plan 05.

**Holding and release**
- A routine message for a client with a default mailbox (no schedule)
  gets a `waiting` pickup. No transport sends content. The arrival
  nudge has no message text.
- Mailbox schedule "office hours": a message at 10am waits, and a
  message at 8pm skips the hold tier and is emailed.
- An urgent message with `push` goes to tier 1 immediately, and a
  pickup row exists too. An SMS ack marks the pickup `superseded`.
- `hold_and_notify`: an urgent nudge is sent at t=0, a reminder at
  10 min, release at 30 min, then the urgent tiers run.
- `hold`: no push. At `release_after` the message lands in the
  dispatcher queue.
- Reminder timeline: `remind_after=60`, `every=60`, `max=2`. Exactly
  two reminders, none in quiet hours, and release at
  `release_after=240`. The sweep is idempotent when run twice in the
  same minute (two workers).

**Operator check-in**
- A check-in DID call with a known caller ID creates `mode=checkin`,
  pre-attaches the team, and starts a session.
- No messages are visible before verification. Livewire calls to
  `pickUp` before verify are rejected server-side.
- Read → mark delivered gives `picked_up` with `via=operator`, a plan
  05 ack, and cancels the escalation timers of an urgent in-flight
  dispatch.
- "Mark all read" only affects `presented` rows.
- An ambiguous caller ID (two clients) shows a picker. Choosing client
  A never shows client B's messages.
- A trial call's check-in is read-only: no pickups and no acks.
- Two operators pick up the same message at once: one succeeds, and
  the other sees "already picked up".
- *Take a message* on a check-in call creates a normal message, which
  then waits in the mailbox like any other.

**AI check-in** (tool API tests, no telephony)
- Correct PIN gives verified. 5 wrong PINs give locked, the 6th call
  returns `locked`, and the transcript contains no PIN digits.
- `checkin_next` before verify returns 403/state error.
- `checkin_confirm` without playout complete is rejected, and the row
  stays `presented`.
- An identity with no PIN can't verify on the AI path, and
  `checkin_to_operator` hands off with the session id.
- Tool responses contain no team ids. A ref from another session
  returns 404.
- Readout text equals `CheckinReadout` output byte for byte (the same
  fixture as the operator pane).

**Portal**
- Opening a waiting message picks it up when the setting is on, and
  only presents it when the setting is off or the user lacks
  `portal.acknowledge_messages`.
- A user linked to the "Dr. Smith" identity without
  `view_all_mailboxes` doesn't see the Maintenance mailbox.
- Client A's portal user requesting client B's pickup id gets 404.

**Isolation and audit**
- `CheckinIdentifier` returns candidates from multiple teams only as
  ids and names. No other model query in the check-in namespace runs
  without a team filter (architecture test: grep for
  `withoutGlobalScope` limited to the two allowed classes).
- The sweep job with no auth user only touches each mailbox's own
  team's pickups.
- Every pickup, override and release writes a `ConversationActivity`
  row on the message with actor and channel. The PIN reset log
  contains no PIN.

**Edge cases**
- A message amended after pickup (callback number changed) reopens to
  `waiting` with the "updated" badge.
- A client suspended mid-wait: check-in works and reminders stop.
- A DST-spring-forward reminder fires once at the correct local time.

## Open questions

1. How does NCI run check-in today? Is it a shared NCI check-in line,
   per-client numbers, or both? Is it operator only, or does Amtelco
   IVR read messages back?
2. Does the Amtelco export include per-account delivery or hold
   settings, check-in numbers or PINs, and undelivered messages at
   cut-over?
3. Is one shared passcode per client acceptable for operator check-in
   (today's model), or do clients expect per-person PINs read aloud
   to the operator too?
4. Should voicemails (deposit-only today) join the check-in list, read
   as "voice message from … " with playback?
5. Should a phone check-in deliver *all* mailboxes the identity can
   access, or ask "messages for Dr. Smith or the office?"
6. Portal: should "opened" or "Got it" be the client-visible delivery
   time in reports (plan 10)? IS reports usually use delivery, not
   read.
7. Should a client be able to set `urgent_handling=hold` themselves in
   the portal, or only platform staff with an audit trail?

## Size estimate

About 3–3.5 weeks after plans 03 and 05's core tables exist:

| Part | Estimate |
|---|---|
| Model, services, transport, sweep, admin pages | 1 wk |
| Operator `CheckinPanel` (Workspace and multi-call), DID destination and dialplan | 1 wk |
| Portal pickup, permissions | 0.5 wk |
| AI tools, DTMF, redaction, orchestration | 1 wk |
| Dialplan-only IVR (phase 3), if wanted | +0.5–1 wk |
| Phase-1 stop-gap without plan 05 | +2–3 days |
