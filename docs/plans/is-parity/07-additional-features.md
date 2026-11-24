# Plan 07: Further IS features worth carrying over

**Status:** survey — candidates to promote into their own plans
**Written:** 2026-11-24 · part of [IS parity](README.md)

Plans 01–06 cover scripting, messages, on-call, delivery and schedules.
This file inventories the *rest* of Amtelco's Intelligent Series /
Infinity feature set (from Amtelco's published IS feature list — see
Sources) and checks each against Orbital today. Each item has a
priority for an answering service like NCI:

- **P1** — operators or clients will notice its absence in the first
  weeks after cut-over.
- **P2** — expected by some clients; plan before onboarding them.
- **P3** — niche (mostly hospital switchboard) or nice to have.

## Summary

| # | IS feature | Orbital today | Priority | Where it goes |
|---|---|---|---|---|
| A | Call patching / conferencing (patch caller to on-call) | Softphone has **blind transfer only** (`sip-phone.js` `blindTransfer`) | **P1** | Plan 08 |
| B | Billing & usage per client | `call_logs.billable_seconds` exists; no reports, no export | **P1** | Plan 09 |
| C | Client reports & daily message summary | Portal has Call History + text conversations only; Grafana is staff-only | **P1** | Plan 10 |
| D | Directory status & status-based dispatch | Directory has no status; dispatch plan assumes availability | **P1** | Plan 11 |
| E | Message check-in / hold for pickup | None; `PROTOCOL_*` only for SMS | **P1** | Plan 12 |
| F | Repeat caller / caller history | Client's recent messages shown; nothing per caller | **P1** | Plan 13 |
| G | Queue announcements, estimated wait, virtual-hold callback | No `announce-*` / callback in generated `queues.conf` | P2 | Plan 14 |
| H | Auto attendant / IVR / speech directory | No `Background`/`WaitExten`/`Read` in dialplan; AI persona fills part of this | P2 | Plan 15 |
| I | Staff status by phone/SMS/portal ("I'm in surgery till 3") | None | P2 | Plan 11 |
| J | Two-way interactive messaging & read receipts | SMS conversations exist, not tied to Messages | P2 | Plan 16 |
| K | Secure messaging (HIPAA) — secure email/app | None | P2 | Plan 17 |
| L | Dispatch list with follow-up expirations | Covered partly by plan 05 dispatcher queue | P2 | Plan 18 |
| M | Scheduled outbound calls — wake-up, check-in, reminders | `schedule_callback` is a catalogue stub | P2 | Plan 19 |
| N | Info Pages with formatting, photos, dialable numbers | Imported plain text in `ClientCallBrief` | P2 | Plan 20 |
| O | Appointment / event scheduling | None | P3 | Plan 21 |
| P | Call log activity documentation (screen capture) | Call recordings only | P3 | Plan 22 |
| Q | Code calls / emergency mass notification | None | P3 | Plan 23 |
| R | Web scripting for client staff | None | P3 | Plan 24 |
| S | Operator message aids (spellcheck, read-back) | Browser default | P3 | Plan 25 |
| T | Supervisor dashboard / service-level reports | Grafana Telephony dashboard (Prometheus, 15-day retention); `queue_metrics_daily` table exists but nothing reads it; live board planned | covered | Plan 10 + live-call plan |
| U | Skills-based ACD with priority & overflow | Queues + AI overflow; skills are data only (roadmap) | covered | Plan 26 |

## Details

### A. Call patching and conferencing — P1

Medical and property clients routinely want "patch the caller through
to the on-call doctor". IS operators dial the on-call, announce the
caller, then connect (or drop off a 3-way call).

- Softphone: **attended transfer** (consult call, then REFER with
  Replaces) and **3-way conference** (Asterisk `ConfBridge` or a bridge
  joined via AMI), plus "park caller while I reach the doctor" (park
  is a catalogue step already).
- Script step `patch_to_contact(contact)` (plan 01) that uses the
  on-call result (plan 04) and records the patch as delivery +
  acknowledgement (plan 05) — a successful patch *is* delivery.
- Recording continues across the patch; call log links both legs.
- AI agents: warm transfer through LiveKit SIP (the AI briefs the
  on-call before connecting).

### B/C. Billing, usage and client reporting — P1

Answering services bill per call, per minute or per message unit, and
clients expect reports. IS ships ~140 standard reports plus scheduled
delivery.

- **Usage ledger**: per client per period — calls, talk/billable
  minutes (`billable_seconds` already stored), messages, dispatch
  attempts by channel (SMS/pager have per-unit cost), AI minutes vs
  operator minutes. Close a period; export CSV / accounting integration.
- **Rate plans** per client (included minutes, overage, per-message
  fee) — optional; many services do billing in their accounting system
  and only need the export.
- **Client reports** in the portal and scheduled by email: call volume
  by hour/day, answer speed, messages by type/urgency, delivery and
  acknowledgement times, after-hours share.
