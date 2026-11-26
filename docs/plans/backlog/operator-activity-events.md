# Plan: Operator activity events

**Status:** proposed · part of [Backlog plans](README.md) · shares the session and state tables designed in [10 §1c](../is-parity/10-client-and-staff-reporting.md); feeds [10](../is-parity/10-client-and-staff-reporting.md) and [22](../is-parity/22-script-replay-and-qa.md); answers [18](../is-parity/18-dispatch-list-followups.md) open question 6
**Written:** 2026-11-26

Covers `TODO.md:644-656` "Operator history events".

## Problem

Supervisors can't answer "what was Maria doing between 2:10 and
2:40?". Nothing records that an operator loaded an account, dialled
out, answered a call, claimed a thread or went on break. The only
operator audit row is the logout reason, and even that drops the note
the operator typed.

Three IS-parity plans each touch part of this and none owns the
whole:

- [Plan 10 §1c](../is-parity/10-client-and-staff-reporting.md)
  designs `operator_sessions` and `operator_state_intervals` for login
  and availability, and says "a full per-click timeline (fetch
  account, dial) is left to plan 22".
- [Plan 22](../is-parity/22-script-replay-and-qa.md) records UI
  events **inside a call** in plan 01's `script_events`, keyed to a
  `CallLog`. It has no place for events outside a call (loading an
  account, claiming an email, going on break).
- [Plan 18](../is-parity/18-dispatch-list-followups.md) proposes
  `ConversationActivity` for its audit and asks, as open question 6,
  whether a dedicated `operator_events` table should be started
  instead.

This plan settles the split and **owns `operator_events`**.

## What exists today (checked in code)

### Audit rows that exist

- **`ConversationActivity`** (`app/Models/ConversationActivity.php`,
  table from `database/migrations/2026_04_16_220000_create_conversation_activities_table.php`).
  Polymorphic `subject` (required, `morphs()`), `user_id`,
  `agent_persona_id`, `action` (50 chars), `metadata` jsonb,
  `created_at`. One index: `(subject_type, subject_id, created_at)`.
  No `team_id`, no index on `user_id`. Written synchronously with
  `ConversationActivity::log()` (`:63-79`) from:
  - message threads: claim, unclaim, reply, close, reopen, message
    taken (`app/Filament/Operator/Pages/ViewMessageThread.php:121,135,174,222,238,409`;
    `MessageInbox.php:267,276,295,316`);
  - email threads: claim, unclaim, reply, forward, close, reopen
    (`ViewEmailThread.php:208,225,250,288,310,324`;
    `EmailInbox.php:264,273,291,309`).
  - Chat sessions and calls write nothing.
- **`UserLogoutEvent`** (`app/Models/UserLogoutEvent.php`): `user_id`
  (`cascadeOnDelete`), `logout_reason_id`, `reason_label_snapshot`,
  `logged_out_at`
  (`database/migrations/2026_04_15_140000_create_logout_reasons_table.php:41-50`).
  Written only from the operator panel's Sign out modal
  (`app/Providers/Filament/OperatorPanelProvider.php:197-208`). The
  modal collects a **note** (`Textarea::make('note')` at `:174` and
  `:187`) that the action never stores, and the table has no column
  for it. Nothing displays these rows.
- **`OperatorCall`** (`app/Models/OperatorCall.php`): the multi-call
  desk's row per call with `arrived_at`, `answered_at`, `held_at`,
  `ended_at`, `closed_at`, `message_saved_at`. Not `BelongsToTeam`
  by design (`:15-17`). Fed from the softphone by
  `MultiCallDesk::followSoftphone()` (`app/Services/Operator/MultiCallDesk.php:271-300`).

### Actions that leave no trace

| Action | Where it happens | Recorded? |
|---|---|---|
| Load an account (single-call) | `Workspace::fetchAccount()` (`app/Filament/Operator/Pages/Workspace.php:94`) | no |
| Release an account | `Workspace::releaseAccount()` (`:294`) | no |
| Attach a client to a desk call | `MultiCallDesk::attachClient()` (`MultiCallDesk.php:152`) | only the `team_id` on the row |
| Save a message from a call | `Workspace::saveMessage()` (`:183`), `MultiCallDesk::saveMessage()` (`:189`) | the `Message` row (`created_by_user_id`) |
| Dial out | `sip-phone.js` `call()` (`resources/js/sip-phone.js:156-200`) | no. The browser places the call; the server isn't told |
| Answer / end (single-call) | `sip-phone.js` emits `orbital-call` with `incoming`/`answered`/`ended` (`:93-103`, `:131-135`) | only the multi-call workspace listens (`resources/views/livewire/multi-call-workspace.blade.php:10`) |
| Blind transfer | `sip-phone.js:292-300` | no |
| Change availability | `AvailabilitySelector::updatedStatus()` (`app/Livewire/AvailabilitySelector.php:48-96`) | overwrites `users.availability_status` and `availability_changed_at`; no history |
| Forced unavailable on login | `ResetAvailabilityOnLogin` (`app/Listeners/ResetAvailabilityOnLogin.php:35-58`, registered `app/Providers/AppServiceProvider.php:114`) | no |
| Log in | Laravel `Login` event | no row |
| Session expires / tab closed | — | nothing |

