# Plan: Live call board and supervisor listen-in

**Status:** proposed — parked for further consideration
**Branch:** none yet
**Written:** 2026-11-24

## Why

Supervisors and platform admins have no view of calls *in progress*.
Today:

- **Call Logs** (`CallLogResource`) shows only finished calls — rows are
  written by `CallLogController` when a call ends. `CallLog::STATUSES`
  has no live state (the `in_progress` colour exists but nothing writes it).
- **SIP Proxy** (`AsteriskClusterActivity`) shows a channel *count* per
  Asterisk node, for draining — not individual calls.
- `AsteriskAmiService::getActiveChannels()` (`core show channels concise`)
  exists but is only used by `orbital:status` and the metrics collector.
- No ChanSpy / ExtenSpy anywhere in the generated dialplan, and nothing
  joins a LiveKit room as a silent observer. Nobody can listen in.

For an answering service this is a standard supervisor tool: see who is
on what call for which client, how long callers have waited, and coach or
QA an operator (or AI agent) live.

## Goals

1. **Live call board** — one admin page listing every call in progress
   across all clients, refreshed in real time.
2. **Listen** — a supervisor can silently monitor any live call from the
   board, on human-operator calls *and* AI-agent calls.
3. **Whisper / barge** (phase 3) — talk to the operator only, or join the
   call fully.
4. Permission-gated and audited; never visible to client portal users.

Non-goals: client-facing live view; recording changes (covered by the
rtpengine plan); wallboard KPIs beyond what falls out naturally.

## What a "live call" is, by source

| Call shape                         | Where it is visible today                         |
|------------------------------------|---------------------------------------------------|
| Caller waiting in a queue          | Asterisk channel in `Queue()`; AMI `QueueStatus`  |
| Caller ↔ operator (WebRTC/SIP)     | Asterisk bridge with two PJSIP channels           |
| Caller ↔ AI agent                  | Asterisk channel `Dial(...@livekit)` + LiveKit room + `CallSessionState` row |
| AI → operator handoff              | `CallSessionState.handed_off_at` / `operator_owned`, plus the handoff `Dial()` |
| Multi-call workspace calls         | `OperatorCall` rows (status, arrived/answered/held) — uncommitted work on this branch |

The board joins these into one row per *caller* (Asterisk `linkedid`),
not one row per channel. `CallSessionState.linked_id` already carries the
linkedid for AI calls, so AI rows can be matched to their Asterisk leg.

## Design

### Phase 1 — Live call board (read only)

**Data source: AMI events → a `live_calls` projection.**
Polling `core show channels` every few seconds on every node works but
doesn't scale and loses queue-wait timing. Instead:

- A long-running `orbital:ami-listen` command (supervised like Horizon,
  one per Asterisk backend) subscribes to AMI events:
  `Newchannel`, `Newstate`, `BridgeEnter/Leave`, `QueueCallerJoin/Leave`,
  `AgentConnect`, `Hangup`, `VarSet` for `ORBITAL_TEAM_ID` / client DID.
- It maintains a `live_calls` row per linkedid (team, DID, caller ID,
  state: ringing / queued / with_operator / with_ai / on_hold / handoff,
  queue, answered-by extension → user, node, started/answered timestamps).
  Store in Valkey (hash per call, TTL safety net) rather than Postgres —
  it's ephemeral and high-churn.
- On every change it broadcasts a `LiveCallUpdated` event over Reverb on a
  private `supervisor.calls` channel (pattern already used by
  `AsteriskDrainInitiated` / `SystemHealthUpdated`).
- **Reconciliation:** on start and every 60 s, `CoreShowChannels` per node
  rebuilds the set so a dropped AMI socket can't leave ghost rows.
  `AsteriskClusterActivity` already has the per-node AMI fan-out to reuse.
- AI enrichment: join to `CallSessionState` by `linked_id` to show persona,
  active flow step, and fields captured so far. LiveKit `room_started` /
  `participant_joined` webhooks (already received by
  `LivekitWebhookController`) can mark AI-side state.

The dialplan needs to stamp what the board needs on the channel
(`Set(CHANNEL(accountcode)=team id)` or `__ORBITAL_TEAM_ID`) in
`from-trunk.blade.php` / `client-dialplan.blade.php` so AMI events carry
the client without lookups.

**UI: `Monitor → Live Calls`** (Filament page next to Call Logs).
- Table: client, DID / line label, caller, state badge, handled by
  (operator name or AI persona), queue, wait time, talk time (ticking
  client-side), node.
