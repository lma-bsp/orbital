# Plan 14: Queue announcements, per-client hold experience and virtual hold

**Status:** proposed · part of [IS parity](README.md) · survey item G in
[07](07-additional-features.md) · depends on 06 (schedules, for callback
windows); uses 01 (script steps) and 05 (callback outcome as delivery)
when they land, but ships without them
**Written:** 2026-11-24

## Problem

### What IS does

An Amtelco IS/Infinity system keeps a caller in a client-branded hold
experience while operators are busy:

- **Per-account greeting and hold treatment**: the client's own answer
  phrase ("Thank you for calling Lakeside Pediatrics…"), its own music
  or message-on-hold, in a consistent voice.
- **Periodic announcements**: "You are caller number 3", "Your
  estimated wait is about 2 minutes", "Your call is important to us",
  repeated on a timer, each one per account where the client wants it.
- **Virtual hold / callback** ("press 1 and we'll call you back without
  losing your place"): the caller hangs up, their place in line is
  kept, and when an operator reaches that place the system dials the
  caller back and connects them to the operator, who sees it is a
  callback for that account.
- **Abandoned-call handling**: callers who hang up while waiting show
  up on a list (and in reports) so the service can call them back,
  often automatically after a short delay.
- Reporting distinguishes answered, abandoned, timed-out/overflowed and
  callback-requested calls.

### What Orbital has today (checked in code)

**Queue definition.** Queues live in the Asterisk Realtime `queues`
table, written by `QueueSyncer` (`app/Services/Telephony/Realtime/QueueSyncer.php:28-51`).
The baked `docker/asterisk/queues.conf:7-13` has only `[general]` and a
`#tryinclude` of a file nothing writes any more (`AsteriskConfigService`
docblock, `app/Services/Telephony/AsteriskConfigService.php:29-32`, says
queues.conf generation was dropped). The synced row sets only
`strategy`, `timeout`, `retry`, `wrapuptime`, `maxlen=0`,
`musiconhold`, `joinempty=1`, `leavewhenempty=1`, `monitor_type`
(`QueueSyncer.php:37-48`). The class docblock says it outright:
"announcements, service level, periodic, etc. … are at their defaults
until we surface them" (`QueueSyncer.php:20-24`). So **no position,
hold-time or periodic announcements, no exit-key context, no
servicelevel**.

**Realtime schema can't hold prompt names.** The `queues` table
(`database/migrations/2026_04_15_100000_create_asterisk_realtime_tables.php:195-251`)
declares the sound-file columns `queue_youarenext` … `queue_thankyou`,
`queue_callerannounce`, `queue_reporthold` as **`integer`** (lines
208-219), plus `monitor_format` and `membermacro`/`membergosub` as
integer (205-207). These are file names in app_queue, so no prompt can
be stored there today. `periodic_announce` is `string(50)` (227) —
too short for a per-client prompt path, and a comma-separated list of
them. `joinempty`/`leavewhenempty` are `integer` (243-244), so the
named conditions (`paused,unavailable,…`) can't be expressed either.
`context` (199) exists as a string — that is the column app_queue uses
for exit-on-keypress, and nothing sets it.

**Dialplan.** Each client's queues are emitted by
`resources/views/asterisk/client-dialplan.blade.php:49-60` (and again,
for all clients, into `[internal]` by `extensions.blade.php:132-142`):

```
exten => queue-{id},1,NoOp(...)
 same => n,Answer()
 same => n,Queue({asteriskName},tTkK,,,{strategyTemplate.timeout ?? 30})
 [if overflowAgent] same => n,Dial(PJSIP/{persona ext}@livekit,120,...)
 same => n,Hangup()
```

`from-trunk.blade.php:40-41` routes a DID with destination `queue` to
that extension. Note the 5th `Queue()` argument is the **total queue
wait timeout**, and it reuses the agent group's strategy-template
`timeout`, which `QueueSyncer.php:40` also writes as the **per-member
ring timeout** (template seeds: 20/30/45 s, `QueueStrategyTemplateSeeder.php:28-44`).
One number means two things. See "Bugs noticed" below.

**Hold music.** `CallQueue.music_on_hold` (`app/Models/CallQueue.php:23`,
column default `'default'` in `2026_04_11_100005_create_call_queues_table.php:24`)
is synced to `queues.musiconhold` (`QueueSyncer.php:44`), but the client
queue form (`ManageClientCallQueues.php:69-122`) has no field for it, so
every queue plays `default`. `HoldMusicClass` (`app/Models/HoldMusicClass.php`)
is a **platform-only** catalogue (no `team_id`), managed in
`HoldMusicResource`, with `files` type marked "coming soon"
(`HoldMusicResource.php:92`). The migration docblock says "the dialplan
generator reads this table and emits … config blocks"
(`2026_04_12_011000_create_hold_music_classes_table.php:13-14`) but
**no code generates `musiconhold.conf`**: grep finds no writer, the
Asterisk image ships the `make samples` copy (`docker/asterisk/Dockerfile`
builder `make samples`, runtime `COPY --from=builder /etc/asterisk`),
and the config-sync sidecar only issues `dialplan reload`, `voicemail
reload` and a cdr module reload (`docker/asterisk-config-sync/entrypoint.sh:102-107`).
Any class other than the sample's own (e.g. a stream class created in
the UI) is unknown to Asterisk. `TelephonySettings` stores
`outage_hold_music` / `outage_max_hold_seconds`
(`app/Filament/Pages/TelephonySettings.php:138-152`), which nothing in
the dialplan reads.

**TTS prompts for Asterisk.** Two renderers exist:
`AiFallbackPrompt` (`app/Services/Telephony/AiFallbackPrompt.php`) —
platform text → OpenAI/ElevenLabs → downsampled to **8 kHz `.sln`**,
re-rendered only when a hash of provider+voice+text changes, stored on
the `asterisk-prompts` disk; and `VoicemailGreetingRenderer`
(`app/Services/Telephony/VoicemailGreetingRenderer.php`) — per-client
text keyed by `team_id`. Prompts reach Asterisk pods through object
storage + the config-sync sidecar into a per-pod emptyDir
(`helm/orbital/templates/asterisk-statefulset.yaml:60-77,151-178`).
`Voice` (`app/Models/Voice.php`) is a provider voice catalogue used for
personas; the agent worker can actually synthesise only `elevenlabs`
and `openai` (`agent-worker/agent.py:372-385`; any other provider falls
back to ElevenLabs).