## Goal

1. Every meaningful operator action is one timestamped row, queryable
   by operator and date range, and by client.
2. Writing an event never slows or breaks the action it records.
3. Supervisors get a per-operator timeline that merges actions,
   sessions, availability and calls.
4. One table per kind of fact, each owned by one plan, so nothing is
   built twice.

## Design

### Decision: a dedicated `operator_events` table

`ConversationActivity` is the wrong home:

- Its `subject` is required. "Logged in", "went on break" and "loaded
  account 1043" have no conversation.
- It answers "what happened to this thread". The supervisor question
  is "what did this operator do", which needs `(user_id, occurred_at)`
  and `(team_id, occurred_at)` indexes that the thread timeline
  doesn't. Adding them, and making `subject` nullable, turns one
  focused table into a general log that both readers then filter.
- Its writes are synchronous and part of the action. Operator events
  should be fire-and-forget.

So: `ConversationActivity` stays the per-thread history, unchanged.
`operator_events` is the per-operator history. Thread actions appear
in both, written by one call (see "Write path").

### Who owns which table

| Fact | Table | Owning plan |
|---|---|---|
| Discrete operator actions, in or out of a call | `operator_events` | **this plan** |
| Login sessions, logout reason **and note** | `operator_sessions` | [10 §1c](../is-parity/10-client-and-staff-reporting.md) |
| Availability as intervals | `operator_state_intervals` | 10 §1c |
| Per-call script and UI events (step shown, field committed, hold) | `script_events` | [01](../is-parity/01-script-runtime.md) / [22](../is-parity/22-script-replay-and-qa.md) |
| Per-call operator legs | `operator_call_legs` | 10 §1b |
| Thread history | `conversation_activities` | existing |

Login, logout and availability changes are **also** written to
`operator_events` as single point events. The interval tables are
derived state for reporting ("time in each state"); the event is the
raw "at 14:02 Maria chose Lunch". Writing both is one extra insert in
`OperatorStateRecorder`, and it means the timeline UI reads one table
for everything that's a moment in time.

**If this plan ships before plan 10**, it implements plan 10 §1c
(`operator_sessions`, `operator_state_intervals`,
`OperatorStateRecorder`, the heartbeat sweeper) exactly as designed
there, and plan 10 marks §1c done. The design stays in plan 10. The
code is written once, by whichever plan starts first.

Plan 22's in-call UI events stay in `script_events`, because a replay
needs them on the call clock next to step views. `operator_events`
records only the call boundaries (`call.answered`, `call.ended`) with
`call_log_id` / `operator_call_id`, so the operator timeline can link
into a replay without copying its contents.

### Table

