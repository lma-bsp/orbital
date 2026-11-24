# Plan: Intelligent Series parity — overview

**Status:** proposed — parked for further consideration
**Written:** 2026-11-24

Orbital's answer to Amtelco Intelligent Series (IS) scripting is the
**orchestration** system: flows, steps (`IntakeGoal` catalogue, ~75
IS-named primitives), transitions, rules, client slots, an expression
language with IS-style functions, and a visual editor. The *design*
covers most of IS. What's missing is mostly **execution**: today the
graph is compiled into an LLM prompt, the LLM decides branches, and
only `send_email` / `web_call` actually run (post-call). Operators
have no usable script, messages have three fixed fields, and nothing
delivers, escalates or tracks acknowledgement.

This folder breaks the gap into separate plans that can ship
independently.

## The plans

| # | Plan | What it delivers | Depends on |
|---|------|------------------|------------|
| 01 | [Script runtime](01-script-runtime.md) | A deterministic server-side engine that runs orchestrations step by step — branching, expressions, time of day, caller ID, lookups — shared by AI and operators | — |
| 02 | [Operator scripting](02-operator-scripting.md) | Operators follow the client's script as a guided, branching form on every call; AI → operator handoff with state | 01, 03 |
| 03 | [Messages with custom fields](03-message-model.md) | Per-client message templates/fields, message history and edits, portal message list | — |
| 04 | [On-call scheduling](04-on-call-scheduling.md) | Rotas, shifts, overrides, "who is on call now" lookups usable from scripts | — |
| 05 | [Delivery and escalation](05-delivery-and-escalation.md) | Dispatch engine: deliver messages by contact method, require acknowledgement, retry/escalate, paging transports | 03, 04 (01 for script-triggered dispatch) |
| 06 | [Time-of-day routing and schedules](06-schedules-and-routing.md) | Business hours, holidays, schedule-aware routing and script conditions; routing rule editor | — (used by 01, 04) |
| 07 | [Further IS features (survey)](07-additional-features.md) | Inventory of the rest of IS, with priorities; each item is expanded in plans 08–26 below | — |

### Further features (from the 07 survey)

| # | Plan | Survey item | Priority |
|---|------|-------------|----------|
| 08 | [Call patching and conferencing](08-call-patching-and-conferencing.md) | A | P1 |
| 09 | [Usage and billing](09-usage-and-billing.md) | B | P1 |
| 10 | [Client and staff reporting](10-client-and-staff-reporting.md) | C, T | P1 |
| 11 | [Directory status](11-directory-status.md) | D, I | P1 |
| 12 | [Message check-in](12-message-checkin.md) | E | P1 |
| 13 | [Caller history](13-caller-history.md) | F | P1 |
| 14 | [Queue announcements and virtual hold](14-queue-announcements-and-virtual-hold.md) | G | P2 |
| 15 | [IVR and auto attendant](15-ivr-and-auto-attendant.md) | H | P2 |
| 16 | [Two-way messaging](16-two-way-messaging.md) | J | P2 |
| 17 | [Secure messaging](17-secure-messaging.md) | K | P2 |
| 18 | [Dispatch list and follow-ups](18-dispatch-list-followups.md) | L | P2 |
| 19 | [Scheduled outbound calls](19-scheduled-outbound-calls.md) | M | P2 |
| 20 | [Info pages](20-info-pages.md) | N | P2 |
| 21 | [Appointment scheduling](21-appointment-scheduling.md) | O | P3 |
| 22 | [Script replay and QA](22-script-replay-and-qa.md) | P | P3 |
| 23 | [Code calls and mass notification](23-code-calls-and-mass-notification.md) | Q | P3 |
| 24 | [Client web scripting](24-client-web-scripting.md) | R | P3 |
| 25 | [Operator message aids](25-operator-message-aids.md) | S | P3 |
| 26 | [Skills-based routing](26-skills-based-routing.md) | U | P2 |

Suggested order: **03 → 01 → 02** (operators get real scripts that
produce real messages), then **06 → 04 → 05** (who gets the message,
and making sure they got it). 06 is small and could go first.

## IS feature map

| IS capability | Orbital today | Plan |
|---|---|---|
| Scripts with branching (If/Else, Select Case, Go To) | Graph exists; branches chosen by LLM, `branch_if` has no executor | 01 |
| Script variables / fields | `ClientSlot` | 01 (runtime), 03 (persist to message) |
| Expressions & functions | `ExpressionEvaluator` (~50 functions), used only in post-call templates | 01 |
| Time-of-day / holiday branching | `OperatingHour`, `RoutingRule.time_condition` stored, never read | 06 |
| Operator-facing guided scripts | Read-only checklist, wrong client (bug) | 02 |
| Info pages / account notes | Imported Amtelco Info Pages text; Team call-handling fields (uncommitted) | 02 (shown beside script) |
| Directory lookups in scripts | Directory model exists; no script access | 01 |
| On-call schedules | None | 04 |
| Message with custom fields | 3 fixed fields | 03 |
| Message dispatch (email, SMS, page, fax, WCTP, voice callout) | Only voicemail email; SMS via Twilio for conversations only | 05 |
| Delivery confirmation / acknowledgement | None | 05 |
| Escalation & retries (until delivered) | None | 05 |
| Dispatcher queue / message hold & re-dispatch | Catalogue entries only | 05 |
| Script subroutines | `action_group` + `call_action_group` (prompt only) | 01 |
| Database / web service steps | `web_call` post-call only; `db_*` stubs | 01 |
| Call tracker / history events | Catalogue entries only | 01 (events), 03 (history) |
| Script import from Amtelco | Not in the export we have | Open question below |

## Cross-cutting principles

- **One engine, three surfaces.** Voice AI, human operator and chat/SMS
  all execute the same orchestration through 01. The AI gets tools
  backed by the engine; the operator gets a UI backed by the engine.
  No more "the LLM decides the branch".
- **Deterministic first, LLM where it helps.** Conditions, time checks,
  lookups and dispatch are code. The LLM gathers and phrases.
- **Everything is auditable.** Each step entered, field set, branch
  taken, delivery attempt and acknowledgement is an event on the call.
  This is also what makes the answering-service test suite possible:
  scripted fixtures in, event log out.
- **Tenant isolation** on every new model (`BelongsToTeam`), with
  explicit tests that client A's schedule, fields or contacts can't
  leak into client B.

## Testing strategy (shared)

The plans together give the "answering service calls" test suite its
backbone:

1. **Engine tests** (01): table-driven — orchestration fixture + inputs
   (caller ID, time, field values) → expected path, fields, message,
   dispatches. No telephony needed.
2. **Operator scenario tests** (02): Livewire tests driving the script
   runner end to end.
3. **Schedule tests** (04, 06): frozen clock, DST transitions, holidays,
   overlapping overrides.
4. **Delivery tests** (05): fake transports; retry/escalation timelines
   with `travel()`; ack via each channel.
5. **AI parity tests**: the same fixture executed through the AI tool
   API produces the same path as through the operator runner.

## Open questions

1. Does NCI have access to its IS script definitions (IS Supervisor
   export), so we can plan an importer, or will scripts be rebuilt by
   hand from templates?
2. Which paging/dispatch methods do NCI's clients actually use today?
   (Decoding the Amtelco dial macros in TODO.md answers part of this.)
3. Are secure-messaging apps (e.g. MiSecure, seen in the directory
   export) required at launch?
4. Who authors scripts — only platform staff (super-admin editor today)
   or also supervisors?
