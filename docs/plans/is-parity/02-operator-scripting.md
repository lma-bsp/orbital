# Plan 02: Operator scripting — guided scripts on every call

**Status:** proposed · part of [IS parity](README.md) · depends on 01, 03

## Problem

In IS, an operator answers a call and the client's script drives the
screen: greeting, prompts, fields, branches, then dispatch. In Orbital:

- The operator Workspace embeds `CompiledFlowViewer`, which is a
  read-only checklist (talking points, slot names, transitions) with no
  inputs, no condition evaluation and no auto-advance.
- It shows the **wrong client's** flow (bug in TODO.md "Known bugs").
- AI → operator handoff doesn't pass `sessionKey`, so fields the AI
  already captured aren't shown.
- The message the operator saves is a fixed form (name, phone, reason,
  urgency) unrelated to the script.
- The multi-call desk (`MultiCallWorkspace` / `MultiCallDesk`,
  `OperatorCall`) doesn't use scripts at all.

## Goal

Every operator call (single and multi-call desk) runs the client's
orchestration through the plan 01 engine as a guided form. The script
*produces* the message; there is no separate message form.

## Design

### Script runner component

New Livewire component `ScriptRunner` (replaces `CompiledFlowViewer`
in operator surfaces; the viewer stays for admins as a read-only map).

- Mounted with a `ScriptSession` (from an `OperatorCall`, or an
  existing AI session on handoff).
- Renders `ScriptEngine::current()`:
  - talking points as the script line to read (with `{{ }}`
    substitutions — caller name, client name, time-aware greeting)
  - inputs typed from the slot (text, phone with formatting, date/time,
    choice buttons, yes/no, address, masked)
  - exit buttons for interactive branch steps
  - **Back** (re-enter previous step; engine records a `step_reverted`
    event, fields kept) and **Jump** for supervisors only
- Keyboard-first: Enter submits, number keys pick choices, Tab between
  fields — operators run dozens of calls an hour.
- Sidebar: client call-handling info (greeting, notes, passcode — Team
  fields added on this branch), Info Pages imported from Amtelco,
  directory search, on-call now (plan 04), recent messages for this
  caller.
- Breadcrumb of the path so far (from `script_events`).

### Call lifecycle integration

- `OperatorCall` gets `script_session_key`. Answering a call starts (or
  resumes) the session with context: caller ID, DID → client, line label.
- Multi-call desk: each call keeps its own session; switching calls
  switches the runner. Holding a call doesn't pause the session.
- Hang-up mid-script: engine `end(reason: caller_hangup)`; partial-
  message rules (`PartialMessagePolicy`) apply as they do now.
- **AI → operator handoff:** `CallSessionState.operator_owned = true`
  already exists. The handoff passes `session_key` to the softphone /
  `OperatorCall`; the runner opens at the AI's current step with fields
  filled. The engine marks the actor change.
- **Operator → AI** (later): hand the session back to an AI persona at
  the current step.

### Messages

`save_message` steps create the message from script fields using the
client's message template (plan 03). The fixed Workspace form is
removed once all clients have a script; until then a default
"take a message" orchestration (seeded) gives the same fields through
the runner, so there is only one code path.

### Defaults and templates

- Seed a platform "Standard message" orchestration (greeting → name →
  phone → reason → urgency → save → read back) and bind it to every
  client without one. This replaces the hard-coded form.
- Extend `ApplyOrchestrationTemplate` with the IS patterns NCI uses
  most: message-only, medical after-hours with on-call dispatch,
  property-management emergency vs. non-emergency, appointment
  callback. (Inventory with NCI; see TODO "Template tenants".)

## Permissions

- Operators: run scripts. Supervisors: jump steps, edit a saved message.
- Script authoring stays in the admin editor (open question in README
  on whether supervisors author).

## Tests

- Livewire scenario tests: answer call → run fixture script with inputs
  → assert message content, events, dispatches queued.
- Multi-call: two calls, interleaved inputs, no cross-contamination.
- Handoff: AI session with fields → operator runner opens at the right
  step with values.
- Tenant: runner for client A can't load client B's orchestration or
  directory (regression test for the current bug).
- Back/revert keeps a consistent event log.

## Size

~2–3 weeks after plan 01 (runner UI 1.5 wk, lifecycle + handoff 1 wk,
default script/template 0.5 wk).