`operator_events`, append-only, platform-level (not `BelongsToTeam`:
operators work across clients, and a supervisor reads across them):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `user_id` | FK users, **nullOnDelete** | history survives user removal (unlike `user_logout_events`' cascade) |
| `user_name_snapshot` | string | so a deleted operator's rows still read |
| `team_id` | FK teams, nullable, nullOnDelete | the client the action was for, when there is one |
| `type` | string(64) | dotted name from the catalogue below |
| `subject_type`, `subject_id` | nullable morph | thread, chat session, message, `OperatorCall`, `CallLog` |
| `call_log_id` | nullable FK | back-filled by CDR ingest via `linked_id`, as plan 22 does for `script_events` |
| `payload` | jsonb, nullable | small, type-specific. No message bodies, no free text the caller said |
| `impersonator_id` | nullable FK users | from `Session::get('impersonator_id')` (`app/Http/Controllers/ImpersonationController.php:30`) |
| `session_id` | nullable FK `operator_sessions` | once 10 §1c exists |
| `occurred_at` | timestamptz(3) | server clock |
| `client_at` | timestamptz(3), nullable | browser clock for browser-originated events; never used for ordering (same rule as plan 22) |

Indexes: `(user_id, occurred_at)`, `(team_id, occurred_at)`,
`(type, occurred_at)`, `(subject_type, subject_id)`.

On PostgreSQL, **partition by month** on `occurred_at` (declarative
range partitions created a month ahead by the scheduler). Retention
then drops whole partitions instead of running large deletes.

### Event catalogue

| Type | Fired from | `team_id` | Payload |
|---|---|---|---|
| `session.login` | `Login` listener, operator-capable users only (`hasAnyPlatformRole()`) | — | `panel`, `ip` |
| `session.logout` | Sign out action (`OperatorPanelProvider.php:194-211`) | — | `reason_id`, `reason_label`, `note` (≤ 500, the field the modal already collects) |
| `session.expired` | 10 §1c heartbeat sweeper | — | `last_seen_at` |
| `availability.changed` | `OperatorStateRecorder::changeState()` (from `AvailabilitySelector`, `ResetAvailabilityOnLogin`, supervisor force) | — | `from`, `to`, labels, `source` (`selector`/`login_reset`/`logout`/`supervisor`) |
| `account.fetched` | `Workspace::fetchAccount()`, `MultiCallDesk::attachClient()` | yes | `via` (`search`/`recent`/`did_match`), `operator_call_id` |
| `account.released` | `Workspace::releaseAccount()`, `MultiCallDesk::close()` | yes | `held_seconds` |
| `call.offered` | softphone `incoming` | from DID when known | `sip_call_id`, `from`, `did` |
| `call.answered` | softphone `answered`; `MultiCallDesk::answer()` | yes when known | `sip_call_id`, `ring_ms` |
| `call.ended` | softphone `ended`; `MultiCallDesk::hangUp()` | yes when known | `sip_call_id`, `talk_ms`, `ended_by` if known |
| `call.declined` | softphone reject | from DID | `sip_call_id` |
| `call.dialed` | softphone `call()` | the account in focus, if any | `target_kind` (`extension`/`external`/`patch_code`), `target` (extensions only; outside numbers masked to last 4) |
| `call.transferred` | softphone transfer; plan 08 patch outcomes | yes | `mode` (`blind`/`attended`/`patch`), `target_kind`, `target` (masked as above), `call_patch_id` |
| `message.saved` | `Workspace::saveMessage()`, `savePartialMessage()`, `MultiCallDesk::saveMessage()` | yes | `message_id`, `is_partial` |
| `thread.claimed` / `thread.unclaimed` / `thread.closed` / `thread.reopened` / `thread.replied` / `thread.forwarded` | `ConversationActivity::log()` when it has a `user` (see Write path) | from the subject | `channel` (`email`/`sms`/`chat`) |
| `followup.*` | plan 18's transitions | yes | as plan 18 defines |
| `monitor.*` | supervisor listen/whisper/barge ([live-call-monitoring](../live-call-monitoring.md)) | yes | `target_user_id`, `call` |

Deliberately **not** events: page views, searches that load nothing,
keystrokes, script field edits (those are plan 22's
`field_committed`, on the call clock), and anything the AI does (the
AI isn't an operator; its actions are in `call_session_states` and
`script_events`).

The catalogue lives in `App\Support\OperatorEventType` (string
constants plus a label and icon per type) so the UI and tests share
one list.

### Write path

`App\Services\Operator\OperatorActivity`:

```php
public function record(
    string $type,
    ?User $user = null,          // default auth()->user()
    ?int $teamId = null,
    ?Model $subject = null,
    array $payload = [],
    ?CarbonImmutable $clientAt = null,
): void
```

- **Buffered, flushed after the response.** `record()` appends to an
  in-memory buffer on a scoped singleton. The first call registers
  `app()->terminating(fn () => $this->flush())`. `flush()` does one
  multi-row `insert()` inside `try/catch`. On failure it logs at
  `warning` with the event types and drops the batch. An audit
  insert never throws into the action, and the browser never waits
  for it.
- **Queue and CLI.** In a queued job or command there's no response
  to wait for, so the buffer is flushed when the job finishes
  (`Queue::after`) or at the end of the command. Long-running workers
  also flush at 100 buffered rows.
- **No model events, no observers.** Call sites call `record()`
  explicitly. The point is to log operator intent, not every row
  change.
- **`ConversationActivity::log()` forwards.** When `$user` is given,
  it also calls `OperatorActivity::record('thread.'.$action, …)`. The
  20 existing call sites get operator events with no change.
  System-written activities (no user) aren't forwarded.
- **Browser-originated events.** The softphone already emits
  `orbital-call` for `incoming`, `answered` and `ended`
  (`sip-phone.js:93-103`). Add `dialed`, `declined` and `transferred`
  to the same emitter. A small Alpine listener in the softphone view
  forwards each to a `Softphone::recordCall(array $event)` Livewire
  method that validates the shape, cleans strings (as
  `MultiCallDesk::clean()` does), and calls `record()`. The
  multi-call workspace keeps its own listener; it records through
  `MultiCallDesk`, and the softphone listener skips events when the
  desk is active (`Workspace::usesMultiCall()`) so calls aren't
  logged twice.
- **Throttle.** `recordCall` is rate-limited per user (60 per minute)
  so a misbehaving tab can't flood the table.
- **Masking.** Outside numbers in `call.dialed` and
  `call.transferred` keep only the last four digits. The full number
  is in the CDR for anyone allowed to see it. Client-side the code
  sends the full target; masking happens server-side before insert.

### Retention

- Platform setting `operator_events.retention_days`, default **400**
  (a year plus a month, so year-over-year reviews work).
- `orbital:prune-operator-events` runs daily with `onOneServer()`
  (the rule stated in `routes/console.php:26-30`). On PostgreSQL it
  detaches and drops partitions wholly older than the window. On
  SQLite (tests) it deletes by `occurred_at`.
- The same command creates next month's partition.
- Plan 22's legal hold doesn't apply here: QA evidence lives in
  `script_events` and recordings. If a hold on an operator's
  activity is ever needed, it's a per-user `hold_until` that the
  prune skips (open question 3).
- `user_logout_events` stays read-only for history. Once
  `operator_sessions` exists, a migration copies it there and the
  modal stops writing it.

### Supervisor timeline UI

Admin panel, Monitor group: **Operator Activity** page
(`app/Filament/Pages/OperatorActivity.php`).

- Filters: operator (required), date (default today in the viewer's
  zone, `User::displayTimezone()`), client, event types.
- **Day strip** across the top: availability intervals as coloured
  bands (from `operator_state_intervals`, colour from
  `availability_reasons`), sessions as brackets, calls as ticks. Built
  with Filament's bundled Chart.js, no new dependency.
- **Event list** below: time, icon, label, client, a one-line summary
  from the payload, and a link: thread → the thread page, message →
  message, call → Call History record or (after plan 22) the replay
  at that offset.
- Merged read: `OperatorTimeline::for(User, CarbonPeriod)` unions
  `operator_events` with session and interval boundaries. No
  denormalised copy.
- Export to CSV with `league/csv` (already a dependency, per plan 10).
- **Client view.** With a client filter and no operator, the list
  answers "who touched account 1043 today", useful when a client
  complains.

Permissions (new, in `PermissionCatalogSeeder`):
`operator_activity.view_any` (supervisors), `operator_activity.view_own`
(operators see their own day, optional), `operator_activity.export`.
Never grantable to clients: add them to `ClientPermissionGatekeeper::PLATFORM_ONLY`
(`app/Services/Clients/ClientPermissionGatekeeper.php:36-39`).

### How plans 10 and 22 consume it

- **Plan 10.** Staff reports S1–S4 read `operator_sessions` and
  `operator_state_intervals` for time-in-state, and
  `operator_call_legs` for handle time. `operator_events` adds counts
  that have no other source: accounts fetched per hour, outbound
  dials, transfers, threads claimed vs closed. The 10 §6 "operator
  scorecard … per-operator timeline" **is** this page; plan 10 links
  to it instead of building its own.
- **Plan 22.** A replay shows the operator's `account.fetched` and
  `call.*` events from `operator_events` for the call's window, read
  through `call_log_id` or `sip_call_id`, as one more source in its
  `CallTimeline` merge (22 §1). It also shows a "what else was this
  operator doing" lane on the multi-call desk, from `operator_events`
  rows for other calls in the same window. In-call step and field
  events stay in `script_events`.
- **Plan 18.** Open question 6 is answered: follow-up transitions
  log to `ConversationActivity` on the follow-up (its subject history)
  and, through the forwarding above, appear in `operator_events`. No
  migration between the two.

## Edge cases

- **Two tabs, one operator.** Both can record. Events are facts, so
  duplicates only matter for the softphone: it is injected on every
  operator page (`OperatorPanelProvider.php:278-284`), so two open
  tabs can both see the same call. Call events carry `sip_call_id`,
  so a unique partial index on `(user_id, type, (payload->>'sip_call_id'))`
  for `call.*` types makes the insert idempotent (`insertOrIgnore`).
- **Flush lost** (worker killed between response and terminate).
  Events are best-effort by design. The truth for calls is still the
  CDR and `OperatorCall`; for state, `operator_state_intervals`, which
  is written synchronously by plan 10's recorder.
- **Browser clock skew.** `client_at` is stored only. Ordering uses
  `occurred_at`.
- **Impersonation.** A super-admin acting as an operator is recorded
  as the operator, with `impersonator_id` set, and the UI marks those
  rows.
- **Deleted user / client.** `nullOnDelete` on both, with
  `user_name_snapshot`. Client name comes from a join; a deleted
  client shows "deleted client #id".
- **Logout by session expiry** writes nothing at the time. The
  heartbeat sweeper (10 §1c) writes `session.expired` with the last
  beat as `occurred_at`.
- **Operator without a softphone** (non-voice desk). No `call.*`
  events; everything else works.
- **Volume.** A busy operator generates on the order of 300–600 rows
  a day (calls × 3–4 events, plus accounts, threads and state). 100
  operators ≈ 50k rows/day, ≈ 20M rows in the 400-day window. That's
  fine for monthly partitions and the two leading indexes.

## Migration and rollout

1. Migration: `operator_events` (partitioned on pgsql, plain on
   sqlite), permissions, platform setting.
2. `OperatorActivity` service, `OperatorEventType` catalogue,
   `ConversationActivity::log()` forwarding. Thread events start
   flowing immediately.
3. Server-side call sites: `fetchAccount`, `releaseAccount`,
   `attachClient`, `close`, `saveMessage` (both), `MultiCallDesk::answer`
   / `hangUp`, logout action. The logout action stores the note in
   `payload` now, before `operator_sessions` exists, so it stops being
   lost on the day this ships.
4. Softphone emitter additions and `Softphone::recordCall`.
5. Plan 10 §1c (sessions, intervals, recorder, sweeper), here or in
   plan 10, whichever starts first. `availability.changed` and
   `session.*` move behind the recorder.
6. Operator Activity page, export, prune command and schedule.
7. Docs: `docs/admin/` page for the timeline; note in
   `docs/admin/users-roles.md` for the new permissions. Remove the
   TODO item; update plans 10 (§1c note, §6 scorecard link), 18 (open
   question 6) and 22 (§1 source table) to point here.

There's no backfill. History starts at deploy, except logout rows,
which step 5 copies from `user_logout_events`.

## Tests

- `record()` inside a request inserts nothing until terminate, then
  one multi-row insert. A forced DB error in `flush()` is logged and
  the response is still 200.
- `ConversationActivity::log()` with a user writes a matching
  `thread.*` operator event; without a user it doesn't.
- `fetchAccount` → `account.fetched` with the right `team_id`;
  `releaseAccount` → `account.released`.
- Logout with a note: the note is in the `session.logout` payload.
  With no reasons configured, the sentinel label is recorded as today.
- `recordCall`: rejects unknown phases and oversized strings; masks an
  external number to its last four digits; ignores a repeated
  `answered` for the same `sip_call_id`; ignored when the multi-call
  desk is active; throttled past 60 a minute.
- Impersonated actions carry `impersonator_id`.
- Deleting a user keeps their events with `user_id` null and the
  snapshot name.
- Prune: rows older than the window are gone, newer kept; on pgsql
  the partition for next month exists after the run.
- Timeline: merges events with session and interval boundaries in
  order across a DST change in the viewer's zone; a user without
  `operator_activity.view_any` gets 403; a client-portal user can't
  reach the page.

## Open questions

1. Should operators see their own timeline by default
   (`operator_activity.view_own`)? Useful for disputes ("I was on a
   call"), but some floors treat it as surveillance. Default off.
2. Is 400 days the right default, or does NCI have a contractual or
   labour-law figure for staff activity records?
3. Is a per-operator hold (HR investigation) needed, or is export
   before the window closes enough?
4. Should `call.dialed` keep the full outside number for supervisors?
   The masked digits are enough to match a CDR, and the CDR already
   has the number under `call_log.view`.
5. Do clients ever get to see "operator X handled your account at
   14:02"? The plan assumes never; the portal shows calls and
   messages, not staff activity.

## Size

- Table, service, catalogue, `ConversationActivity` forwarding,
  server call sites, logout note: ~2 days.
- Softphone events and `recordCall`: ~1 day.
- Plan 10 §1c, if built here: ~1 week (plan 10's estimate).
- Timeline page, export, prune and partitions: ~3 days.

Total without §1c: ~1–1.5 weeks.
