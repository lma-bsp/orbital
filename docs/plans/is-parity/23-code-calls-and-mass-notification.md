# Plan 23: Code calls and mass notification

**Status:** proposed · part of [IS parity](README.md) · depends on 04
(on-call groups), 05 (dispatch, acknowledgement, transports); uses 01/02
(script runtime, operator runner) and 08 (conferencing) where they exist ·
survey item Q in [07](07-additional-features.md) · **P3: build only when a
client signs that needs it**, but designed in full so the work can start
without another design round.

## Problem

### What IS does

Amtelco sells IS into hospital switchboards as well as answering
services. Two related features matter here (see the Amtelco feature
list linked from 07):

- **Code calls.** A nurse dials the code line, the operator picks the
  code (Blue, Rapid Response, Stroke, STEMI, Trauma, Red, Pink…) and
  location, and the script drives a fixed sequence: overhead
  announcement over the hospital paging system (often repeated), group
  page to the code team, follow-up calls, "all clear". Every step is
  time-stamped, because the hospital is asked afterwards how long it
  took from call to overhead to first responder.
- **Mass / emergency notification.** Send one message to a group by
  voice, SMS, email and pager at the same time; recipients answer
  ("responding" / "not available"); the console shows a live
  **roll-call** of who is coming; responders can be dropped into a
  **conference bridge**; drills run the same path marked as tests;
  everything ends in a report.

### What Orbital has today (checked in the code)

| Area | Today | Reference |
|---|---|---|
| Code-call / mass-notification model, service, UI | **None.** No hits for overhead, code call, roll call or mass notification in `app/` or the seeders. | grep |
| Script steps | `send_page` (one pager number, one body), `enter_dispatcher_queue`, `auto_dispatch`, `park_call` with a `conference_bridge` option, `save_call_tracker_event` — catalogue entries only, no executor (plan 01). `send_sms`/`send_page` already carry a `send_in_test_drive` flag. | `database/seeders/IntakeGoalLibrarySeeder.php:491`, `:498-516`, `:791`, `:812`, `:858`, `:1028` |
| Sending SMS | `OutboundMessageService` sends **one reply on one `MessageThread`**, synchronously, and is meant to be the single place the opt-out check lives. No batch or fan-out path. | `app/Services/Messaging/OutboundMessageService.php:33-38`, `:71-167` (opt-out at `:83`) |
| Provider | `TwilioProvider::send()` is a blocking HTTP POST (10 s timeout) and refuses to send without a Messaging Service SID. Only `TwilioProvider` and `LogProvider` exist. The contract has no batch send. | `app/Services/Messaging/Providers/TwilioProvider.php:178-245`, `:192-205`; `config/messaging.php:55`; `app/Services/Messaging/Contracts/MessagingProvider.php:30` |
| Outbound rate limits | **None.** The only `RateLimiter::for` definitions are Fortify login/2FA and `messaging-inbound`. | `app/Providers/AppServiceProvider.php:72-74`, `app/Providers/FortifyServiceProvider.php:43,49` |
| Inbound limit | Provider webhooks (inbound SMS **and** delivery receipts) are capped at 300/min **per source IP**. | `AppServiceProvider.php:72-74`, `routes/api.php:89-91` |
| Extension types | `sip_phone, ata, softphone, webrtc_client, staff_softphone, ai_agent, virtual`. No paging / intercom type, no auto-answer. | `database/migrations/2026_04_11_100003_create_extensions_table.php:24-32`; `app/Models/Extension.php:15-91` |
| Endpoint naming | Client endpoints are `t{team}_{number}` in Asterisk. | `app/Models/Extension.php:83-90` |
| Groups | `AgentGroup` is a **platform** pool of staff for queues — no `BelongsToTeam`, wrong place for a client's code team. `CallQueue` is inbound routing only. `DirectoryTag` is a per-client tag with an entries pivot — usable as "everyone tagged X". `DirectoryEntry` is `BelongsToTeamOrSharedPool` with JSON `values`, no contact-method list yet (plan 04 adds it). | `app/Models/AgentGroup.php:12-25`; `app/Models/CallQueue.php:14-17`; `app/Models/DirectoryTag.php:13-36`; `app/Models/DirectoryEntry.php:24-57` |
| Asterisk modules | `app_page`, `app_confbridge`, `app_chanspy` are compiled in. `bridge_softmix` (needed by ConfBridge) and `chan_rtp` (MulticastRTP) are not named and rely on menuselect defaults — check with `module show like softmix` / `like chan_rtp` before building on them. | `docker/asterisk/Dockerfile:82,89,90` |
| Dialplan | **No `Page()` or `ConfBridge()` anywhere** in the generated templates (`extensions`, `client-dialplan`, `from-trunk`, `dialplan-index`, `voicemail`, `partials/mix-monitor`). No `confbridge.conf` in `docker/asterisk/`; the image only has the `make samples` copy. | `resources/views/asterisk/`; `docker/asterisk/Dockerfile:116,178` |
| Predial header pattern | Exists for AI calls: `Dial(...,b(orbital-ai-headers^s^1))` — the same mechanism can add auto-answer headers for paging. | `resources/views/asterisk/client-dialplan.blade.php:31` |
| AMI | One-shot client to one configured host, logged in with `Events: off` — it can originate (`originate`, `originateToApplication` with comma-joined variables) but **cannot observe** answers, DTMF or hangups. `commandOn(host)` reaches other nodes for `Command` only. AMI user has `originate`. No ARI client in `app/`. | `app/Services/Telephony/AsteriskAmiService.php:22-27`, `:47-64`, `:129`, `:256-321`, `:327-341`; `config/telephony.php:11-16`; `docker/asterisk/manager.conf:23` |
| Cluster | Several Asterisk nodes behind Kamailio (`AsteriskBackend`). A ConfBridge lives on one node. | `app/Models/AsteriskBackend.php:11-18` |
| TTS prompts | Per-client TTS WAVs rendered into `/var/spool/asterisk/prompts/…` for `Playback()` — reusable for announcements. | `app/Services/Telephony/VoicemailGreetingRenderer.php:12-21`, `DisclosureRenderer.php:28` |
| Trunk capacity | `SipTrunk.max_channels` exists — bounds parallel voice callouts. | `app/Models/SipTrunk.php:30` |
| Broadcasting | Reverb. Three events, all on public or per-user channels (`system-health`, `admin-alerts`, `operator.drain.{id}`). **No team-scoped private channel.** | `app/Events/*`; `routes/channels.php:5,14,19`; `.env.example:333` |
| Tenant scope | `BelongsToTeam` filters only when a user is logged in; queued jobs and webhooks run **unscoped**. | `app/Models/Concerns/BelongsToTeam.php:16-35` |
| Audit | No generic audit package (only `spatie/laravel-permission`); per-domain tables (`FailoverAuditLog`, `UserLogoutEvent`). Plan 01's append-only `script_events` is the pattern to follow. | `composer.json:31` |
| Permissions | Fixed catalogue, seeded; `softphone.conference` exists, nothing for notifications. No PDF library is installed. | `database/seeders/PermissionCatalogSeeder.php:19-60` (`:38`) |