**Overflow.** `CallQueue.overflow_agent_persona_id` dials the persona's
first extension at LiveKit after `Queue()` returns
(`client-dialplan.blade.php:53-57`). The AI can hand back only to
external numbers via `from-livekit` handoff entries
(`HandoffDestinations`, `from-trunk.blade.php:103-111`); there is no
path back into a queue, and no callback tool. `schedule_callback`
exists only as a catalogue entry (`database/seeders/IntakeGoalLibrarySeeder.php:973-987`)
with no executor (plan 01 lists it under "Later").

**Outbound calling.** `AsteriskAmiService::originate()` /
`originateToApplication()` (`app/Services/Telephony/AsteriskAmiService.php:256-320`)
send async `Originate` to the single AMI host in config — the headless
service, i.e. a random Asterisk pod (`asterisk-statefulset.yaml:163-167`
overrides it only for the sidecar). Per-pod AMI addresses exist in
`AsteriskBackend` (`AsteriskBackendDiscovery.php:41-53`) and
`commandOn()` (`AsteriskAmiService.php:129`). Trunk selection for a
client's outbound leg exists: `HandoffDestinations::trunkFor()`
(`HandoffDestinations.php:96-106`) and the dial string
`PJSIP/{e164}@trunk_{id}` (`AsteriskConfigService.php:96`).

**Cluster topology matters.** Asterisk runs as a StatefulSet with
`replicas` (`asterisk-statefulset.yaml:26`), and Kamailio spreads calls
with `ds_select_dst("1", "0")` (hash over Call-ID,
`docker/kamailio/kamailio.cfg:282,400,421,436`). app_queue state is
in-memory per pod, so **a queue's waiting callers are split across
pods**: position and app_queue's hold-time average are per pod, not per
queue.

**Metrics.** `queue_log` is written by Asterisk via realtime
(`docker/asterisk/logger.conf:19`, `extconfig.conf:29`).
`RollUpQueueMetricsCommand` (`app/Console/Commands/RollUpQueueMetricsCommand.php:53-100`)
counts only `ENTERQUEUE`, `CONNECT`, `ABANDON`, `COMPLETE*`;
`TelephonyMetricsCollector::queueActivity()` (`app/Services/Metrics/TelephonyMetricsCollector.php:117-170`)
adds `EXITWITHTIMEOUT`/`RINGNOANSWER` counts. Neither knows
`EXITWITHKEY` (callback requested) or `EXITEMPTY`. `CallLog.call_queue_id`
is set by `CdrIngester::route()` from a `queue-{id}` destination
(`app/Services/Telephony/CdrIngester.php:203-207`), but nothing records
whether a queued caller abandoned.

**Summary:** hold music is one platform class; there are no
announcements, no per-client queue prompts, no exit keys, no callback
model or dialer, and no abandon list.

## Goal

Every client queue gets a configurable, client-branded hold experience
and a callback path, executed by Asterisk's own queue engine wherever
possible:

1. Position / estimated-wait announcements and periodic messages, on
   or off per queue, with the client's own TTS or uploaded prompts.
2. Hold music per client (pick a platform class or upload the client's
   own files), actually delivered to every Asterisk pod.
3. **Virtual hold**: after a configurable wait the caller is offered a
   callback, confirms a number, hangs up, and keeps their place; when an
   operator reaches that place, the operator is connected and the
   caller is dialled (agent-first). Retries, give-up rules, outcome on
   the call record.
4. **Abandoned-call callbacks**: per queue, off / list for operators /
   automatic, with de-duplication and quiet hours.
5. Clear ordering with **AI overflow**: max wait is its own setting; the
   AI can also take a callback request.
6. Reporting that separates answered, abandoned, overflowed, callback
   requested and callback completed.

Non-goals: classic IVR menus (plan 15), scheduled outbound programmes
like wake-up calls (plan 19), skills-based priority (plan 26).

## Design

### 1. Data model

All new client-owned tables use `BelongsToTeam`. Because the trait's
scope does nothing when there is no authenticated user
(`app/Models/Concerns/BelongsToTeam.php:16-21`), every job, dialplan
webhook and AMI path below filters `team_id` explicitly.

**`call_queues` — new columns** (hold & callback settings live on the
queue, as a client can have several queues with different treatment):

| Column | Type | Default | Meaning |
|---|---|---|---|
| `max_wait_seconds` | int | 300 | Total time in queue before overflow / fallback (replaces the template-timeout reuse) |
| `hold_music_class_id` | FK → `hold_music_classes`, nullable | null = platform default | Replaces free-text `music_on_hold` (kept for one release, then dropped) |
| `announce_position` | enum `off/on/limit` | `off` | Maps to app_queue `announce-position` |
| `announce_position_limit` | int | 5 | With `limit`: only say position ≤ N |
| `announce_holdtime` | enum `off/on/once` | `off` | Maps to `announce-holdtime` |
| `announce_frequency` | int s | 60 | `announce-frequency` (0 = off) |
| `min_announce_frequency` | int s | 15 | Avoid back-to-back position+periodic |
| `announce_round_seconds` | int | 30 | Round "about N minutes" |
| `periodic_announce_frequency` | int s | 45 | Periodic message interval |
| `random_periodic_announce` | bool | false | Rotate vs. in order |
| `vhold_enabled` | bool | false | Offer callback |
| `vhold_offer_after_seconds` | int | 60 | Offer starts after the caller has waited this long |
| `vhold_min_waiting` | int | 1 | Only accept the keypress if ≥ N callers were ahead (avoids pointless callbacks) |
| `vhold_digit` | char(1) | `1` | Exit digit |
| `vhold_max_attempts` | int | 3 | Dial attempts per callback |
| `vhold_retry_minutes` | int | 5 | Gap between attempts |
| `vhold_expire_minutes` | int | 120 | Give up (→ abandoned list) if not reached |
| `vhold_schedule_id` | FK → `schedules` (plan 06), nullable | null = always | Only offer/dial callbacks inside this schedule |
| `abandon_callback_mode` | enum `off/list/auto` | `off` | |
| `abandon_min_wait_seconds` | int | 20 | Ignore hang-ups faster than this (wrong numbers, IVR hits) |
| `abandon_auto_delay_minutes` | int | 2 | Auto mode: wait before dialling |
| `announcement_voice_id` | FK → `voices`, nullable | client default | Voice for this queue's TTS prompts |

