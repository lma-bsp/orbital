# Plan 01: Script runtime — a deterministic orchestration engine

**Status:** proposed · part of [IS parity](README.md)

## Problem

Orchestrations are only *compiled into a prompt*
(`AgentFlowCompiler`). At runtime:

- The LLM decides which transition fires; `transition_to_flow` in the
  worker just logs it (`agent-worker/tools/compiled_flow.py`).
- `branch_if`, `branch_on_time_of_day`, `call_action_group`, `db_*`,
  `match_*`, `save_call_tracker_event` etc. have no executor.
- `ConditionEvaluator` (JsonLogic) is used only *after* the call, by
  `PostCallActions`, to reconstruct which path was probably taken.
- `ExpressionEvaluator` (IS-style functions) is reachable only through
  `TemplateEvaluator` in post-call templates.
- Live context has no caller ID, called number or time of day — the
  prompt is built once from the persona.
- Directory entries are never visible to scripts; `lookup_account` in
  the worker is a stub.

IS runs scripts deterministically: the script engine owns position,
variables and branching. Operators (and here, the AI) supply input.

## Goal

A server-side **ScriptSession** engine that executes an orchestration
step by step, owns all state, evaluates every condition itself, and
exposes the same API to the AI worker, the operator UI (plan 02) and
chat/SMS.

## Design

### State

Extend `CallSessionState` (already keyed per session, already has
`fields`, `active_step`, `entry_flow_id`, `operator_owned`) rather than
inventing a new table:

- `active_flow_id`, `active_step_id` (replace the integer `active_step`)
- `call_stack` (JSON) — return addresses for `call_action_group`
  subroutines
- `context` (JSON) — frozen at session start: caller ID / name, called
  DID, client, channel, start time in the client's timezone, linkedid
- Make sessions channel-agnostic: a session for an operator call, an AI
  call, an SMS thread or a chat — `session_key` already abstracts this.

New `script_events` table (append-only): session, sequence, type
(`flow_entered`, `step_entered`, `field_set`, `branch_taken`,
`action_run`, `action_failed`, `handoff`, `ended`), payload, actor
(`ai` / user id / `system`), timestamp. This replaces log lines as the
source of truth and feeds call history, QA, and tests.

### Engine (`app/Services/Script/`)

- `ScriptEngine::start(Orchestration|IntakeFlow, context): ScriptSession`
- `ScriptEngine::current(session): StepView` — what to show/say now:
  rendered talking points, inputs needed (from the goal's `data_fields`
  + `step_params`), allowed exits.
- `ScriptEngine::submit(session, inputs | exit): StepView` — validate
  inputs against slot types, write fields, run step side-effects, then
  **advance**: next step in the flow, or evaluate the flow's transitions
  in priority order with `ConditionEvaluator` and move.
- Automatic (non-interactive) steps run immediately on entry until an
  interactive step is reached: `branch_if`, `branch_on_time_of_day`,
  `match_did`, `db_lookup_single`, `web_call`, `save_*`, `call_action_group`.
- **Step handlers**: one class per goal key implementing
  `StepHandler { interactive(): bool; render(); handle(); }` registered
  in a map. Unknown keys fail loudly in the editor (validation) and at
  runtime (event + safe fallback exit), never silently.
- **Expressions everywhere:** conditions may be JsonLogic *or* an
  expression string; both evaluated server-side with the same variable
  resolver (slots → `call.*` / `caller.*` / `client.*` / `now` / `agent.*`).
  Unify the two evaluators behind one `Condition` interface.

### Step handlers to implement (first wave)

| Group | Handlers |
|---|---|
| Ask | all `gather_*` (typed validation, masking for `gather_masked`) |
| Logic | `branch_if`, `branch_on_time_of_day` (uses plan 06 schedules), `call_action_group` / return |
| Match | `match_did`, `match_extension`, `match_email_address` |
| Data | `db_lookup_single`, `db_picklist` against **directory entries** (client directory + shared directories, using `DirectoryFieldDefinition` roles); `web_call` live (not just post-call); `parse_json` |
| Message | `save_message` → plan 03; `send_*` → enqueue dispatch (plan 05) |
| On-call | `lookup_on_call` (new) → plan 04 |
| Tracking | `save_call_tracker_event`, `save_keyword`, `save_summary` → `script_events` |
| Routing | `transfer_to_extension`, `enter_call_queue`, `transfer_to_voicemail` → AMI/handoff (reuse `HandoffDestinations`) |

Later: `db_save`, `db_iterate`, `parse_xml`, `schedule_callback`,
`park_call`, `toggle_recording`, `change_account`.

### AI integration

The worker stops receiving the whole graph as one prompt. Instead:

- Prompt = persona + **current step** (rendered by the engine) + a short
  map of the flow for context.
- Tools: `submit_step(inputs)` / `choose_exit(exit)` call the engine via
  the existing call-session API (`/api/call-sessions/{key}/…`); the
  response carries the next step's instructions, which the worker
  injects as an updated instruction. `set_field` / `advance_step` /
  `transition_to_flow` are retired.
- Branch decisions the LLM legitimately makes (e.g. "classify reason")
  are modelled as an interactive step with named exits; the engine
  validates the chosen exit exists.

### Editor changes

- Validation on save: every step key has a handler or is flagged
  "AI-only / not executable"; every flow has a reachable end; conditions
  reference existing slots.
- Test panel runs the real engine (dry-run mode: side-effects faked),
  replacing the current two-key `test-step` endpoint.
- OR groups in `ConditionBuilder` (currently AND-only).

## Migration

- Feature flag per orchestration: `runtime = prompt | engine`. Existing
  clients stay on `prompt` until their orchestration validates cleanly.
- `PostCallActions` keeps running for `prompt` sessions; for `engine`
  sessions actions run in-line and post-call reconstruction is skipped.

## Tests

- Table-driven engine tests: fixture orchestration (YAML/PHP array
  builder) + scripted inputs + frozen clock/caller → expected event log,
  fields, final state. Cover every handler, transitions priority and
  fallback, subroutine call/return, loops guard (max steps per session).
- Expression/condition parity tests: the same condition as JsonLogic and
  as expression gives the same result.
- Tenant isolation: directory lookups never cross clients (shared
  directories only when bound).
- Worker contract tests: API responses for `submit_step` match the
  Python tool's expectations (JSON schema shared in repo).

## Size

~3–4 weeks: state + engine + first-wave handlers (2 wk), AI tool
switch (1 wk), editor validation/test panel (0.5–1 wk).