So: the building blocks (Page/ConfBridge modules, TTS rendering, AMI
originate, SMS provider, directory, tags) exist; nothing ties them into
a group send, a response, a code, or a timeline.

## Goal

One **notification engine** with two front ends:

1. **Mass notification** — a templated message to a group over several
   channels at once, live roll-call of responses, optional conference
   bridge for responders, drills, report. Useful beyond hospitals
   (property managers' storm call-outs, a client's staff call-in).
2. **Code calls** — an operator (or authorised client staff) starts a
   code of a configured type at a location; Orbital runs the code's
   playbook — overhead page, code-team notification with roll-call,
   escalation, all-clear — recording every step to the second, and
   produces an after-action report.

Non-goals: replacing a hospital's nurse-call or fire-alarm system;
public alerting (IPAWS/WEA); multicast audio across the WAN; being a
certified life-safety system (contract language, see Open questions).

## Design

### Concepts

| Concept | Meaning |
|---|---|
| Notification group | Who: a reusable recipient set built from directory entries, on-call groups (plan 04), tags and other groups. |
| Template | What: per-channel bodies, response options, bridge on/off. |
| Campaign | One send of a template to a resolved group — a mass notification, or the team-notify part of a code. Owns the roll-call. |
| Code type | A client's code definition (Code Blue) plus its playbook. |
| Code event | One activation of a code type: location, timeline, campaign(s), overhead announcements, bridge. |
| Paging zone | A place Orbital can make an overhead announcement. |
| Notification event | Append-only audit row for everything above. |

Delivery is **not** reimplemented: each campaign recipient gets a plan
05 `dispatch` under a campaign-specific policy, and plan 05's
attempts/acknowledgements carry the per-channel truth.

### Data model

All tables `team_id` + `BelongsToTeam` unless noted.

**`notification_groups`** — `name`, `slug`, `description`,
`min_responders` (int, nullable — for codes), `is_active`.