- Filters: client, queue, state, operator/AI.
- Header stats: callers waiting, longest wait, operators on calls, AI calls.
- Livewire listens on the Reverb channel; no polling.
- Row click → side panel with AI transcript-so-far / captured fields
  (from `CallSessionState`), or the operator's `OperatorCall` draft.

### Phase 2 — Listen (silent monitor)

**Human-operator and queued calls → Asterisk ChanSpy.**
- New generated context `orbital-spy` in `extensions.blade.php`:
  `ChanSpy(${SPY_TARGET},qEw)` — `q` quiet, `E` exit when the target
  hangs up, `w`/`B` reserved for phase 3. Target the *operator's* channel
  (`PJSIP/<ext>-`) so the supervisor hears both sides of the bridge.
- "Listen" button → backend `AMI Originate` to the supervisor's own
  extension (their WebRTC softphone or desk phone) into `orbital-spy`
  with `SPY_TARGET` set. Reuses `AsteriskAmiService::originate()` (needs a
  variables parameter). The softphone auto-answers / shows "Monitoring
  call for <client>".
- **Multi-node:** the originate must go to the node that owns the target
  channel (live_calls.node). `commandOn(host, port, ...)` already supports
  addressing a specific node.
- **rtpengine plan interaction:** if operator WebRTC registration moves to
  Kamailio, the bridge still lives in Asterisk, so ChanSpy still works.
  Verify once that plan lands.

**AI-agent calls → LiveKit room observer.**
- Mint a LiveKit token for the supervisor with `canSubscribe=true`,
  `canPublish=false`, `hidden=true` for the call's room
  (`CallSessionState.session_key`), and open a small in-browser listener
  (livekit-client JS) — no SIP leg needed. Hidden participants don't
  trigger the agent's participant-joined logic; confirm the agent worker
  ignores them.
- Fallback if the browser path is unwanted: ChanSpy on the Asterisk leg
  that dials `@livekit` — same UX as human calls, zero LiveKit work.
  **Recommended to start with this fallback**; it gives one mechanism
  for every call.

### Phase 3 — Whisper and barge
- Whisper: same originate with ChanSpy `w` (supervisor audio to the
  spied channel only — the operator).
- Barge: ChanSpy `B`, or for AI calls, publish into the room and let the
  AI's existing handoff path (`handed_off_at` / `operator_owned`) hand
  control to the supervisor.
- UI: mode switch inside the active monitor session (listen → whisper →
  barge) via `ChanSpy` DTMF mode switching (`d` option) so no re-dial.

## Security, permissions, compliance

- New permissions: `calls.view_live`, `calls.listen`, `calls.whisper`,
  `calls.barge`. Default: super_admin all; supervisor view + listen;
  operator none. Registered through the existing role/permission setup
  (`PlatformRoleResource`, `PermissionRegistrar`).
- Private Reverb channel authorises on `calls.view_live`. Platform panel
  only — never the client portal.
- The `orbital-spy` context is reachable only via AMI originate, never
  dialable from an extension (no feature code), so an operator can't
  dial into someone else's call.
- **Audit log** every listen/whisper/barge: who, which call, client,
  start/end. Exposed on the Call Log row afterwards.
- Disclosure: the client's existing recording/monitoring announcement
  ("may be monitored or recorded", `ClientResource` disclosure text) covers
  live monitoring; check with whoever owns compliance for two-party-consent
  states.

## Testing (feeds the answering-service test suite)

- Unit: AMI event → live_calls state machine (fixture event streams for
  queue-join → agent-connect → hold → hangup; AI dial → handoff → hangup;
  abandoned in queue; transfer). This is the bulk of the logic and is
  fully testable without Asterisk.
- Unit: reconciliation drops ghosts and adds missed calls.
- Feature: permission gates on the page, the Reverb channel, and the
  listen action; audit row written; originate called with the right node
  and variables (fake AMI client).
- Dialplan snapshot test: generated `orbital-spy` context and the
  team-id channel variable.
- Manual/integration: real call through the local SIP edge, supervisor
  softphone listens, confirm one-way audio and clean teardown.

## Open questions

1. Is listening on AI calls needed at launch, or human calls only?
2. Supervisor device: always the WebRTC softphone, or desk phones too?
3. Should clients ever see a live view of their own calls (portal)? Assumed no.
4. Retention of the monitoring audit log.
5. Sequence relative to the rtpengine-edge plan.

## Rough size

- Phase 1: AMI listener + projection + page — largest piece, ~1–1.5 weeks.
- Phase 2: ChanSpy context + originate + softphone UX — ~3–4 days
  (+ ~3 days if browser LiveKit observer for AI calls).
- Phase 3: ~2 days.
