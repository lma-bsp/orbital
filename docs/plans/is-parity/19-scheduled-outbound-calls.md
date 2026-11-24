# Plan 19: Scheduled outbound calls — wake-up, check-in, reminders, callbacks

**Status:** proposed · part of [IS parity](README.md) · depends on 05
(failure escalation), 06 (schedules/holidays); uses 01 (script engine,
`schedule_callback` handler), 02 (operator runner), 03 (result messages),
04 (shares the RRULE expander) · expands survey item M in
[07](07-additional-features.md)

## Problem

### What IS does

Amtelco IS sells outbound call services alongside answering:

- **Wake-up / reminder calls** — at a set time (once or recurring), the
  system dials a number and plays a recorded or TTS message, usually
  asking the called party to press a key to confirm. Unconfirmed calls
  retry, then go to an operator.
- **Check-in (welfare) calls** — daily calls to elderly or at-risk
  subscribers ("press 1 if you're OK"). No answer after the retries
  escalates to a family member, neighbour or emergency contact, often
  by an operator working a contact list.
- **Appointment reminders** — per-appointment calls generated from a
  list or client system, with confirm / cancel / reschedule keypresses.
- **Scheduled callbacks** — an operator (or script step) schedules a
  call back to a caller at a requested time; at that time it appears on
  an operator's screen with the original call's context.

Every attempt and its outcome is logged against the client account like
an inbound call, and failures feed IS's dispatch/escalation.

### What Orbital has today (verified)

Nothing schedules or places a call to the outside world, except the AI
handoff. In detail:

**Origination.**
- `AsteriskAmiService::originate()` (`app/Services/Telephony/AsteriskAmiService.php:256-276`)
  and `originateToApplication()` (`:283-321`) send AMI `Originate` with
  `Async: true` and return `true` when Asterisk *accepted* the request.
  AMI logs in with `Events: off` (`:50-63`), so the `OriginateResponse`
  event — the only AMI signal of answered / busy / no answer — is never
  read. There is no way today to learn how an originated call ended,
  other than CDRs.
- AMI variables are joined comma-separated into one `Variable:` header
  (`:309-315`), because `sendAction()` takes a map with unique keys.
  A value containing a comma would be split.
- `TestCallPlacer::place()` (`app/Services/Operator/TestCallPlacer.php:60-113`)
  is the only originate caller: it rings the **operator's own**
  softphone (`PJSIP/{endpoint}`) into `MusicOnHold`, tags the call with
  an `X-Orbital-Test-Call` header token (`:102`), and pre-creates an
  `OperatorCall` row (`:76-92`) that `MultiCallDesk::followSoftphone()`
  (`app/Services/Operator/MultiCallDesk.php:271`) later binds by SIP
  Call-ID. This "pre-create the row, carry a token, bind on ring"
  pattern is exactly what an operator-executed outbound call needs.
- There is **no ARI client** in `app/` (res_ari is compiled in —
  `docker/asterisk/Dockerfile:67-71` — but unused).

**Outbound dialplan.**
- `[internal]` (`resources/views/asterisk/extensions.blade.php:17-82`)
  and the per-client contexts (`client-dialplan.blade.php:26-55`) have
  no PSTN pattern; operators' softphones cannot dial out. The only
  `_1XXXXXXXXXX` match is the dev-only internal DID simulation, which
  bounces *into* `[from-trunk]` (`extensions.blade.php:33-49`).
- The only path from Asterisk to a carrier is the AI handoff:
  `[from-livekit]` gets one `handoff-{team}-{digits}` extension per
  configured external transfer (`from-trunk.blade.php:96-112`), dialling
  `PJSIP/{number}@trunk_{id}` (`app/Services/Telephony/AsteriskConfigService.php:96`).
  The trunk is chosen by `HandoffDestinations::trunkFor()`
  (`app/Services/Telephony/HandoffDestinations.php:96-107`): the trunk
  of one of the client's DIDs, **else any active trunk**.
- Caller ID: handoffs keep the caller's own number and add a
  `Diversion` header (`from-trunk.blade.php:162-166`). Nothing sets
  `P-Asserted-Identity`; `TrunkSyncer` writes no `send_pai` /
  `from_user` / caller-ID settings on trunk endpoints
  (`app/Services/Telephony/Realtime/TrunkSyncer.php:45-75`).
- Every trunk endpoint's outbound INVITEs go through Kamailio +
  rtpengine via `outbound_proxy` (`TrunkSyncer.php:59-75`), and
  `OutboundTrunkAuditor` alarms if one doesn't
  (`app/Services/Telephony/Realtime/OutboundTrunkAuditor.php:13-33`).
  Outbound calls placed through Asterisk trunks are therefore recorded
  at the edge for free; a LiveKit outbound SIP trunk would bypass this.

**Trunks.** `SipTrunk` (`app/Models/SipTrunk.php:17-33`) has no
outbound caller-ID, CPS or "outbound allowed" fields. `max_channels`
is stored (`:30`) but only appears in the Filament form
(`app/Filament/Resources/SipTrunkResource.php:86`) — nothing enforces it.

**STIR/SHAKEN.** No `Identity` header handling in
`docker/kamailio/kamailio.cfg`, `res_stir_shaken` is not in the
explicit menuselect list (`Dockerfile:55-111`), and no
`stir_shaken.conf` is generated. Orbital relies entirely on the carrier
to sign.

**AI path.**
- LiveKit's dispatch rule is callee-based: room `agent-{dialled}_…`
  (`app/Services/Telephony/LiveKitConfigService.php:46-70`); the worker
  parses it with `ROOM_NAME_RE = ^agent-_?(r?\d+)_`
  (`agent-worker/agent.py:38`) and Laravel resolves the target through
  `AiCallTarget` (`app/Services/Telephony/AiCallTarget.php:13-21`:
  `1001` = AI extension, `r42` = routing rule). A third prefix is the
  natural hook for "this AI leg is scheduled attempt N".
- `agent.py:79-84` decides "outbound" by looking for a participant
  whose name contains `OUTBOUND`; nothing in Orbital sets such a name
  (grep), so `persona.outbound_greeting` (`agent.py:141-145`,
  column on `agent_personas`) is effectively dead.
