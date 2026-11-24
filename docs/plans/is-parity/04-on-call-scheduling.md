# Plan 04: On-call scheduling

**Status:** proposed · part of [IS parity](README.md) · uses 06 for calendars

## Problem

There is no on-call model. The step catalogue's `auto_dispatch` refers
to "configured rules" that don't exist, and the AI prompt says "notify
the on-call roster" with nothing behind it. IS's On-Call scheduler is
central to medical and property-management answering: the script asks
"who is on call for cardiology right now?" and dispatches to them with
their preferred contact methods.

Directory entries exist (`DirectoryEntry` with flexible `values` and
`DirectoryFieldDefinition` roles; Amtelco import brings phones, pager,
text, "How to reach" contact procedure), so the people already exist.

## Goal

Per-client on-call schedules — groups, rotations, shifts, overrides —
answering "who is on call for X at time T" from scripts, the operator
sidebar, and the dispatch engine, with each person's ordered contact
methods.

## Design

### Model (all `BelongsToTeam`)

- `on_call_groups`: name ("Cardiology", "Maintenance"), timezone
  (defaults to client), fallback contact, notes shown to operators.
- `on_call_shifts`: group, directory entry (or ordered list of entries
  for "primary / backup"), start/end, recurrence (RRULE: weekly
  rotations, every-other-weekend, etc.), position (primary=1,
  backup=2…).
- `on_call_overrides`: group, entry, start/end, reason — wins over
  shifts (vacation swaps, "Dr X covering tonight").
- **Contact methods** on the directory entry: ordered list
  `[{method: sms|voice|email|page|wctp|secure_app, address, label,
  hours?}]` — built from imported fields (decoded Amtelco macros, see
  TODO "Decode the remaining Amtelco dial macros"). A person can have
  different methods for urgent vs non-urgent.
- `ContactProcedure` (optional per group or per entry): the IS-style
  instructions ("text, wait 10 min, then call cell, then call backup"),
  stored structured so plan 05 can execute it, plus a free-text note.

### Resolution service

`OnCallResolver::who(group, at): OnCallResult` — primary + backups in
order, source (shift vs override), resolved contact methods. Pure,
clock-injected, DST-safe (store wall-clock + timezone; expand RRULE in
the group's zone).

### Surfaces

- **Admin / supervisor**: calendar view per client (week grid, drag to
  create, override button), import/export CSV for bulk rotas.
- **Client portal** (roadmap "Client self-service"): clients maintain
  their own rota and overrides, behind a permission; every change
  audited and shown to operators ("changed by client 2h ago").
- **Operator sidebar** (plan 02): "On call now" per group, with contact
  methods and a click-to-call / click-to-page.
- **Script step** `lookup_on_call(group) → slot` (plan 01) so scripts
  branch on "no one on call" and pass the person to `send_*`/dispatch.
- **Gap alerts**: nightly check for uncovered windows in the next 7
  days → notify client + supervisors.

## Tests

- Resolver: weekly rotations, overnight shifts crossing midnight, DST
  spring-forward/fall-back, overrides beating shifts, overlapping
  overrides (latest wins, tested), empty coverage → fallback.
- Portal permission: client user can edit own groups only; audit rows.
- Gap detection finds the uncovered window.

## Size

~2 weeks (model + resolver 1 wk, calendar UI + portal 1 wk).
