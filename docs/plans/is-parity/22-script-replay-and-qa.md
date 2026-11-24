# Plan 22: Script replay and QA — call activity documentation without screen capture

**Status:** proposed · part of [IS parity](README.md) · depends on 01 (`script_events`), 02 (operator runner), [rtpengine-edge](../rtpengine-edge.md) (recording surfaces); uses 03 (message revisions) and 05 (delivery attempts) when present; complements [live-call-monitoring](../live-call-monitoring.md)

## Problem

### What IS does

IS ships "call activity documentation": the supervisor can play back an
agent's screen video in step with the call audio, so QA and disputes
can see what the operator saw and typed. On top of that sits the usual
answering-service QA loop: supervisors sample calls, score them against
a form, coach the agent, and answer client complaints ("your operator
never told me the patient was bleeding") with the recording plus the
screen.

Screen video is expensive (storage, bandwidth, privacy: it captures
whatever else was on screen) and you can't search it. Survey item P
in [07](07-additional-features.md) proposes replaying the **plan 01
event log** against the recording instead. That is what this plan
designs, plus the QA workflow on top.

### What Orbital has today (verified)

**Recordings.** `CallRecording` (`app/Models/CallRecording.php:25`) is
`BelongsToTeam` (`:27`), one row per audio file, with `source`
(`rtpengine_edge` / `livekit_egress` / `asterisk_mixmonitor`,
`:30-40`), `direction` (`caller_in` / `caller_out` /
`participant_track` / `mixed`, `:42-55`), `leg_uuid`,
`participant_identity`, `storage_path`, `duration_ms`, `metadata`
(`:57-69`). `CallLog::recordings()` links them (`app/Models/CallLog.php:123`).
Writers:

- rtpengine spool → `UploadRtpengineRecordings` →
  `UploadCallRecordingJob` (`app/Jobs/UploadCallRecordingJob.php:138`).
  The filename parser only accepts the **mixed** output
  (`app/Console/Commands/UploadRtpengineRecordings.php:172`, rows get
  `DIRECTION_MIXED` at `:180`). The per-leg `caller_in` / `caller_out`
  split the rtpengine plan describes is **not** what is ingested today.
  `duration_ms` is not set by this job.
- LiveKit Track Egress → `LivekitWebhookController` →
  `IngestLivekitEgressJob` (`app/Jobs/IngestLivekitEgressJob.php:31`),
  one row per participant track; egress `started_at` / `ended_at` are
  kept only inside `metadata` (`:137-142`). Egress is started
  fire-and-forget by the worker (`agent-worker/agent.py:218-229`).
- The table has **no recording start time column**
  (`database/migrations/2026_04_28_110100_create_call_recordings_table.php:26-71`),
  so there is no way to place a recording on a wall-clock timeline
  except via `metadata` for egress rows.

**Recording UI.** `CallLogResource` (super-admin only,
`app/Filament/Resources/CallLogResource.php:27-30`) and the portal
`CallHistory` page (`app/Filament/Portal/Pages/CallHistory.php:96`)
both show a "Play" action **only when the legacy
`recording_path` / `recording_rx_path` / `recording_tx_path` columns
are set** (`CallLogResource.php:125-127`), and the player
(`resources/views/filament/components/recording-player.blade.php:40-53`)
reads only those columns. Nothing in `app/` writes those columns any
more — MixMonitor is inert (`resources/views/asterisk/partials/mix-monitor.blade.php:1-20`)
and `CdrIngester` does not set them. **Result: every recording now
landing in `call_recordings` is invisible in both UIs.** The player is
WaveSurfer with leg tabs, transport, and a download link (`:114-120`);
it has no seek-to-time API, no markers, no regions.

**Transcripts.** AI calls only. The worker formats LiveKit chat history
as plain `Speaker: text` lines with no timestamps
(`agent-worker/transcript.py:14-28`) and posts it at session end
(`agent-worker/agent.py:275-293`) to
`CallSessionController::transcript`
(`app/Http/Controllers/Api/CallSessionController.php:118-140`), which
stores it on `CallSessionState.transcript` (`app/Models/CallSessionState.php:37`)
and copies it to `CallLog.transcript` by `linked_id`; `CdrIngester`
also copies it at ingest (`app/Services/Telephony/CdrIngester.php:142`).
The view splits on `": "` (`resources/views/filament/components/call-transcript.blade.php:4-11`).
Human-operator calls have **no transcript**. There is also a worker
endpoint `PATCH /call-logs/{callLog}/transcript`
(`app/Http/Controllers/Api/CallLogController.php:40`, `routes/api.php:112`)
that overwrites a transcript with no history.

**Speech-to-text.** `TranscriptionDriver::transcribe(string $wavPath): string`
(`app/Services/Voicemail/TranscriptionDriver.php:20-23`) with Deepgram,
ElevenLabs Scribe, OpenAI Whisper and local Whisper drivers, chosen per
client through `teams.voicemail_transcription_provider`
(`app/Services/Voicemail/VoicemailTranscriber.php:25-46`). Plain text
only, local file path only, voicemail only.

**What the operator did.** No event log yet (plan 01 adds
`script_events`). Today we have only coarse timestamps on
`OperatorCall` (`arrived_at`, `answered_at`, `held_at`, `ended_at`,
`message_saved_at`, `closed_at`, plus a `draft` JSON —
`app/Models/OperatorCall.php:45-76`) and `Message.created_by_user_id`
(`app/Models/Message.php:41`). Orchestrations have **no versioning**
(`app/Models/Orchestration.php:42-47`), so "what the script said at
the time" cannot be reconstructed after an edit.

**Retention.** `CallRecordingPolicy.retentionDays`
(`app/Services/Telephony/CallRecordingPolicy.php:21`), resolved per
client from `teams.recording_overrides`
(`app/Services/Telephony/CallRecordingService.php:86-106`).
`PruneExpiredCallRecordingsJob` (scheduled daily at 03:15,
`routes/console.php:36`) **only prunes the legacy path columns**
(`app/Jobs/PruneExpiredCallRecordingsJob.php:79-140`); `call_recordings`
rows and their objects are never pruned. There is no legal hold.
Transcripts are never pruned.

**Permissions.** Fixed Spatie catalog in
`database/seeders/PermissionCatalogSeeder.php:19-64`, grouped in the
role editor by prefix (`app/Filament/Resources/PlatformRoleResource.php:66-77`).
Relevant: `call_log.view_any|view|export` (`:34`) used by
`CallLogPolicy` (`app/Policies/CallLogPolicy.php:16-59`, operators see
only their own extension's calls), and `portal.view_calls` /
`portal.view_recordings` (`:46-48`) granted by `ClientProvisioner`
(`app/Services/Clients/ClientProvisioner.php:51-68`). **Neither portal
permission is checked anywhere**: `CallHistory` has no `canAccess` and
its Transcript/Play actions are only gated on data being present
(`CallHistory.php:83-110`). No QA permissions exist. The only audit
table is `failover_audit_logs` (`app/Models/FailoverAuditLog.php`);
nothing records who listened to or downloaded a recording.

**Privacy plumbing that exists.** `Scrubber` already treats
`transcript`, `recording`, `reason`, `dtmf` as sensitive for
observability (`app/Services/Observability/Scrubber.php:49`).

## Goal

1. **Call replay**: for any call (AI, human, or AI → human handoff), one
   page that plays the recording(s) with a synchronised, clickable
   timeline of script events (step shown, field entered, branch taken,
   info page opened, hold, transfer, message saved, delivery attempted)
   and a timestamped, speaker-attributed transcript. Click any event or
   line to seek; playback highlights the current step and line.
2. **QA workflow**: sampling rules per client/script/operator, scorecard
   templates, reviews with time-anchored comments, operator feedback and
   acknowledgement, calibration sessions, and reports (feeds plan 10).
3. **Disputes**: a structured case for "operator never said X" with an
   evidence bundle (clip, transcript excerpt, events, message revisions)
   that is frozen under legal hold.
4. **Retention and access control** that actually cover
   `call_recordings`, transcripts and events, with every playback,
   download and export audited.

Non-goals: video/screen capture; live coaching (live-call-monitoring
plan); automated AI scoring as the system of record (offered as an
assist only, see Design §7).

## Design

### 1. The timeline model

A replay is a merge of several sources onto one **call clock**:
`t = 0` at `CallLog.started_at` (first leg's INVITE as seen by the
edge). Every item gets `offset_ms = occurred_at − started_at`.

Sources, read-time merged by a `CallTimeline` service (no
denormalised copy):

| Source | Table (plan) | Items |
|---|---|---|
| Script engine | `script_events` (01) | `flow_entered`, `step_entered`, `field_set`, `branch_taken`, `action_run/failed`, `handoff`, `ended`, `step_reverted` (02) |
| Operator UI | `script_events`, new types below (02/22) | `screen_shown`, `field_committed`, `info_viewed`, `directory_searched`, `readback_shown`, `hold`, `resume` |
| Call lifecycle | `call_logs`, `operator_calls`, `call_session_states` | ringing/answered/ended, operator answer/hold, AI `handed_off_at` |
| Message | `messages`, `message_revisions` (03) | saved, edited after the call (who, diff) |
| Delivery | dispatch attempts (05) | sent, acked, escalated |
| Monitoring | monitor audit (live-call plan) | supervisor listened/whispered/barged |
| Audio | `call_recordings` | tracks, each with `started_at` |
| Speech | `call_transcript_segments` (new) | caller / agent / operator utterances |

Requirements this puts on plan 01's `script_events` (to be folded into
01 before it ships, not a separate table):

- `occurred_at timestamptz(3)` (millisecond precision), set by the
  server; plus nullable `client_at timestamptz(3)` for UI-originated
  events (browser clock, used only to measure UI latency, never for
  ordering).
- `call_log_id` nullable FK filled when known (CDR ingest back-fills by
  `linked_id`, like the transcript copy today) so the timeline query is
  one index hit.
- `team_id` + `BelongsToTeam`.
- `step_entered` payload carries the **rendered `StepView`** (talking
  points after `{{ }}` substitution, input labels, exit labels) and the
  orchestration content hash. Orchestrations aren't versioned, so the
  event itself must be the record of what was on screen. Size: ~1–3 KB
  per step; a 12-step call ≈ 30 KB, against ~1 MB/min of WAV.

New UI event types emitted by `ScriptRunner` (plan 02). We deliberately
log **committed values, not keystrokes**: a `field_committed` on blur /
submit with the final value and `edit_count`. That keeps volume low and
avoids capturing half-typed sensitive data, and it's enough to answer
"when did the operator type the callback number".

| Type | Payload |
|---|---|
| `screen_shown` | step id, rendered view hash (body in `step_entered`) |
| `field_committed` | slot key, value (masked per slot type), `edit_count`, `ms_on_field` |
| `info_viewed` | info page / call-handling section id |
| `directory_searched` | query text, result count, picked entry id |
| `readback_shown` | message preview hash |
| `hold` / `resume` | `OperatorCall` id |
| `desk_switched` | from/to `OperatorCall` (multi-call desk: which call was on screen) |

`desk_switched` matters on the multi-call desk: a replay must show
"operator was on a different call between 0:42 and 1:10".

### 2. Recordings on the clock

Migration on `call_recordings`:

- `started_at timestamptz(3)` nullable, `ended_at timestamptz(3)` nullable.
- `sync_offset_ms int default 0` — manual correction (see Edge cases).
- `speaker_role` nullable string: `caller` / `operator` / `ai` / `mixed`
  (derived from `direction` + `participant_identity`).
- `legal_hold_until` nullable timestamp (§6).
- `pruned_at` nullable timestamp (keep the row as a tombstone so a
  replay can say "audio deleted on <date> per retention policy").

Fill `started_at`:

- **LiveKit egress**: from `egressInfo.started_at` (ns) in
  `IngestLivekitEgressJob::upsertRecording` (it is already in hand at
  `:140`); also compute `duration_ms` (already done).
- **rtpengine**: recording-daemon writes a metadata/`.meta` file per
  call (or use the `metadata` NG flag carrying `call_uuid`), with the
  start timestamp. Until that's wired, fall back to the WAV header
  duration and `file mtime − duration`. `UploadCallRecordingJob` also
  learns to set `duration_ms`.

`CallRecordingService::tracksFor(CallLog)` returns a `ReplayTracks`
value: the tracks to play (preference: per-leg pair > egress
per-participant > mixed), each with offset on the call clock, speaker
role and a short-lived signed URL. It is the single place the player,
the replay page and the evidence export get audio from — and it fixes
the existing invisible-recordings bug by replacing the legacy-column
logic in `recording-player.blade.php`.

### 3. Timestamped transcripts

New table `call_transcript_segments` (`BelongsToTeam`):

| Column | Notes |
|---|---|
| `team_id`, `call_log_id` | FK; indexed `(call_log_id, start_ms)` |
| `call_recording_id` | nullable — which track it was heard on |
| `source` | `ai_realtime` (worker STT/LLM turns) or `post_call_stt` |
| `speaker_role` | `caller` / `operator` / `ai` / `unknown` |
| `speaker_user_id` | operator, when known |
| `start_ms`, `end_ms` | on the call clock |
| `text`, `confidence` | |
| `words` | jsonb, nullable — word timings when the provider gives them (dispute search, clip trimming) |
| `interrupted` | bool (AI barge-in) |
| `revision` | int — re-transcription keeps old rows, replay shows latest |

**AI calls.** The worker sends structured turns in addition to the
text: extend `format_transcript` (`agent-worker/transcript.py`) to emit
`[{role, text, created_at, interrupted}]` from LiveKit chat items
(`created_at` is on `ChatMessage`), and extend the existing
`POST /call-sessions/{key}/transcript` to accept an optional
`segments` array. Laravel converts to call-clock offsets once the
`CallLog` is linked (same `linked_id` path as today). `CallLog.transcript`
stays populated (plain text) for the existing modal and portal.

Realtime timestamps are turn-level, not word-level, and are when the
text was finalised, not when speech started. For QA that is fine; for
disputes we also run post-call STT on AI calls when the client has a
provider configured, and the replay prefers `post_call_stt` when both
exist.

**Human calls.** New queued job `TranscribeCallRecordingJob` (Horizon,
low-priority queue) after a recording row is created, for clients whose
policy enables call transcription:

- Generalise the voicemail drivers: new interface
  `SegmentedTranscriptionDriver::transcribeSegments(string $localPath, TranscriptionOptions $o): list<TranscriptSegment>`
  implemented by Deepgram (utterances + `diarize`), OpenAI Whisper
  (`verbose_json` segments), ElevenLabs Scribe (word timestamps) and
  local Whisper where the server returns segments. Existing
  `transcribe(): string` stays for voicemail.
- Download the object to a temp file first (drivers take a local path).
- **Speaker attribution** is trivial with per-leg tracks (each track has
  one speaker). With today's **mixed** rtpengine output it needs
  provider diarization, and "speaker 0/1" must be mapped to
  caller/operator (heuristic: the speaker active right after the
  `answered_at` greeting is the operator; reviewer can swap). This is
  the main reason this plan wants the per-leg ingestion the rtpengine
  plan promises.
- New per-client setting `call_transcription_provider` (default
  `none`), separate from voicemail, because cost and consent differ.
  Registered through `SettingsRegistry` and the client recording
  section of `ClientResource`.

### 4. Replay UI

**Admin: `Call Logs → View → Replay`** — a Filament view page
(`CallLogResource` gains a `view` page; today it is index-only,
`CallLogResource.php:139-144`) with a Livewire component
`CallReplay`:

```
┌──────────────────────────────────────────────────────────────────┐
│ Client · From → To · 3:42 · Operator Jane (handoff from AI 1:05) │
├──────────────────────────────────────────────────────────────────┤
│ [waveform: caller ▇▇▇▇▁▁▇▇ ]   ◆ step   ● field   ▲ hold         │
│ [waveform: agent  ▁▁▇▇▇▇▁▁ ]   markers on a shared time axis     │
│ ▶ 1:12 / 3:42   1x 1.5x 2x   ⟲5s   [Add comment @1:12]           │
├────────────────────────────┬─────────────────────────────────────┤
│ Script (what was on screen)│ Transcript                          │
│ ▸ Greeting        0:03     │ 0:03 Operator: Thank you for calling│
│ ▸ Caller name     0:11  ✎  │ 0:09 Caller:   Hi, it's about my…   │
│   = "Pat Smith"   0:19     │ ...                                 │
│ ▶ Emergency? (yes)1:12     │ ▶ 1:10 Caller: …he's bleeding…      │
│   branch → On-call 1:13    │                                     │
├────────────────────────────┴─────────────────────────────────────┤
│ Message: saved 3:20 · edited by Sup. Kim 2h later (diff)          │
│ Deliveries: SMS 3:22 ✓ ack 3:31                                   │
└──────────────────────────────────────────────────────────────────┘
```

- WaveSurfer (already bundled via Vite) with the Regions + Timeline
  plugins; one instance per track, synchronised on a shared clock
  (play/seek drive all; mute per track). Tracks with `started_at`
  offsets are placed at their offset; gaps render empty.
- The script pane is rebuilt from `step_entered` payloads, so it shows
  what the operator actually saw even if the orchestration has since
  changed. Clicking a step expands the rendered view read-only
  (talking points, inputs with values committed).
- Current-time cursor highlights the active step and transcript line;
  clicking either seeks.
- Search box over transcript + field values ("bleeding") → jump list.
- Calls without audio (recording disabled, pruned, failed) still replay
  events and transcript, with a banner explaining why audio is absent.
- Multi-call desk calls show shaded regions where the operator's
  screen was on another call (`desk_switched`).
- AI calls show the AI's tool calls / engine submissions as events;
  LLM system prompts are not shown.

Reuse: the existing Play modal is replaced by a compact version of the
same player (`CallReplayPlayer`) so there is one audio component.

**Portal.** Clients see a reduced replay on `CallHistory`: audio +
transcript + the message and deliveries, **not** the operator script
pane, field edit counts, QA data or internal comments. Gated by
`portal.view_recordings` / `portal.view_calls` (which also fixes their
current non-enforcement). Client portal users can open a dispute from
here (§5).

**Operator.** "My reviews" page in the operator panel: the operator's
own reviewed calls with the replay, scorecard and feedback.

### 5. QA workflow

#### Data model (all `BelongsToTeam` where marked; platform-level
templates have `team_id` null and are read through an explicit scope)

