# Plan 08: Call patching and conferencing

**Status:** proposed · part of [IS parity](README.md) · depends on 04 (who to patch to), 05 (patch counts as delivery/ack); uses 01/02 (script step), 03 (message link); shares the AMI event listener with [live-call-monitoring](../live-call-monitoring.md); recording relies on [rtpengine-edge](../rtpengine-edge.md)
**Written:** 2026-11-24 · expands survey item A in [07](07-additional-features.md)

## Problem

### What IS does

An IS operator taking an after-hours medical or property call routinely
"patches" the caller through to the on-call person:

1. Takes (or starts) the message so nothing is lost if the patch fails.
2. Puts the caller on hold (music), dials the on-call doctor from the
   on-call schedule, **announces** the call ("Dr Lee, this is NCI with
   an urgent call from Jane Doe about her father, can you take it?").
3. If accepted: **connects** caller and doctor and drops off, or stays
   on as a **3-way** call. If not: returns to the caller, tries the
   backup, or takes/dispatches the message.
4. The patch is logged on the message: who, when, connected or not,
   and a connected patch closes the dispatch — the doctor *has* the
   message. The recording covers the whole call.
5. Variants: **park** the caller while paging the doctor and connect
   when the doctor calls back; automatic **screened** patches from a
   script ("press 1 to accept this call"); conference beyond 3 parties.

### What Orbital has today (verified)

**Softphone — blind transfer only, single session.**

- `resources/js/sip-phone.js:287-300` `blindTransfer()` sends a REFER
  and hangs up immediately. No consult call, no REFER-with-Replaces, no
  conference.
- `sip-phone.js:76-83` rejects any second INVITE while a call is up: the
  browser phone holds exactly **one** SIP session (`this.session`). The
  multi-call desk inherits this (`MultiCallDesk::followSoftphone`
  docblock, `app/Services/Operator/MultiCallDesk.php:265-305`).
- `sip-phone.js:244-272` `toggleHold()` only disables local audio
  tracks. Despite the "re-invite with sendrecv" comment there is no
  re-INVITE, so Asterisk never knows the call is held and the caller
  hears silence, not hold music. The multi-call desk's hold/resume
  commands call the same method (`resources/js/softphone.js:52-64`).
- `softphone.js:216-230` transfer mode → `blindTransfer(transferInput)`;
  the Transfer button is always rendered
  (`resources/views/components/softphone.blade.php:525-535`).

**Dialplan — operators can't reach an outside number at all.**

- Operator softphones are platform extensions with `team_id = null`,
  `context = internal` (`app/Services/Telephony/PlatformExtensionAllocator.php:41-51`);
  `EndpointSyncer` gives them that context
  (`app/Services/Telephony/Realtime/EndpointSyncer.php:70-72`). A REFER
  from the operator resolves in `[internal]`, which contains every
  client's extensions and queues, `*43`, `*60` and (dev only) DID
  simulation — **no outbound trunk route**
  (`resources/views/asterisk/extensions.blade.php:17-80`,
  `AsteriskConfigService::generateInternalContext()` `:141-180`). So
  today's "Transfer" can only reach internal extensions.
- The only outbound dialling is the AI handoff: one `from-livekit`
  entry per configured (client, external number), dialled out the
  client's trunk with a Diversion header
  (`from-trunk.blade.php:103-110`, `:162-166`;
  `AsteriskConfigService::handoffDialplanEntries()` `:82-102`;
  `HandoffDestinations` `app/Services/Telephony/HandoffDestinations.php:26-107`).
  Its security model — *Laravel picks the number, the dialplan only
  knows configured numbers* — is the right one to extend.
- Every caller-facing `Dial`/`Queue` uses `tTkK`
  (`extensions.blade.php:54,63,75`, `client-dialplan.blade.php:31,39,52,55`,
  `from-trunk.blade.php:51`), so the bridge DTMF transfer/park features
  are switched on for both parties, but no `features.conf`,
  `res_parking.conf` or `confbridge.conf` is baked
  (`docker/asterisk/Dockerfile:186-217` copies none); the image runs
  Asterisk's `make samples` defaults (`Dockerfile:116`).
- `res_parking`, `app_confbridge`, `res_ari`/`res_stasis` are compiled in
  (`Dockerfile:68-82`) but nothing in the dialplan parks, conferences
  or enters Stasis.

**AMI — one-shot commands only.**

`AsteriskAmiService` logs in with `Events: off`
(`app/Services/Telephony/AsteriskAmiService.php:49-62`), talks to a
single configured host (`:22-28`), and offers `reload`,
`getActiveChannels`, `originate` (`:256-276`),
`originateToApplication` (`:283-325`, uncommitted) and
`endpointIsRegistered`. No `Atxfer`, `Redirect`, `Bridge`, `Park`, no
event stream, no per-node targeting of a specific channel.

**AI — blind REFER only.**

- `agent-worker/tools/transfer.py:25-60` `transfer_call(extension)` —
  LLM-chosen string, REFER to `sip:{extension}@asterisk`, always
  registered (`agent-worker/agent.py:199-206`).
- `agent-worker/tools/handoff.py:26-82` `transfer_to_operator` — asks
  Laravel (`CallSessionController::handoff`,
  `app/Http/Controllers/Api/CallSessionController.php:151-172`) for the
  REFER target, then `transfer_sip_participant` and shuts the session
  down. Blind: the operator gets no brief, the AI never learns whether
  anyone answered.
- No LiveKit outbound SIP trunk exists (`LiveKitConfigService` only
  creates an inbound trunk, `app/Services/Telephony/LiveKitConfigService.php:107`),
  so the AI cannot place a consult call of its own.

**Script catalogue — names without behaviour.**

`IntakeGoalLibrarySeeder` has `transfer_call` with `mode: cold|warm`
(`database/seeders/IntakeGoalLibrarySeeder.php:770-786`),
`transfer_to_extension` with the same (`:1228-1241`), `park_call`
(`:848-866`), `toggle_hold` (`:885-899`) and `toggle_recording`
(`:902-918`). `warm` is passed through to the compiled tool schema
(`app/Services/Flows/AgentFlowCompiler.php:948-959`) and ignored;
`park_call` becomes prompt text (`AgentFlowCompiler.php:592`, `:741-751`).
There is no `patch_to_contact`.

**Call records and recordings.**

- `CallLog` is one row per Asterisk `linkedid`
  (`app/Services/Telephony/CdrIngester.php:161`), keyed to a single
  `sip_call_id` — the inbound dialog's (`CdrIngester.php:126`). Calls
  with no client tag are dropped (`CdrIngester.php:105-108`).
- rtpengine writes recordings per SIP dialog, named by Call-ID
  (`app/Console/Commands/UploadRtpengineRecordings.php:97-131`);
  `UploadCallRecordingJob` attaches them only where
  `call_logs.sip_call_id` equals that Call-ID
  (`app/Jobs/UploadCallRecordingJob.php:72-89`). A consult leg is a
  different dialog with a different Call-ID, so today its recording
  has nowhere to go. `CallRecording` already supports many rows per
  call with `leg_uuid` (`app/Models/CallRecording.php:13-55`).
- Outbound trunk legs do go through the edge
  (`app/Services/Telephony/Realtime/TrunkSyncer.php:72-74`,
  `outbound_proxy` = Kamailio), so rtpengine *will* see a consult leg.
- Beep/disclosure prompts were dropped with MixMonitor
  (`resources/views/asterisk/partials/mix-monitor.blade.php:17-20`), so
  nothing tells a patched third party the call is recorded.

**Permissions exist but are never checked.** `softphone.transfer`,
`softphone.park`, `softphone.conference` are seeded
(`database/seeders/PermissionCatalogSeeder.php:39`) and granted to
operator and supervisor (`database/seeders/SuperAdminRoleSeeder.php:48-53,70-74`);
nothing in `app/` or `resources/` reads them.

## Goal

An operator (or AI, or a script step) can patch a live caller to a
client's contact — usually the on-call person from plan 04 — with:

- **attended patch**: caller on hold with music, operator consults the
  contact, then *connect & drop*, *3-way*, *swap*, or *cancel* back to
  the caller, and try the next contact;
- **announce/screen**: an optional automated announcement to the
  contact with "press 1 to accept" (mandatory for unattended patches);
- **park & connect later**: caller parked while the operator pages the
  contact and handles other calls; connect when the contact calls back
  or answers;
- **recording** that continues across the patch, with the consult leg
  recorded and linked to the same call;
- **delivery semantics**: an accepted, connected patch is a delivery
  *and* acknowledgement of the call's message (plan 05);
- the same mechanism from a **script step** `patch_to_contact` and from
  the **AI** (screened patch first, conversational warm transfer later);
- Laravel always chooses the number; tenant isolation and audit on
  every patch.

Non-goals: client staff placing patches themselves; conference rooms as
a product (dial-in bridges); video.

## Design

### Key decision: patch in Asterisk, driven by Laravel over AMI

Two ways to do attended transfer were considered:

| | Browser-side (SIP.js 2nd session + REFER/Replaces) | **Asterisk-side (AMI `Atxfer` + bridge features)** |
|---|---|---|
| Browser sessions | Two concurrent sessions — breaks the one-session model in `sip-phone.js:76-83` and `MultiCallDesk` | **Unchanged**: the operator's single SIP dialog stays up; Asterisk swaps what it is bridged to |
| Who picks the number | Browser dials it → needs an outbound route in `internal` reachable by any operator | **Laravel** issues a one-time numeric code; only that code is dialable |
| Client attribution (trunk, caller ID, CDR team) | Unknown to the dialplan (operator context is platform-wide) | Looked up from the patch row |
| Hardware desk phones (this branch's work) | Depends on each phone's Replaces support | Works for any endpoint |
| Recording | Caller's trunk dialog stays on Asterisk either way | Same, plus Laravel knows the consult Call-ID |

So: **Asterisk is the patch engine; Laravel commands it over AMI and
follows it over AMI events.** The browser only shows state and sends
button presses. ARI/Stasis would give finer control but would require
moving every operator call into a Stasis app; revisit if AMI proves
limiting (see Open questions).

### Data model

All new tables `BelongsToTeam` (the client whose call it is).

**`call_patches`** — one row per attempt to reach one contact.

| Column | Notes |
|---|---|
| `id`, `team_id` | |
| `code` | 10-digit numeric, unique among live rows, e.g. `7` + 9 random digits. Numeric because AMI `Atxfer` feeds the extension to Asterisk as DTMF. Single use, `expires_at` ≈ 2 min after creation |
| `linked_id` | the **caller's** Asterisk linkedid (joins `call_logs.linked_id`, `call_session_states.linked_id`) |
| `caller_channel`, `asterisk_node` | resolved server-side from AMI, never accepted from the browser |
| `operator_call_id` (nullable), `user_id` (nullable) | operator path |
| `call_session_key` (nullable) | AI / script path (`CallSessionState.session_key`) |
| `message_id` (nullable), `dispatch_id` (nullable, plan 05) | |
| `initiator` | `operator` · `script` · `ai` |
| `mode` | `attended` · `park` · `screened` (unattended, accept by keypress) · `ai_warm` |
| `target_type` | `directory_entry` · `on_call` · `extension` · `external` |
| `directory_entry_id`, `on_call_group_id`, `on_call_position` | plan 04 result that produced the target |
| `contact_label`, `number_e164` | what was dialled (E.164) |
| `trunk_id`, `presented_caller_id` | chosen by policy, see below |
| `announcement` (nullable text), `require_accept` (bool), `accept_digit` | screening |
| `status` | `pending → dialing → ringing → answered → (screening) → accepted \| declined → consulting → connected \| conference → completed`; terminal failures `no_answer`, `busy`, `failed`, `cancelled`, `caller_hung_up`, `expired` |
| `consult_channel`, `consult_sip_call_id` | outbound leg; `consult_sip_call_id` is what recordings join on |
| `hangup_cause`, `dial_status` | from AMI `DialEnd` / `Hangup` |
| `dialed_at`, `answered_at`, `accepted_at`, `connected_at`, `ended_at`, `connected_seconds` | |
| `consumed_at` | set when the dialplan reads the code; second use fails |

**`call_patch_events`** — append-only: `call_patch_id`, `seq`, `type`
(`created`, `ami_action`, `dial`, `ringing`, `answered`,
`screen_played`, `accepted`, `declined`, `three_way`, `swap`,
`cancelled`, `connected`, `caller_hung_up`, `contact_hung_up`,
`operator_dropped`, `error`), `payload` (AMI event excerpt, never
credentials), `actor` (`user:{id}` / `ai` / `system` / `asterisk`), `at`.
When a script session exists, the same events are mirrored into plan
01's `script_events` (`patch_started`, `patch_result`) so script replay
and the AI parity tests see them.

**Patch policy on the client** (`teams` columns or a `patch_policies`
row if plan 05's policy table lands first):
`patching_enabled`, `presented_caller_id` (`client_did` default ·
`service_number` · `original_caller` with Diversion), `announce_mode`
(`operator` · `tts` · `tts_with_accept`), `announcement_template`
(plan 03 `TemplateEvaluator` syntax: `{{caller_name}}`, `{{reason}}`…),
`max_hold_seconds` (default 90), `record_consult` (default on),
`recording_notice` (play "this call may be recorded" to the contact),
`allow_unlisted_numbers` (default off).

**Directory contact methods** (plan 04) gain `patchable: bool` on voice
methods, so "cell — patch OK, home — message only" is data.

**`call_logs`**: no schema change required for phase 1. Add a
`patches()` relation `hasMany(CallPatch::class, 'linked_id', 'linked_id')`
next to `callSessions()` (`app/Models/CallLog.php:132-135`). The
consult leg's CDR rows normally share the caller's linkedid (Asterisk
propagates linkedid to channels requested on behalf of a bridged
channel), so they land in the same `CallLog.metadata.legs`; the design
does not rely on that — `call_patches.linked_id` is set explicitly.

### Services (`app/Services/Telephony/Patch/`)

- **`PatchTargetResolver`** — turns a request (directory entry + method,
  on-call group + position, client extension, or a raw number) into a
  dialable target for *this call's client*: validates tenant ownership
  (entry's `team_id` = call's team, or a shared directory bound to it),
  `patchable` method, E.164 via `PhoneNumber::toE164`, blocks emergency
  / premium / international-by-policy numbers, and **refuses numbers
  that are the platform's own DIDs** (`ClientDid` lookup) to prevent
  loops back into Orbital. Raw numbers need `softphone.patch_unlisted`
  and `allow_unlisted_numbers`.
- **`PatchDialPlanner`** — picks the trunk (reuse
  `HandoffDestinations::trunkFor()`, `HandoffDestinations.php:96-107`),
  presented caller ID, ring timeout; builds the PJSIP dial string.
- **`CallPatchService`** — the only thing that talks to AMI for
  patches:
  - `consult(OperatorCall|session, target)` → creates the row, issues
    AMI `Atxfer` (`Channel` = operator's channel, `Exten` = code,
    `Context` = `orbital-patch`).
  - `threeWay(patch)`, `swap(patch)` → return the DTMF feature string
    for the browser to send (see Telephony) — AMI has no action for
    these.
  - `cancel(patch)` → AMI `CancelAtxfer` on the operator channel.
  - `connect(patch)` → the operator's softphone hangs up its leg; in
    Asterisk's attended-transfer state machine the transferer hanging
    up completes the transfer. Laravel marks `operator_dropped` and
    waits for the `AttendedTransfer` event to confirm.
  - `park(call)` / `retrieve(parked, operator)` / `connectParked(parked, patch)`
    → AMI `Park`, `Originate` (operator endpoint → `ParkedCall`), `Bridge`.
  - `screened(sessionKey, target)` → AI/script path, returns a REFER
    target (see AI path).
  - Every action is idempotent per patch status and recorded as a
    `call_patch_events` row before and after the AMI round trip.
- **`AmiNodeRouter`** — finds the Asterisk node holding a channel.
  `AsteriskAmiService` currently talks only to one configured host
  (`AsteriskAmiService.php:22-28`); with several backends behind
  Kamailio the action must reach the node that owns the bridge. Use
  the node recorded by the AMI listener (below), falling back to
  `CoreShowChannels` on each node via the per-node fan-out
  `AsteriskClusterActivity` already has.
- **`AmiEventListener`** (`orbital:ami-listen`, one per node, supervised
  like Horizon) — **shared with the live-call board**
  (`docs/plans/live-call-monitoring.md:57-75`); whichever plan ships
  first builds it. Patch-relevant events: `AttendedTransfer`,
  `BlindTransfer`, `DialBegin`/`DialEnd`, `BridgeEnter`/`BridgeLeave`,
  `Hangup`, `ParkedCall`/`ParkedCallTimeOut`/`ParkedCallGiveUp`,
  `UserEvent(OrbitalPatch)` (emitted by our dialplan with code + state).
  It drives `call_patches.status` and broadcasts `CallPatchUpdated`
  over Reverb on the operator's private channel (pattern of
  `app/Events/AsteriskDrainInitiated.php`).
- **`PatchOutcomeRecorder`** — on terminal status, writes plan 05
  rows (below) and resolves the waiting script step (plan 01).

### Telephony / dialplan

All generated by `AsteriskConfigService` from Blade; snapshot-tested
like the existing dialplan tests (`tests/Feature/Telephony/PerTenantDialplanTest.php`).

1. **`features.conf`** (new, generated `generated/features_generated.conf`
   `#include`d from a baked `features.conf`):
   - `[featuremap] atxfer => *8#` (obscure, needed: AMI `Atxfer` refuses
     to run when no attended-transfer feature code is configured),
     `blindxfer =>` empty (see Dial-option fix), `disconnect =>` empty,
     `parkcall =>` empty.
   - `[general] atxferabort`, `atxfercomplete`, `atxferthreeway`,
     `atxferswap` set to `*81`, `*82`, `*83`, `*84` (sent by the browser's
     existing `sendDTMF`, `sip-phone.js:274-285`, RFC 4733 via the edge),
     `atxfernoanswertimeout` = client ring timeout,
     `atxferdropcall = no`, `atxferloopdelay`/`atxfercallbackretries = 0`
     (no "blonde" call-back to an operator who already left),
     `transferdigittimeout = 3`.
2. **Dial/Queue options** — the operator's leg keeps `t` (callee may
   transfer); the external caller's side loses `T` and `K` everywhere
   (`extensions.blade.php:54,63,75`, `client-dialplan.blade.php:31,39,52,55`,
   `from-trunk.blade.php:51`). A caller must never be able to trigger a
   bridge feature from their keypad. Add `m(<client MOH class>)` where
   relevant so held callers hear the client's music (`HoldMusicClass`).
3. **`[orbital-patch]`** context — reachable only via AMI `Atxfer`/`Originate`
   and via the explicit `from-livekit` entry below; never `include`d by
   an endpoint context (same rule as `orbital-spy` in the live-call plan).

   ```
   exten => _7XXXXXXXXX,1,Set(PATCH=${ODBC_PATCH_CLAIM(${EXTEN})})   ; func_odbc, single-use claim
    same => n,GotoIf($["${PATCH}" = ""]?invalid,1)
    same => n,Set(CDR(orbital_team_id)=${CUT(PATCH,|,1)})
    same => n,Set(CDR(orbital_patch_code)=${EXTEN})
    same => n,Set(CALLERID(num)=${CUT(PATCH,|,3)})
    same => n,UserEvent(OrbitalPatch,Code: ${EXTEN},State: dialing)
    same => n,Dial(${CUT(PATCH,|,2)},${CUT(PATCH,|,4)},b(orbital-patch-leg^s^1(${EXTEN}))U(orbital-patch-screen^${EXTEN}))
    same => n,UserEvent(OrbitalPatch,Code: ${EXTEN},State: ${DIALSTATUS})
    same => n,Hangup()
   exten => invalid,1,UserEvent(OrbitalPatch,Code: invalid) ...
   ```
   - The lookup is `func_odbc` against a Postgres function
     `asterisk_claim_patch(code)` that returns
     `team|dial|cid|timeout|announce_path|require_accept` only for an
     unexpired, unconsumed row and sets `consumed_at` atomically. ODBC to
     Postgres is already configured for realtime (`docker/asterisk/res_odbc.conf`,
     `extconfig.conf`). Alternative if `func_odbc` is unavailable in the
     image: `CURL()` to an internal Laravel endpoint with a node token.
   - `orbital-patch-leg` (pre-dial handler on the outbound channel) adds
     the Diversion header when presenting the original caller's number
     (same as `orbital-handoff-headers`, `from-trunk.blade.php:162-166`),
     and emits `UserEvent(OrbitalPatch, Code, CallID: ${CHANNEL(pjsip,call-id)})`
     so Laravel records `consult_sip_call_id`.
   - `orbital-patch-screen` (Dial `U()` subroutine, runs on the contact
     after answer, before bridging): plays the recording notice if
     enabled, the announcement (pre-rendered TTS file, path from the
     claim), and when `require_accept`, `Read()` one digit up to 3
     times; on anything but the accept digit sets
     `GOSUB_RESULT=ABORT` (Dial then reports `CANCEL`, mapped to
     `declined`). Emits `UserEvent(OrbitalPatch, State: accepted|declined)`.
     This is also the AMD substitute: a voicemail greeting never presses 1.
4. **Parking** — generated `res_parking.conf`: one platform lot
   `orbital` (e.g. positions 7001-7999, `parkingtime` = client
   `max_hold_seconds`, `comebacktoorigin = no`,
   `comebackcontext = orbital-park-timeout`, MOH class per call via
   `PARKINGLOT`/`CHANNEL(musicclass)`). Laravel tracks which client and
   operator own each slot; slots are never dialled by number (no
   `parkext` reachable from endpoint contexts). `[orbital-park-timeout]`
   emits a `UserEvent` and returns the caller to the operator queue the
   call came from with raised priority — never drops them.
5. **Conference beyond three** (phase 3) — `confbridge.conf` profiles
   `orbital_patch_user` / `orbital_patch_operator` (operator marked,
   `end_marked = no`), `[orbital-conf]` exten `_7XXXXXXXXX` →
   `ConfBridge(patch-${EXTEN},…)`; channels moved in with AMI `Redirect`.
   The Dockerfile enables `app_confbridge` (`Dockerfile:82`); confirm
   `bridge_softmix` is built (it is not in the explicit enable list).
6. **from-livekit** gets `exten => _7XXXXXXXXX,1,Goto(orbital-patch,${EXTEN},1)`
   above the `_X.` catch-all (`from-trunk.blade.php:117-118`) for the AI
   path; the code is still the only key.

### Operator flows

**A. Attended patch (default).** Operator is talking to the caller
(OperatorCall live).

1. Opens the **Patch panel** (workspace sidebar + softphone): on-call
   now for this client (plan 04, with primary/backup and patchable
   methods), client directory search (people with a `phone`-role
   field, `DirectoryFieldDefinition::ROLES`), client extensions, and —
   with permission — "Other number".
2. Clicks a contact → `CallPatchService::consult()` → Asterisk puts the
   caller on MOH and dials the contact *from the operator's existing
   call*. The panel shows: caller-on-hold timer (amber at 60 s, red at
   `max_hold_seconds`), contact ringing/answered, Cancel.
3. Operator announces the caller. Buttons:
   - **Connect** — operator's leg hangs up, caller ↔ contact bridged.
   - **3-way** — everyone hears everyone; **Leave** later drops the
     operator (caller and contact stay).
   - **Back to caller** (swap) — talk to the caller while the contact
     holds; swap again.
   - **Cancel** — contact leg hung up, operator back with caller; then
     **Next contact** pre-selects the backup.
4. Outcome written to the patch, the OperatorCall card, the message
   timeline and (plan 05) the dispatch.

Keyboard: `P` open panel, `1-9` choose listed contact, `C` connect,
`3` three-way, `S` swap, `Esc` cancel — consistent with plan 02's
keyboard-first runner.

**B. Park & connect later.** "Doctor is being paged, I'll hold you."
`park()` moves the caller to a slot (the operator's SIP call ends, the
OperatorCall card shows *Parked 0:42 → Dr Lee (paged)*). The operator
can take other calls. Connect paths:

- operator clicks *Call contact* → Laravel `Originate`s the operator's
  softphone into `orbital-patch` with a code; on answer + announce,
  **Connect parked caller** → AMI `Bridge` (parked caller ↔ contact
  channel), operator leg drops;
- the contact **calls back** into the client's DID: the inbound call is
  matched (caller ID ∈ contact's numbers, parked call exists for that
  client) and the answering operator sees *"Dr Lee calling back for
  parked caller Jane Doe — Connect?"*;
- retrieve to self: `Originate` operator endpoint → `ParkedCall(orbital,slot)`.

Park timeout → `orbital-park-timeout` → back to queue with priority and
an operator alert. This mechanism also gives the multi-call desk a real
hold (today's hold is a local mute, see Problem); adopting it there is
a follow-up owned by the multi-call work.

**C. Screened (unattended) patch.** From a script step without an
operator in the loop, or an AI. Caller hears "Please hold while I
connect you" + MOH; contact hears the announcement and must press 1;
decline/no answer returns control to the script (see AI path for how
the caller gets back to the AI).

### Script step `patch_to_contact` (plans 01/02)

New `IntakeGoal` (category `action`), seeded next to `transfer_call`:

- `data_fields`: `target` (`on_call_group` · `directory_entry` ·
  `slot` holding an entry id from `lookup_on_call` · `extension`),
  `try` (`primary_only` · `primary_then_backups`), `mode`
  (`operator_attended` · `screened`), `announcement` (template),
  `require_accept` (bool, forced true for `screened`),
  `ring_seconds`, `max_hold_seconds`, `save_message_first` (default
  true — IS practice: take the message, then patch), `mark_delivered`
  (default true).
- `exits`: `connected`, `declined`, `no_answer`, `busy`,
  `caller_hung_up`, `unavailable` (no one on call / no patchable
  method), `failed`.
- **Engine**: this is the first *asynchronous* step — plan 01's
  `StepHandler` needs a `pending(awaiting: 'patch:{id}')` result; the
  `PatchOutcomeRecorder` resumes the session with the exit. Plan 01
  lists `park_call` and `transfer_*` as later handlers; this plan
  supplies `patch_to_contact`, `park_call` (`park_type = orbit` only;
  other park types rejected by editor validation) and `toggle_hold`
  (via real SIP hold, below).
- **Operator runner (plan 02)**: renders the Patch panel pre-filled
  with the resolved contacts; the exit is chosen by the outcome, the
  operator can override with a reason (audited).
- `transfer_call` / `transfer_to_extension` with `mode: warm` map to
  `patch_to_contact` with a fixed target; `cold` stays blind REFER.

### Delivery and acknowledgement (plan 05)

- A patch made for a message creates a plan 05 `delivery_attempts` row:
  method `voice_patch`, recipient = directory entry, address = number,
  status from the patch (`delivered` on `connected`, `failed` on
  `no_answer`/`busy`/`failed`, `undeliverable` on `declined`).
- **Connected + accepted** (operator confirmed or keypress) ⇒ an
  `acknowledgements` row, `how = patch`, and the dispatch is
  `acknowledged` — escalation timers cancel. A patch that connects but
  where the caller hangs up within N seconds (policy, default 10 s) is
  `delivered` but not acknowledged; plan 05 keeps going.
- Failed patches fall through to the dispatch policy's next tier (plan
  05 already allows "voice callout" tiers; `voice_patch` is the live-
  call variant and is only valid while the caller is still on the line).
- Until plan 05 ships: store `message_id` on the patch and show the
  patch on the message; backfill attempts/acks when 05 lands.

### Recording across the patch

- The caller's inbound trunk dialog never leaves Asterisk during
  attended, 3-way, park or screened patches (Asterisk is the B2BUA), so
  rtpengine's per-dialog recording of the **caller's** leg captures the
  whole call — operator, hold music, contact — under the CallLog's
  `sip_call_id`. Nothing to do for the main recording.
- The **consult leg** (operator ↔ contact while the caller holds) is a
  separate dialog through the edge. `UploadCallRecordingJob` must
  resolve `call_patches.consult_sip_call_id` → patch → CallLog when the
  primary lookup (`UploadCallRecordingJob.php:72-76`) misses, and store
  the rows with `metadata.leg = 'patch_consult'`, `metadata.call_patch_id`.
  Honour `record_consult = false` by deleting the consult files on
  upload (the recording-daemon cannot be told per dialog in phase 1;
  phase 2 of the rtpengine plan's `X-Orbital-Record-*` headers can).
- Recording notice to the contact: played by `orbital-patch-screen`
  when `recording_notice` is on — the dialplan prompt the MixMonitor
  partial no longer plays.
- AI warm transfer (phase 3b) also has LiveKit Egress tracks for the
  consult room; same `call_patch_id` metadata.
- Call History / message detail: one player for the caller leg with
  patch markers (from `call_patch_events` timestamps), plus the consult
  recording(s) listed under the patch.

### AI path

**Phase 3a — screened patch via Asterisk (recommended default).** The
AI never dials; Laravel and Asterisk do.

1. New worker tool `patch_to_contact` (no number parameter) — registered
   when the compiled flow contains the step; plan 01's engine variant
   uses `submit_step` instead. Calls
   `POST /api/call-sessions/{key}/patch` (worker token, like
   `handoff`, `CallSessionController.php:278-283`).
2. Laravel resolves the target from the step params + `lookup_on_call`
   result, renders the announcement from session fields ("Urgent call
   from {{caller_name}} regarding {{reason}}, callback {{callback}}")
   to a TTS prompt (pattern of `AiFallbackPrompt` /
   `VoicemailGreetingRenderer`), creates the patch (`mode = screened`,
   `require_accept = true`) and returns
   `transfer_to: sip:7XXXXXXXXX@asterisk`.
3. Worker says the hold line, then `transfer_sip_participant` (as
   `handoff.py:65-74`) and ends its session. Caller hears MOH; contact
   hears the brief and presses 1 → bridged.
4. **Decline / no answer**: the `[orbital-patch]` exten continues after
   `Dial` to `patch-failed`, which dials the caller **back to the AI**
   with header `X-Orbital-Resume-Session: {session_key}` and
   `X-Orbital-Patch-Result: no_answer`. `CallSessionController::context()`
   learns to attach the new room to the existing `CallSessionState`
   (fields preserved) and the worker opens at the step's failure exit
   ("I couldn't reach Dr Lee; I've sent your message and they'll be
   paged again"). Without the resume header the caller would meet a
   fresh AI with no memory.

**Phase 3b — conversational warm transfer in LiveKit (optional).** The
AI briefs the contact in its own words and answers questions before
connecting.

- Add a LiveKit **outbound** SIP trunk pointing at Asterisk (only the
  inbound trunk exists today). The AI places the contact via
  `CreateSIPParticipant` into a consult room `{room}-patch-{id}`,
  dialling `7XXXXXXXXX@asterisk` — so Laravel still chose the number and
  trunk.
- Caller in the main room hears hold audio published by the agent;
  a consult agent session in the consult room briefs, asks "shall I put
  them through?"; on yes, move the contact into the caller's room
  (LiveKit `MoveParticipant`, or livekit-agents' warm-transfer workflow
  if present in the pinned version) and the agent leaves.
- Version risk: `livekit-agents[codecs]>=1.0` is unpinned
  (`agent-worker/requirements.txt:2`) and the server/SIP images are
  `:latest` (`docker-compose.yml:606,623`). Pin both before relying on
  newer APIs.
- Cost/media: caller and contact then talk through
  Asterisk → LiveKit → Asterisk for the rest of the call, holding
  LiveKit SIP ports; prefer 3a unless the client asks for it.
- `CallFallback` (`agent-worker/fallback.py:27-65`) must learn about an
  in-flight warm transfer: on agent failure, connect the caller to the
  contact if accepted, else hang up the consult participant and REFER
  to `ai-unavailable` as today.

**AI → human operator warm handoff** (brief the operator before the
caller is connected) reuses the same screened mechanism with the
operator's queue as the target; the plan 02 runner already opens the
session with the AI's fields. Tracked here only as a follow-on.

### Permissions and tenant isolation

- Enforce the existing permissions: `softphone.transfer` (blind),
  `softphone.park`, `softphone.conference` (3-way and n-way). Add
  `softphone.patch` (attended/screened patch to listed contacts) and
  `softphone.patch_unlisted` (raw number; supervisors by default).
  Hide UI controls and reject server actions (Livewire actions and API)
  without them; tests for both.
- `CallPatch` uses `BelongsToTeam`. Operator actions run under the
  operator's current team **and** check the OperatorCall belongs to the
  operator (`MultiCallDesk` already scopes by `user_id`,
  `MultiCallDesk.php:39-70`) and its `team_id` equals the patch target's
  team. Worker requests derive the team from the session (as `handoff`
  does), never from the request body; queries use
  `withoutGlobalScopes()` + explicit `team_id` like
  `HandoffDestinations`.
- The browser never sends channel names, dial strings, trunk ids or
  numbers for listed contacts — only ids; the server builds the rest.
- Codes are random, single-use, short-lived, and only valid inside
  `orbital-patch`; a code from client A can't be replayed from client
  B's call because the claim returns A's team and Laravel checks the
  `linked_id` the AMI events report against the row.
- Fix while here: the AI `transfer_call` tool accepts any string
  (`transfer.py:25-44`); restrict it to extensions Laravel lists for the
  session's client (or retire it in favour of `patch_to_contact` /
  `transfer_to_extension`), so it can't target another client's
  `handoff-{team}-…` entry.
- Portal: clients see patch outcomes on their messages and calls
  (who, when, connected, duration) and recordings under
  `portal.view_recordings`; never operator names or internal events
  beyond what the message timeline already shows.

### Audit

`call_patch_events` (every action and AMI result, with actor), mirrored
to `script_events` when scripted, plus plan 05 attempts/acks. Operator
overrides of an outcome ("doctor said yes but hung up") need a reason.
Supervisor view on the Call Log detail: patch timeline per attempt.
Metrics to the existing pushgateway path: patches per client, connect
rate, time-to-connect, caller hold time, declines.

## Edge cases and failure modes

| Case | Behaviour |
|---|---|
| Caller hangs up during consult | Asterisk leaves operator with contact; patch `caller_hung_up`; UI banner "caller gone — tell Dr Lee"; message still dispatched by plan 05 |
| Contact hangs up during consult | Back to caller automatically (Asterisk returns transferer to transferee); patch `contact_hung_up` |
| No answer / busy / congestion | `atxfernoanswertimeout` / `DialEnd` status → operator back with caller; "Next contact" offered |
| Voicemail picks up | Attended: operator hears it and cancels. Screened: no keypress → `declined`. Never connect a caller to voicemail unattended |
| Operator's browser/network dies mid-consult | Transferer hangup **completes** the transfer (caller ↔ contact, possibly unannounced). Mark `operator_dropped`, flag supervisor; with `atxferloopdelay = 0` no callback attempt. Consider RTP timeout on operator endpoints so a dead WebSocket ends the leg promptly |
| Operator presses Connect before the contact answers | Asterisk "blonde" transfer: caller rings the contact. Allowed only if `require_accept = false`; otherwise button disabled until answered/accepted |
| Caller held past `max_hold_seconds` | Alert at limit; a scheduled check issues `CancelAtxfer` at limit + 30 s so the caller isn't stranded |
| Caller presses keys during patch | No effect — `T`/`K` removed from caller-side Dial options |
| Two operators act on the same call | Patch actions take a row lock on the OperatorCall; second gets "patch in progress" |
| Contact forwards to a number that routes back into Orbital | Direct DIDs refused by `PatchTargetResolver`; forwarded loops show as a new inbound call — detect `X-Orbital-Patch` header/Diversion on inbound and route to the parked/held caller's operator rather than the queue |
| Contact number is one of the platform's DIDs / 911 / premium | Refused before dialling, with reason |
| Carrier rejects presented caller ID | Fall back to the client DID (configurable), log `dial_status` |
| AMI socket drops between action and event | Listener reconciliation (`CoreShowChannels`) + status timeout → `failed`; actions are idempotent per status |
| Several Asterisk nodes | `AmiNodeRouter` targets the node from the operator's channel; parked caller and retrieving operator may be on different nodes → retrieve by `Originate` on the caller's node, with the operator endpoint reached through Kamailio |
| TTS render slow or fails | Fall back to a generic announcement prompt ("You have a call from NCI, press 1 to accept"), never block the patch |
| Prompt file only on one node | Render to the shared prompts volume used by `VoicemailGreetingRenderer`, or render on the node selected by the claim |
| Consult recording arrives before CallLog | Same deferral as today, keyed on `consult_sip_call_id` |
| Recording consent (two-party states) | `recording_notice` on by default for patches; `record_consult` per client |
| AI agent crashes during 3b | `CallFallback` handles the consult room (see AI path) |
| Patch code reused / guessed | Claim is atomic single-use, expires in 2 min, 10^9 space, only from `orbital-patch`; invalid code emits an event and alert |

## Migration and rollout

0. **Prerequisites (ship independently, all small):**
   - Generate `features.conf` and `res_parking.conf`; drop `T`/`K` from
     caller-side Dial/Queue options; dialplan snapshot tests.
   - Real SIP hold in `sip-phone.js` (re-INVITE `sendonly`; Asterisk
     plays MOH) and fix the numpad/transfer-input bug (see below).
   - Enforce `softphone.*` permissions in UI and server.
   - `AmiEventListener` + `AmiNodeRouter` (coordinate with
     live-call-monitoring; build once).
1. **Phase 1 — attended patch for operators**: `call_patches` +
   events, `[orbital-patch]` + func_odbc claim, `CallPatchService`
   consult/connect/3-way/swap/cancel, Patch panel in Workspace and
   multi-call desk, recording linkage for consult legs. Behind a
   per-client `patching_enabled` flag; enable for one medical client
   first. Keep blind Transfer as is.
2. **Phase 2 — screening, park, delivery**: `orbital-patch-screen`
   announcements with accept digit, park/retrieve/callback-connect,
   plan 05 attempts/acks (or interim message link), `patch_to_contact`
   step in the operator runner (plan 02).
3. **Phase 3 — AI and conference**: 3a screened AI patch with resume
   session; 3b LiveKit warm transfer if clients want it; ConfBridge
   n-way.

Rollback: each phase is a flag plus generated config; disabling
`patching_enabled` hides the panel and the dialplan entries are inert
without codes.

## Tests

Telephony is faked at the AMI boundary (a `FakeAmi` recording actions
and replaying event fixtures captured from a real Asterisk 22), so the
suite runs without Asterisk. Dialplan is covered by snapshot tests.

**Dialplan / config**

- `features_generated.conf` contains the attended-transfer codes and
  `atxferdropcall = no`; `blindxfer` empty.
- No caller-side `Dial`/`Queue` in `from-trunk`, `[internal]` or any
  client context contains `T` or `K` (regression guard across all
  generated files).
- `[orbital-patch]` exists, is not included by `internal` or any
  `tenant_*` context; `from-livekit` routes `_7XXXXXXXXX` there before
  `_X.`.
- `res_parking.conf` lot uses `comebacktoorigin = no` and the timeout
  context.

**Services**

- `consult()` sends `Atxfer` to the node owning the operator channel,
  with `Context: orbital-patch` and a 10-digit numeric code; row
  `dialing`.
- Event fixture streams drive status: answered → accepted (UserEvent)
  → `AttendedTransfer` = `connected`; `DialEnd NOANSWER` = `no_answer`;
  transferee `Hangup` during consult = `caller_hung_up`;
  `CancelAtxfer` = `cancelled`.
- Claim function: valid code returns team/dial once; second claim and
  expired code return empty; `consumed_at` set.
- `PatchTargetResolver`: refuses client B's directory entry on client
  A's call; refuses non-patchable methods, own DIDs, `911`, and raw
  numbers without `softphone.patch_unlisted`; on-call primary → backup
  order from plan 04.
- Presented caller ID per policy, Diversion added for
  `original_caller`.
- Max-hold watchdog with `travel()` issues `CancelAtxfer` once.

**Answering-service scenarios (Livewire + FakeAmi)**

1. *Medical after-hours, patch accepted*: script runs
   `lookup_on_call(cardiology)` → `save_message` → `patch_to_contact`;
   operator consults primary, connects → step exit `connected`; message
   timeline shows patch; plan 05 dispatch `acknowledged`, no escalation
   jobs remain.
2. *Primary no answer, backup accepts*: first patch `no_answer`, Next
   contact picks backup, connect → two attempts recorded, one ack.
3. *Doctor declines*: screened mode, digit 2 → `declined`; script takes
   the `declined` exit; dispatch escalates to tier 2 after
   `wait_minutes`.
4. *Caller hangs up while operator is consulting*: `caller_hung_up`,
   message still dispatched, OperatorCall ended, partial-message policy
   unchanged.
5. *3-way then operator leaves*: statuses `conference` → `connected`;
   `connected_seconds` counts from caller–contact bridge.
6. *Park and callback*: caller parked, contact calls the client DID,
   inbound matched by caller ID → operator prompted → `Bridge` →
   `connected`; park timeout path returns caller to queue with priority.
7. *Multi-call desk*: patch on call A while call B rings; B stays on
   the desk, A's card shows patch state; no cross-contamination of
   OperatorCall rows.
8. *Recording*: caller-leg WAV attaches as today; consult-leg WAV with
   `consult_sip_call_id` attaches to the same CallLog with
   `metadata.leg = patch_consult`; `record_consult = false` deletes it.

**Tenant isolation and permissions**

- Operator with current team A cannot read or act on a client B patch
  (Livewire action and API) — 403.
- Operator without `softphone.patch` sees no Patch panel and server
  rejects `consult()`; without `softphone.conference` the 3-way button
  is hidden and the DTMF code is never returned.
- Worker `POST /call-sessions/{key}/patch` without token → 401; team
  comes from the session, a body `team_id` is ignored.
- Portal user of client A sees A's patch outcomes and (with
  `portal.view_recordings`) recordings; nothing of B.

**AI**

- Contract test: `/call-sessions/{key}/patch` response schema matches
  the Python tool's expectations (shared JSON schema, as plan 01).
- Worker unit test: tool REFERs to the returned URI and never accepts a
  number from the LLM.
- Resume: a second AI leg with `X-Orbital-Resume-Session` attaches to
  the same `CallSessionState`, fields intact, active step at the failure
  exit.
- Parity: the same fixture (`lookup_on_call` → `patch_to_contact`) run
  through the operator runner and the AI tool API produces the same
  exit and the same plan 05 records for the same fake outcomes.

**Manual / staging**

Real Asterisk 22 + a desk phone + a PSTN test number: attended,
3-way, cancel, screened accept/decline, park/callback, operator browser
killed mid-consult, two-node cluster with the operator and caller on
different nodes.

## Open questions

1. **Caller ID to the contact**: client DID, the service's main number,
   or the original caller (with Diversion)? IS installs vary; doctors
   often screen unknown numbers.
2. **Is acceptance keypress required** for operator-attended patches,
   or does the operator's word suffice for the ack? (Affects plan 05
   audit strength.)
3. Should the **consult leg be recorded** by default, and must the
   contact hear a recording notice in all states?
4. Do NCI clients use **park & callback** patches (page the doctor,
   connect on callback)? If rare, phase 2's park work can shrink to
   max-hold safety only.
5. **n-way conference** (interpreter, second doctor) — needed at launch?
6. **AI 3b** (conversational warm transfer) vs 3a (screened) — does any
   client want the AI to talk to the doctor?
7. **AMI vs ARI**: AMI `Atxfer` + DTMF features covers the flows above
   but 3-way/swap are DTMF-driven. If that proves brittle (DTMF over
   WebRTC through the edge), move operator calls into a Stasis app.
8. Is `func_odbc` built in the image (not in the explicit enable list,
   `Dockerfile:55-111`), or should the claim use `CURL()`?
9. Billing (plan 09): are patch minutes (outbound trunk) billed to the
   client separately?

## Size estimate

| Piece | Estimate |
|---|---|
| Phase 0 prerequisites (features/parking config, Dial options, SIP hold, permissions; AMI listener if live-call plan hasn't built it) | 1–1.5 wk |
| Phase 1 attended patch (model, claim, dialplan, service, panel, recording linkage) | 2 wk |
| Phase 2 screening + park/callback + plan 05 hook + script step | 1.5–2 wk |
| Phase 3a AI screened patch + session resume | 1 wk |
| Phase 3b LiveKit warm transfer (optional) | 1–1.5 wk |
| ConfBridge n-way (optional) | 0.5–1 wk |

Core (phases 0–2 + 3a): **~6–6.5 weeks**; with optional parts ~8–9.

## Bugs noticed while surveying

- `resources/js/softphone.js:288-299` — `onPadKey` checks `in-call`
  before `transferMode`; since transfer mode only exists in a call,
  numpad digits (always intercepted, `:146-152`) go to the caller as
  DTMF and into `dialInput`, never into the transfer number.
- `resources/js/sip-phone.js:244-272` — hold is a local mute, no
  re-INVITE: caller hears dead air, no MOH; Asterisk thinks the call is
  active.
- `softphone.transfer/park/conference` seeded and granted but never
  checked; the Transfer button is shown to everyone.
- Operator blind transfer resolves in `[internal]`, which has no
  outbound route — transfers to outside numbers fail.
- AI `transfer_call` (`agent-worker/tools/transfer.py:44-56`) REFERs to
  `sip:{extension}@asterisk`; LiveKit's endpoint context is
  `from-livekit`, whose `_X.` sends the call to `[default]`, which only
  hangs up (`docker/asterisk/extensions.conf`), so an AI transfer to an
  extension appears to drop the caller. The LLM-chosen string can also
  name another client's `handoff-{team}-{number}` entry.
- Caller-side `Dial(...,tTkK)` gives the **external caller** bridge
  transfer rights (`T`); with Asterisk's built-in default
  `blindxfer = #` (no `features.conf` overrides it) a caller pressing
  `#` during an operator or AI call may get a transfer prompt. Verify on
  a running node.
- `UploadCallRecordingJob` defers with `release()` when no CallLog
  matches (`UploadCallRecordingJob.php:78-89`), but the default Horizon
  supervisor has `tries = 1` (`config/horizon.php:213`), so the retry
  fails; the spool watcher re-dispatches every settled file on each
  run (`UploadRtpengineRecordings.php:97-131`), so recordings for
  dialogs that never match a CallLog (operator legs, future consult
  legs) loop indefinitely.