Validation (form request + Filament rules): `announce_frequency` either
0 or ≥ 15; `vhold_offer_after_seconds < max_wait_seconds`;
`periodic_announce_frequency ≥ 15`; overflow persona must belong to the
same client.

**`audio_prompts`** (`BelongsToTeam`; `team_id` null = platform
prompt) — one row per spoken thing, designed to be reused by plan 15
(IVR) and the voicemail greeting:

| Column | Notes |
|---|---|
| `team_id`, `call_queue_id` (nullable) | Queue-specific override, else client-wide |
| `purpose` | `queue.periodic`, `queue.vhold_offer`, `queue.vhold_confirm`, `queue.vhold_accepted`, `queue.vhold_unavailable`, `queue.callback_whisper`, `queue.callback_intro`, `queue.position_prefix` (replaces `queue-thereare`), `queue.holdtime_prefix` (replaces `queue-holdtime`), `queue.thankyou`, `voicemail.greeting` (later) |
| `source` | `tts` / `upload` / `stock` |
| `text` | TTS text; may contain `{client_name}` only (rendered once, not per call) |
| `voice_id` | FK `voices`; null → queue `announcement_voice_id` → client default → platform default |
| `upload_path` | Original upload (any format) |
| `audio_key` | Rendered 8 kHz `.sln` key on the `asterisk-prompts` disk |
| `content_hash` | sha256(provider, voice, text or upload bytes) |
| `render_status` / `render_error` / `rendered_at` | |
| `sort` | Order for multiple `queue.periodic` messages |

Rendered files are **content-addressed**:
`clients/{team_id}/prompts/{purpose}-{hash12}.sln`. A text change
produces a new file, the queue row is pointed at it only after render
succeeds, and the old file is deleted a sync interval later. This keeps
pods (which pull prompts asynchronously) from ever being pointed at a
file they don't have yet, and avoids `periodic_announce` length limits
(paths stay < 80 chars).

**`hold_music_classes` — extend** (stays platform-managed, gains
client-owned rows): `team_id` nullable (null = platform class every
client may pick), `files` type implemented via `hold_music_files`
(`hold_music_class_id`, `upload_path`, `audio_key`, `sort`). Client
classes are named `t{team_id}_{slug}` (same convention as
`CallQueue::asteriskName()`, `CallQueue.php:112-125`) so Asterisk's
global MOH namespace can't collide. The model can't use the
`BelongsToTeam` global scope as-is (platform rows would vanish for
client users); follow the existing `Orchestration` pattern
(`withoutGlobalScope('team')->where(team_id = owner or null)`,
`ManageClientCallQueues.php:81-90`) in a `scopeAvailableTo(Team)`.

**`queue_callbacks`** (`BelongsToTeam`) — one row per callback promise:

| Column | Notes |
|---|---|
| `team_id`, `call_queue_id` | |
| `source` | `virtual_hold` / `abandoned` / `ai` / `operator` (manual from the abandon list) |
| `status` | `pending` → `waiting_for_operator` → `dialing` → `connected` / `retry_wait` / `failed` / `expired` / `cancelled` |
| `callback_number` (E.164), `caller_id_name` | |
| `presented_did` | The client DID the caller originally dialled (`ORBITAL_DID`) — shown to the caller on the return call |
| `original_linked_id` | CallLog of the original call (FK by `linked_id`) |
| `original_entered_at` | When the caller first entered the queue — the "place in line" |
| `original_position` | `QUEUEPOSITION` at exit (informational) |
| `asterisk_backend_id` | Pod holding the placeholder (nullable) |
| `placeholder_channel` | `Local/vh-{id}@…;2` once injected |
| `attempts`, `next_attempt_at`, `expires_at` | |
| `answered_by_user_id`, `connected_at`, `callback_linked_id` | Outcome; links the return call's CallLog |
| `outcome_note` | Operator's note (left voicemail, wrong number…) |
| `lock_version` | Optimistic lock: only one dialer per row |

Indexes: `(status, next_attempt_at)`, `(team_id, callback_number, status)`
for dedupe, unique `(original_linked_id, source)` so the same abandoned
call can't produce two callbacks.

**`queue_callback_events`** — append-only audit (state transitions,
dial attempts with hangup cause, actor `system`/user id/`ai`). When
plan 01's `script_events` exists, callback events are also mirrored
there for the original session.

**`queue_waits`** (optional, phase 3 — see §6) — a projection of callers
currently waiting, per queue across pods.

### 2. Asterisk side

#### 2a. Realtime schema fix (prerequisite)

Migration altering the ARA `queues` table to Asterisk's own types:
`queue_youarenext` … `queue_reporthold`, `monitor_format`, `membergosub`
→ `varchar(128)`; `periodic_announce` → `varchar(4096)` (comma list);
`joinempty`/`leavewhenempty` → `varchar(128)`; add `announce_position_only_up`
if present in Asterisk 22's `queues` realtime schema (check
`contrib/ast-db-manage` for 22 — open question 6). `membermacro` stays
(unused; Asterisk 21 removed macro support). Existing integer values
`1` are rewritten as `yes`.

#### 2b. `QueueSyncer` writes the hold experience

`QueueSyncer::sync()` grows a `QueueTreatment` value object built by a
new `QueueTreatmentResolver` (settings + resolved prompts + MOH class).
New row fields:

| ARA column | Source |
|---|---|
| `timeout` | per-member ring timeout from template (unchanged) |
| `musiconhold` | resolved class name (`t42_jazz` / platform name / `default`) |
| `announce_position`, `announce_position_limit`, `announce_holdtime`, `announce_frequency`, `min_announce_frequency`, `announce_round_seconds` | queue settings |
| `queue_thereare`, `queue_youarenext`, `queue_holdtime`, `queue_thankyou`, … | client prompt path if rendered, else null (Asterisk stock sound) |
| `periodic_announce` | comma list of rendered `queue.periodic` prompts, then `queue.vhold_offer` last when vhold is on |
| `periodic_announce_frequency` | `vhold_enabled ? vhold_offer_after_seconds : periodic_announce_frequency` for the first play, see below |
| `relative_periodic_announce` | `yes` (time from end of last announcement — avoids talking over itself) |
| `random_periodic_announce` | queue setting |
| `context` | `orbital-vhold-t{team_id}` when vhold is on, else null |
| `servicelevel` | from client SLA (plan 10), default 30 |
| `joinempty` | `yes` (callers may queue while operators are briefly away; max wait + overflow bound it) |
| `leavewhenempty` | `penalty,paused,invalid,unavailable` — explicit instead of `1` |
| `announce_to_first_user` | `no` |

