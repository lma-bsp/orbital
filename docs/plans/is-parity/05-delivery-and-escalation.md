# Plan 05: Message delivery, acknowledgement and escalation

**Status:** proposed · part of [IS parity](README.md) · depends on 03, 04

## Problem

Saving a `Message` notifies no one — no observer, listener or job.
Voicemail transcripts are the only thing emailed to clients
automatically. `send_sms`, `send_page`, `send_fax`, `send_wctp`,
`mark_message_sent/delivered`, `auto_dispatch`, `enter_dispatcher_queue`
exist only as catalogue entries / prompt text. `PostCallActions` runs
only `send_email` and `web_call`. `MessageQueue` declares SMPP / WCTP /
paging protocols but only Twilio and Log providers exist. There is no
retry, escalation or acknowledgement anywhere.

IS (with Amtelco's dispatch and Secure Messaging) delivers each message
by the recipient's preferred methods, tracks delivery and
acknowledgement, escalates until someone acknowledges, and puts
undelivered messages in front of an operator to work by phone.

## Goal

A dispatch engine that, for every message, decides **who** gets it
(directory entry, on-call group, client default), **how** (contact
methods), **when** (now, or hold until a window), and **keeps going**
until it's acknowledged or a human takes over — with a full timeline.

## Design

### Model

- `dispatch_policies` (`BelongsToTeam`, attachable to a message
  template, an orchestration step, or a client default): ordered
  **tiers**. Each tier: recipients (entry / on-call group position /
  client contact list), methods (`all` / `first available` / explicit),
  `require_ack`, `wait_minutes` before escalating, repeat count.
  Example: tier 1 on-call primary via SMS+page, ack within 10 min;
  tier 2 resend + voice call; tier 3 backup; tier 4 operator callout queue.
  Urgency selects the policy (urgent vs routine), and a schedule (plan
  06) can **hold** routine messages until business hours ("next
  morning delivery").
- `dispatches`: one per message per policy run — status
  (`pending/active/acknowledged/exhausted/cancelled/held`), current tier.
- `delivery_attempts`: dispatch, tier, recipient, method, address,
  transport, provider id, status (`queued/sent/delivered/failed/
  undeliverable`), timestamps, error. Plugs into the existing
  `MessageEntry`-style statuses.
- `acknowledgements`: who, how (reply keyword, link, portal button,
  phone call with operator, IVR keypress), when.

### Engine

- `DispatchMessage` job on message save (and on `send_*` / `auto_dispatch`
  script steps). Horizon queue `dispatch`, high priority.
- Escalation timer: delayed jobs per tier (`wait_minutes`), cancelled
  on ack. Idempotent — safe if a worker dies mid-tier; a scheduled
  sweeper (every minute) catches overdue tiers.
- Exhausted policies land in the **dispatcher queue**: an operator
  worklist ("undelivered urgent messages") with the contact procedure,
  one-click call-out via the softphone, and a "delivered by phone"
  acknowledgement. This is IS's dispatch screen and maps to
  `enter_dispatcher_queue`.

### Transports (`app/Services/Dispatch/Transports/`)

One interface: `send(Recipient, RenderedMessage): AttemptResult` plus
inbound status/ack webhooks.

| Method | First implementation |
|---|---|
| Email | Laravel mail (already relayed); HTML from plan 03 template; ack link |
| SMS | existing `MessagingProvider` (Twilio); reply `1`/`OK` = ack, matched by sender + open dispatch |
| Voice callout | AMI/LiveKit outbound call; TTS reads the message; "press 1 to acknowledge"; AI persona optional |
| Pager — WCTP | WCTP 1.3 client (most US carriers, e.g. Spok/USA Mobility); status polling |
| Pager — SNPP / TAP | later, if NCI clients need them (see TODO macro decoding) |
| Fax | provider API (later) |
| Secure messaging | MiSecure-style apps — open question; plugin interface only |
| Webhook / client system | reuse `web_call` endpoint auth |
| Portal | message appears in portal list (plan 03); "acknowledge" button |

Rendering uses the plan 03 per-channel templates; PHI-sensitive
templates send a secure link instead of content.

### Visibility

- Message timeline (operator, supervisor, portal): every attempt, status
  callback, escalation step, ack.
- Supervisor board: active dispatches, overdue acks (can live on the
  live-call board from `docs/plans/live-call-monitoring.md`).
- Metrics: time-to-ack per client, delivery failure rate per transport
  (pushgateway, existing Telephony dashboard).

## Tests

- Policy engine with fake transports and `travel()`: ack in tier 1
  stops escalation; no ack walks every tier then lands in dispatcher
  queue; held message releases at the schedule boundary; duplicate
  webhook doesn't double-ack.
- Each transport: contract tests with recorded fixtures (WCTP XML,
  Twilio callbacks).
- SMS ack matching with two open dispatches to the same phone.
- Tenant isolation on ack links and reply matching.
- Crash safety: kill mid-tier, sweeper resumes exactly once.

## Size

~4–5 weeks: engine + email/SMS/portal ack (2 wk), voice callout (1 wk),
WCTP (1 wk), dispatcher queue UI (0.5–1 wk). Other transports later.
