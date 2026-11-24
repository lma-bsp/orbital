# Plan 15: IVR and auto attendant

**Status:** proposed · part of [IS parity](README.md) · survey item H in
[07](07-additional-features.md) · depends on [01](01-script-runtime.md)
(engine semantics, `script_events`), [06](06-schedules-and-routing.md)
(schedules, `GotoIfTime` generation, manual override) · uses 11
(directory status), 14 (queue announcements) and 08 (warm transfer)
when they land

## Problem

### What IS does

Amtelco IS (with its Intelligent Call Router / auto-attendant options)
lets an answering service put a menu in front of a client's line:

- A per-account **greeting** ("Thank you for calling Riverside
  Dental…"), recorded or TTS, chosen by time of day ("our office is
  closed…").
- **DTMF menus**: "press 1 for emergencies, 2 to leave a message for
  billing, 0 for the operator". Each key leads to a queue, an operator
  with a specific script, voicemail, an outside number, or another menu.
- **Digit collection**: "enter your account number", "enter the
  extension of the person you're calling".
- **Directory by name**: spell the last name on the keypad, or (newer
  systems) say it, then transfer.
- **Time-of-day / holiday menus**: a different menu, or none, after
  hours.
- **Fallback to an operator**: no input, invalid input or "0" always
  ends at a person (or at the answering service's agent queue) with the
  menu choice shown on screen so the operator knows why the call came.

In IS this is authored in the same Supervisor tooling as scripts. For
Orbital that means: **menus are orchestration steps in the same flow
editor**, not raw dialplan.

### What Orbital has today (verified)

**Dialplan has no IVR primitives.**

- `resources/views/asterisk/from-trunk.blade.php:27-88` emits one
  `exten => <DID>,1,…` per active `RoutingRule` and immediately routes
  by `destination_type`: `Goto` to an extension or `queue-{id}`
  (`:37-42`), `Dial(PJSIP/r{rule}@livekit…)` for an orchestration
  (`:43-53`), or `VoiceMail` with an optional pre-rendered greeting
  (`:54-78`). There is no `Background`, `WaitExten`, `Read`,
  `GotoIfTime`, `AGI` or `Stasis` anywhere in `resources/views/asterisk/`
  or `docker/asterisk/` (grep).
- `resources/views/asterisk/client-dialplan.blade.php:28-58` (per-client
  `[tenant_{id}]` context) holds only extensions (AI → `Dial …@livekit`,
  others → `Dial` + `VoiceMail` on busy/unavailable) and
  `queue-{id}` entries with optional AI overflow. The header comment in
  `from-trunk.blade.php:10-11` says "the actual extensions / queues /
  IVR live inside the client context" — the IVR part doesn't exist.
- `resources/views/asterisk/extensions.blade.php` is `[globals]` +
  `[internal]` only (echo test, speaking clock, DID simulation at
  `:47-48`, extensions, queues).
- `RoutingRule.time_condition` is stored and cast
  (`app/Models/RoutingRule.php:24,36`) but never read — plan 06's
  problem statement. Because `from-trunk` emits one `exten` per rule,
  two rules for the same DID (e.g. day/night) would produce a duplicate
  `exten => <DID>,1` that Asterisk rejects; time selection therefore
  has to happen *inside* one DID entry, which is what this plan does.

**Routing is DID → one destination.** `ClientDidRouting::sync()`
(`app/Services/Telephony/ClientDidRouting.php:46-91`) keeps exactly one
`RoutingRule` per `ClientDid` (priority 10, `source = did`), destination
`extension | queue | voicemail | orchestration` (`:22`). For
`orchestration` the rule points at the orchestration's **Inbound
Phone** channel flow (`inboundPhoneChannel()`, `:28-36`). The admin UI
is `ManageClientDids` (`app/Filament/Resources/ClientResource/Pages/ManageClientDids.php:63-89`).

**An orchestration call always goes straight to the AI.**
`AiCallTarget::forRule()` (`app/Services/Telephony/AiCallTarget.php:33-36`)
builds `r{rule_id}`; `fromRule()` (`:62-81`) resolves the channel's
persona. The worker parses the room name with
`ROOM_NAME_RE = ^agent-_?(r?\d+)_` (`agent-worker/agent.py:38`), fetches
`/agent-personas/by-extension/{target}`
(`AgentPersonaController::byExtension`, `:35-63`), which compiles the
graph from `AgentFlowCompiler::resolveEntryFlow()`
(`app/Services/Flows/AgentFlowCompiler.php:150-166`:
rule → extension → persona default). The AI then speaks the persona
greeting (`agent.py:143-145,231`). So "press 1 for emergencies" today
can only be an LLM reading a menu aloud and guessing from speech.

**The worker ignores DTMF.** No `dtmf` handling anywhere in
`agent-worker/` (grep). Asterisk endpoints already negotiate RFC 4733
(`Realtime/TrunkSyncer.php:58`, `EndpointSyncer.php:104`,
`InfrastructureEndpointSyncer.php:42`), so digits do reach LiveKit;
nothing consumes them.

**Step catalogue has no telephony-menu primitives.**
`database/seeders/IntakeGoalLibrarySeeder.php` defines ~60 primitives;
the relevant ones:

| Key | Line | Relevance |
|---|---|---|
| `gather_choice` | 301 | closest analogue to a menu; exits are dynamic, one per option (`defaultExits` `:80`, editor `dynamicExitsFor` in `resources/js/flow-editor/App.svelte:255-265`) |
| `branch_on_time_of_day` | 1122 | `time_window_list` param; no executor; editor renders it as a plain text box (`ParamField.svelte:10-11,324-334`) |
| `match_did` | 1138 | DID branch, no executor |
| `pause`, `play_hold_music` | 869, 920 | prompt-only |
| `transfer_call` | 770 | `extension / did / queue / external`; only `external` is executable (handoff) |
| `enter_call_queue`, `assign_to_persona`, `transfer_to_extension`, `transfer_to_voicemail` | 1180, 1212, 1228, 955 | terminal routing steps, prompt-only today |
| `trigger_inbound_phone` | 1258 | entry anchor; its description ("DID matching is configured on the Call Queue", `:1263`) is stale — DIDs route via `ClientDid` now |

There is no `play_prompt`, `dtmf_menu`, `collect_digits` or
`dial_by_name`. The editor palette groups by category with a fixed
order `['trigger','match','intake','action','control','queue','assign']`
(`resources/js/flow-editor/components/Palette.svelte:22`) and appends
unknown categories, so a new `telephony` category shows up without
code — but would sort last.

**Flow exits come from the flow's last step.** `flowExits()`
(`App.svelte:218-243`) derives a flow node's ports from its terminal
step's primitive (or dynamic exits for `gather_choice`). A menu step
must therefore be the last step of its flow, with one port per option
— the same shape `gather_choice` already uses.

**Prompt audio exists in three places, two of them probably broken.**

- `AiFallbackPrompt` renders 8 kHz signed-linear `.sln` because
  "Asterisk's `.wav` reader rejects the 16/24 kHz audio TTS services
  return" (`app/Services/Telephony/AiFallbackPrompt.php:24-25`).
- `VoicemailGreetingRenderer` writes `response_format: wav` from OpenAI
  (24 kHz) and wraps ElevenLabs `pcm_16000` as a 16 kHz `.wav`
  (`VoicemailGreetingRenderer.php:147,184,200`); `DisclosureRenderer`
  also requests OpenAI `wav` (`DisclosureRenderer.php:172`). By
  `AiFallbackPrompt`'s own finding, Asterisk's `wav` format (8 kHz)
  will refuse these files — see **Bugs noticed** below. The IVR prompt
  renderer must follow the `.sln` path.

**AI can't hand a call back to Asterisk's routing.** The livekit
endpoint's context is `from-livekit`
(`docker/asterisk/pjsip_livekit.conf:15`). That context has generated
`handoff-{team}-{digits}` entries for external operators
(`from-trunk.blade.php:103-112`, `HandoffDestinations::dialplanExtension()`
`:87`), `ai-unavailable` (`:115`), and a catch-all
`_X. → Goto(default,${EXTEN},1)` (`:117-118`). `[default]` only hangs
up (`docker/asterisk/extensions.conf:25-27`). So the worker's
`transfer_call` tool, which REFERs to `sip:{extension}@asterisk`
(`agent-worker/tools/transfer.py:44-55`), lands the caller in
`[default]` and hangs up. An IVR that routes "press 2" to an AI and
lets the AI send the caller on to a queue needs this fixed first.

**ARI is compiled in but unused.** `res_ari`, `res_ari_channels`,
`res_ari_bridges`, `res_stasis` are enabled in
`docker/asterisk/Dockerfile:68-72`; `http.conf` serves 8088;
`config/telephony.php:18-22` has ARI credentials. There is no
`ari.conf` in `docker/asterisk/`, no ARI client class in `app/`, and no
Stasis app. `AsteriskAmiService` is request/response only (`reload`,
`originate` `:256`, `originateToApplication` `:283`, no event loop).

**Editing and permissions.** The flow editor route is super-admin only
(`routes/web.php:110-111`). `RoutingRulePolicy` uses
`ClientResourcePolicy` with prefix `routing_rule`
(`app/Policies/RoutingRulePolicy.php`), permissions seeded in
`PermissionCatalogSeeder.php:30`. Config regeneration on routing
changes is per-client: `TelephonyObserver::onRoutingRuleChange()` →
`RegenerateTelephonyConfig` → `writeDialplanForTenant()` +
`reloadDialplan()` (`app/Observers/TelephonyObserver.php:98-106`,
`AsteriskConfigService.php:193,368`), on every backend
(`commandOnAllBackends`, `:380`).

**Data the IVR needs that already exists:** `Team.timezone`,
`Team.greeting` (operator answer phrase, uncommitted migration
`2026_11_24_100000_…`), `OperatingHour` (`Team::operatingHours()`),
`DirectoryEntry` with role-tagged fields — roles are
`name, email, phone, organization, none`
(`DirectoryFieldDefinition::ROLES`, `:37`), no extension/routable role.

## Goal

A client's phone entry point can be an **auto attendant built in the
orchestration editor**: greetings, DTMF menus, digit collection,
time-of-day branches, dial-by-name, and terminal routes (queue,
operator, AI persona, extension, voicemail, outside number). The
deterministic menu part runs **in Asterisk** (cheap, no LLM minutes, no
dependence on Laravel being up per call); anything conversational is
handed to the **AI persona at a specific flow**, and every dead end
(no input, invalid, error, closed queue) ends at a **person**, with the
IVR path shown to that person.

Non-goals: classic ASR grammars (the AI persona is our speech layer);
replacing the AI for natural-language reception; outbound IVR (plan 19).

## Design

### 1. Where the IVR lives in the graph

No new destination type and no separate "IVR builder". An
orchestration's Inbound Phone trigger already leads to an entry flow
(`IntakeFlow::channelEntryFlow()`). We split the reachable graph into
two **runtime regions**:

- **Phone-system region** — flows whose steps are all
  *IVR-executable* (table below). Starting at the entry flow, the
  compiler walks transitions while the next flow is IVR-executable.
- **AI / operator region** — the first flow containing any other step
  (a `gather_*`, `identify_reason`, `save_message`, …), or an explicit
  terminal (`assign_to_persona`, `enter_call_queue`, …).

If the entry flow itself isn't IVR-executable, nothing changes: the
DID dials the AI exactly as today (`from-trunk.blade.php:43-53`). An
orchestration "has an IVR" simply by starting with telephony steps.
This keeps one editor, one graph and one set of transitions, and the
plan 01 engine remains the semantic reference for every step (see §5).

The editor shows the region on each node (badge "Phone system" /
"AI" / "Operator"), so authors see where the LLM starts.

### 2. New and extended primitives

New category **`telephony`** in `IntakeGoalLibrarySeeder`, placed after
`match` in `Palette.svelte`'s `CATEGORY_ORDER`.

| Key | Params (`data_fields`) | Exits | Notes |
|---|---|---|---|
| `play_prompt` | `prompt_id` (`prompt_picker`), `interruptible` (bool; Background vs Playback), `skip_if_seen` (bool, for loops) | `continue` | Greeting / announcement. Inline text in the picker creates a `ClientPrompt`. |
| `dtmf_menu` | `prompt_id`, `options` (`dtmf_option_list`: `{digit, label}`; digits `0-9*#`, or multi-digit), `timeout_seconds` (5), `max_attempts` (3), `invalid_prompt_id`, `timeout_prompt_id`, `operator_digit` ('0', blank = off), `repeat_digit` ('*' default) | one per option label + `no_input` | Must be the flow's last step. `no_input` = attempts exhausted on timeout *or* invalid. Unconnected exits compile to the orchestration's operator fallback (§4). |
| `collect_digits` | `slot`, `prompt_id`, `min_digits`, `max_digits`, `terminator` ('#'), `timeout_seconds`, `max_attempts`, `confirm` (read back with `SayDigits`) | `continue`, `failure` | Value travels with the call (§3.4). Validation beyond length needs the engine (phase 3, AGI). |
| `dial_by_extension` | `prompt_id`, `digits` (length), `allowed_extension_ids` (`extension_picker`, multi) | `connected`, `not_found`, `no_answer` | "If you know your party's extension…". Only the client's own extensions. |
| `dial_by_name` | `mode` (`speech` \| `dtmf_spell`), `directory_scope` (client directory / bound shared directories), `confirm` (bool), `persona_id` for speech | `connected`, `not_found`, `no_answer` | Speech mode runs in the AI region (§6.3). `dtmf_spell` is phase 3. |
| `hangup` | `prompt_id` (optional goodbye) | — (terminal) | Only valid after a played prompt; validator warns. |

Extended existing primitives (become IVR-executable, params unchanged
unless stated):

- `branch_on_time_of_day` — takes plan 06's `schedule_id` +
  `when: open|closed` exits (plus named intervals). Until 06 ships, an
  adapter reads the client's `OperatingHour` rows. Editor gets a real
  `schedule_picker` instead of the free-text fallback.
- `match_did` — exits per DID; compiles to `GotoIf` on
  `${ORBITAL_DID}` (set in `from-trunk.blade.php:34`).
- Terminals: `enter_call_queue` (→ `Goto(tenant_{id},queue-{q},1)`),
  `transfer_to_extension` (cold only in the IVR region),
  `transfer_to_voicemail` (→ `VoiceMail(mailbox@default)` with optional
  prompt), `transfer_call` with `external` (→ the existing
  `handoff-{team}-{digits}` entry via `Goto(from-livekit,…)`) or `did`,
  `assign_to_persona` (→ AI at that persona, §6.1).
- `pause` → `Wait()`, `play_hold_music` → `MusicOnHold(,secs)`.

Editor changes for these: `dynamicExitsFor('dtmf_menu')` returns
`[...labels, 'no_input']` (same pattern as `gather_choice`,
`App.svelte:255-265`); new `ParamField` types `prompt_picker`,
`dtmf_option_list`, `schedule_picker`, `directory_scope`.

### 3. Data model (all `BelongsToTeam`)

#### 3.1 `client_prompts`

| Column | |
|---|---|
| `team_id` | `BelongsToTeam`; nullable = platform prompt (stock "invalid option", "please hold") |
| `name` | "Main greeting", "After-hours menu" |
| `source` | `tts` \| `upload` |
| `text` | TTS source text; also the transcript for uploads (shown to operators and used by the AI path) |
| `language` | BCP-47, default `en-US` |
| `voice_provider`, `voice_id` | same choices as voicemail greetings (OpenAI / ElevenLabs) |
| `upload_path` | original upload (private disk) |
| `audio_hash` | sha256 of (text, voice, provider) or of the upload |
| `render_status` | `pending` \| `rendered` \| `failed`, `render_error`, `rendered_at` |
| `client_editable` | bool — client portal may change the text (open question 3) |

Rendered audio is written to the shared `asterisk-prompts` disk at
`clients/{team_id}/{prompt_id}-{audio_hash}.sln` (8 kHz s16le mono,
the `AiFallbackPrompt` format). The hash in the filename means a
re-render never overwrites a file a live call is playing, and a
published dialplan always points at audio that exists.

`PromptRenderer` (new, `app/Services/Telephony/PromptRenderer.php`)
generalises `AiFallbackPrompt::synthesize()`/`downsample()`: TTS as raw
PCM → 8 kHz `.sln`; uploads (wav/mp3/m4a) converted with `sox`/`ffmpeg`
in `RenderClientPromptJob` (check the app image has one; the Asterisk
image has `sox`, `docker/asterisk/Dockerfile` runtime packages).
Garbage collection: delete `.sln` files not referenced by any of the
last N published revisions.

#### 3.2 `ivr_revisions`

The live dialplan is generated from a **published snapshot**, never
from the graph being edited.

| Column | |
|---|---|
| `team_id`, `orchestration_id` | |
| `number` | per orchestration, increasing |
| `ir` (JSONB) | compiled intermediate representation (§4.1) |
| `dialplan_hash` | hash of the rendered dialplan fragment |
| `prompt_ids` (JSONB) | prompts + hashes the revision plays |
| `published_by`, `published_at`, `note` | audit |
| `status` | `draft` \| `published` \| `superseded` |

`orchestrations.published_ivr_revision_id` (nullable FK) points at the
live one. Rollback = re-point at an older revision and regenerate.

#### 3.3 Extended

- `directory_entries`: add field role **`routable_number`** to
  `DirectoryFieldDefinition::ROLES` and an entry-level `routable`
  boolean, so dial-by-name only offers people the client agreed can be
  reached by transfer (default off).
- `IntakeGoal` rows: add `ivr_executable` (bool) to the seeded
  metadata so the compiler, the editor badge and validation read one
  source of truth.
- `call_logs`: `ivr_path` (JSONB, short) filled from the CDR (§3.4)
  for Call History and reporting (plan 10: "% of callers who chose 1").

#### 3.4 Call-time state

Asterisk channel variables, inherited with `__` like `ORBITAL_DID`:

- `__ORBITAL_IVR_TRAIL` — compact path, e.g.
  `f12:open>f15:1>q7` (flow ids, choices, final target), capped at
  ~500 chars.
- `__ORBITAL_IVR_<SLOT>` — collected digits.
- `ORBITAL_IVR_HOPS` — loop guard counter.

They leave Asterisk three ways:

1. **To the AI** — the predial handler `[orbital-ai-headers]`
   (`from-trunk.blade.php:150-159`) adds `X-Orbital-Ivr` (trail) and
   `X-Orbital-Ivr-Fields` (urlencoded `slot=value` pairs). The worker
   already reads SIP headers (`_header_attr`, `agent.py:270`) and sends
   them in `/call-sessions/{key}/context`; `CallSessionController::context()`
   (`:87`) writes them into `CallSessionState.fields` and (with plan 01)
   `script_events`.
2. **To an operator** — `[orbital-ivr-report]`: when the call leaves
   the IVR region, `System(/usr/local/bin/orbital-ivr-report.sh … &)`
   posts `{linkedid, team, did, trail, fields}` to a new
   `POST /api/ivr/trail` (same shape and auth as
   `notify-voicemail.sh` → `/api/voicemail/received`). Laravel writes
   `script_events` and pushes to the operator Workspace (the incoming
   call card shows "Pressed 1 — Emergencies, after hours"). Background
   `&` so the caller never waits on HTTP.
3. **To Call History** — `Set(CDR(orbital_ivr_path)=…)` in the same
   Gosub, picked up by `CdrIngester`.

### 4. Telephony integration — how the engine drives Asterisk

#### Options considered

| Option | For | Against |
|---|---|---|
| **A. Compile to dialplan** (Blade, like everything else) | No per-call dependency on Laravel; fast; matches plan 06's choice of `GotoIfTime`; reload per client already exists | Second implementation of step semantics; can't do live lookups or arbitrary expressions |
| **B. ARI Stasis app** run by a long-lived Laravel worker | One engine (plan 01) for everything; full expressions | New always-on daemon per Asterisk backend; call control leaves the dialplan, so a crashed worker strands live callers; ARI not configured today (no `ari.conf`, no client) |
| **C. AI persona does the menu** | Exists today | LLM minutes and latency on every call; non-deterministic; useless when the AI is down — which is exactly when a menu should still reach an operator |
| **D. FastAGI to Laravel per dynamic step** | Dialplan keeps control; on AGI failure the dialplan continues to a fallback label | Long-lived PHP socket server; per-step round trip |

**Decision: A, with D for dynamic steps later (phase 3), C as the
speech layer.** The deterministic menu subset compiles to dialplan;
anything that needs data or language goes to the AI at a named flow
(§6) or, later, to a FastAGI call into the plan 01 engine. ARI stays
out of the IVR path; it remains the right tool for plan 08's
bridges/conferences.

#### 4.1 `IvrCompiler` (`app/Services/Ivr/IvrCompiler.php`)

`compile(Orchestration): IvrProgram` walks from the Inbound Phone
channel's entry flow and produces an IR, e.g.

```json
{"entry": "f12", "fallback": {"type": "queue", "id": 7},
 "nodes": {
  "f12": {"steps": [{"op": "schedule", "schedule": 3, "open": "f13", "closed": "f20"}]},
  "f13": {"steps": [{"op": "prompt", "prompt": 41, "interruptible": true},
                    {"op": "menu", "prompt": 42, "timeout": 5, "attempts": 3,
                     "options": {"1": "f30", "2": "q7", "0": "@fallback"},
                     "no_input": "@fallback"}]},
  "f30": {"steps": [{"op": "ai", "rule": 88, "flow": 30}]}}}
```

Rules enforced at compile (and shown as editor validation):

- Every referenced object (`ClientPrompt`, `CallQueue`, `Extension`,
  `AgentPersona`, schedule, `DirectoryEntry`, handoff number) has
  `team_id` = orchestration's team — loaded with `withoutGlobalScopes()`
  but asserted, never trusted.
- Every prompt is `rendered`; otherwise **publish is blocked** and the
  previous revision stays live.
- `dtmf_menu` is the last step of its flow; option digits unique; no
  prefix ambiguity (`1` and `12` both present) unless the menu sets a
  terminator.
- Every path ends in a terminal, a hand-off to the AI region, or the
  operator fallback. Cycles are allowed (submenu "press 9 to go back")
  but bounded by `ORBITAL_IVR_HOPS`.
- An option whose label matches `/emerg|urgent/i` may not end in
  `hangup` or voicemail-only (warning that requires an explicit
  acknowledgement to publish).

#### 4.2 `IvrDialplanRenderer` + `asterisk/client-ivr.blade.php`

Rendered from the **published** IR and `@include`d at the end of
`client-dialplan.blade.php`, so it is written and reloaded by the
existing per-client path (`writeDialplanForTenant` → `dialplan reload`,
both backends). One context per flow, names from ids only:
`[ivr-t{team}-f{flow}]`. No author text enters dialplan except via
`NoOp` labels stripped to `[A-Za-z0-9 _-]` (labels with `)` or a
newline would otherwise break the generated file).

Sketch for the menu above:

```
[ivr-t42-f13]
exten => s,1,Set(ORBITAL_IVR_HOPS=$[${ORBITAL_IVR_HOPS}+1])
 same => n,GotoIf($[${ORBITAL_IVR_HOPS} > 20]?ivr-t42-fallback,s,1)
 same => n,Set(__ORBITAL_IVR_TRAIL=${ORBITAL_IVR_TRAIL}>f13)
 same => n,Set(ATTEMPTS=0)
 same => n,Background(/var/spool/asterisk/prompts/clients/42/41-<hash>)
 same => n(menu),Set(ATTEMPTS=$[${ATTEMPTS}+1])
 same => n,Background(/var/spool/asterisk/prompts/clients/42/42-<hash>)
 same => n,WaitExten(5)
exten => 1,1,Set(__ORBITAL_IVR_TRAIL=${ORBITAL_IVR_TRAIL}:1)
 same => n,Goto(ivr-t42-f30,s,1)
exten => 2,1,Set(__ORBITAL_IVR_TRAIL=${ORBITAL_IVR_TRAIL}:2)
 same => n,Gosub(orbital-ivr-report,s,1)
 same => n,Goto(tenant_42,queue-7,1)
exten => 0,1,Goto(ivr-t42-fallback,s,1)
exten => *,1,Goto(s,menu)
exten => i,1,Playback(<invalid prompt>)
 same => n,GotoIf($[${ATTEMPTS} >= 3]?ivr-t42-fallback,s,1:s,menu)
exten => t,1,Playback(<timeout prompt>)
 same => n,GotoIf($[${ATTEMPTS} >= 3]?ivr-t42-fallback,s,1:s,menu)
exten => h,1,Gosub(orbital-ivr-report,s,1(abandoned))
```

- **Greetings:** `Answer()` once at IVR entry, then `Playback`/
  `Background`.
- **Time of day:** plan 06's generated `GotoIfTime()` lines per
  schedule interval, with holiday exceptions rolled forward nightly
  (plan 06). The **manual override** ("closed — weather") is read from
  AstDB — `GotoIf($["${DB(orbital/override/42)}" = "closed"]?…)` — and
  set by Laravel via AMI `DBPut` on every backend, so flipping it needs
  no dialplan reload. (`res_sorcery_astdb` is enabled explicitly,
  `Dockerfile:81`; `func_db`, `app_read`, `func_dialplan` are
  menuselect defaults and not listed — confirm with
  `module show like` in the built image before relying on them.)
- **collect_digits:** `Read(ORBITAL_IVR_<SLOT>,<prompt>,<max>,,<attempts>,<timeout>)`
  then a length check.
- **dial_by_extension:** `Read` → `GotoIf` against a generated list of
  the allowed extension numbers → `Goto(tenant_42,<n>,1)` (existing
  extension entries, which already fall to voicemail).
- **Terminals** call `Gosub(orbital-ivr-report…)` first, then `Goto`
  the existing contexts. Nothing duplicates queue/extension logic.

`from-trunk.blade.php`'s `orchestration` case becomes: if the
orchestration has a published IVR revision whose entry is
IVR-executable → `Goto(ivr-t{team}-f{entry},s,1)`; else today's
`Dial(PJSIP/r{rule}@livekit…)`.

#### 4.3 Operator fallback

Per orchestration, `ivr_fallback` (on the Inbound Phone trigger's
params, editable in `FlowSettingsModal`): `queue` (default: the
client's first call queue), `extension`, `external handoff`, or `ai`.
Generated as `[ivr-t{team}-fallback]`. If the fallback itself is a
queue with AI overflow, the existing queue entry
(`client-dialplan.blade.php:49-58`) already handles "no operator
answers". The fallback never resolves to `hangup`.

Platform-wide last resort: if an IVR context is missing (file not yet
written, client half-migrated), the `from-trunk` entry `Goto`s with
`GotoIf($[${DIALPLAN_EXISTS(ivr-t42-f12,s,1)}]…)` and otherwise uses
today's AI dial.

#### 4.4 Simulator

`IvrSimulator::run(IvrProgram, inputs: digits/timeouts, at: Carbon,
callerId, did): IvrTrace` interprets the IR with the same rules the
dialplan uses. Used by the editor test panel ("press keys" with a
clock override), by tests, and by a **conformance test** that compares
the trace with plan 01's `ScriptEngine` dry-run on the same graph, so
the two implementations of each step can't drift.

### 5. Relationship to plan 01

Plan 01 is the semantic owner: each new primitive gets a
`StepHandler` (`play_prompt` → `say`, `dtmf_menu` → interactive step
with named exits, `collect_digits` → typed gather) so the **same graph
runs for an operator, the AI or chat**. On a web chat the menu renders
as buttons; for an operator who took the call from `no_input` the
runner opens at the step after the menu. The IVR compiler is an
ahead-of-time executor for the telephony subset; conformance tests
(§4.4) keep them aligned. Phase 1 can ship before 01 because the
simulator stands alone; the conformance tests arrive with 01.

### 6. AI path

#### 6.1 IVR → AI at a specific flow

The `ai` op dials `PJSIP/r{rule}f{flow}@livekit`.

- `AiCallTarget`: accept `r(\d+)f(\d+)`; the flow must belong to the
  rule's orchestration and team, else ignore it (persona default).
- `ROOM_NAME_RE` in `agent.py:38` becomes
  `^agent-_?(r?\d+(?:f\d+)?)_` (LiveKit names the room after the
  callee; the dispatch rule must allow the letter — verify).
- `AgentFlowCompiler::resolveEntryFlow()` gets a first branch: an
  explicit start flow wins over the rule's channel entry, source
  `ivr`. The compiled prompt starts there, with IVR fields
  pre-filled (from `X-Orbital-Ivr-Fields`) and a line like "The caller
  chose *Emergencies* from the menu; don't ask why they're calling
  again."
- **Greeting:** when started by the IVR, the worker skips the persona's
  generic greeting (`agent.py:143-145`) in favour of the flow's first
  talking point — the caller already heard the client greeting.

#### 6.2 Menus inside an AI call (speech + keypad)

When a `dtmf_menu` sits in the AI region (an AI-first orchestration
that offers "press or say 1 for…"), the worker handles it:

- Subscribe to the room's `sip_dtmf_received` event and forward digits
  to the engine (plan 01 `choose_exit`); spoken choices map to option
  labels through the same tool. Until 01, the compiler renders the
  menu into the prompt with the options and the worker exposes a
  `menu_choice(label)` tool plus a DTMF → label shortcut that injects
  the choice.
- Prompts are spoken by the persona voice from `ClientPrompt.text`
  (TTS live) — uploaded audio is only used in the Asterisk region.

#### 6.3 Directory by name (speech)

IS-style "say the name of the person" uses the AI persona, as 07
proposed:

- `dial_by_name` in an Asterisk-run region compiles to the `ai` op,
  targeting a small seeded **"Directory assistant"** flow/persona per
  client (cheap model, short prompt, no message-taking).
- New worker tools backed by Laravel:
  `find_directory_entry(name)` → `POST /call-sessions/{key}/directory/search`
  — searches `DirectoryEntry` (role `name`) in the client's directory
  and bound shared directories, only `routable` entries, returns
  ≤ 5 candidates with ids and spoken disambiguators (organization,
  department) — never numbers.
  `transfer_to_directory_entry(entry_id)` →
  `POST /call-sessions/{key}/directory/transfer` → returns a REFER
  target, like the external handoff (`CallSessionController::handoff`,
  `:151`).
- REFER targets are generated `from-livekit` entries
  `dir-{team}-{entry}` → `Dial(PJSIP/<number>@trunk_<id>)` (same shape
  as `handoff-{team}-{digits}`, `AsteriskConfigService::handoffDialplanEntries()`
  `:83-104`), so the LLM can't dial anything the client didn't list.
- With plan 11, a "not available" status routes to the entry's
  alternative contact or to the fallback.
- **DTMF spell-by-name** (`mode = dtmf_spell`) is phase 3: generate a
  per-client map of last-name keypad prefixes → entries with rendered
  name prompts, `Read(3)` → `GotoIf` list. Only if NCI clients need it.

#### 6.4 AI → back into Asterisk routing (prerequisite fix)

Generate per-client `from-livekit` return entries so an AI REFER lands
somewhere real:

```
exten => ret-t42-q7,1,Goto(tenant_42,queue-7,1)
exten => ret-t42-f13,1,Goto(ivr-t42-f13,s,1)      ; "back to main menu"
exten => ret-t42-x1005,1,Goto(tenant_42,1005,1)
```

Built from the client's queues, extensions and published IVR flows;
the worker's `transfer_call` tool stops taking a free-form extension
and asks Laravel for the target (like `transfer_to_operator`), so
cross-client targets are impossible. This also fixes the bug below.

### 7. Admin UI

- **Flow editor**: `telephony` palette group; new param widgets;
  region badges on `FlowNode`; validation panel lists compile errors
  with "jump to step". A **Publish** button (with note) and a
  **Revisions** drawer: who/when, diff of IR, "roll back". Draft edits
  don't change live calls.
- **Test panel**: keypad simulator over `IvrSimulator` with "pretend
  it's Saturday 22:00" and caller ID; plus **"Call my softphone"**,
  which originates a call from the staff softphone into
  `ivr-t{team}-f{entry}` via `AsteriskAmiService::originate()` (`:256`,
  context parameter) with `ORBITAL_DID` set, so the real prompts play.
- **Prompts**: `ManageClientPrompts` relation page under
  `ClientResource` (list, text, voice, language, upload, render status,
  play button, "used by"). A prompt edit re-renders and flags dependent
  orchestrations "republish needed" (or auto-republishes when the
  change is text-only and the user has `ivr.publish` — open question).
- **DIDs**: `ManageClientDids` shows "IVR (rev 7)" vs "AI" next to
  orchestration destinations.
- **Templates**: add to `ApplyOrchestrationTemplate`: "Main menu
  (emergency / message / operator)", "After-hours: emergencies to
  on-call, others take a message", "Medical: 911 disclaimer + menu".

### 8. Permissions and tenant isolation

- New models use `BelongsToTeam` (`ClientPrompt`, `IvrRevision`).
  Platform prompts (`team_id` null) are readable by all, editable by
  super-admin only.
- Permissions in `PermissionCatalogSeeder`, policies via
  `ClientResourcePolicy`: `client_prompt.view_any|view|create|update|delete`,
  `ivr.publish`, `ivr.rollback`. The editor stays super-admin until the
  README's open question 4 is answered; `ivr.publish` lets that change
  without code.
- Compile-time team assertions (§4.1); generated contexts are
  `ivr-t{team}-…` and only ever `Goto` contexts of the same team
  (`tenant_{team}`, `ivr-t{team}-*`) or shared infrastructure
  (`orbital-ai-unavailable`, `orbital-ivr-report`) — asserted by a
  dialplan snapshot test.
- New APIs (`/api/ivr/trail`, directory search/transfer) authenticate as
  the worker / the Asterisk shared secret and derive the team from the
  session or linkedid, never from the request body.
- `client_editable` prompts in the portal: client users may edit text
  of their own prompts only; render + publish runs as a system action
  recorded with the user's id.

### 9. Audit

- `ivr_revisions` rows (who published what, when, with note) and the
  rendered dialplan hash.
- Prompt create/update/render events (activity-style rows; no audit
  log package exists today — reuse plan 01's event table pattern or a
  small `config_audit_events` table shared with plan 06's overrides).
- Manual override toggles (plan 06) with actor and expiry.
- Per call: `script_events` of type `ivr_entered`, `prompt_played`,
  `menu_choice`, `menu_invalid`, `menu_timeout`, `digits_collected`
  (masked for `collect_digits` marked sensitive), `schedule_branch`,
  `ivr_exit{target}`, `ivr_abandoned`; plus `call_logs.ivr_path`.

## Edge cases and failure modes

| Case | Handling |
|---|---|
| Laravel down | Asterisk-run IVR keeps working (no per-call HTTP). Trail report fails silently (`&`); CDR still has the path. |
| AI/LiveKit down | `ai` ops already fall to `orbital-ai-unavailable` on non-ANSWER; for IVR calls use `ivr-t{team}-fallback` instead so the caller reaches a person, not "please call later". |
| Prompt not rendered / TTS provider down | Publish blocked; live revision unchanged. A missing file at runtime (deleted volume): `Background` fails silently and the menu still waits for digits; health check `orbital:status` lists missing prompt files per published revision. |
| Two Asterisk backends | Dialplan via existing `commandOnAllBackends`; prompts on the shared `asterisk-prompts` volume (SeaweedFS in HA, see `DisclosureRenderer` notes) — publish verifies the files are visible before reloading. |
| Dialplan reload mid-call | Context names are stable per flow id, so in-flight calls continue. A deleted flow keeps a stub context → fallback for one revision. |
| Caller with no DTMF (rotary, some VoIP) | Timeout → attempts → `no_input` → operator. Never hangs up on silence. |
| Inband DTMF from a carrier | Trunks use `rfc4733`; add trunk option `dtmf_mode=auto` if a carrier needs it (TrunkSyncer). |
| Digit pressed during greeting | `interruptible` prompts use `Background`, so the digit is taken. |
| Overlapping options (`1`, `12`) | Rejected at compile unless a terminator is set. |
| Infinite loop (submenu ↔ main) | `ORBITAL_IVR_HOPS` > 20 → fallback; compile warns on loops without a menu in them. |
| Hang-up inside the IVR | `h` extension reports `ivr_abandoned` with the last node; counted in reports (plan 10). No partial message (nothing collected by a person). |
| Holiday / DST | Plan 06's evaluator + nightly regeneration; simulator tests at DST boundaries. |
| Manual override set while calls are in the menu | Read at each `schedule` op, so new branches pick it up immediately. |
| Emergency option to voicemail | Compile warning requiring acknowledgement (§4.1). |
| Max concurrent calls per client (`teams.max_concurrent_calls`) | Calls in the IVR count; document it; billing (plan 09) can report IVR minutes separately. |
| `collect_digits` of sensitive data | Slot marked `masked` → digits not in trail/CDR, only in session fields with masking (plan 01 `gather_masked` rules). |
| Caller ID-based branches in the IVR | Static lists compile to `GotoIf` on `${CALLERID(num)}`; lookups need phase 3 AGI. |
| Orchestration unpublished or IVR deleted | `orchestrations.published_ivr_revision_id` null → today's AI dial. |

## Migration / rollout

- **Phase 0 — prerequisites (0.5 wk).** Generated `from-livekit` return
  entries + server-chosen `transfer_call` target (§6.4). `PromptRenderer`
  in `.sln`; move voicemail greetings and disclosures onto it (fixes
  the format bug). No behaviour change for existing DIDs: snapshot test
  that `from-trunk.conf` and every `clients/*-dialplan.conf` are byte-
  identical (minus timestamps) for clients without a published IVR.
- **Phase 1 — Asterisk-run menus (2.5 wk).** `client_prompts`,
  `ivr_revisions`, primitives (`play_prompt`, `dtmf_menu`,
  `dial_by_extension`, `hangup`, terminals, `branch_on_time_of_day` via
  06 or the `OperatingHour` adapter, `match_did`), compiler, renderer,
  simulator, publish/rollback, editor widgets, trail report → operator
  card. Opt-in: only orchestrations that start with telephony steps
  *and* are published change routing.
- **Phase 2 — AI integration (1.5 wk).** `r{rule}f{flow}` targets,
  IVR fields into the session, AI-region menus with DTMF, speech
  dial-by-name with directory tools and `dir-*` entries.
- **Phase 3 — dynamic steps (1–1.5 wk, on demand).** FastAGI bridge to
  the plan 01 engine for `collect_digits` validation/lookups and caller
  ID lookups; DTMF spell-by-name; client-editable prompts in the
  portal.
- Pilot with one NCI client that uses a menu on Amtelco today; compare
  menu-choice distribution and operator fallback rate for a week before
  moving others.

## Tests

Compiler / renderer (unit, snapshot):

- Menu with 3 options + operator digit → expected contexts; `i`/`t`
  handlers; attempts counted; unconnected exit → fallback context.
- Overlapping digits rejected; emergency-to-voicemail warning; menu not
  last in flow rejected; unrendered prompt blocks publish.
- Cross-tenant reference (queue/prompt/persona/extension of client B in
  client A's graph) → compile error; generated file for A contains no
  `tenant_B` / `ivr-tB` string.
- Label injection: option label `"x)\n exten => 9,1,Dial(PJSIP/…)"`
  renders as a sanitized `NoOp` and the file parses (load it in an
  Asterisk container test or a dialplan parser fixture).
- Client without telephony steps → `from-trunk.conf` unchanged
  (regression guard for every existing DID).
- Schedule branch emits plan 06's `GotoIfTime` lines; override reads
  AstDB.

Simulator (table-driven answering-service scenarios, frozen clock):

- Weekday 10:00, press 1 → `emergencies` flow → AI target
  `r{rule}f{flow}`, trail `f12:open>f13:1>f30`.
- Saturday 22:00 → after-hours menu; press 2 → queue 7.
- No input × 3 → operator fallback queue; trail ends `:no_input`.
- Invalid `7` twice then `2` → queue 7, two `menu_invalid` events.
- `*` repeats the menu without consuming an attempt.
- `0` at any menu → fallback.
- Holiday exception day → closed branch; DST spring-forward 02:30 →
  well-defined branch.
- Submenu loop pressing 9 forever → fallback after 20 hops.
- Hang-up after greeting → `ivr_abandoned` at node `f13`.
- Manual override "closed" set → closed branch regardless of schedule.

Integration / API:

- `POST /api/ivr/trail` with linkedid → `script_events` rows on the
  right session and team; wrong secret → 401; linkedid of another
  team's call can't be written under a forged team.
- `AiCallTarget::resolve('r88f30')` → persona + start flow; flow from
  another orchestration/team → ignored.
- `AgentFlowCompiler` with start flow + IVR fields → prompt starts at
  that flow, fields pre-filled, no generic greeting.
- Directory search returns only `routable` entries of this client (and
  bound shared directories); transfer returns `dir-{team}-{entry}`;
  entry of another client → 404.
- AI `transfer_call` to a queue lands in `tenant_{id},queue-{q}` (not
  `[default]`) — regression for the bug below.
- Publish → `RegenerateTelephonyConfig` dispatched for that team only;
  rollback restores the previous IR and dialplan hash.
- Prompt render: OpenAI/ElevenLabs fakes → `.sln` 8 kHz file of the
  expected length; failed render → `failed` status, publish blocked.

Worker (pytest):

- `ROOM_NAME_RE` accepts `agent-_r88f30_abc`, `agent-_1001_abc`,
  `agent-r88_abc`.
- DTMF event in an AI-region menu → `menu_choice` called with the
  mapped label.

Conformance (after plan 01):

- For every fixture orchestration, `IvrSimulator` trace ==
  `ScriptEngine` dry-run path for the same inputs.

Live (manual / staging, documented checklist):

- Real carrier call: greeting plays, keypad works through Kamailio
  edge, interrupting the greeting works, operator card shows the
  choice, recording/CDR has `orbital_ivr_path`.

## Open questions

1. Which NCI clients run a menu on Amtelco today, and what do the menus
   look like (depth, languages, "press 1 for emergencies" share)?
   Decides whether `dtmf_spell` directory and multi-language prompts
   are phase 1 or never.
2. Recorded voice talent vs TTS — do clients supply studio recordings
   (upload path matters more), and are there per-client voice choices?
3. Can clients edit their own greeting/holiday prompt text in the portal
   (and is a text change published automatically)?
4. Should IVR minutes be billed (plan 09) separately from operator/AI
   minutes?
5. "Press 0 for operator" — always on by default, or client choice?
6. Is the operator fallback the client's own queue or a platform-wide
   overflow queue when the client has none?
7. Compliance: do medical clients need the "if this is an emergency,
   hang up and dial 911" disclaimer as a mandatory first prompt?

## Size estimate

~5–6 weeks total: phase 0 0.5 wk, phase 1 2.5 wk (data model + renderer
0.5, compiler/renderer/simulator 1, editor/publish/prompts UI 1),
phase 2 1.5 wk, phase 3 1–1.5 wk (optional, on demand).

## Bugs noticed while writing this

1. **AI `transfer_call` hangs up the caller.** The worker REFERs to
   `sip:{extension}@asterisk` (`agent-worker/tools/transfer.py:44-55`);
   the livekit endpoint's context is `from-livekit`
   (`pjsip_livekit.conf:15`), whose catch-all sends it to `[default]`
   (`from-trunk.blade.php:117-118`), which only hangs up
   (`extensions.conf:25-27`). The LLM also chooses the extension freely,
   with no team check. Fixed by §6.4.
2. **Voicemail greetings and recording disclosures are probably
   unplayable.** `VoicemailGreetingRenderer` writes 24 kHz (OpenAI
   `wav`) or 16 kHz (ElevenLabs) `.wav` files
   (`VoicemailGreetingRenderer.php:147,184,200`) and `DisclosureRenderer`
   requests OpenAI `wav` (`:172`); `AiFallbackPrompt.php:24-25` records
   that Asterisk's `.wav` reader rejects those rates. Needs a live check
   (`Playback` of a rendered greeting); fix is phase 0.
3. **Queue overflow to a persona without an extension dials `0`.**
   `client-dialplan.blade.php:55` (and `extensions.blade.php:78`) uses
   `…extensions->first()?->number ?? '0'`, so the AI leg targets `0`,
   which `AiCallTarget` can't resolve → the caller gets "AI unavailable"
   instead of something useful.
4. **Soft-deleted routing rules would still route.**
   `generateFromTrunkDispatcher()` and `generateInternalContext()` use
   `RoutingRule::withoutGlobalScopes()` (`AsteriskConfigService.php:113,164`),
   which also drops `SoftDeletingScope`. Today only `ClientDidRouting`
   writes rules and it force-deletes (`ClientDidRouting.php:38-44,63-64`),
   so it's latent; any future rule editor (plan 06) that soft-deletes
   would leave rules live. Use `withoutGlobalScope('team')`.
5. **Minor:** `ClientDid` has `team_id` but no `BelongsToTeam`
   (`app/Models/ClientDid.php`), against CLAUDE.md's rule; the
   `trigger_inbound_phone` description is stale
   (`IntakeGoalLibrarySeeder.php:1263`); `from-trunk.blade.php:14-17`
   claims rules are ordered by DID specificity but the query orders by
   priority only (`AsteriskConfigService.php:113-117`; harmless since
   Asterisk orders patterns itself, but a rule with an empty pattern
   becomes `_X.` and collides with the catch-all at `:92`).