Asterisk has a single `periodic-announce-frequency`, so "offer after 60 s,
then every 45 s" isn't expressible exactly. Rule: when vhold is on, the
frequency is `vhold_offer_after_seconds` and the offer is the last item
in the list, so the first offer comes at that time; the in-dialplan
check (`vhold_min_waiting`, schedule) gates whether pressing the digit
does anything. Documented in the form helper text.

Note app_queue re-reads a realtime queue row when a caller joins, so a
settings change applies from the next caller on each pod — acceptable,
and called out in the UI ("applies to new callers").

#### 2c. Dialplan (`client-dialplan.blade.php`, shared partial)

Move the queue block into `resources/views/asterisk/partials/queue.blade.php`
included by both `client-dialplan` and `extensions` (removing the copy
in `[internal]`, `extensions.blade.php:132-142`). Sketch:

```
exten => queue-{id},1,NoOp(Queue {name} -> {asteriskName})
 same => n,Set(__ORBITAL_QUEUE_ID={id})
 same => n,Set(__ORBITAL_QUEUE_ENTERED=${EPOCH})       ; place-in-line timestamp
 same => n,Answer()
 same => n,Queue({asteriskName},tTkK,,,{max_wait_seconds})
 same => n,NoOp(QUEUESTATUS=${QUEUESTATUS})
 same => n,GotoIf($["${QUEUESTATUS}" = "TIMEOUT" | "${QUEUESTATUS}" = "LEAVEEMPTY" | "${QUEUESTATUS}" = "JOINEMPTY"]?overflow)
 same => n,Hangup()
 same => n(overflow),...existing overflow Dial to AI, else fallback (voicemail / outage message)
```

The exit-key context, one per client (`orbital-vhold-t{team}`), keyed by
the digit:

```
[orbital-vhold-t42]
exten => 1,1,NoOp(Virtual hold requested, queue ${ORBITAL_QUEUE_ID} pos ${QUEUEPOSITION})
 same => n,Set(CB=${CURL(${ORBITAL_INTERNAL_URL}/api/telephony/vhold/offer,...)})   ; see 2d
 same => n,GotoIf($["${CB_DECISION}" = "accept"]?confirm)
 same => n,Playback({vhold_unavailable prompt})         ; "we can't schedule a callback right now"
 same => n,Queue(t42_support,tTkK,,,${CB_REMAINING},,,,,${QUEUEPOSITION})   ; back in at the same place
 same => n,Goto(queue-${ORBITAL_QUEUE_ID},overflow)
 same => n(confirm),Playback({vhold_confirm prompt})    ; "We'll call you back at"
 same => n,SayDigits(${CALLERID(num)})
 same => n,Read(CHOICE,{press 1 to confirm, 2 to enter another number},1,,,6)
 ...  ; 2 → Read(NEWNUM,…,15) with NANP validation in Laravel
 same => n,Set(CB=${CURL(…/vhold/accept,…)})            ; creates the callback + placeholder
 same => n,Playback({vhold_accepted prompt})            ; "You'll keep your place. Goodbye."
 same => n,Hangup()
```

Two Asterisk facts this relies on, to verify against Asterisk 22 in the
spike (open question 6): `Queue()`'s **position** argument (last
argument; the macro argument was removed in 21, so it moved one place
left) re-enters a caller at a given position, and `QUEUEPOSITION` is set
when a caller leaves via an exit key.

The **placeholder** that holds the caller's place, and the agent-first
callback, in a platform context:

```
[orbital-vhold-wait]       ; ;2 half of Local/vh-{cbid}@orbital-vhold-wait/n
exten => _vh-X.,1,Set(ORBITAL_CALLBACK=${EXTEN:3})
 same => n,Set(CALLERID(name)=CB ${ORBITAL_CB_NAME})
 same => n,Set(CALLERID(num)=${ORBITAL_CB_NUMBER})
 same => n,Queue(${ORBITAL_CB_QUEUE},tTkK,,,${ORBITAL_CB_TTL},,orbital-vhold-whisper^s^1,,${ORBITAL_CB_POS})
 same => n,Set(CURL… /vhold/{id}/not-reached?status=${QUEUESTATUS})
 same => n,Hangup()

[orbital-vhold-dial]       ; ;1 half — runs once an operator answered the placeholder
exten => _vh-X.,1,Set(CURL… /vhold/{id}/dialing)      ; returns number, trunk, presented DID
 same => n,Set(CALLERID(num)=${ORBITAL_CB_PRESENT})
 same => n,Dial(PJSIP/${ORBITAL_CB_NUMBER}@trunk_${ORBITAL_CB_TRUNK},${ORBITAL_CB_RING},b(orbital-cdr-callback^s^1))
 same => n,Set(CURL… /vhold/{id}/result?dialstatus=${DIALSTATUS})
 same => n,Playback({no-answer notice to operator if DIALSTATUS != ANSWER})
 same => n,Hangup()

[orbital-vhold-whisper]    ; Queue gosub — runs on the operator's leg before bridging
exten => s,1,Playback({client callback_whisper prompt})   ; "Callback for Lakeside Pediatrics"
 same => n,Return()
```

The placeholder is created by `Originate(Local/vh-{id}@orbital-vhold-wait/n,exten,orbital-vhold-dial,vh-{id},1)`
— from the dialplan right after `accept` (same pod as the caller, so the
position is meaningful) or via AMI on a chosen pod for re-injection and
for abandoned/AI callbacks. The `orbital-cdr-callback` pre-dial handler
tags the outbound CDR with `orbital_team_id` and a new
`orbital_callback_id` CDR variable so `CdrIngester` links the return
call (add the column to `cdr_adaptive_odbc.conf` / cdr table).

Prompts in dialplan are referenced by the content-addressed paths from
`audio_prompts`; when a prompt is not rendered the partial emits the
stock sound name instead (as `from-trunk.blade.php:63-77` does for the
voicemail greeting).

#### 2d. Asterisk → Laravel calls

The dialplan needs synchronous answers (accept or not, callback id).
Options, in order of preference:

