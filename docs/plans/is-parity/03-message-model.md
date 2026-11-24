# Plan 03: Messages with custom fields

**Status:** proposed · part of [IS parity](README.md)

## Problem

`Message` has fixed columns: `caller_name`, `caller_phone`, `reason`,
`urgency`, `notes`, plus partial-message flags.
`SessionMessageWriter::normalise()` maps AI slots into name/phone/reason
by guessing synonyms; everything else captured stays in
`call_session_states.fields` and never reaches the client. IS messages
are per-account templates with any number of fields (patient DOB,
unit number, callback window, policy number…), formatted for each
delivery method.

The client portal has no message list yet (`docs/roadmap.md`).

## Goal

Per-client message templates with typed fields, filled by scripts (AI
or operator), stored with the message, rendered consistently for the
operator, the portal, and every delivery channel (plan 05).

## Design

### Model

- `messages.fields` (JSONB) — `{slot_key: value}` snapshot at save time,
  plus `messages.template_id` and `messages.orchestration_id`,
  `script_session_key`.
- Keep `caller_name`, `caller_phone`, `reason`, `urgency` as promoted
  columns (search, lists, existing code) — filled from fields mapped to
  those roles, not by synonym guessing.
- `message_templates` (`BelongsToTeam`, plus platform-shared with
  `team_id` null like orchestrations): name, ordered field list
  (slot key, label, required, show-in-summary, redact-in-email),
  **summary line template** and **per-channel body templates** (email
  HTML/text, SMS/pager short form ≤ 160/240 chars, voice read-out),
  using `TemplateEvaluator` (`{{ }}` + expression functions).
- `ClientSlot` gains a `role` (`caller_name`, `caller_phone`, `reason`,
  `urgency`, `callback_number`, `account_number`, none) so promotion is
  explicit.
- `message_revisions`: edits after save (who, when, diff). IS lets
  operators/supervisors amend; clients see the latest with history.
- Message status lifecycle widened for plan 05:
  `new → dispatched → delivered → acknowledged` plus `held`
  (waiting for a dispatch window), `filed` (no delivery required),
  `closed`. Keep `read`/`archived` for portal-side state, separate from
  delivery state.

### Writers

- `save_message` step handler (plan 01) builds the message from the
  template and session fields; validates required fields; partial rules
  unchanged (`PartialMessagePolicy`).
- `SessionMessageWriter` becomes a thin adapter onto the same builder
  for legacy `prompt` runtime sessions.
- SMS/email/chat intake (TODO "AI-side intake capture") uses the same
  builder — `MessageThread.fields` already exists.

### Surfaces

- Operator: message view renders template fields; supervisor edit with
  revision log.
- **Portal message list** (roadmap item): list, filter by date/status/
  urgency, detail with all fields, delivery & ack timeline (plan 05),
  linked recording (permission `portal.view_recordings`), mark read.
- Search: fields searchable (Postgres JSONB GIN index on `fields`).

### Data migration

Existing messages: copy promoted columns into `fields` under the role
keys; `template_id` null renders with a built-in default template.

## Tests

- Builder: template + fields → promoted columns, summary, each channel
  body; required-field validation; redaction in email.
- Revisions: edit produces a diff, portal shows latest.
- Portal: client sees only own messages; field visibility honours
  template flags.
- Back-compat: legacy AI session still produces the same name/phone/
  reason as today.

## Size

~1.5–2 weeks (model + builder 1 wk, portal list 0.5–1 wk).