- When the AI fails, `fallback.py:32` REFERs the far end to
  `ai-unavailable`, which plays "the AI can't take your call"
  (`from-trunk.blade.php:121-135`) — wrong wording for a person *we*
  called.

**Scripts.**
- `IntakeFlow::TRIGGER_OUTBOUND_PHONE` exists (`app/Models/IntakeFlow.php:46`,
  in `CHANNEL_TRIGGERS` `:52-58`) and the catalogue has
  `trigger_outbound_phone` with exits `matched` / `fallback`
  (`database/seeders/IntakeGoalLibrarySeeder.php:88`, `:1310-1321`,
  description: "Runtime wiring lands in a later phase"). Nothing
  starts a session from it.
- `schedule_callback` (`IntakeGoalLibrarySeeder.php:973-987`) only
  captures a window into a slot (`window_slot`, `allow_specific_time`);
  plan 01 lists it under "Later". No callback is ever placed.

**Call history.** `CdrIngester::record()` sets `direction` to
`'inbound'` when a DID was tagged, else `'internal'`
(`app/Services/Telephony/CdrIngester.php:131`), and drops calls with no
team (`:105-108`, team from the `orbital_team_id` CDR var or a
`tenant_N` context, `:172-188`). An originated call through a trunk
would carry neither and **never appear in Call History**. (Same for
today's test calls from `TestCallPlacer`.) `CallLog::STATUSES`
(`app/Models/CallLog.php:60-66`) has `completed/missed/busy/failed/ai_unavailable`.

**Suppression.** `MessagingOptOut` (`app/Models/MessagingOptOut.php:13-24`)
is a per-client SMS suppression list: never deleted, lifted by stamping
`opted_in_at`; normalised `address_key` (`:121-137`) and
`keyVariants()` (`:144-152`). `OptOutRegistry::isSuppressed()`
(`app/Services/Messaging/OptOutRegistry.php:33-57`) is the single
choke point every SMS send passes. There is **no voice DNC** of any
kind, no calling-hours logic, no consent record, and no RRULE library
in `composer.json` (grep for `rrule`).

**Scheduling infra.** The Laravel 12 scheduler lives in
`routes/console.php` (no `Console/Kernel.php`); every task uses
`->withoutOverlapping()->onOneServer()` (`routes/console.php:22-31`
explains why). Horizon has dedicated supervisors per channel so floods
don't starve telephony (`config/horizon.php:217-265`, e.g. `supervisor-inbound-messages` at `:252`).

**Tenant scoping.** `BelongsToTeam`'s global scope does nothing when
there is no authenticated user (`app/Models/Concerns/BelongsToTeam.php:16-20`)
— i.e. in every scheduled command and queued job. Isolation in this
feature must be explicit, not inherited.

## Goal

A per-client **outbound call program** engine:

1. Programs (wake-up, check-in, reminder, callback, custom) with a
   target, an RRULE or one-off time in the **target's** timezone, a
   confirmation rule, retries, and a no-answer escalation.
2. Each occurrence is executed by one of three executors: **TTS**
   (deterministic: play text, read a keypress — no LLM), **AI persona**
   (outbound-phone orchestration), or **operator** (appears on the
   workspace; operator-first dialling).
3. Every attempt is a real `CallLog` row (`direction = outbound`),
   recorded at the edge, with its outcome on the program timeline;
   failed occurrences create a result message and go through plan 05.
4. One compliance choke point (`OutboundCallGuard`) — consent, voice
   DNC, calling hours, caller-ID ownership, frequency caps, blocked
   destinations — that **every** outbound placement passes, mirroring
   `OptOutRegistry` for SMS.
5. `schedule_callback` becomes executable and produces a one-off
   program; plan 14's virtual-hold callback and plan 05's voice callout
   transport can reuse the same placement service.

Non-goals: telemarketing / predictive dialling / campaign management.
Orbital places **informational calls on behalf of a client to people
who asked for them**. A `marketing` kind is deliberately not offered
(see Compliance).

## Design

### Data model (all `BelongsToTeam`, `declare(strict_types=1)`)

**`scheduled_calls`** — the program.

| Column | Notes |
|---|---|
| `team_id` | client |
| `kind` | `wake_up` · `check_in` · `reminder` · `callback` · `custom` — drives defaults and copy, not behaviour |
| `name` | "Mrs Patel daily check-in" |
| `status` | `active` · `paused` · `completed` · `cancelled` |
| `target_name`, `target_number` (E.164 via `App\Support\PhoneNumber::toE164`), `target_number_key` (`MessagingOptOut::key()` form, indexed) | |
| `directory_entry_id` (nullable) | target picked from the client directory; number snapshotted on save |
| `target_timezone` | IANA; default `Team::displayTimezone()` (`app/Models/Team.php:265-273`) |
| `dtstart_local` (wall-clock datetime, no tz), `rrule` (nullable RFC 5545 string, no DTSTART), `until_local` (nullable), `exdates` (JSON) | one-off when `rrule` null |
| `next_due_at` (UTC, indexed) | cached; recomputed on save and after each occurrence |
| `executor` | `tts` · `ai` · `operator` |
| `tts_template` (text, `TemplateEvaluator` `{{ }}`), `voice_id` / `tts_provider` | TTS executor |
| `agent_persona_id`, `orchestration_id` | AI executor; orchestration must have an active `outbound_phone` trigger |
| `operator_instructions` (text), `orchestration_id` | operator executor; runner from plan 02 if set |
| `confirm_mode` | `keypress` (default: "press 1"), `speech` (AI only), `answer` (any human answer), `none` (fire-and-forget, e.g. reminder with voicemail drop) |
| `leave_voicemail` (bool) + `voicemail_template` | reminders only; needs machine detection (see Telephony) |
| `ring_seconds` (default 30), `max_attempts` (default 3), `retry_interval_minutes` (default 5 wake-up / 15 check-in / 60 reminder), `answer_deadline_minutes` | after `due + deadline` no more attempts |
| `retry_on` (JSON) | subset of `no_answer, busy, voicemail, failed, not_confirmed` |
| `on_failure` | `dispatch` (plan 05 policy) · `operator` (worklist item) · `message_only` · `none` |
| `dispatch_policy_id` (plan 05) / `escalation_contacts` (JSON fallback until 05 ships: ordered `[{name, number, email}]`) | welfare escalation targets |
| `result_message_mode` | `always` · `failures_only` · `never` — whether each occurrence writes a plan 03 Message |
| `caller_id_did_id` → `client_dids` | presented number; must be same team (validated) |
| `calling_window` (JSON `{start:"08:00", end:"21:00"}`), `respect_schedule_id` (plan 06, e.g. skip holidays) | |
| `consent_type` (`subscriber_requested` · `caller_requested` · `client_attested` · `written`), `consent_at`, `consent_by_user_id`, `consent_note`, `consent_evidence_path` | required for `tts` / `ai` (artificial voice) |
| `source` (`admin` · `portal` · `script` · `api` · `import`), `source_call_log_id`, `source_session_key`, `source_message_id` | callback provenance |
| `created_by_user_id`, `updated_by_user_id`, soft deletes | |

**`scheduled_call_occurrences`** — one per due time.

`scheduled_call_id`, `team_id`, `due_at` (UTC), `due_local` (wall-clock
string for display/audit), `status` (`pending` · `in_progress` ·
`succeeded` · `failed` · `skipped` · `cancelled`), `outcome`
(`confirmed` · `reached` · `needs_help` · `declined` · `voicemail_left`
· `no_answer` · `busy` · `invalid_number` · `blocked` · `suppressed` ·
`outside_hours` · `no_operator`), `attempt_count`, `next_attempt_at`,
`config_snapshot` (JSON: the program fields that matter, frozen at
materialisation so a mid-day edit can't change a running occurrence),
`message_id`, `dispatch_id` (plan 05), `completed_at`.
**Unique `(scheduled_call_id, due_at)`** — the scheduler can't
double-materialise.

**`scheduled_call_attempts`** — one per dial.

`occurrence_id`, `team_id`, `attempt_no`, `executor`, `status`
(`placing` · `ringing` · `answered` · `ended` · `refused`), `result`
(`confirmed` · `reached` · `needs_help` · `no_answer` · `busy` ·
`voicemail` · `invalid_number` · `rejected_608` · `declined` · `failed`),
`placed_at`, `answered_at`, `ended_at`, `dial_status`, `sip_cause`
(numeric SIP response), `amd_status`, `dtmf`, `linked_id` (→
`call_logs.linked_id`), `session_key` (AI), `operator_call_id`,
`user_id`, `sip_trunk_id`, `presented_caller_id`, `guard_decision`
(JSON — every check and its result), `error`.

**`voice_suppressions`** — voice DNC, modelled on `MessagingOptOut`.

`team_id` (**nullable**: null = platform-wide internal DNC, honoured for
every client), `address`, `address_key` (via `MessagingOptOut::key()`),
`reason`, `source` (`in_call` · `operator` · `admin` · `portal` · `api`
· `import`), `opted_out_at`, `lifted_at`, `lifted_by_user_id`,
`lift_note`, `created_by_user_id`, `scheduled_call_id` (where it came
from). Same rules as `MessagingOptOut`: never delete, lift by stamping,
first `opted_out_at` never moves. Kept separate from `messaging_opt_outs`
because that row is tied to a `messaging_endpoint_id` and SMS keyword
semantics; the guard consults **both** (see Compliance).

**Changes to existing tables**

- `call_logs`: `direction` gains `outbound`; add nullable
  `scheduled_call_attempt_id` (indexed). `CallLog::STATUSES` gains
  `no_answer` label reuse (`missed`), plus `rejected` for 603/608.
- `operator_calls`: add `direction` (`inbound` default / `outbound`),
  `scheduled_call_attempt_id`, `outbound_token` (like `test_token`).
- `client_dids`: `outbound_enabled` (bool), `outbound_verified_at`,
  `outbound_notes` (carrier LOA / registry status).
- `sip_trunks`: `outbound_enabled`, `outbound_cps` (int, default 1),
  `caller_id_mode` (`from` · `pai` · `both`), and **enforce**
  `max_channels` for outbound.
- `teams`: `outbound_calls_enabled` (bool, default false),
  `outbound_max_concurrent` (default 2), `outbound_daily_cap_per_number`
  (default 6).
- Platform setting (`SettingsRegistry`): `outbound.kill_switch`,
  `outbound.platform_max_concurrent`, `outbound.allow_international`
  (false), `outbound.default_window` (`08:00-21:00`).

### Recurrence

- Add `rlanvin/php-rrule` (RFC 5545, MIT). Wrap it in
  `App\Services\Scheduling\RecurrenceExpander` — **shared with plan 04**
  (on-call shifts are RRULEs too) so DST handling is written and tested
  once.
- Store wall-clock `dtstart_local` + `target_timezone`; expand in that
  zone; convert each occurrence to UTC. 07:00 stays 07:00 across DST.
- Spring-forward gap (02:30 doesn't exist): run at the first valid
  instant after the gap (03:00) and note it on the occurrence.
  Fall-back overlap (01:30 twice): first instance only. Both tested.
- Plan 06 integration: `respect_schedule_id` + `skip_when`
  (`closed` / `open`), e.g. "no reminders on the clinic's holidays" →
  occurrence `skipped` with reason, not silently dropped.
- Admin UI shows the next 10 occurrences in the target's zone *and* the
  client's zone before saving.
- Presets cover 95% of cases so nobody types RRULE: once; daily at;
  weekdays at; weekly on [days] at; monthly on day N; custom (raw RRULE
  with preview).

### Services (`app/Services/Outbound/`)

- **`ScheduledCallPlanner`** — `materialise(ScheduledCall, horizon)`:
  creates the next pending occurrence(s) (idempotent via the unique
  index) and updates `next_due_at`. Called on save, after each
  occurrence closes, and by the sweeper.
- **`OutboundCallGuard`** — `check(Team, target, kind, executor, at,
  callerIdDid): GuardDecision` (allow / defer-until / deny + reason).
  The only way to obtain a `PlacementTicket`; the placer refuses to
  dial without one. Rules in Compliance below.
- **`OutboundCallPlacer`** — the only class that originates customer-
  facing outbound calls. `place(Attempt, PlacementTicket)`: picks trunk
  (DID's trunk, **no "any active trunk" fallback** — contrast
  `HandoffDestinations::trunkFor()`), acquires concurrency slots,
  writes the attempt row, sends AMI Originate (below). Plan 05's voice
  callout transport and plan 14's virtual-hold callback should call
  this rather than AMI directly.
- **`AttemptResultRecorder`** — idempotent sink for results from three
  sources (dialplan CDR vars, AI session end, operator disposition);
  first terminal result wins, later ones only enrich. Decides
  retry / succeed / fail for the occurrence.
- **`OccurrenceEscalator`** — on a failed occurrence (or `needs_help`
  at any time): builds the result Message (plan 03 template
  "Outbound call result" with fields `program`, `due_local`,
  `attempts`, `outcome`, `target`), then `on_failure`:
  `dispatch` → plan 05 `DispatchMessage` with the program's policy
  (typical welfare policy: tier 1 SMS + voice callout to family
  contact, ack within 10 min; tier 2 backup contact; tier 3 operator
  dispatcher queue); `operator` → worklist item; `message_only` →
  message filed for the portal.
  **Before plan 05 ships**: email the escalation contacts via Laravel
  mail and put an item on the operator worklist — never fail silently.
- **`VoiceSuppressionRegistry`** — `isSuppressed(teamId, number)` (team
  rows + platform rows), `suppress(...)`, `lift(...)`; same shape as
  `OptOutRegistry`.

### Jobs and scheduling

- `routes/console.php`: `orbital:dispatch-scheduled-calls` —
  `everyMinute()->withoutOverlapping()->onOneServer()`. Selects
  occurrences with `status = pending AND coalesce(next_attempt_at,
  due_at) <= now()+60s` using `FOR UPDATE SKIP LOCKED`, flips them to
  `in_progress`, dispatches `PlaceScheduledCallJob` with a `delay` to
  the exact second. Also sweeps: `in_progress` attempts with no result
  after `ring_seconds + 15 min` → reconcile from CDR or mark `failed`
  (worker/Asterisk died); programs whose `next_due_at` is past with no
  occurrence → materialise.
- New Horizon supervisor `supervisor-outbound-calls`, queue
  `outbound-calls`, `tries = 1` (retries are *business* retries driven
  by the occurrence, never Horizon re-runs that could double-dial),
  `timeout = 30`, `maxProcesses` 2 — so a 9:00 burst of reminders can't
  starve `default` (telephony config regen, ARA syncs).
- Pacing: `Redis::throttle("outbound:trunk:{id}")->allow(cps)->every(1)`
  per trunk; `Redis::funnel` for concurrency per trunk (`max_channels`),
  per client (`outbound_max_concurrent`) and platform. A job that can't
  get a slot releases itself with a short delay; occurrences keep their
  order by `due_at`. Large batches due at the same minute are spread
  with jitter (≤ 2 min, reminders only; never wake-ups).
- `PlaceScheduledCallJob` → guard → (TTS) ensure prompt rendered →
  placer. `RenderOutboundPromptJob` renders per-occurrence TTS
  ~10 min ahead into the shared prompts volume, reusing the renderer
  used for `AiFallbackPrompt` / `VoicemailGreetingRenderer`
  (rendered `.sln` checked with `STAT(e,…)` as at
  `from-trunk.blade.php:131`); if the render isn't there at dial time,
  fall back to the client-level static prompt for that kind.
- `orbital:ingest-cdrs` (already every minute) gains a hook: rows
  tagged `orbital_attempt_id` are passed to `AttemptResultRecorder`.
- When `docs/plans/live-call-monitoring.md`'s `orbital:ami-listen`
  exists, it also forwards `UserEvent(OrbitalOutbound…)` to the
  recorder for sub-second results. CDR reconciliation stays as the
  backstop either way.

### Telephony

**Context generated in `extensions.blade.php` (new `[orbital-outbound-*]`
contexts; reachable only via AMI Originate, never from an endpoint's
context — same rule as the live-call plan's `orbital-spy`).**

Placement for `tts` / `ai` uses a Local channel so **our dialplan owns
the outcome even when nobody answers**:

```
AMI Originate
  Channel:  Local/{attemptId}@orbital-outbound-dial/n
  Context:  orbital-outbound-connected
  Exten:    {executor}           ; tts | ai
  Priority: 1
  CallerID: "{client name}" <{caller_id_did E.164}>
  Variable: ORBITAL_ATTEMPT={id}, ORBITAL_TEAM={team}, ORBITAL_TARGET={e164},
            ORBITAL_TRUNK=trunk_{id}, ORBITAL_RING={secs}, ORBITAL_PROMPT=…
```

- `[orbital-outbound-dial]` (;2 half): `Gosub(orbital-cdr-outbound,…)`
  sets `CDR(orbital_team_id)`, `CDR(orbital_attempt_id)`,
  `CDR(orbital_direction)=outbound`; `Dial(PJSIP/${ORBITAL_TARGET}@${ORBITAL_TRUNK},${ORBITAL_RING},b(orbital-outbound-headers^s^1))`;
  then always sets `CDR(orbital_dialstatus)=${DIALSTATUS}` and
  `CDR(orbital_sip_cause)=${HANGUPCAUSE(${HANGUPCAUSE_KEYS()},tech)}`
  so busy (486), no answer (480/408), invalid (404/484), declined (603)
  and **analytics-blocked (608)** are distinguishable.
- `[orbital-outbound-headers]`: per trunk `caller_id_mode`, add
  `P-Asserted-Identity` with the DID; nothing else (no caller-supplied
  headers).
- `[orbital-outbound-connected]` (;1 half, after answer):
  - `tts`: optional `AMD()` when `leave_voicemail` (→ `CDR(orbital_amd)`);
    on MACHINE play `voicemail_template` after the beep and end; on
    HUMAN `Playback(identification)`, `Playback(${ORBITAL_PROMPT})`,
    `Read(DIGITS,…,1,,2,8)`: `1` → confirmed, `2` → `needs_help`
    (check-in) / cancel (reminder), `9` → do-not-call (writes
    `CDR(orbital_dtmf)` — the recorder files the suppression), no input
    → replay once, then `not_confirmed`.
  - `ai`: `Dial(PJSIP/s{attemptId}@livekit,120,b(orbital-ai-headers^s^1))`;
    on failure → `[orbital-outbound-ai-failed]` (TTS static "Sorry,
    we'll try again shortly", mark attempt `failed`) — **not**
    `orbital-ai-unavailable`.
- Verify the image contains `app_amd`, `app_read` and
  `func_hangupcause`; they aren't in the explicit `--enable` list
  (`Dockerfile:55-111`). Add them explicitly.
- Fix along the way: `AsteriskAmiService::sendAction()` accepts a list
  of `[key, value]` pairs so each variable gets its own `Variable:`
  line; values containing commas (client names, prompt paths) no
  longer split (`AsteriskAmiService.php:309-315`).

**Operator executor (operator-first / "preview" dialling).** Reuse the
`TestCallPlacer` pattern:

1. The worklist item is claimed by an operator (row lock, `claimed_by`,
   expires after 2 min if not dialled).
2. `OperatorCall::create([... 'direction' => 'outbound',
   'team_id' => program team, 'outbound_token' => …])`, like
   `TestCallPlacer.php:76-92`.
3. AMI Originate `Channel: PJSIP/{operator endpoint}`,
   `Context: orbital-outbound-dial`, `Exten: {attemptId}`,
   `Variable: PJSIP_HEADER(add,X-Orbital-Outbound-Call)={token}` — the
   operator's softphone rings first, the operator answers, Asterisk
   dials the target from the same dial context (same CDR tags, same
   edge recording).
4. `MultiCallDesk::followSoftphone()` binds by token as
   `claimTestCall()` does (`MultiCallDesk.php:311`), generalised to
   "claim pre-created call by header token".
5. Operator records a disposition (buttons: Reached — confirmed /
   Reached — needs help / Voicemail left / No answer / Wrong number /
   Do not call) → `AttemptResultRecorder`. A dial failure before the
   operator disposes is filled from CDR.

**AI executor.**

- `AiCallTarget` gains prefix `s` → `fromScheduledAttempt(id)`:
  resolves team, persona and the orchestration's `outbound_phone`
  trigger entry flow (`IntakeFlow::channelEntryFlow()`), and **refuses**
  unless the attempt is `answered`/`ringing` and was placed < 5 min ago
  (no replaying a room name). `ROOM_NAME_RE` in `agent.py:38` becomes
  `^agent-_?([rs]?\d+)_`.
- The persona envelope gains `direction: "outbound"`,
  `outbound_context` (target name, program kind, due time, rendered
  reminder text, client name, callback number) — replacing the dead
  participant-name heuristic at `agent.py:79-84`; `outbound_greeting`
  becomes live.
- Mandatory first utterance is fixed (not LLM-generated): identification
  line (see Compliance), then the persona proceeds.
- Seeded outbound orchestration templates (via
  `ApplyOrchestrationTemplate`): *Wake-up* (greet → confirm awake →
  end), *Check-in* (greet → "how are you today?" → `gather_choice`
  ok / needs help / no clear answer → `save_summary` → end),
  *Appointment reminder* (read appointment → confirm / cancel /
  reschedule-requested → end), *Callback* (identify → "you asked us to
  call back about {reason}" → standard message flow).
- New worker tools: `report_outcome(outcome)` (enum validated
  server-side), `opt_out_calls()` (suppression with source `in_call`),
  `voicemail_detected()` (leave `voicemail_template`, hang up). Until
  plan 01's engine runs outbound sessions, these post to
  `/api/call-sessions/{key}/outcome` (worker-token auth like the other
  POST routes, `routes/api.php:131-143`); under plan 01 they become
  step exits.
- Outbound AI session end → `AttemptResultRecorder` via the existing
  `/call-sessions/{key}/end` hook.

**`schedule_callback` (plan 01 handler).**

- Catalogue gains params: `callback_number_slot` (default caller ID),
  `executor` (`operator` default / `ai` / `tts`), `persona`,
  `max_attempts`, and exits `scheduled` · `outside_hours` · `declined`
  (replacing `completion: slot_filled`).
- The handler parses the window slot (`allow_specific_time` → exact
  time; otherwise fuzzy window → `[start, end]` in the client zone,
  using a small parser: "tomorrow afternoon", "after 3", "Monday
  morning") and runs the guard *at scheduling time*: if the window is
  outside calling hours or client hours (plan 06), exit `outside_hours`
  so the script can offer another time. Creates a one-off
  `scheduled_call` with `source = script`, `consent_type =
  caller_requested`, provenance (`source_session_key`,
  `source_call_log_id`, message id once saved).
- Operator UI (plan 02 runner) gets the same as a button: "Schedule
  callback" on any call.

### Compliance (`OutboundCallGuard`)

Order matters; first deny wins; every decision is stored in
`attempt.guard_decision`.

1. **Kill switches**: platform `outbound.kill_switch`, team
   `outbound_calls_enabled`, program `active`.
2. **Blocked destinations**: N11 / 911 / 933 / 988, 900/976 premium,
   toll-free when presented caller-ID would be toll-free too,
   international unless `outbound.allow_international`, the client's
   own DIDs and any platform DID (loops), internal extensions.
3. **Voice DNC**: `VoiceSuppressionRegistry` (team + platform rows).
   Also `OptOutRegistry::isSuppressed()` for the same team: a person
   who texted STOP to the client is treated as having revoked consent
   for automated calls too (FCC's 2024 revocation order lets consumers
   revoke "by any reasonable means"; the safe default is cross-channel —
   configurable per platform, see Open questions). `operator` executor
   with `kind = callback` and `consent_type = caller_requested` within
   24h of the request may still be placed — the caller *just* asked —
   but the operator sees a red DNC banner.
4. **Consent**: `tts` and `ai` count as artificial/prerecorded voice
   (FCC Feb 2024 declaratory ruling: AI-generated voices are
   "artificial" under the TCPA), so prior express consent is required —
   `consent_type` + `consent_at` must be present or the program can't
   be activated. `client_attested` stores who attested and when; for
   wireless numbers and `reminder` kinds the admin form shows the
   consent requirement text.
5. **Calling hours** in the **target's** timezone: default window
   08:00–21:00 (47 CFR 64.1200(c)(1) applies to solicitations; we apply
   it to everything by default), narrowed by stricter state rules from
   `config/orbital.php` `outbound.state_windows` keyed by NPA→state
   (e.g. FL 08:00–20:00) and by the program's `calling_window`.
   **Exception**: `wake_up` and `callback` with `subscriber_requested` /
   `caller_requested` consent may run at the requested time (a 05:30
   wake-up is the service). Outside window → `defer-until` next window
   start if before `answer_deadline`, else occurrence `skipped
   (outside_hours)` + result message.
   Target timezone mismatch hint: if the NPA's home zone differs from
   `target_timezone`, the form warns (no libphonenumber today; a small
   NPA→tz table in config is enough for NANP).
6. **Caller ID**: presented number must be an `outbound_enabled`
   `ClientDid` of the **same team** and the chosen trunk must be that
   DID's trunk (`client_dids.sip_trunk_id`). No presenting the client's
   unhosted main number, the original caller's number, or another
   client's DID (Truth in Caller ID Act). Fail closed.
7. **Frequency caps**: ≤ `outbound_daily_cap_per_number` attempts per
   target per client per local day across all programs; ≤ 3 per hour.
8. **Capacity**: trunk `outbound_enabled`, `max_channels`, CPS.

**Identification**: every `tts`/`ai` call begins with a fixed,
non-editable line "This is {platform display name} calling on behalf
of {client name}" and ends (or offers on `0`) "To reach us call
{caller ID DID}" — 47 CFR 64.1200(b)(1)-(2) for artificial/prerecorded
messages. Templates are validated to keep it; the AI's first turn is
spoken by the worker from the envelope, not generated.

**In-call opt-out**: TTS "press 9 to stop these calls"; AI tool
`opt_out_calls`; operator disposition "Do not call". All three write a
`voice_suppression`, pause every active program for that number in
that client, and create a message for the client ("Mrs X asked to
stop check-in calls") — the client must know their welfare service
stopped.

**STIR/SHAKEN**: Orbital doesn't sign and won't in this plan; the
carrier signs as originating provider. Attestation A requires the
carrier to know we're authorised for the number — hence rule 6
(present only DIDs hosted on that trunk). `client_dids.outbound_verified_at`
records that the carrier confirmed A-attestation for that number
(manual check, e.g. test call to a verification line).
608 responses (`Rejected` by analytics) and repeated 603 for one DID
raise a Health card ("Outbound calls from +1… are being blocked —
register the number with the Free Caller Registry / carrier") and pause
programs using it after N in 24h.

**Recording disclosure**: outbound calls are recorded at the edge like
everything else. The disclosure prompt is currently not played anywhere
(`resources/views/asterisk/partials/mix-monitor.blade.php` — "beep +
disclosure are silently dropped"). For outbound to two-party-consent
states either the identification line includes "this call may be
recorded" or recording is disabled per program. Default: include it.

**Reassigned numbers**: long-running check-in programs call the same
number for months. Optional (Open questions) monthly FCC Reassigned
Numbers Database query; until then the program shows "consent date"
and prompts re-confirmation yearly.

### UI

**Admin (Filament, platform staff)**

- `ClientResource` sub-page **Scheduled calls** (pattern of
  `ManageClientOptOuts`): table (name, kind, target, next due in target
  tz, executor, last outcome, status), create/edit form with the
  sections above (target, schedule presets + preview, executor,
  confirmation, retries, escalation, caller ID, consent), actions
  *Run now* (still guarded), *Skip next*, *Pause/Resume*, *Duplicate*.
- Program detail: occurrence timeline — each occurrence, its attempts
  (time, result, SIP cause, duration, recording player, transcript for
  AI), guard decisions on skips, escalation dispatch link (plan 05
  timeline).
- **Voice DNC** sub-page per client + a platform-wide list (super-admin)
  — add, lift with mandatory note; history never deleted.
- **CSV import** for reminder batches (name, number, appointment time,
  free fields for the template) → one-off programs; dry-run shows guard
  failures per row before committing.
- Platform **Outbound activity** page: due next hour, in progress,
  failed today, deferred by hours, 608/603 per DID, concurrency usage;
  kill switch toggle.
- Trunk / DID forms gain the outbound fields; `SystemHealthService`
  card for misconfigured outbound (program presents a DID that isn't
  `outbound_enabled`, trunk with outbound programs but `outbound_enabled`
  false).

**Operator (Workspace)**

- **Outbound due** panel (shares the worklist component with plan 05's
  dispatcher queue / plan 18's dispatch list): items due now/overdue
  for clients the operator serves, sorted by due time and kind
  priority (check-in escalations first). Each shows target, program,
  attempt N of M, instructions / script, client call brief
  (`ClientCallBrief`), previous attempt results.
- Claim → Dial (operator-first) → plan 02 `ScriptRunner` if the
  program has an orchestration, else instructions + disposition
  buttons.
- The multi-call desk shows outbound calls with an "Outbound" badge;
  holding/hanging up works as for inbound.
- Inbound calls from a number we called in the last 24h (callee calling
  back the caller ID) show "We called this number at 07:02 (Daily
  check-in — no answer)" — a plan 13 caller-history hint fed by
  `scheduled_call_attempts`.

**Client portal**

- **Scheduled calls** page (`portal.view_scheduled_calls`): programs,
  next due, last 30 days of outcomes, recordings if
  `portal.view_recordings`.
- `portal.manage_scheduled_calls` (granted to `client_admin`, never to
  `client_user` by default): create/pause/cancel programs and import
  reminder CSVs — with the consent attestation checkbox and text
  recorded against the user.
- Clients can **add** numbers to their voice DNC; **lifting** requires
  platform staff (written request), same stance as SMS opt-outs.
- Call History shows outbound calls with a direction filter.

### Permissions (`PermissionCatalogSeeder`)

`scheduled_call.view_any/view/create/update/delete/run_now`,
`voice_suppression.view_any/create/lift`, `softphone.outbound`
(operators may claim outbound worklist items), `outbound.manage_platform`
(kill switch, platform DNC), `portal.view_scheduled_calls`,
`portal.manage_scheduled_calls`, `portal.manage_call_suppressions`.

### Tenant isolation

- All four new tables `BelongsToTeam` — but the scheduler and jobs run
  without a user, where the global scope is a no-op
  (`BelongsToTeam.php:16-20`). So:
  - every job loads by id **and** asserts `team_id` against the
    occurrence's team; queries in commands use explicit
    `where('team_id', …)`.
  - A `ScheduledCall::saving` guard validates that `agent_persona_id`,
    `orchestration_id`, `caller_id_did_id`, `directory_entry_id`,
    `dispatch_policy_id` all belong to the program's team (FormRequest
    for API/portal, model guard as backstop).
  - `AiCallTarget::fromScheduledAttempt()` derives the team only from
    the attempt row; the persona/orchestration must match it.
  - `voice_suppressions.team_id IS NULL` rows are readable by the guard
    for every team but only listed/edited by super-admins.
- Operator worklist shows only clients the operator is assigned to (the
  existing desk/team membership rules), and `OperatorCall` binding by
  token checks `user_id` as `followSoftphone()` does.

### Audit

- `scheduled_call_revisions` (who, when, field diff, source
  admin/portal/api) — clients editing their own programs must be
  visible to operators ("changed by client 2h ago", as plan 04 does for
  rotas).
- Attempts + `guard_decision` are the per-call audit; occurrences are
  never deleted (soft-cancel). Suppressions keep full history.
- Consent fields are immutable once an attempt has been placed under
  them; changing consent creates a revision and a new `consent_at`.
- Plan 01 `script_events` carry the AI/operator script path for
  engine-run outbound sessions.

## Edge cases and failure modes

| Case | Behaviour |
|---|---|
| AMI down / Originate refused | Attempt `refused`, occurrence retried after 1 min up to 3 times *without* consuming a business attempt; then occurrence `failed` + escalate; Health card. |
| Asterisk accepted but no result (node died) | Sweeper after `ring + 15 min`: reconcile from CDR; if none, attempt `failed`, retry policy applies. |
| Duplicate scheduler ticks / two scheduler replicas | `onOneServer` + `SKIP LOCKED` + unique `(scheduled_call_id, due_at)`; `PlaceScheduledCallJob` checks attempt state before dialling. Never `tries > 1` on the job. |
| Program edited / paused while an attempt is ringing | Running attempt finishes; occurrence uses `config_snapshot`; pause cancels remaining retries of that occurrence. |
| Number becomes suppressed mid-retries | Guard runs before *every* attempt, not just the first; occurrence `skipped (suppressed)`. |
| Retry would cross the calling window | Defer to window start if before `answer_deadline`; wake-up/check-in otherwise fail and escalate (time-critical); reminder skipped with message. |
| DST gap / overlap | See Recurrence; tested. |
| Target timezone differs from client | Guard uses target zone; UI shows both. |
| Voicemail answers a wake-up | Without confirmation → `not_confirmed` → retry; voicemail never counts as success for `keypress`/`speech` modes. |
| AMD false positive (human treated as machine) | Only used when `leave_voicemail`; TTS still offers "press 1" after the message; result `voicemail_left` not `confirmed`. |
| 404/484 invalid number | No retry; occurrence failed; program flagged "number invalid" and paused after 2 consecutive. |
| 608 / repeated 603 | Rejected, not retried on same DID; Health card; auto-pause after threshold. |
| AI worker fails mid-call | Callee hears outbound-specific apology (not `orbital-ai-unavailable`); attempt `failed`; retry or fall back to `tts` executor if `fallback_executor` set. |
| No operator claims an operator-executor item before deadline | Occurrence `failed (no_operator)` → escalate; supervisor board shows overdue outbound items. |
| Check-in answered but "needs help" | Immediately `needs_help` → urgent message + plan 05 dispatch, even though the call "succeeded". |
| Callee calls back the presented DID | Normal inbound routing for that DID; caller-history hint shows the outbound attempt. |
| Burst: 400 reminders at 09:00 | Throttled per trunk CPS and concurrency; jitter; reminders have a deadline so late ones still go. |
| Client disabled / deleted | Team flag off → guard denies; programs of a soft-deleted client are cancelled by observer. |
| `schedule_callback` with a fuzzy window that's already past | Handler exit `outside_hours`; script asks again. |
| Callback number differs from ANI | Stored from slot; guard runs on the slot number; consent `caller_requested` applies to the number the caller gave. |

## Migration / rollout

1. **Foundation (no calls placed)**: tables, models, `RecurrenceExpander`
   (shared with 04), guard, suppression registry, admin CRUD with
   occurrence preview, permissions, kill switch default **on**
   (blocking). `CdrIngester` learns `orbital_direction=outbound` and
   `orbital_attempt_id`. AMI `sendAction` variable fix.
2. **TTS executor + wake-up/reminder**: dialplan contexts, placer,
   result recorder, retries, prompt rendering, Call History rows.
   Enable for one internal test client; place calls to staff phones.
3. **Escalation**: result messages (plan 03 if shipped, else current
   `Message` with `reason` = summary) and `on_failure`; plan 05
   dispatch when available, email + worklist before that.
4. **Operator executor**: worklist panel, operator-first originate,
   token binding, dispositions.
5. **AI executor**: `AiCallTarget` `s` prefix, worker direction flag,
   outbound templates, outcome/opt-out tools. Ships on the `prompt`
   runtime first, moves to plan 01 engine with the rest.
6. **`schedule_callback`** handler + operator "Schedule callback"
   button; portal pages; CSV import; API endpoint for client systems
   (auth via existing `TeamApiKey`).

Per-client opt-in (`teams.outbound_calls_enabled`) throughout. If NCI
runs wake-up/check-in services in Amtelco today, import those programs
(Open questions) with `source = import` and `consent_type =
client_attested`, all **paused**, for staff to review and activate.

## Tests

Answering-service suite (frozen clock with `travel()`, fake AMI client
recording originates, fake CDR rows, `Queue::fake()` where noted):

**Recurrence / planner**
- Daily 07:00 America/New_York across the March and November DST
  changes → UTC instants shift by an hour, local stays 07:00.
- 02:30 daily on spring-forward day → runs 03:00, flagged.
- Weekdays RRULE skips Sat/Sun; `exdates` honoured; `UNTIL` ends program
  → `completed`.
- Program with `respect_schedule_id` on a plan 06 holiday → occurrence
  `skipped` with reason, next one materialised.
- Re-running the planner twice creates one occurrence (unique index).

**Guard**
- Suppressed via `voice_suppressions` (team) → deny; via platform row →
  deny for every team; via `MessagingOptOut` STOP → deny when
  cross-channel setting on.
- `+1` / bare 10-digit / formatted spellings all match one suppression
  (`keyVariants`).
- 07:30 target-local reminder → defer to 08:00; 05:30 wake-up with
  `subscriber_requested` → allowed; FL number 20:30 → deferred.
- `tts` program without consent can't be activated (validation).
- Caller ID DID of another client → deny; DID of same client on a
  different trunk than chosen → deny; no trunk fallback.
- 911, 988, 1-900, international, the client's own DID → deny.
- Daily cap: 7th attempt of the day to one number → deny.

**Execution (fake AMI)**
- Wake-up, answered, DTMF `1` (CDR vars) → attempt confirmed,
  occurrence succeeded, `CallLog` direction `outbound` with
  `scheduled_call_attempt_id`, team correct.
- No answer ×3 with 5-min interval → three originates at the right
  times, then occurrence failed → result message + escalation
  (plan 05 `DispatchMessage` dispatched; pre-05: mail to contacts +
  worklist item).
- Busy then answered → two attempts, success; busy not counted beyond
  `max_attempts`.
- 404 → no retry, program paused after second occurrence.
- 608 → Health card, no retry on that DID.
- Check-in DTMF `2` → `needs_help` urgent message dispatched
  immediately.
- DTMF `9` → suppression row, all programs for that number in the
  client paused, client message created.
- Originate refused → infra retry, business attempt count unchanged.
- Worker/Asterisk silent → sweeper reconciles from CDR, else failed.
- Two scheduler runs in the same minute → one originate.
- Edit program during ringing attempt → retry uses snapshot.
- Concurrency: client cap 2, five due at once → two originates, rest
  released and placed as slots free, in due order.

**Operator path (Livewire)**
- Item appears on worklist for an assigned operator only; claim locks it
  for others; claim expiry returns it.
- Dial → `OperatorCall` direction outbound created with token;
  `followSoftphone` binds by `X-Orbital-Outbound-Call`; disposition
  "Reached — needs help" escalates.
- Operator for client A never sees client B's items.

**AI path**
- `AiCallTarget::resolve('s123')` → persona + outbound entry flow of the
  attempt's team; stale attempt (> 5 min) or ended attempt → null.
- Persona envelope for `s…` has `direction=outbound` and the outbound
  context; worker contract test for `report_outcome` / `opt_out_calls`.
- AI fallback on outbound reaches `[orbital-outbound-ai-failed]`, not
  `orbital-ai-unavailable` (dialplan snapshot).

**schedule_callback**
- "Tomorrow afternoon" at 16:00 Tuesday → one-off program Wednesday
  window 12:00–17:00 client tz, `consent_type=caller_requested`,
  provenance set; exit `scheduled`.
- Requested 22:30 → exit `outside_hours`, no program created.
- Callback executes on the operator worklist with the original
  message and call linked.

**Dialplan / config snapshots**
- Generated `[orbital-outbound-dial]` / `-connected` / `-headers` /
  `orbital-cdr-outbound` contexts; no endpoint context can `Goto` them.
- `CdrIngester` with outbound-tagged rows → `direction=outbound`,
  `to_number` = target, team from `orbital_team_id`.

**Tenant isolation**
- Job for team A's occurrence with a persona id from team B (forged
  row) → refuses.
- Portal user of client A can't list/create programs for client B;
  can't lift a suppression.
- Platform DNC row blocks all teams but is invisible in client portals.

## Open questions

1. Does NCI sell wake-up / check-in / reminder services today (07 Q5),
   how many programs, and can they be exported from Amtelco (IS
   "Scheduled Events" / wake-up lists)?
2. Cross-channel revocation: should an SMS STOP block automated
   *calls* for that client (default proposed: yes)? And the reverse
   (voice "press 9" blocking SMS)? Needs NCI's counsel; the FCC's
   "revoke all" provision timing should be confirmed.
3. Which carrier(s) will carry outbound, and do they give A-attestation
   for every NCI-hosted DID? Is number registration (Free Caller
   Registry / branded calling) NCI's job or the platform's?
4. Recording of outbound calls in two-party-consent states: disclosure
   line always, or recording off for outbound by default?
5. Do check-in escalations need an operator to call family (classic IS
   practice) or is SMS/voice-callout to family via plan 05 enough?
6. Reassigned Numbers Database subscription for long-running programs —
   worth the cost/integration?
7. Should clients be able to create programs themselves in the portal
   at launch, or platform staff only (consent responsibility)?
8. Is a client API (create reminder from practice-management system)
   needed at launch, or CSV import only?
9. Add `giggsey/libphonenumber-for-php` for line-type (wireless vs
   landline) and timezone lookups, or keep the NANP config tables?

## Size estimate

~4.5–5.5 weeks:

- Model, recurrence expander (shared with 04), planner, guard,
  suppressions, admin CRUD + preview: 1.5 wk
- Dialplan contexts, placer, AMI fixes, CDR ingest, result recorder,
  retries, TTS rendering: 1 wk
- Escalation wiring (pre-05 fallback + 05 hook), result messages: 0.5 wk
- Operator worklist + operator-first dialling: 0.75 wk
- AI executor (target prefix, worker changes, templates, tools): 0.75 wk
- `schedule_callback` handler, portal pages, CSV import: 0.75 wk
- Compliance review with NCI/counsel and carrier attestation checks:
  calendar time, not engineering time.