1. `CURL()` (func_curl) to an internal-only endpoint, authenticated
   with a shared secret HMAC like the voicemail webhook
   (`routes/api.php:95-99`, `docker/asterisk/notify-voicemail.sh:10-18`).
   func_curl is not in the explicit menuselect list
   (`docker/asterisk/Dockerfile:56-109`); add `--enable func_curl`
   (libcurl is already in the runtime image).
2. `func_odbc` against the same Postgres DSN already configured for
   realtime — no HTTP hop, but logic in SQL; fallback only.

Endpoints (new `Api\Telephony\VirtualHoldController`, middleware
`asterisk-webhook`, thin — logic in `VirtualHoldService`):
`POST /api/telephony/vhold/offer` (decide: enabled, inside schedule,
waiting ≥ `vhold_min_waiting`, number dialable, not already holding a
pending callback for this number+queue), `/accept`, `/{id}/dialing`,
`/{id}/result`, `/{id}/not-reached`. Each must answer in < 300 ms; on
timeout/5xx the dialplan treats the result as "unavailable" and puts
the caller back in the queue at the same position — a broken Laravel
never drops a caller.

#### 2e. Hold music generation

New `MusicOnHoldConfigService` renders
`resources/views/asterisk/musiconhold.blade.php` →
`generated/musiconhold_generated.conf`:

- `builtin` → `mode=files`, stock directory;
- `files` → `mode=files`, `directory=/var/spool/asterisk/prompts/moh/{class}`,
  files transcoded to 8 kHz `.sln` on upload (same downsampler as
  `AiFallbackPrompt`), `sort=alpha` / `random`;
- `stream` → `mode=custom` with the stream application (as the
  migration intends; the binary — `mpg123` or `ffmpeg` — must be added
  to the runtime image).

A baked `docker/asterisk/musiconhold.conf` with `[general]` and
`#tryinclude "generated/musiconhold_generated.conf"` replaces the sample.
The config-sync sidecar adds `moh reload` to `ami_reload()`
(`docker/asterisk-config-sync/entrypoint.sh:92-107`). MOH files ride the
existing prompts sync, so no new volume.

### 3. Services and jobs (`app/Services/Queue/`)

| Class | Responsibility |
|---|---|
| `QueueTreatmentResolver` | Settings + prompts + MOH → the ARA row fields and dialplan variables; pure, unit-testable |
| `PromptRenderer` | Generalises `AiFallbackPrompt::render/synthesize/downsample` for any `AudioPrompt` (TTS via `Voice` provider + `provider_voice_id`, or upload transcode); hash-skip; never throws |
| `RenderAudioPrompt` job | Queue `prompts`; on success re-syncs affected queues (`QueueSyncer`) and dispatches `RegenerateTelephonyConfig` for the client |
| `VirtualHoldService` | offer / accept / dialing / result decisions; state machine on `queue_callbacks`; dedupe |
| `CallbackPlaceholderInjector` | AMI `Originate` of the Local placeholder on a specific `AsteriskBackend` (needs a per-host `originateOn()` — `commandOn()` shows the pattern); variables passed one per `Variable:` line rather than comma-joined (`AsteriskAmiService.php:304-311` breaks on values containing commas, e.g. caller names) |
| `ReconcileCallbacks` job (every minute) | For `waiting_for_operator` rows: is the placeholder channel still alive on its pod (AMI `Status`/`CoreShowChannels` on that backend)? If the pod died or the channel is gone without a `/result`, re-inject on a healthy pod at the **front** (position 1) — the caller already waited; move `retry_wait` rows whose `next_attempt_at` passed back into a queue; expire rows past `expires_at` (→ abandoned list + `queue_callback_events`) |
| `DetectAbandonedCalls` job (every minute) | Reads new `queue_log` `ABANDON` rows (cursor on `queue_log.id`), joins to `CallLog` by `callid = linked_id` once ingested; applies `abandon_callback_mode`; creates `queue_callbacks(source=abandoned)` for `auto` (`next_attempt_at = now + delay`) or flags for the list |
| `DispatchDueCallbacks` job (every minute) | For `abandoned`/`ai`/`operator` callbacks due now and inside `vhold_schedule_id` (plan 06 `ScheduleEvaluator::isOpen`): inject placeholder at the end of the queue with a priority boost (Queue `QUEUE_PRIO` set on the Local channel) — still agent-first, so no operator-less dial-outs |

**Agent-first only.** We never dial the caller before an operator is
on the line: no robocall feel, no abandoned outbound calls (relevant
for TCPA/abandonment rules), and no AMD needed — the operator hears
voicemail and decides.

**De-duplication.** Before creating any callback, and again just
before dialling (`/dialing`), cancel it if: the same number reached an
operator or AI on this client's DIDs after `original_entered_at`
(`CallLog` with status `completed`, same `from_number`, same team), or
another active callback exists for the same number+client. The
operator sees "cancelled — caller got through at 10:42".

**Locking.** State transitions use `lock_version` compare-and-set so a
reconcile re-injection and a late `/result` can't both act. A callback
has at most one live placeholder (`placeholder_channel` set ⇒ no
re-inject until confirmed gone).

### 4. AI overflow interplay

Order of events for a queue with both features:

```
enter queue ──(vhold_offer_after_seconds)──▶ offer callback (periodic)
     │                                            │ press 1 → callback flow (no overflow)
     ▼
max_wait_seconds reached / LEAVEEMPTY ──▶ overflow AI persona (if set)
                                          └─▶ else fallback: voicemail or message
```

- `max_wait_seconds` replaces the template ring timeout as the queue
  wait, fixing the conflation (§Bugs).
- `LEAVEEMPTY` (all operators paused) goes straight to overflow and
  suppresses the vhold offer (`/offer` checks `QUEUE_MEMBER(q,logged)`
  passed as a parameter, or the member table) — offering a callback no
  one can make is worse than the AI.
- **AI takes a callback.** A new worker tool `request_callback(number?)`
  backed by `POST /api/call-sessions/{key}/callback` (alongside the
  existing call-session routes, `routes/api.php:131-143`) creates
  `queue_callbacks(source=ai)` for the overflow queue, carrying the
  original `ORBITAL_QUEUE_ENTERED` (passed via the existing
  `X-Orbital-*` header pre-dial handler, `from-trunk.blade.php:150-159`)
  so the callback is placed in order. The AI confirms the number and
  ends the call. When plan 01 lands this becomes the executor for the
  `schedule_callback` step (window → `next_attempt_at`).