- **Daily / per-shift message summary** to the client ("12 messages
  today, 2 urgent, all acknowledged") — a common IS deliverable.
- **Staff reports**: operator productivity (calls/hour, handle time,
  messages, availability states — `AvailabilitySelector` and logout
  reasons already capture this), service level per queue.

### D/I. Directory status and status-based dispatch — P1/P2

IS directory contacts carry a status ("In", "Out", "In surgery",
"On vacation until …") with a return time, and each status has its own
contact order: in clinic → page; at home → cell; on vacation → backup.

- `directory_statuses` per entry: status (client-defined list),
  until, note, set_by.
- Contact methods (plan 04) gain a per-status order.
- Staff set status by **phone** (IVR: call in, enter ID, press 2 for
  out), **SMS** keyword ("OUT 3PM"), **portal**, or via the operator.
- Operator sidebar and dispatch both respect status; expired statuses
  revert automatically.

### E. Message check-in / hold for pickup — P1

Many clients don't want messages pushed; they **call in** or log in to
collect them (or want them held until a check-in time).

- Delivery method `hold_for_checkin` in plan 05: messages wait in a
  per-client (or per-contact) mailbox.
- **Phone check-in**: client calls a check-in number, identified by
  caller ID + PIN; an operator (or TTS/AI) reads messages and marks them
  delivered. The operator Workspace gets a "client checking in" mode
  that lists held messages.
- Portal check-in: viewing/acknowledging in the portal list (plan 03)
  counts as delivery.
- Reminder if held messages exceed an age.

### F. Repeat caller / caller history — P1

- On answer, look up the caller's number within this client: previous
  calls, messages, open dispatches — "called 3 times tonight, last
  message not yet acknowledged".
- Expose to scripts as `caller.previous_calls`, `caller.open_messages`
  so a script can branch ("caller is following up → add to existing
  message instead of creating a new one").
- Optional cross-client hint for staff only (same number calling
  several accounts), never shown to clients.

### G/H. Voice services — P2

- Queue: periodic position / estimated-wait announcements and
  **virtual hold** ("press 1 and we'll call you back, keeping your
  place") — generated into `queues.conf` + a callback job that dials
  out when an operator frees up.
- Auto attendant / menus per client where wanted ("press 1 for
  emergencies"), built as orchestration steps rather than raw dialplan,
  so it's the same editor.
- Speech directory ("say the name of the person") — the AI persona
  already covers natural-language routing; reuse it with directory
  lookup from plan 01 rather than building classic ASR menus.

### J. Two-way interactive messaging — P2

- Replies from recipients (SMS/WCTP/email) thread onto the Message and
  show on the operator and portal timeline, not only as acks.
- Read receipts where the channel supports it (secure messaging, WCTP
  status).
- Recipient can reply "CALL ME" style keywords that create an operator
  task.

### K. Secure messaging — P2

Healthcare clients will ask about HIPAA. Options, cheapest first:

1. **Secure link**: SMS/email carries only "You have a message from
   <service>: <link>"; the link opens the message in the portal after
   PIN/login, and viewing is the acknowledgement.
2. **Secure two-way email** (encrypted at rest, portal-hosted thread).
3. **Mobile app** (push, in-app ack) — large; consider only if clients
   require it. Interop with existing miSecure users is an open question
   (the directory import shows MiSecure handles).

### L. Dispatch list with expirations — P2

Plan 05's dispatcher queue, plus: each pending item has an owner
(originating operator) and an expiration; when it nears expiry the
owner is prompted, then it escalates to the shared list. Operators see
"my pending follow-ups" on the workspace.

### M. Scheduled outbound calls — P2

Wake-up calls, daily check-in calls to elderly/at-risk clients' contacts,
appointment reminders, scheduled callbacks.

- `scheduled_calls`: client, target, time/RRULE, script, retries,
  "no answer" escalation (e.g. notify family contact).
- Executed by AI persona (TTS + simple script) or queued to an operator.
- Results logged like inbound calls; failures go through plan 05.

### N. Richer Info Pages — P2

Info pages today are imported plain text. Make them a per-client rich
document: headings, tables, photos, links, **click-to-dial numbers**,
and sections a script step can pin ("show section: Emergency
procedures"). Keep an import path from the Amtelco Info Pages text.

### O. Appointment / event scheduling — P3

Operators (or AI) book into a client's calendar. Prefer integrating
with the client's calendar (Google / Microsoft 365 / practice systems
via `web_call`) over building a calendar engine. Becomes a script step
`book_appointment`.

### P. Call activity documentation — P3

IS records operator screens. The plan 01 event log plus the call
recording gives a synchronised **script replay** (what the operator
saw and entered, when) at a fraction of the storage — better for QA
and disputes than video.

### Q. Code calls / mass notification — P3

Hospital switchboard feature (overhead paging, code teams). Only build
if a client needs it; plan 05's tiered dispatch to a group is the base.

### R. Web scripting for client staff — P3

Clients' own staff run a script in the portal (e.g. a clinic entering
an after-hours request). Once plan 02's runner exists this is mostly a
permission and a portal page.

### S. Operator message aids — P3

Spellcheck on free-text fields, readback prompt on save, phone/email
format validation (typed slots already in plan 01), optional AI
"clean up the reason" suggestion the operator can accept.

## Individual plans

Every item above has its own plan: A → 08, B → 09, C/T → 10,
D/I → 11, E → 12, F → 13, G → 14, H → 15, J → 16, K → 17, L → 18,
M → 19, N → 20, O → 21, P → 22, Q → 23, R → 24, S → 25, U → 26.
See the table in the [README](README.md).

## Questions for NCI

1. How do clients get billed today, and from which Amtelco reports?
   (Decides whether 09 is "export" or "rate plans".)
2. Which reports do clients receive now, and how often?
3. How many clients use check-in instead of push delivery?
4. How often do operators patch calls, and to whom?
5. Are wake-up / check-in call services sold today?
6. Any hospital switchboard clients (code calls)?

## Sources

- [Amtelco — Intelligent Series Platform Features](https://www.amtelco.com/resources/1calls-intelligent-series-platform-features/)
- [Amtelco — Better call management / advanced contact center software](https://www.amtelco.com/resources/better-call-management-advanced-contact-center-software-3/)
- [Amtelco — What is Smart Paging?](https://amtelco.com/resources/what-smart-paging/)
- [Connections Magazine — IS scheduling enhancements](https://connectionsmagazine.com/news/amtelco-introduces-is-scheduling-enhancements/)