| Table | Purpose |
|---|---|
| `qa_scorecards` (team_id nullable) | Template: name, applies_to (`human` / `ai` / `both`), optional orchestration id, version, `active`. Platform-wide default + per-client overrides. |
| `qa_scorecard_items` | Section, question, type (`yes_no` / `scale_1_5` / `na_allowed` / `text`), weight, `critical` (auto-fail), help text, optional link to a script step key ("Did the operator read the emergency prompt?") |
| `qa_sampling_rules` (team_id nullable) | Scope (client / orchestration / operator / AI persona / all), rate (% or N per operator per week), filters (min duration, direction, has message, urgent, after-hours, handoff happened, new hire < 30 days), active window |
| `qa_reviews` (BelongsToTeam) | call_log_id, scorecard id + **version snapshot**, reviewer user id, subject (`operator_user_id` or `agent_persona_id`), status (`queued` → `in_progress` → `submitted` → `acknowledged` / `disputed_by_operator` → `closed`), score, critical_failed, reason (`sampled` / `manual` / `dispute` / `calibration` / `client_complaint`), due_at |
| `qa_review_answers` | review id, item id, value, comment |
| `qa_comments` (BelongsToTeam) | review id or dispute id, author, body, `anchor_ms` (time on the call clock) and/or `script_event_id`, `visibility` (`internal` / `operator` / `client`) |
| `qa_calibrations` | name, call_log_id, scorecard version, facilitator, status; `qa_calibration_participants` link N independent `qa_reviews` (reason `calibration`) to one session |
| `call_disputes` (BelongsToTeam) | call_log_id, message_id, raised_by (user, portal or staff), claim text, category (`not_said`, `wrong_info`, `not_delivered`, `rude`, `other`), status (`open` → `investigating` → `upheld` / `not_upheld` / `inconclusive`), finding text, evidence_bundle_path, hold id, due_at |
| `recording_legal_holds` (BelongsToTeam) | call_log_id, reason, placed_by, placed_at, released_by/at, source (`dispute` / `manual` / `litigation`) |
| `recording_access_log` (BelongsToTeam) | user id, call_log_id, recording id nullable, action (`play`, `download`, `export_bundle`, `view_transcript`, `view_replay`), panel (`admin` / `portal` / `operator`), ip, at. Append-only. |