- The callback's session context (fields the AI captured, reason) is
  shown to the operator who answers the placeholder (plan 02 handoff
  with `sessionKey`).
- Overflow persona must belong to the queue's client — the current
  picker doesn't enforce this (§Bugs).

### 5. Operator workspace

- A placeholder ringing an operator shows as a call whose caller ID name
  starts `CB` and number = callback number; the workspace resolves it
  to the `queue_callbacks` row (by `callback_linked_id` / channel var
  `ORBITAL_CALLBACK` via the live-call projection when available,
  else by number + `status=dialing`) and shows a banner: "Callback for
  Lakeside Pediatrics — caller waited 6 min, requested 10:31, attempt
  2/3", plus the original call's AI fields / message draft.
- Outcome buttons after the call: *Reached*, *Left voicemail*, *No
  answer*, *Wrong number* → `/result` equivalents from the UI; these
  override the DIALSTATUS-derived outcome.
- **Abandoned list** (operator panel page + supervisor view): per client,
  newest first, filters by queue; "Call back now" creates
  `source=operator` and dials via the operator's own softphone
  (click-to-dial, as plan 05's dispatcher queue does) rather than the
  placeholder, since the operator is already free; "Dismiss" with a
  reason. Rows auto-close on de-dup.

### 6. Accurate position / wait across pods

Because Kamailio hashes calls across pods (§Problem), app_queue's
"you are caller 3" is per pod. Options:

1. **Phase 1 default:** if more than one active `AsteriskBackend`
   exists, the treatment resolver forces `announce_position=off` and
   keeps `announce_holdtime` (app_queue's per-pod average is a fair
   estimate since all pods share the same operators). The UI explains
   why position is disabled.
2. **Phase 3:** a cluster-wide wait estimate computed in Laravel from
   `queue_log` (`ENTERQUEUE` without `CONNECT`/`ABANDON`/`EXIT*` for the
   same `callid` = waiting) plus the live-call AMI projection proposed
   in `docs/plans/live-call-monitoring.md`. Announcements then come
   from a dialplan `Gosub` on a timer instead of app_queue's built-in
   ones — larger change, only if NCI asks for position on multi-pod.
3. Alternatively make Kamailio pin each client DID to one pod (hash on
   To-user instead of Call-ID). Simplest correct answer but changes
   failover/drain behaviour — decision for the SIP-edge owners.

Callback place-keeping has the same issue: the placeholder holds a place
on its pod. Acceptable, as all pods ring the same members; documented.

### 7. Admin UI (Filament)

- **Client → Call Queues** (`ManageClientCallQueues::formSchemaFor`):
  new tabs/sections *Hold experience* (MOH picker scoped to platform +
  this client's classes, announcement toggles, frequencies, voice) and
  *Callbacks* (virtual hold settings, schedule picker from plan 06,
  abandon mode). Max wait moves here from the template.
- **Client → Prompts** relation page: list/create `audio_prompts` per
  purpose, TTS text + voice picker (only voices whose provider the
  renderer supports — today `openai`, `elevenlabs`), upload, **Preview**
  (plays the rendered `.sln` converted to WAV in the browser), render
  status badge, "Re-render".
- **Client → Hold music**: upload files into a client class.
- **Platform → Hold Music** (`HoldMusicResource`): finish `files` type;
  show "used by N queues".
- **Platform → Queue defaults** in `PlatformSettings`/`SettingsRegistry`:
  default prompts (platform `audio_prompts` with `team_id` null), default
  vhold limits, global kill switch `telephony.vhold.enabled`.
- **Portal (client)**: read-only "Callbacks" and "Abandoned calls"
  lists behind new permissions; clients can edit their own prompt text
  only if granted (open question 3).

### 8. Permissions and tenant isolation

New Spatie permissions in `PermissionCatalogSeeder`, following the
existing `resource.action` naming:
`audio_prompt.view_any/view/create/update/delete`,
`hold_music.manage_client`, `queue_callback.view_any/view/update`
(operator outcome), `queue_callback.cancel`, `portal.view_callbacks`,
`portal.manage_prompts`. Queue treatment fields piggy-back on
`call_queue.update`.

Isolation rules (each gets a test):

- Prompt, MOH class and voice pickers list only the client's own rows
  plus platform rows; saving a foreign id fails validation server-side
  (not just in the select).
- Rendered audio keys include `team_id`; the controller that streams
  previews checks ownership.
- Asterisk webhooks carry `team_id` + `callback_id`; the service loads
  `queue_callbacks` with **both**, and the placeholder only ever enters
  a queue whose `team_id` matches the callback's.
- `presented_did` must be one of the client's own `client_dids` (never
  another client's number); trunk from `HandoffDestinations::trunkFor($teamId)`.
- Jobs query with explicit `where('team_id', …)` — the trait scope is
  inactive in jobs.
- Exit-key context is per client (`orbital-vhold-t{team}`), so a
  forged digit can't land in another client's flow.

### 9. Audit

- `queue_callback_events` for every transition and attempt (actor,
  pod, channel, DIALSTATUS, hangup cause).
- Settings changes on queues and prompts through the existing model
  audit approach (activity log if present; otherwise `updated_by` +
  a `queue_settings_history` JSON row) — enough to answer "who turned
  off the callback offer for this client and when".
- `CallLog.metadata` gains `queue: {entered_at, exit: answered|abandoned|timeout|leaveempty|callback, wait_seconds, position}` and `callback_id`;
  the return call's CallLog gets `metadata.callback_for = original linked_id`.
- `queue_log` extended use: rollups count `EXITWITHKEY` (callback
  requested), `EXITWITHTIMEOUT`, `EXITEMPTY`; `queue_metrics_daily`
  gains `calls_callback_requested`, `calls_overflowed`,
  `callbacks_completed`, `callbacks_failed` (completed/failed from
  `queue_callbacks`, not queue_log). Prometheus gauges for pending
  callbacks per queue and oldest pending age, with an alert when the
  oldest exceeds `vhold_expire_minutes` / 2.

## Edge cases and failure modes

- **Caller ID unusable** (anonymous, `restricted`, short internal
  number, international when the trunk can't dial it): `/offer` returns
  "enter number" mode; entering nothing returns the caller to the
  queue at the same position.
- **Caller presses 1 before the offer** (exit context is active from
  entry): `/offer` applies the same gates; if waited <
  `vhold_offer_after_seconds`, return to queue at same position
  silently (no prompt).
- **Two callbacks for one number** (presses 1 on two lines, or calls
  again while a callback is pending): dedupe on (team, number, active);
  the second call hears "you already have a callback scheduled — stay on
  the line to keep waiting" (configurable).
- **Caller calls back in and gets through** before the callback: the
  pending callback is cancelled at `/dialing` time by the dedupe check;
  if the placeholder already reached an operator, the operator hears
  "callback no longer needed" and is released.
- **Pod dies** with placeholders on it: `ReconcileCallbacks` re-injects
  at front on another pod within ~1 min. Callers in the queue on that
  pod are lost anyway (existing behaviour); their CDRs make them
  abandoned-list candidates.
- **Laravel down / slow**: dialplan treats CURL failure as "unavailable"
  and returns the caller to the queue; placeholders already in Asterisk
  still dial out using variables set at injection time, and `/result`
  failures are retried by reconcile from CDR (the outbound CDR carries
  `orbital_callback_id`).
- **No operators for hours** (all paused after closing): callbacks past
  `vhold_schedule_id` closing time are held, not dialled at 2 a.m.;
  if `expires_at` passes they become abandoned-list items for the
  morning, and the caller was told "we'll call you back when we
  reopen" only if the accept happened outside the schedule (prompt
  variant `queue.vhold_accepted_after_hours`).
- **Operator answers placeholder, caller doesn't answer**: operator
  hears a short notice and is released; wrap-up time still applies
  (queue `wrapuptime`) — consider a shorter wrap-up for callback legs
  (open question 4). Retry after `vhold_retry_minutes`, at front.
- **Caller answers but is a voicemail box**: operator decides (leave
  message / mark *Left voicemail*); counts as an attempt.
- **Outbound trunk rejects caller ID** (presenting the client DID):
  carrier may need a Diversion header as for handoffs
  (`orbital-handoff-headers`, `from-trunk.blade.php:162-166`); reuse it.
- **Prompt render fails** (no API key, provider down): queue row keeps
  the previous prompt path or stock sounds; UI shows the error; the
  queue keeps working.
- **Prompt not yet synced to a pod**: content-addressed names + "point
  at it only after render + one sync interval" (store `rendered_at`;
  `QueueTreatmentResolver` only uses prompts older than
  `SYNC_INTERVAL_SECONDS`). Asterisk skips a missing file with a
  warning and continues, so worst case is silence, not a dropped call.
- **Settings changed while callers wait**: app_queue picks up the row on
  the next join; placeholders keep their injected variables.
- **Queue deleted with pending callbacks**: soft delete cancels pending
  callbacks (event `cancelled: queue deleted`) and moves them to the
  abandoned list of the client.
- **Client offboarded / suspended**: kill switch per client; pending
  callbacks cancelled.
- **Long caller names / commas** in AMI variables (see injector note).
- **DST / timezone**: schedule evaluation via plan 06 in the client's
  timezone (`Team` timezone accessor, `app/Models/Team.php:265-272`).

## Migration / rollout

1. **Phase 0 — fixes (independent, ~2–3 days):** ARA `queues` column
   types; `max_wait_seconds` split from ring timeout (backfill =
   current template timeout so behaviour is unchanged until edited);
   CallQueue changes dispatch `RegenerateTelephonyConfig`; exclude
   soft-deleted queues from dialplan; tenant filter on overflow persona
   picker; render voicemail greetings as 8 kHz `.sln` via the shared
   renderer.
2. **Phase 1 — hold experience:** `audio_prompts`, `PromptRenderer`,
   musiconhold generation + sidecar `moh reload`, MOH picker, announcement
   settings (position auto-disabled on multi-pod). All defaults `off` —
   existing queues sound exactly as today until configured.
3. **Phase 2 — virtual hold + abandoned callbacks:** schema, endpoints,
   dialplan contexts, jobs, workspace banner, abandoned list. Behind
   `telephony.vhold.enabled` (platform) and per-queue toggle; pilot on
   one NCI client with a single Asterisk replica first.
4. **Phase 3 (optional):** cluster-wide position/EWT (§6), AI
   `request_callback` tool, portal lists.

Backfill: `hold_music_class_id` from existing `music_on_hold` names;
unknown names → platform default with a warning in the migration
output. `music_on_hold` column dropped one release later.
`queue_metrics_daily` new columns default 0; the rollup can be re-run
for past dates from retained `queue_log` (90 days,
`RollUpQueueMetricsCommand.php:33`).

## Tests

Answering-service suite additions (feature tests unless noted;
`travel()` for time, fake AMI client, fake TTS HTTP):

**Config generation**
1. `QueueSyncer` with announcements off writes exactly today's row plus
   explicit `leavewhenempty` (snapshot).
2. Announcements on: ARA row has `announce_position`, frequencies,
   client prompt paths for `queue_thereare` etc.; with an unrendered
   prompt → null (stock).
3. Two active `AsteriskBackend`s → `announce_position` forced `no`.
4. Vhold on → `context = orbital-vhold-t{team}`, offer prompt last in
   `periodic_announce`, frequency = offer-after.
5. Dialplan snapshot: `queue-{id}` uses `max_wait_seconds`, branches on
   `QUEUESTATUS`, overflow block only when a persona is set; soft-deleted
   queues absent; vhold contexts emitted per client only when a queue
   has vhold on.
6. `musiconhold_generated.conf` snapshot for builtin / files / stream,
   client class names prefixed `t{team}_`.

**Prompts**
7. Render produces 8 kHz mono s16le of expected length (fake 24 kHz /
   16 kHz input → 1/3, 1/2 samples); same text twice → one HTTP call;
   text change → new key, old key deleted after the grace job.
8. Render failure keeps the previous key and records the error; queue
   sync unaffected.
9. Voice picker rejects providers the renderer can't synthesise.

**Virtual hold**
10. `/offer` accepts when enabled, in schedule, waited ≥ threshold,
    callers ahead ≥ min; rejects for each failing gate (table-driven).
11. `/accept` creates one `queue_callbacks` row with `original_entered_at`,
    `presented_did` = the dialled DID, status `waiting_for_operator`;
    second accept for same number+client → rejected ("already
    scheduled").
12. `/dialing` returns trunk + number; if the caller got through since
    (completed CallLog from the same number after entry) → cancels and
    tells the dialplan to release the operator.
13. `/result` ANSWER → `connected`; NOANSWER → `retry_wait` with
    `next_attempt_at`; after `vhold_max_attempts` → `failed` + abandoned
    list; idempotent on duplicate posts.
14. `ReconcileCallbacks`: placeholder missing on its pod → re-injected
    at position 1 on another active backend exactly once (two
    concurrent runs, optimistic lock).
15. Expiry: `travel(+expire)` → `expired`, appears on abandoned list.
16. Schedule gate: callback due at 21:00 with client closed → held until
    08:00 next open (plan 06 evaluator, frozen clock, DST week).
17. Webhook auth: missing/invalid HMAC → 403; callback id of client A
    with team id of client B → 404.

**Abandoned calls**
18. `queue_log` ABANDON with wait 5 s < `abandon_min_wait_seconds` →
    ignored; 40 s with mode `list` → list item; mode `auto` → callback
    due after delay.
19. Same caller calls again and is answered within the delay → auto
    callback cancelled.
20. Cursor: re-running the job doesn't create duplicates (unique on
    `original_linked_id, source`).

**AI interplay**
21. Queue with persona + vhold: timeout → overflow Dial emitted;
    `LEAVEEMPTY` → overflow without offer.
22. `request_callback` from an AI session creates `source=ai` row for
    the session's client only; foreign `sessionKey` → 404.

**Isolation & permissions**
23. Client user can't list or attach another client's prompts, MOH
    classes, callbacks (Filament + API).
24. Overflow persona of another client rejected on save.
25. Portal user without `portal.view_callbacks` gets 403.

**Metrics**
26. Rollup counts `EXITWITHKEY`, `EXITWITHTIMEOUT`, `EXITEMPTY` into the
    new columns; callback completed/failed from `queue_callbacks`.

**Asterisk integration (manual / spike, not CI)**
27. On the local SIP edge: call a DID, wait, hear position + offer,
    press 1, confirm, hang up; second softphone becomes free → operator
    hears whisper, original phone rings with the client DID; verify
    `QUEUEPOSITION` and `Queue(…,position)` behave as assumed on 22.

## Open questions

1. Does NCI offer virtual hold or position announcements today in IS,
   and for which clients? (Decides whether Phase 2 is launch-blocking.)
2. Should callbacks present the **client's DID** or NCI's main number?
   Some carriers reject presenting a number not on the trunk; some
   clients forbid it.
3. May clients edit their own hold prompts in the portal, or is that a
   platform-staff task (with an approval step)?
4. Wrap-up after an unanswered callback attempt — full queue wrap-up or
   none?
5. Multi-pod: accept "no position announcements" when replicas > 1, or
   invest in §6 option 2/3?
6. Asterisk 22 specifics to confirm in a half-day spike: `Queue()`
   argument order (position), `QUEUEPOSITION` on exit-key, realtime
   column list for 22 (`ast-db-manage`), func_curl availability.
7. Abandoned-call auto callbacks: legal review for healthcare clients
   (calling back someone who hung up may disclose they called a
   clinic) — default `list`, not `auto`?
8. Should the AI place callbacks itself (customer-first via LiveKit
   outbound, plan 19) when no operator reaches the callback before
   expiry?

## Size estimate

- Phase 0 fixes: 2–3 days.
- Phase 1 (prompts, renderer, MOH generation + sidecar, announcement
  settings, UI): ~1.5 weeks.
- Phase 2 (callback model, endpoints, dialplan contexts, placeholder +
  reconcile, abandoned detection, workspace banner, abandoned list,
  metrics): ~2.5–3 weeks including the Asterisk spike.
- Phase 3 (cluster-wide EWT/position, AI tool, portal): ~1.5 weeks,
  optional.

Total ~4.5–5 weeks for Phases 0–2.

## Bugs noticed while writing this plan

- **Queue wait = ring timeout.** `Queue(…,{template timeout})`
  (`client-dialplan.blade.php:52`, `extensions.blade.php:135`) uses the
  strategy template's per-member ring timeout (also written to
  `queues.timeout`, `QueueSyncer.php:40`) as the total queue wait. With
  the seeded templates callers leave the queue after 20–45 s, and a
  queue **without** an overflow persona then hits `Hangup()`
  (`client-dialplan.blade.php:58`) — the caller is cut off.