**`notification_group_members`** — `notification_group_id`,
`member_type` (`directory_entry | on_call_group | directory_tag |
notification_group`), `member_id`, `on_call_positions` (JSON, e.g.
`[1]` primary only, `[1,2]` primary+backup; on-call members only),
`role_label` ("Anesthesia", "Respiratory"), `required` (bool — the code
isn't "staffed" until this role responds), `sort`. Nested groups are
allowed to depth 3; cycles rejected on save.

**`notification_templates`** — `team_id` nullable (platform-shared
starters, like orchestrations in plan 03), `name`, `category`
(`code | emergency | operational | test`), `sms_body`, `page_body`,
`email_subject`, `email_body`, `voice_script`, `overhead_script`,
`response_options` (JSON `[{key:"1", label:"Responding", counts_as:
"yes"}, {key:"2", label:"Not available", counts_as:"no"}, {key:"3",
label:"Responding, 15+ min", counts_as:"yes", eta:15}]`),
`include_bridge`, `required_variables` (e.g. `["code.location"]`).
Rendering uses the plan 03 `TemplateEvaluator`; variables include
`code.name`, `code.location`, `code.details`, `campaign.response_url`,
`bridge.number`, `bridge.pin`, `drill_prefix`.

**`notification_campaigns`** — `kind` (`mass | code_team | test`),
`code_event_id` nullable, `template_id`, `rendered` (JSON snapshot of
every channel body as sent), `group_snapshot` (JSON: resolved
recipients and where each came from), `dispatch_policy_id` (plan 05),
`delivery_mode` (`live | drill | dry_run`), `require_response`,
`response_deadline_at`, `status` (`draft | sending | active | closed |
cancelled`), `conference_bridge_id`, `created_by`, `started_at`,
`closed_at`, `closed_by`, cached counters (`targeted, delivered,
responded_yes, responded_no, unreachable`), `idempotency_key` (unique
per team).

**`campaign_recipients`** — `notification_campaign_id`,
`directory_entry_id` (nullable for ad-hoc numbers), `display_name`,
`role_label`, `required`, `source` (`tag:icu`, `on_call:anesthesia#1`…),
`dispatch_id` (plan 05), `response_key`, `response_label`,
`responded_at`, `response_channel` (`sms | voice | link | portal |
operator | bridge`), `eta_minutes`, `response_raw`, `is_late`,
`token_hash` (per-recipient response link), `unreachable_reason`
(`opted_out | no_contact_method | all_attempts_failed`).

Plan 05's `acknowledgements` gains a nullable `response_key`; any
response is an acknowledgement (stops that recipient's escalation).
"Not available" is still an ack, but the campaign may then reach for
the same role's next on-call position (see escalation below).

**`code_types`** — `code` ("BLUE"), `name`, `color`, `priority`,
`location_mode` (`list | free_text | list_or_free`), `locations`
(JSON list or FK to a client location list if plan 20 provides one),
`notification_group_id`, `template_id`, `all_clear_template_id`,
`playbook` (JSON, below) **or** `orchestration_id` (plan 01),
`client_can_trigger` (bool), `auto_close_after_minutes`, `is_active`.

**`code_events`** — `code_type_id`, `status` (`initiated | paging |
active | all_clear | cancelled`), `delivery_mode`, `location`,
`details`, `source` (`operator | portal | phone_trigger | api`),
`call_log_id` (the call that reported it), `script_session_key`,
`initiated_by_user_id`, `initiated_at`, `confirmed_at`,
`all_clear_at`, `all_clear_by`, `cancel_reason`, `idempotency_key`.

**`paging_zones`** — `name` ("All call", "4 West"), `transport`
(`sip_gateway | pbx_dial | multicast`), `extension_ids` (JSON; new
Extension type `paging`), `dial_string` (for `pbx_dial`: trunk + number
+ post-answer DTMF zone digits), `multicast_address`/`port`/`codec`
(only for `multicast`), `auto_answer_style` (`call_info | alert_info |
none`), `pre_tone`, `max_seconds`, `allow_drills`, `is_active`.
Managed by platform staff only (infrastructure).

**`paging_announcements`** — `paging_zone_id`, `code_event_id`
nullable, `mode` (`tts | recorded | live`), `text`, `audio_path`,
`repeats`, `status` (`queued | playing | done | failed`), `channel`,
`started_at`, `ended_at`, `failure`.

**`conference_bridges`** — `campaign_id`, `room` (`t{team}_br{id}`),
`pin`, `asterisk_backend_id` (node it lives on), `record`,
`call_recording_id`, `opened_at`, `closed_at`.
**`bridge_participants`** — `conference_bridge_id`,
`campaign_recipient_id` nullable, `caller_id`, `channel`, `joined_at`,
`left_at`.

**`notification_events`** (append-only) — `team_id`, `subject_type`
(`code_event | campaign`), `subject_id`, `seq`, `type`, `actor_type`
(`user | recipient | system | provider`), `actor_id`, `payload` (JSON),
`occurred_at` (server UTC, millisecond precision), `client_time`
(operator browser time, display only). Types:
`code.initiated`, `code.location_set`, `code.confirmed`,
`overhead.queued/started/completed/failed`, `campaign.started`,
`recipient.sent/delivered/failed/unreachable`, `response.received`,
`response.late`, `escalation.next_position`, `bridge.opened`,
`bridge.joined/left`, `checkpoint.confirmed`, `note.added`,
`code.all_clear`, `code.cancelled`, `campaign.closed`,
`report.generated`. The model throws on update/delete; a migration adds
a Postgres trigger rejecting `UPDATE`/`DELETE` on the table.

Feature flags on `teams`: `code_calls_enabled`,
`mass_notification_enabled` (both default false; menus hidden).

### Playbook

With plan 01 shipped, a code type points at an orchestration and this
plan adds step handlers to the catalogue: `overhead_page(zone,
template|text, repeats)`, `notify_group(group, template, policy)`,
`open_bridge`, `wait_for_responses(n | required_roles, timeout) →
exits met/timeout`, `code_checkpoint(label)` (operator confirms a
manual action), `all_clear`. The operator runner (plan 02) drives it.

If a client needs codes before plan 01 exists, `code_types.playbook`
holds a **linear** list of the same step keys and a small
`CodePlaybookRunner` executes it with the same handlers; converting to
an orchestration later is a mechanical import. Default Code Blue
playbook:

1. `code_checkpoint` "Location read back to caller"
2. `overhead_page` zone *All call*, ×3, 2 s gap
3. `notify_group` Code Blue team, template *Code Blue*, policy *code*
4. `open_bridge` (if the template includes one)
5. `wait_for_responses` required roles, 120 s → on timeout escalate
   to next on-call position and alert the operator
6. `code_checkpoint` "Called house supervisor"
7. (operator) `all_clear` → overhead "Code Blue, 4 West, all clear" +
   SMS "ALL CLEAR" to the team, close campaign, close bridge after
   5 min grace.

### Services

`app/Services/Notification/`:

- **`GroupResolver::resolve(NotificationGroup, CarbonImmutable $at):
  RecipientSet`** — expands members (on-call via plan 04's
  `OnCallResolver::who`, tags via `directory_entry_directory_tag`,
  nested groups), de-duplicates by directory entry and then by contact
  address, keeps every source for the report, attaches contact methods
  (plan 04) and directory status (plan 11, if present — codes still
  notify "off duty" people but flag them; mass sends can filter them).
  Every entry is checked against the campaign's team (or a shared
  directory attached to it).
- **`CampaignLauncher::launch(Template, Group|RecipientSet, options)`**
  — validates required variables, renders and snapshots, creates the
  campaign + recipients in one transaction, then queues
  `SendCampaignChunk` jobs (50 recipients each). Idempotent on
  `idempotency_key`.
- **Fan-out** goes through plan 05's dispatch engine with a
  campaign policy: codes use "all methods in parallel, tier wait 60 s,
  then next on-call position"; mass sends default to "first available
  method, then next".
- **`OutboundThrottle`** — a Redis limiter wrapped around
  `MessagingProvider::send()` per (provider, sender pool), with
  throughput configured on the messaging endpoint (it depends on number
  type and carrier registration, so it's data, not a constant). Two
  Horizon queues on their own supervisor: `notify-urgent` (codes) and
  `notify-bulk` (mass) so a 2,000-person staff notice never delays a
  code page, and neither waits behind `default`.
- **SMS path.** Group sends must keep `OutboundMessageService` as the
  single opt-out chokepoint (its own doc comment says so). Add
  `sendToAddress(MessagingEndpoint, string $to, string $body,
  ?CampaignRecipient)` that finds or creates the `MessageThread` for
  (endpoint, recipient) and reuses the existing private `send()`. Each
  recipient therefore has a thread, and inbound replies already land
  on it through `InboundMessageRouter`.
- **`ResponseRecorder`** — one entry point for every channel: SMS reply
  (a `CampaignReplyMatcher` runs in `InboundMessageRouter` before
  normal thread handling: open campaign recipient on this thread →
  parse `1`/`2`/`3`, `Y`/`YES`/`OMW` → yes, `N`/`NO` → no, else store
  raw and flag for the operator; reply with a confirmation "Recorded:
  RESPONDING to CODE BLUE 4W"), voice callout DTMF (plan 05 voice
  transport must return the key pressed), signed response link
  (`/r/{token}` — buttons, no login, works from email, SMS, secure app),
  portal button, operator "record on behalf", and bridge join (counts as
  "responding"). Writes the recipient row, the plan 05 ack and a
  `response.received` event in one transaction; first answer wins,
  later ones are events only.
- **`RollCall`** — read model for the board: counts by response, the
  required roles still uncovered, time since start, ETA list.
- **Broadcasts** — `CampaignUpdated` and `CodeEventUpdated` on a new
  private channel `team.{teamId}.notifications` (authorised for
  platform staff with `campaign.view_any` and for that team's portal
  users with `portal.view_code_calls`). Coalesced: at most one
  broadcast per campaign per second via a debounced job, so 500
  replies don't become 500 websocket frames.
- **`AfterActionReport`** — built when a code or campaign closes (and
  rebuildable): timeline, intervals, per-recipient table, recordings,
  notes, failed steps. Stored as a versioned snapshot with a content
  hash. HTML print view + CSV; PDF only if a renderer is added (none
  installed today).

`app/Services/CodeCalls/`: `CodeCallService` (initiate, confirm,
checkpoint, all-clear, cancel), `CodePlaybookRunner` (if no plan 01),
`OverheadPager`, `ResponderBridge`.

### Telephony

**Overhead paging.** Orbital's Asterisk is hosted; the paging
amplifiers are on the hospital LAN, and multicast does not cross the
WAN. Supported topologies, in order of preference:

| Topology | How | When |
|---|---|---|
| A. SIP paging gateway | An on-site SIP paging adapter (Algo, Valcom, CyberData, Bogen class) registers to Orbital as an Extension of new type `paging` through the Kamailio edge (same path as desk phones), auto-answers, and drives the amplifier — or its own local multicast zones (one SIP extension per zone). | Default. |
| B. Hospital PBX paging access | Dial the hospital PBX's paging feature over a `SipTrunk` / tie line, `SendDTMF` the zone after answer. | No gateway; the client's PBX allows it. |
| C. Phone speakers | `Page()` to many registered phones with an auto-answer header. | Small sites with Orbital-registered phones. |
| D. MulticastRTP | `Page(MulticastRTP/basic/239.x.x.x:port)` | Only on an on-site Orbital install whose Asterisk shares the multicast network. |

Generated dialplan — new section in `client-dialplan.blade.php`, using
the client's own endpoints (`realtimeEndpointId()`):

```
[t{team}_paging]
exten => zone-{id},1,NoOp(Paging zone {name})
 same => n,Set(CDR(accountcode)=code-${ORBITAL_CODE_EVENT})
 same => n,Page(PJSIP/t42_9001&PJSIP/t42_9002,iqb(orbital-page-headers^s^1),{max_seconds})
 same => n,Hangup()

[orbital-page-headers]   ; static, in extensions.blade.php
exten => s,1,Set(PJSIP_HEADER(add,Call-Info)=<sip:x>\;answer-after=0)
 same => n,Set(PJSIP_HEADER(add,Alert-Info)=auto-answer)
 same => n,Return()
```

`OverheadPager` renders the announcement to a WAV with the existing TTS
renderer (prompts volume, `paging/{team}/{announcement}.wav`, repeats
and gaps baked in, "This is a drill" prefixed for drills), then calls
`originateToApplication(Local/zone-{id}@t{team}_paging/n, 'Playback',
'paging/...', callerId, ['ORBITAL_CODE_EVENT' => $id])`. The Local
channel's dialplan side runs `Page()`; the application side plays into
it. **Live** mode instead originates the operator's softphone into
`zone-{id}`; **recorded** mode lets the operator record, review, then
send (safer than live; IS supports both). One announcement per zone at
a time (Redis lock per zone); a second code's announcement queues and
the console shows "waiting for zone".

Knowing it worked: AMI runs `Events: off`, so `originate()` returning
true means *queued*, not *played*. Two additions:

1. **Pre-flight** — before a code starts, `endpointIsRegistered()` on
   each gateway (`AsteriskAmiService.php:327`); a missing gateway shows
   the zone red immediately with the fallback instruction
   ("Gateway offline — call PBX operator at …").
2. **Completion** — a hangup handler on the Local channel posts
   `PAGE_RESULT`/answered-leg count/duration to a signed internal
   endpoint (`/api/internal/paging/{announcement}`); the CDR row
   (`accountcode = code-{id}`, already ingested by `CdrIngester`) is
   the after-the-fact cross-check. If no callback arrives within
   `max_seconds + 15 s`, mark `failed (no confirmation)`.

Paging gateways also get a **health check** in the existing health
system (unregistered for > 2 min → warn, surfaced via
`SystemHealthUpdated`) — a dead gateway must be found before a code,
not during one.

**Responder bridge.** ConfBridge with baked static profiles in a new
`docker/asterisk/confbridge.conf` (they aren't per client):
`orbital_responder_bridge` (`record_conference=yes`, `max_members=50`),
`orbital_responder` (`announce_join_leave=yes`, `quiet=no`),
`orbital_moderator` (`marked=yes`, `admin=yes`) for the operator.
Joining paths: press 3 in the voice callout ("…press 3 to join the
bridge"); operator "add to bridge" (originate the person into the
room); dial-in DID + PIN (new `ClientDid.destination_type =
'responder_bridge'`); bridge join records a "responding" response.

ConfBridge lives on one node. `conference_bridges.asterisk_backend_id`
pins it; every originate for that bridge must go to that node's AMI —
`AsteriskAmiService` reads one host from config (`:22-27`), so it needs
a `forBackend(AsteriskBackend)` factory (useful for plan 08 too). Dial-in
through Kamailio can land on another node; v1 handles that by
`Dial`-ing across to the owning node, and a node lost mid-code reopens
the bridge elsewhere and re-invites responders who were in it. If plan
08 chooses LiveKit rooms for conferencing (cluster-wide, browser join),
use that instead and drop the pinning — see Open questions.

**Voice callouts** come from plan 05's voice transport. This plan needs
two things from it: the DTMF key pressed (response options 1/2/3), and
a concurrency cap per trunk from `SipTrunk.max_channels` (codes take
priority over mass callouts on the same trunk).

### Operator UI

**Code console** — Filament Operator page `CodeConsole`, opened from
the call (a code line DID routes to a top-priority queue) or from a
hotkey bar on the Workspace and multi-call desk:

- Code buttons (colour-coded, hotkeys), location picker, details;
  "Confirm & start" — one click after read-back; double-click safe
  (idempotency key).
- Big elapsed timer since `initiated_at`; per-step rows with time
  stamps (overhead ×3 ✓ 00:14, team paged ✓ 00:16, first responder
  00:52, …), manual checkpoints as checkboxes.
- Overhead panel per zone: queued / playing / done / failed + fallback.
- Roll-call: required roles first (red until a "yes"), then everyone,
  with channel icons and ETA; "record response on behalf", "call",
  "add to bridge", "resend to non-responders".
- All clear / Cancel (false alarm) — distinct events, both notify
  everyone already paged.
- Several active codes at once are tabs; the console survives a page
  reload (state is server-side).

**Mass notification** — admin resource + portal page:
compose (template, groups, channels, response required, deadline,
bridge, now/scheduled, mode live/drill/dry run) → **preview** (recipient
count per channel, opted-out, missing contact methods, required
variables, estimated time to send everything given throttles) →
confirm modal requiring the recipient count to be typed for live sends
over 50 → live roll-call board → close. Non-responders can be pushed
to plan 05's dispatcher queue for phone follow-up.

**Portal (client)** — read-only live board and history of their codes
and campaigns; client admins with permission can send notifications
to their own groups and (if `client_can_trigger`) start a code, which
appears on the operator console immediately. Groups maintained by the
client are audited like plan 04 rotas.

**AI agents** never start a code or send a mass notification. An AI
taking a call that sounds like a code hands off to an operator at once
(top-priority queue) with the location it has heard.

### Drills and test mode

`delivery_mode` on code events and campaigns:

- **`live`** — real sends.
- **`drill`** — real sends, every rendered body starts with the
  template's `drill_prefix` ("DRILL — THIS IS A TEST"), applied at the
  render layer so no template can omit it; overhead announcements start
  and end with "This is a drill"; overhead only to zones with
  `allow_drills`. Drill events are excluded from service-level metrics
  and reported in a separate drill report (time to overhead, time to
  full team).
- **`dry_run`** — no external effect: transports swapped for the plan
  05 fake/Log transports, AMI calls recorded not sent, responses
  simulated from the console. Same events and report. This is the mode
  for operator training, client sign-off and the test suite; it maps
  to the catalogue's existing `send_in_test_drive` flag.

### Permissions and tenant isolation

New catalogue entries in `PermissionCatalogSeeder::CATALOG`:
`code_type.{view_any,view,create,update,delete}`,
`code_call.{view_any,initiate,all_clear}`,
`notification_group.{view_any,view,create,update,delete}`,
`notification_template.{…}`, `campaign.{view_any,create,send_drill,
send_live}`, `paging_zone.manage` (platform only, never grantable to a
client), `report.code_calls`; portal: `portal.view_code_calls`,
`portal.send_notifications`, `portal.manage_notification_groups`,
`portal.trigger_code` — granted to `client_admin` only, never to
`client_user` by default.

Isolation, beyond `BelongsToTeam` on every model:

- Jobs, webhooks and the response-link controller run **without** a
  logged-in user, so the global scope does nothing there
  (`BelongsToTeam.php:17-19`). They load the campaign by id and reach
  everything through its relations (`$campaign->recipients()`), never a
  bare `Model::query()`; a test proves it.
- Group resolution rejects entries, tags and on-call groups from
  another team (shared directories only if attached to this team).
- Response tokens are 32 random bytes, stored hashed, single campaign;
  no team or campaign id in the URL.
- SMS reply matching is per messaging endpoint (each client's own
  number) and per thread; a doctor on two clients' groups replying to
  two numbers can't cross over.
- The broadcast channel checks team membership/permission.
- Paging dialplan contexts are generated per team and only reference
  that team's `t{team}_` endpoints; a zone can't point at another
  team's extension (validated on save and in the generator).

### Audit and reporting

- `notification_events` is the record: every button, send, delivery
  receipt, response, bridge join, page start/stop, with actor and
  server time. Provider timestamps (Twilio delivered-at) stay in plan
  05's `delivery_attempts`.
- After-action report per code: timeline; **intervals** — call
  answered → code confirmed → overhead started → first page sent →
  first "yes" → all required roles covered → first bridge join → all
  clear; per-recipient table (methods tried, delivered, response,
  latency, source); recordings (bridge, live overhead, the reporting
  call); operator notes; failed or skipped steps; drill flag; times in
  the client's timezone.
- Monthly roll-ups (plan 10): code volume by type, median/90th
  percentile intervals, drills run, unreachable recipients (stale
  directory data).
- Retention follows the client's recording/message retention; reports
  are kept at least as long as the events they summarise.

## Edge cases and failure modes

- **Gateway offline / not auto-answering** — pre-flight shows it red;
  a gateway that rings instead of answering ends with no answered legs
  → `overhead.failed`; the console shows the fallback.
- **Two codes, one zone** — announcements serialise per zone; the
  second shows "waiting"; a "play now" override for the operator.
- **Inbound webhook limit** — 300/min per IP (`AppServiceProvider.php:
  72-74`) covers inbound SMS *and* delivery receipts. A 300-person
  campaign produces several status callbacks per message plus replies
  from a handful of provider IPs, so the limiter will 429 them and
  replies can be lost. **Must fix before mass notification:** key the
  limiter per provider after signature verification, or raise it for
  verified requests.
- **SMS throughput** — at low per-number throughput, a large send takes
  minutes; the preview shows the estimate, codes jump the bulk queue,
  and a client needing large sends needs a high-throughput sender
  (toll-free or short code) — an onboarding question, not code.
- **Synchronous provider send** — `TwilioProvider::send()` blocks up
  to 10 s; with fan-out in chunked jobs, one slow call stalls one
  worker, not the console.
- **Opted-out recipients** — STOP is honoured even for codes; shown as
  unreachable by SMS and moved to voice/page/email.
- **Duplicates** — same person via several groups → one recipient, all
  sources kept; same phone on two entries → one send, response applied
  to both.
- **Ambiguous reply** ("on my way to 4W") → yes-words match; anything
  else stored raw and flagged on the board for the operator.
- **Two open campaigns to one person on one number** — reply goes to
  the most recent; confirmation text names the code so the person can
  correct it; the console lets the operator move a response.
- **Late responses** — after close, recorded as `response.late`, shown
  in the report, don't reopen.
- **Cancel vs all clear** — false alarms send a cancel to everyone
  already reached; partially fanned-out chunks are cancelled.
- **Worker crash mid-fan-out** — per-recipient jobs are idempotent
  (unique on recipient + tier + method); plan 05's sweeper resumes.
- **Asterisk node lost** — bridge re-opened on another node; overhead
  in progress marked failed and retried once on the operator's say-so.
- **Laravel down** — codes are life-safety adjacent: the client keeps
  its own fallback (printed call list, direct PBX paging); Orbital
  states it is not the system of record for life safety.
- **Missing variables** (no location) — send blocked for code
  templates; mass templates warn.
- **PHI** — code templates carry location, not patient details; a lint
  rejects patient-name variables in SMS/page bodies; secure link via
  plan 17 if more is needed.
- **Pager limits** — `page_body` rendered with the recipient method's
  character cap; preview shows truncation.
- **Live overhead dropped** — operator softphone hangs up mid-page →
  `overhead.completed` with actual duration; report shows it.
- **Huge groups** — chunked resolution and sends; voice callouts capped
  by trunk `max_channels`.
- **Clock** — server time is the record; operator browser time stored
  only to spot skew; DST-safe display in client timezone.

## Migration and rollout

Build only when a client needs it. Order once triggered:

0. **Prerequisites**: plans 04 and 05 (engine, SMS/email/voice
   transports, acks); fix the inbound limiter; add
   `AsteriskAmiService` per-node targeting; confirm `bridge_softmix` /
   `chan_rtp` are in the image.
1. **Mass notification core** — groups, templates, campaigns, responses
   (SMS, link, portal, operator), roll-call board, broadcasts, drills /
   dry run, events, report. Valuable to non-hospital clients on its own.
2. **Responder bridge** — confbridge profiles, join paths, recording.
3. **Code calls** — code types, console, playbook runner (or plan 01
   steps), code line routing.
4. **Overhead paging** — `paging` Extension type, zones, dialplan
   generation, TTS announcements, completion callback, health check;
   needs on-site testing with the client's gateway model.

Migrations: the new tables above; `extensions.type` gains `paging`
(Laravel created it as a Postgres `CHECK` constraint — the migration
drops and re-adds it); `teams` feature flags; `client_dids` destination
`responder_bridge`; `acknowledgements.response_key`; reseed the
permission catalogue. Pilot: one client, `dry_run` for operator
training → scheduled `drill`s for two weeks → `live`, with the client
signing off on templates, groups and zones.

## Tests

Answering-service scenario tests (frozen clock, plan 05 fake
transports, a `FakeAmi` that records actions):

1. **Code Blue happy path** — 03:12 call, code confirmed 4 West; expect
   overhead originate to `Local/zone-1@t42_paging/n` with the right WAV,
   campaign to 6 recipients, ICU resident replies "1" by SMS at +40 s,
   anesthesia presses 1 at +75 s; event log in order; report intervals
   confirm→overhead, confirm→first yes, all required covered.
2. **Escalation** — anesthesia primary silent 120 s → backup (on-call
   position 2) notified, `escalation.next_position` event, operator
   alert; backup responds → role covered.
3. **"Not available"** — counts as ack (no further retries to that
   person) and triggers the next position for a required role.
4. **All clear vs cancel** — both notify everyone reached; cancel during
   fan-out stops remaining chunks.
5. **Idempotency** — double "Confirm & start" creates one code event and
   one campaign.
6. **Mass notification** — 300 recipients, mixed channels; preview counts
   (opted out, missing methods); chunking; throttle honoured
   (`Redis::throttle` faked); counters correct; late reply after close
   recorded as late.
7. **Reply parsing** — table test: `1`, `yes`, `Y`, `omw`, `2`, `no`,
   `3`, `STOP` (opt-out wins, no response recorded), free text (flagged).
8. **Two campaigns, one phone** — reply attaches to the latest;
   operator move works.
9. **Response link** — valid token records once; reused token is
   idempotent; tampered token 404; expired campaign shows "closed".
10. **Drill safety** — every channel body in `drill` mode starts with
    the prefix (including a template that tries to omit it); overhead to
    a zone without `allow_drills` is refused; `dry_run` makes zero
    provider/AMI calls.
11. **Overhead** — gateway unregistered → zone red before start; no
    completion callback → `failed (no confirmation)`; two codes on one
    zone serialise.
12. **Generated config** — snapshot of `[t42_paging]` uses only
    `PJSIP/t42_*` endpoints and the header predial; ConfBridge room name
    carries the team prefix.
13. **Bridge** — press-3 join records "responding"; pinned-node
    originate goes to that node's AMI; node loss → reopen event.
14. **Tenant isolation** — client A's group can't include client B's
    entry/tag/on-call group; a queued `SendCampaignChunk` for team A
    never touches team B rows with no auth user; SMS reply on B's
    number never matches A's campaign; broadcast channel auth denies B;
    portal user of B can't see A's codes.
15. **Permissions** — `client_user` can't send; `client_admin` with
    `portal.send_notifications` can, only to own groups; nobody on a
    client can manage paging zones.
16. **Report** — rebuilt report has the same hash when nothing changed;
    times rendered in client timezone across a DST boundary.
17. **Append-only** — updating/deleting a `notification_events` row
    fails (model and DB trigger).

## Open questions

1. Does NCI have, or expect, a hospital switchboard client (07,
   question 6)? Which paging hardware/PBX does it use — that picks
   topology A, B or C.
2. Does NCI's operator make the overhead page, or only notify the team
   while the hospital PBX operator pages? (If the latter, skip phase 4.)
3. Liability: contract wording that Orbital is not a certified
   life-safety system; who owns the fallback procedure?
4. Must code-team pages go to a secure messaging app (plan 17) rather
   than SMS?
5. Responder bridge on ConfBridge (node pinning) or on LiveKit rooms
   (cluster-wide, browser join)? Decide together with plan 08.
6. Can code types, groups and code-team rosters be imported from the
   client's IS setup, or are they rebuilt by hand?
7. Retention period hospitals require for code logs and recordings.
8. Expected largest mass notification (hundreds vs thousands) — decides
   whether a short code / toll-free sender is needed at onboarding.

## Size estimate

On top of plans 04 and 05:

| Phase | Work | Estimate |
|---|---|---|
| 0 | Inbound limiter fix, AMI per-node targeting, module check | 2–3 days |
| 1 | Mass notification core, roll-call, drills, report | ~3 weeks |
| 2 | Responder bridge | ~1 week |
| 3 | Code types, console, playbook runner | ~2 weeks |
| 4 | Overhead paging + health check + on-site test | ~1.5–2 weeks |

About **8–9 weeks** in total; phase 1 alone (~3 weeks) gives
non-hospital clients group call-outs with roll-call.