The scorecard version is copied into the review (`scorecard_snapshot`
jsonb) so editing a template never rewrites past scores.

#### Services (`app/Services/Qa/`)

- `QaSampler` — scheduled job (`qa:sample`, every 15 min, `onOneServer`)
  that picks newly ended calls matching active rules and creates
  `queued` reviews with a reviewer chosen round-robin from users with
  `qa.review` assigned to that client (or the pool). Deterministic:
  seeded hash of `call_log_id` + rule id decides inclusion, so a re-run
  never double-samples and tests are reproducible. Never assigns a
  reviewer their own call.
- `ScorecardEvaluator` — weights, N/A handling, critical auto-fail,
  item-to-step linking: items linked to a step key are pre-answered
  "N/A" when the call never reached that step (from `script_events`),
  and pre-flagged "check" when the step was skipped by `step_reverted`
  / supervisor jump.
- `ReviewWorkflow` — state machine with transitions guarded by
  permission; every transition writes an audit row.
- `CalibrationService` — opens N blind reviews of the same call (other
  participants' answers hidden until all submit), then computes
  per-item agreement and score variance; facilitator records the
  agreed "master" answers.
- `DisputeService` — opens a dispute, places a legal hold, auto-creates
  a `qa_review` with reason `dispute`, assembles the evidence bundle on
  close.
- `EvidenceBundleBuilder` — a zip (or PDF + audio clip) with: call
  metadata, the relevant audio clip(s) trimmed to the window ±30s,
  transcript excerpt with timestamps, script events, message and its
  revisions, deliveries/acks, the finding, and a SHA-256 manifest. The
  bundle is written to the recording disk under
  `evidence/{team}/{dispute}/`, and the hash is stored on the dispute.

#### Flows

- **Sampled review**: sampler queues → reviewer opens replay with the
  scorecard docked on the right → answers + time-anchored comments
  (click "comment at 1:12") → submit → operator notified (database
  notification) → operator acknowledges, or contests with a comment →
  supervisor closes.
- **AI calls**: same scorecard flow with subject = persona; feedback
  goes to the script/persona owner instead of an operator. Common
  findings link to the orchestration step (feeds plan 01 editor:
  "step X failed QA 7 times this week").
- **Dispute ("operator never said X")**: client opens from portal
  replay or staff opens on the client's behalf → hold placed → staff
  investigator uses replay search for the phrase, looks at the
  transcript **and** the audio (transcripts can be wrong; the audio is
  authoritative and the UI says so) → finding with anchored evidence →
  client sees the outcome and the client-visible comments; bundle
  downloadable by staff with `qa.export_evidence`.
- **Calibration**: supervisor picks a call, invites reviewers, blind
  scoring, then a results view (per-item agreement, outliers) and a
  master answer set; agreement over time is a report.

#### Reports (plan 10 consumes these)

Per operator / per client / per script: average score, critical-fail
rate, reviews completed vs due, acknowledgement latency, dispute
counts and outcomes, top failing items, calibration agreement. Also
timeline-derived metrics that need no human review: time to first
field, time on hold, steps reverted, readback skipped, handle time per
step.

### 6. Retention

Replace the legacy-only prune with a `RetentionService` covering every
call artefact, driven by `CallRecordingPolicy` plus new keys in
`teams.recording_overrides`:

| Artefact | Key | Default |
|---|---|---|
| Audio (`call_recordings`) | `retention_days` (existing) | 90 |
| Transcript segments + `CallLog.transcript` | `transcript_retention_days` | = audio |
| Script events (field values) | `event_retention_days` | 365 (QA and billing need longer than audio) |
| QA reviews / comments | `qa_retention_days` | 730 |
| Access log | platform setting | 2555 (7 yrs) |
| Evidence bundles | lifetime of dispute + `qa_retention_days` | |

- `PruneExpiredCallRecordingsJob` is extended (same schedule) to walk
  `call_recordings` by `started_at`/call `ended_at`, skip rows with an
  active legal hold, delete the object, set `pruned_at` and clear
  `storage_path`. The legacy-column branch stays until those columns
  are dropped.
- Events past retention are **redacted, not deleted**: field values in
  payloads replaced with `"[expired]"`, structure kept, so reports and
  replays of the step path still work.
- Holds: a hold freezes audio, transcript, events and message revisions
  for that call until released; prune logs "skipped: hold".
- Client offboarding: team deletion currently cascades `call_recordings`
  rows (`create_call_recordings_table.php:28`) without deleting objects.
  The retention service gets a `purgeTeam(Team)` path used by
  offboarding that deletes objects first, honouring holds.

### 7. Optional AI assist (later phase)

An `AiQaAssistant` job proposes answers for scorecard items from the
transcript + events (e.g. "greeting matched script", "callback number
read back"), shown as suggestions with the supporting transcript line.
Reviewers accept/override; the review stores both. Disabled per client
by default; uses whatever LLM provider the platform already runs
(Ollama-first setups included). Never auto-submits.

### 8. Permissions & tenant isolation

New catalog entries in `PermissionCatalogSeeder::CATALOG` and a `qa`
group in `PlatformRoleResource::PERMISSION_GROUPS`:

| Permission | Default roles |
|---|---|
| `call_log.replay` | super_admin, supervisor |
| `call_log.play_recording` | super_admin, supervisor |
| `call_log.download_recording` | super_admin |
| `qa.review` | supervisor, qa_reviewer (new seeded role) |
| `qa.manage_scorecards` | super_admin |
| `qa.manage_sampling` | super_admin, supervisor |
| `qa.calibrate` | supervisor |
| `qa.view_own` | operator |
| `qa.handle_disputes` | supervisor |
| `qa.export_evidence` | super_admin |
| `recording.legal_hold` | super_admin |
| `recording.view_access_log` | super_admin |
| `portal.open_dispute` | client_admin (new portal permission, also in `ClientProvisioner`) |

Isolation rules:

- Every new tenant table uses `BelongsToTeam`. Admin pages for platform
  staff work across clients the way `CallLogResource` does today
  (`withoutGlobalScope('team')`), but QA pages additionally restrict a
  non-super-admin reviewer to clients they are assigned (see Open
  questions) via a `QaScope` applied in `getEloquentQuery`.
- Operators see only reviews where they are the subject; replay from
  "My reviews" reuses `CallLogPolicy::view`'s own-extension rule plus
  "is subject of a review on this call" (needed because desk calls
  aren't tied to an extension).
- Portal: queries filter explicitly on `current_team_id` (as
  `CallHistory` does) and render only client-visible artefacts;
  `qa_comments.visibility = client` is the only QA content exposed.
- Signed audio URLs are minted per request by `CallRecordingService`
  (15-min TTL as today), only after the policy check and access-log
  write; the URL is never cached into Livewire state that outlives the
  request. Download is a separate permission; the player's current
  unconditional `download` link goes away.
- `script_events` reads go through the `CallLog` relationship so they
  inherit its authorisation, not by session key.

### 9. Audit

- `recording_access_log` for every play (first play per page view, not
  every seek), download, transcript view, replay view and bundle export,
  in all three panels.
- Review / dispute / hold state transitions recorded as rows in a
  `qa_audit_events` table (actor, from, to, at, note). Submitted
  reviews are immutable; corrections create a new review linked as
  `supersedes_id`.
- Manual `sync_offset_ms` edits and speaker swaps are audited
  (they change what evidence appears to show).
- `CallLogController::updateTranscript` overwrite becomes "insert a new
  segment revision" so a transcript cannot be silently replaced after a
  dispute is opened; the endpoint refuses to modify a call under hold.

## Edge cases

- **Clock skew** between edge VMs (rtpengine), LiveKit egress and the
  Laravel host. All run NTP; `orbital:status` gains a skew check.
  Residual skew is corrected per track with `sync_offset_ms`, editable
  by `qa.review` with audit, and auto-estimated by aligning the first
  AI TTS segment start with energy onset on the AI track.
- **Recording started late** (disclosure playing, egress started after
  the 0.8s sleep in `agent.py`): offsets handle it; the gap shows as
  silence on the track.
- **Recording disabled for the client/extension**: replay is events +
  transcript only (AI realtime transcript still exists). If transcription
  needs audio, no human transcript.
- **Recording pruned but events kept**: replay shows step path with
  redacted values and a "deleted per retention" banner.
- **AI → operator handoff**: two sessions linked by `linked_id`; the
  timeline shows both actors, the handoff event, and the operator's
  runner opening at the AI's step (plan 02). Two recording sources
  (egress for the AI part, rtpengine for the whole call) — prefer the
  rtpengine track as the reference clock.
- **Transfers / patches (plan 08)**: multi-leg calls stitched by
  `linked_id`; each leg's recording placed by `started_at`.
- **Multi-call desk**: one operator, several concurrent calls; events
  carry `operator_call_id`, `desk_switched` shows attention.
- **Caller hung up mid-script**: `ended` event with `caller_hangup`;
  scorecard items for unreached steps auto-N/A.
- **Masked fields** (card numbers, SSNs, `gather_masked`): never stored
  in events or transcripts in clear; `field_committed` stores the mask;
  post-call STT runs a redaction pass on digit sequences for slots
  marked sensitive, and the audio region of a masked step can be
  muted in client-visible replay (PCI pause-and-resume equivalent).
- **Operator reviewing a call involving themselves** (as a supervisor
  who barged): sampler excludes; manual assignment warns.
- **Dispute on a call already past retention**: dispute opens but shows
  "audio deleted <date>"; outcome likely `inconclusive`.
- **Dispute after re-transcription changed text**: bundle references
  the segment revision shown at the time of the finding.
- **Transcription provider fails / times out**: job retries with
  backoff, then marks `transcription_status=failed` on the recording
  metadata; replay shows "transcript unavailable" and a retry button
  for `qa.review` users.
- **Very long calls** (> 1 h): waveform peaks precomputed server-side
  (`audiowaveform` or ffmpeg) and stored beside the object so the
  browser doesn't decode an hour of WAV.
- **Orchestration edited mid-call**: payload snapshots make this a
  non-issue for replay; scorecard step-links use step key, so a renamed
  key breaks the link (validation warning in the scorecard editor).

## Migration / rollout

1. **Fix the foundations (ship independently, ~3 days):**
   `CallRecordingService::tracksFor`, player reads `call_recordings`,
   portal enforces `portal.view_calls` / `portal.view_recordings`,
   prune covers `call_recordings`, access log on play/download.
   These fix existing bugs and don't need plan 01.
2. **Clock & transcript (~1 wk):** `started_at`/offset columns and
   back-fill for egress rows from `metadata.started_at`; worker sends
   segments; `call_transcript_segments`; `TranscribeCallRecordingJob`
   behind `call_transcription_provider` (default `none`).
3. **Replay page (~1.5 wk):** after plan 01 ships `script_events` with
   ms timestamps + rendered StepView payloads and plan 02 emits UI
   events. Before 01/02, the replay works with lifecycle events only
   (answer, hold, message saved) — still useful.
4. **QA core (~1.5 wk):** scorecards, sampling, reviews, comments,
   operator acknowledgement, seeded default "Answering service
   standard" scorecard (greeting, verification, accuracy of message,
   readback, courtesy, protocol for urgent calls — confirm with NCI).
5. **Disputes, holds, evidence bundles (~1 wk).**
6. **Calibration + reports (~1 wk).** AI assist after that, opt-in.

Feature flags: `qa.enabled` (platform setting), per-client
`call_transcription_provider`. Existing `CallLog.transcript` text and
legacy recording columns stay populated/read until a later cleanup
migration drops the columns.

## Tests

Feature/unit tests for the answering-service suite (Pest, fakes for
storage and STT, frozen clock):

**Timeline & replay**
- Fixture: AI call → handoff → operator saves message; assert
  `CallTimeline` order and offsets across `script_events`, session
  handoff, `OperatorCall`, message, two recording sources.
- `step_entered` payload renders the step as it was: edit the
  orchestration after the call → replay still shows the old text.
- Recording with `started_at` 2.3 s after call start appears at offset
  2300; `sync_offset_ms` adjustment shifts it and writes an audit row.
- Multi-call desk: two calls interleaved; replay of call A shows the
  `desk_switched` region and none of call B's fields.
- Masked slot: `field_committed` payload never contains the clear value.
- Call with no recording still renders events + transcript.

**Recordings & retention (regressions for current bugs)**
- A call with only `call_recordings` rows shows the Play/Replay action
  in admin and portal.
- Prune deletes `call_recordings` objects past retention, sets
  `pruned_at`, skips rows under legal hold, respects per-client
  `retention_days` and `0 = forever`.
- Event redaction past `event_retention_days` keeps step path, removes
  values.
- `purgeTeam` deletes objects before rows.

**Transcripts**
- Worker `segments` payload → rows with correct offsets; plain text
  still written to `CallLog.transcript`.
- `TranscribeCallRecordingJob` with fake Deepgram response (diarized)
  maps speakers to caller/operator; per-leg tracks map without
  diarization.
- Provider `none` → no job dispatched; provider failure → status
  failed, retry action works.
- `updateTranscript` on a held call is refused; otherwise creates a new
  revision.

**QA**
- Sampler is deterministic (same calls chosen on re-run), honours rate
  and filters (after-hours only, urgent only), never assigns a reviewer
  their own call, never double-samples.
- Score calculation: weights, N/A, critical auto-fail; unreached step
  items auto-N/A from the event log.
- Scorecard edit after a review leaves the review's snapshot and score
  unchanged.
- Workflow transitions: operator can acknowledge/contest only own
  review; submitted review is immutable; supersede creates a new one.
- Calibration: answers hidden until all participants submit; agreement
  computed correctly on a fixture.

**Disputes**
- Portal user with `portal.open_dispute` opens a dispute → hold placed,
  dispute review queued, staff notified; user without it gets 403.
- Evidence bundle contains clip window, transcript excerpt, events,
  message revisions; manifest hash matches stored hash.
- Releasing the hold returns the call to normal retention.

**Permissions & isolation**
- Portal user of client A cannot load client B's replay, recording URL,
  transcript, dispute or client-visible comments (direct URL and
  Livewire action).
- Portal replay never includes internal comments, scores, or the
  script pane.
- Operator sees own reviews only; cannot open replay for another
  operator's call without `call_log.replay`.
- Reviewer restricted to assigned clients does not see others in the QA
  queue.
- Every play/download/export writes exactly one access-log row; denied
  attempts write none and return 403.
- Signed URL is not issued without `call_log.play_recording`
  (or `portal.view_recordings` in the portal).

## Open questions

1. **Per-leg rtpengine recording**: the code ingests only the mixed
   file. Do we switch to per-leg (`output-single`) as the rtpengine plan
   states? Without it, human-call speaker attribution depends on
   provider diarization.
2. **Who reviews whom?** Is there a reviewer ↔ client (or team-of-
   operators) assignment, or does every QA reviewer see all clients?
   No operator/client assignment model exists today.
3. **Do clients see QA scores** or only dispute outcomes? Some answering
   services sell "QA reports" as a premium.
4. **Call transcription consent and cost**: transcribe all calls, only
   sampled/disputed ones, or per-client opt-in? (Opt-in assumed.)
   Cloud STT for medical clients needs a BAA (Deepgram/OpenAI) or local
   Whisper.
5. **Retention defaults**: NCI's current Amtelco retention for
   recordings and for QA data, and any client contracts that require
   longer (legal hold beyond retention).
6. **Two-party-consent states**: the recording beep/disclosure is
   currently dropped at the dialplan layer
   (`mix-monitor.blade.php:17-20`) pending rtpengine `play-media`.
   Transcribing human calls amplifies that exposure — should this plan
   block on the disclosure being restored?
7. **Standard scorecard**: does NCI have an existing QA form we should
   seed?

## Size estimate

~6–7 weeks total, staged:

| Stage | Effort |
|---|---|
| Foundations (recording UI/prune/portal-permission fixes, access log) | 3 days |
| Clock columns + transcript segments + post-call STT drivers | 1 wk |
| Replay page (multi-track player, timeline merge, script pane, search) | 1.5 wk |
| QA core (scorecards, sampling, reviews, comments, feedback) | 1.5 wk |
| Disputes, holds, evidence bundles, retention service | 1 wk |
| Calibration, reports | 1 wk |
| AI assist (optional) | +0.5 wk |

Depends on plan 01 shipping `script_events` with the additions in
Design §1 and plan 02 emitting the UI event types; stage 1–2 do not.