- **Soft-deleted queues stay in the dialplan.** `CallQueue` uses
  `SoftDeletes` (`CallQueue.php:17`) but the generators load queues
  with `withoutGlobalScopes()` (`AsteriskConfigService.php:54-57,
  160-162`), which also drops the soft-delete scope; `QueueSyncer::delete()`
  removes the ARA row, so a deleted queue's DID dials a queue Asterisk
  no longer knows.
- **Queue edits don't regenerate the dialplan.** `TelephonyObserver::onQueueChange()`
  only syncs ARA rows (`app/Observers/TelephonyObserver.php:86-96`);
  creating a queue or changing its overflow persona doesn't rewrite
  `queue-{id}` in the client dialplan until something else triggers
  `RegenerateTelephonyConfig`.
- **Cross-client overflow persona picker.** `ManageClientCallQueues.php:105-107`
  lists every client's personas (`withoutGlobalScope('team')->whereNotNull('team_id')`)
  — a queue can overflow to another client's AI.
- **Realtime `queues` column types** (integers for sound files,
  `periodic_announce` 50 chars) — see Problem.
- **Hold music classes never reach Asterisk** — no musiconhold.conf
  generator; non-sample classes chosen in the UI don't exist in
  Asterisk.
- **Voicemail custom greeting is likely unplayable.** `VoicemailGreetingRenderer`
  stores OpenAI `wav` (24 kHz) and a 16 kHz WAV for ElevenLabs
  (`VoicemailGreetingRenderer.php:143-148,184-199`), while
  `AiFallbackPrompt.php` documents that Asterisk's `.wav` reader rejects
  16/24 kHz audio and uses 8 kHz `.sln` instead.
- **Abandoned queue calls are probably logged as "completed".** The
  queue extension `Answer()`s before `Queue()` (`client-dialplan.blade.php:51`),
  and `CdrIngester` treats any ANSWERED CDR leg as answered
  (`CdrIngester.php:117,236-241`). Needs a CDR fixture to confirm.
- **AMI variables joined with commas** (`AsteriskAmiService.php:304-311`)
  break values containing commas.
- Stale docblock: `CallQueue::asteriskName()` cites
  `resources/views/asterisk/queues.blade.php`, which doesn't exist
  (`CallQueue.php:104`).
