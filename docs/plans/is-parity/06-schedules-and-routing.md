# Plan 06: Business hours, holidays and time-of-day routing

**Status:** proposed · part of [IS parity](README.md)

## Problem

- `OperatingHour` exists (`Team::operatingHours()`) but nothing in
  `app/` reads it.
- `RoutingRule.time_condition` is stored and cast but never evaluated.
- The roadmap notes no routing rule editor: calls reach a queue by a
  direct DID binding, so no time-of-day routing from the UI.
- Scripts can't branch on "office open?" (`branch_on_time_of_day` has
  no executor) and the AI has no current time in its context.

IS scripts and call routing lean heavily on schedules: office hours,
lunch, holidays, "after 10pm only emergencies", seasonal hours.

## Goal

Named, reusable schedules per client (and platform holiday calendars),
evaluated in the client's timezone, used by inbound routing, scripts
(plan 01), on-call (plan 04) and dispatch holds (plan 05).

## Design

### Model

- `schedules` (`BelongsToTeam`, or platform-wide when `team_id` null):
  name ("Office hours", "Lunch", "Clinic open"), timezone, weekly
  intervals (replace/absorb `OperatingHour` rows), exceptions.
- `schedule_exceptions`: date or date range, closed / special hours,
  label ("Thanksgiving"), optional link to a platform **holiday
  calendar** (US federal etc.) a client can subscribe to.
- `ScheduleEvaluator::isOpen(schedule, at)`, `nextChange(schedule, at)`
  — pure, DST-safe, clock-injected.
- `Team.timezone` (exists, falls back to `app.timezone`) is the default.

### Inbound routing

- `RoutingRule.time_condition` → `schedule_id` + `when` (`open` /
  `closed`), evaluated top-down by priority per DID.
- Asterisk side: generated dialplan can't call PHP per call cheaply.
  Options, in order of preference:
  1. Generate `GotoIfTime()` lines from schedules in
     `client-dialplan.blade.php` (regenerated on change and nightly to
     roll exceptions forward). Fast, no runtime dependency on Laravel.
  2. AGI/ARI lookup to Laravel when a rule needs something
     `GotoIfTime` can't express.
- **Routing rule editor** in Filament (roadmap item): per client, per
  DID, ordered rules with schedule pickers and targets (queue, AI
  persona/orchestration, voicemail, external number), plus a "what
  happens at <time>" preview.
- Manual override switch per client ("Closed — weather emergency")
  that wins over schedules, with optional expiry. Very common in
  answering services.

### Scripts and AI

- `branch_on_time_of_day` handler (plan 01) takes a schedule id → exits
  `open` / `closed` (and named intervals).
- Expression functions: `isopen("Office hours")`, `nextopen(...)`.
- Script context includes `now` in the client timezone; the AI prompt
  for the current step gets "office is closed, reopens Mon 8:00".

## Tests

- Evaluator: overnight intervals, DST both directions, exceptions
  beating weekly hours, holiday subscription, manual override expiry.
- Dialplan snapshot tests for generated `GotoIfTime` lines, including a
  schedule that can't be expressed (falls back to AGI path).
- Routing: DID with open/closed rules routes correctly at frozen times.
- Migration: existing `OperatingHour` rows become an equivalent schedule.

## Size

~1.5–2 weeks (model + evaluator 0.5 wk, dialplan generation 0.5 wk,
routing editor 0.5–1 wk).
