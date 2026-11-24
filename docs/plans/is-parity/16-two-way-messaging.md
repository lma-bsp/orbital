# Plan 16: Two-way messaging, reply matching and read receipts

**Status:** proposed · part of [IS parity](README.md) · depends on 03, 05
(uses 04 for "who is the recipient", 17 for secure links, 18 for
follow-up expiry) · expands survey item J in
[07](07-additional-features.md#j-two-way-interactive-messaging--p2)

## Problem

### What IS does

In Intelligent Series a dispatched message is a conversation, not a
one-way push. The doctor who gets a page or text can answer it: "OK",
"CALL ME", "Pt already seen", "Not on call, try Dr Lee". IS (with
Secure Messaging / Infinity dispatch) threads each answer onto the
message, shows it to the operator and the client, uses some answers
as acknowledgements, turns others ("call me") into work for an
operator, and records read and delivery status where the transport
reports it (secure app, WCTP). With several messages outstanding to
the same person, the reply still lands on the right one.

### What Orbital has today (checked in the code)

The **customer-facing** SMS channel is solid, and almost everything
below re-uses it. What doesn't exist is anything joining that channel
to an answering-service `Message`, or any outbound to a *recipient*.

- **No outbound to recipients at all.** Saving a `Message` sends
  nothing (plan 05). `Message` has `status` new/read/archived only
  (`app/Models/Message.php:29-33`) and links to the conversation it was
  *taken from* (`message_thread_id`, `Message.php:121`), not to anything
  it was *sent to*.
- **Inbound SMS pipeline** (reused as-is):
  `InboundMessageController` verifies the signature first
  (`app/Http/Controllers/Api/InboundMessageController.php:52`), applies
  delivery receipts inline (`:61-65`, `:111-136`) and queues
  `ProcessInboundMessageJob`. The job routes
  (`InboundMessageRouter::route`, `app/Services/Messaging/InboundMessageRouter.php:47`
  — sender pool first `:51-53`, then number `:56`), threads by
  (endpoint, remote address) (`MessageThreadResolver::resolve`,
  `app/Services/Messaging/MessageThreadResolver.php:44`, reopen window
  `:112`, `config/messaging.php:96`), applies STOP/START/HELP
  (`app/Jobs/ProcessInboundMessageJob.php:118`, `:178-226`), writes a
  `MessageEntry` idempotently on `(provider, provider_message_id)`
  (`:123-148`), fetches MMS (`FetchMessageMediaJob`) and maybe hands
  to the AI (`:236`).
- **Endpoints are client-owned.** `MessagingEndpoint` is `BelongsToTeam`
  and identified by sender pool (`app/Models/MessagingEndpoint.php:229`
  docblock, `resolveByPool` `:109`). `resolveByPool` returns
  `->first()` for a pool id — so one Twilio Messaging Service shared
  by several clients would silently route every reply to whichever
  client's row comes first. There is no notion of a platform-owned
  "dispatch" number.
- **No per-message sender choice.** `TwilioProvider::send` sends with
  `MessagingServiceSid` only and never sets `From`
  (`app/Services/Messaging/Providers/TwilioProvider.php:210-214`); the
  `$from` argument is ignored. Sticky sender picks the number, and we
  learn it afterwards via `SendResult::$sender`
  (`app/Services/Messaging/SendResult.php:35`). So "a different reply
  number per open message" is not possible today.
- **Delivery receipts** exist per entry (`MessageEntry` statuses
  queued/sent/delivered/undelivered/failed,
  `app/Models/MessageEntry.php:39-54`), but `StatusCallback` is only
  sent when an admin types `status_callback` into the endpoint's
  provider config (`TwilioProvider.php:219-221`; the only other mention
  is helper text in `ManageClientMessagingEndpoints.php:155`). There is
  no **read** status: Twilio's `read` (RCS/WhatsApp) falls into
  `default => null` and is dropped (`TwilioProvider.php:165`).
- **Opt-out is per client**, by design (`app/Models/MessagingOptOut.php`
  docblock; unique `(team_id, address_key)` in
  `database/migrations/2026_08_23_110000_create_messaging_opt_outs.php:84`).
  The single send chokepoint checks it
  (`OutboundMessageService::send`, `app/Services/Messaging/OutboundMessageService.php:71`, check at `:83`).
  Keyword sets: STOP includes **`cancel`** (`MessagingOptOut.php:40`),
  START includes **`yes`** (`:45`), whole-message match
  (`classify`, `:165`). Any non-`none` intent ends processing before
  anything else sees the message (`ProcessInboundMessageJob.php:165`).
- **Email threading** exists for client inboxes:
  `{account}.{function}@domain` local parts (`app/Services/Mail/LocalPartParser.php:41`),
  `InboundRouter` resolves the team from the account number and passes
  the function suffix through (`app/Services/Mail/InboundRouter.php:76-91`),
  `ThreadResolver` walks In-Reply-To / References within the team
  (`app/Services/Mail/ThreadResolver.php:119-147`), and
  `OutboundReplyService` stamps our own Message-ID and a
  `{account}@domain` From (`app/Services/Mail/OutboundReplyService.php:89-118`, `:177`).
  All of this is reusable for recipient email replies; none of it is
  pointed at `Message`s.
- **No WCTP driver.** Only `twilio` and `log` are registered
  (`config/messaging.php:29-32`); TODO.md "Messaging channel
  follow-ons → More transports" lists WCTP as remaining.
- **Operator surfaces** are customer-conversation surfaces:
  `MessageInbox` (visible threads `app/Filament/Operator/Pages/MessageInbox.php:126`)
  and `ViewMessageThread` (claim-to-reply `ViewMessageThread.php:85`,
  suppression banner `:98`, reply `:142`, take-message `:327`). Audit is
  `ConversationActivity::log` (polymorphic, `app/Models/ConversationActivity.php`),
  attached to threads via `HasConversationActivities`.
- **Portal** has `TextConversations` (`app/Filament/Portal/Pages/TextConversations.php`)
  and `CallHistory`, but no message list yet (plan 03 adds it). The
  permission `portal.view_messages` exists
  (`app/Services/Clients/ClientProvisioner.php:52`).
- **No operator task model.** Plan 05 describes a dispatcher queue but
  names no table; `OperatorCall` (uncommitted) is a per-desk call row,
  explicitly not tenant-scoped, and not a fit.

## Goal

Every reply a recipient sends about a dispatched message — by SMS/MMS,
WCTP pager, email, or the portal — is:

1. **matched** to the right `Message` and delivery attempt, even when
   that person has several open messages from one or more clients;
2. **classified** deterministically as acknowledgement, an action
   keyword (CALL ME, DECLINE, …), an opt-out/consent keyword, or free
   text;
3. **acted on** (ack stops escalation via plan 05; CALL ME creates an
   operator task; DECLINE escalates now; STOP disables that transport
   for that person and fails over);
4. **shown** on the message timeline for operators, supervisors and the
   client portal, together with delivered/read status where the
   transport reports it;
5. **answerable**: an operator can reply back to the recipient on the
   same channel, from the message.

Non-goals: a mobile app (plan 17 option 3), LLM-decided acks, two-way
on fax or voice callout (voice callout gets DTMF ack in plan 05).

## Design

### Vocabulary

- **Recipient** — whoever plan 05 dispatched to: a directory entry, an
  on-call position (plan 04) resolved to one, or a client contact.
- **Attempt** — plan 05 `delivery_attempts` row (one transport, one
  address).
- **Reply** — anything inbound from a recipient about an attempt.
- **Reference** — the thing that ties a reply to an attempt (token,
  sender number, WCTP id, email header/local part).

### Where outbound recipient traffic goes

Plan 05's SMS transport does **not** call the provider directly. It
calls a new `OutboundMessageService::sendDispatch(DeliveryAttempt, RenderedMessage)`
so the opt-out check at `OutboundMessageService.php:83` stays the single
chokepoint (that file's own docblock asks for exactly this). The entry
it writes is a normal `MessageEntry` with two new nullable FKs, so
delivery receipts (`InboundMessageController.php:111`) and media fetch
work unchanged.

### Data model

All new tables `BelongsToTeam` unless stated. Migrations use
`declare(strict_types=1)`.

**`messaging_endpoints`** (existing)

- `purpose` enum `conversation` (default, today's behaviour) |
  `dispatch` | `both`.
- `team_id` becomes nullable **only** for `purpose = dispatch`:
  a platform dispatch pool owned by the answering service, shared by
  all clients. Enforced by a check constraint
  (`team_id IS NOT NULL OR purpose = 'dispatch'`). Model scope: platform
  endpoints are invisible to tenant users (the `BelongsToTeam` scope
  already hides `team_id` null rows from non-super-admins, see
  `app/Models/Concerns/BelongsToTeam.php:30-35`).
- `reply_strategy` enum `token` | `sender_rotation` | `token_and_rotation`
  (default `token`).
- `resolveByPool` (`MessagingEndpoint.php:109`) gains a guard: if more
  than one active row shares `(provider, sender_pool_id)` it logs an
  error and returns null instead of `first()` — see *Bugs noticed*.

**`messaging_pool_numbers`** (platform table, not tenant-scoped)

- `messaging_endpoint_id`, `address` (E.164), `capabilities`,
  `is_active`, `synced_at`. Filled by a new optional provider method
  (below) on a schedule, so sender rotation knows which numbers exist.

**`message_threads`** (existing)

- `kind` enum `conversation` (default) | `dispatch`. Dispatch threads
  hold the raw SMS traffic between a dispatch endpoint and one
  recipient address **for one client** (identity
  `(endpoint, remote_address, team_id, kind)`), so a doctor serving two
  clients from the shared pool has two dispatch threads and neither
  client sees the other's traffic.
- `MessageInbox` and portal `TextConversations` filter `kind =
  conversation`; dispatch threads appear only through the message
  timeline.

**`message_entries`** (existing)

- `message_id` nullable FK, `delivery_attempt_id` nullable FK.
- `delivery_status` gains `read` (outbound only; finality 4 — above
  delivered).
- `reply_reference` nullable string — the token or sender number this
  outbound carried, for debugging.

**`dispatch_reply_references`** (`BelongsToTeam`)

One row per outbound attempt that can be replied to.

| column | notes |
|---|---|
| `delivery_attempt_id`, `message_id`, `dispatch_id` | FKs (plan 05) |
| `channel` | `sms` / `mms` / `wctp` / `email` |
| `recipient_address_key` | `MessagingOptOut::key()` (`MessagingOptOut.php:121`) of the address we sent to |
| `endpoint_id` | sending endpoint |
| `token` | SMS: 3 chars from `ACDEFHJKMNPQRTUVWXY3479` (no 0/O/1/I/L/S/5/B/8/G/6), unique among **open** references for `(endpoint, recipient_address_key)`; email: 16 chars base32 (~80 bits) |
| `sender_address` | number we pinned (rotation) or the one the carrier picked |
| `external_ref` | WCTP `messageID`, email `Message-ID` |
| `opens_at`, `expires_at` | reply window; default `message closed` or 72 h after last attempt (`config('messaging.reply_window_hours')`) |
| `closed_at` | set when the message is closed/acked-and-done |

Indexes: `(endpoint_id, recipient_address_key, closed_at)`,
`(endpoint_id, recipient_address_key, token)` partial unique where
`closed_at is null`, `(endpoint_id, sender_address, recipient_address_key)`
partial where open, `(channel, external_ref)`.

Lookups from the inbound job run with `withoutGlobalScopes()` (queued
jobs have no auth user, so the scope is a no-op anyway, but being
explicit mirrors `MessageThreadResolver.php:94-102`) and always filter
by endpoint and recipient address first. The team is **derived from the
matched row**, never from the endpoint when the endpoint is a platform
pool.

**`message_replies`** (`BelongsToTeam`) — the semantic record shown on
timelines.

| column | notes |
|---|---|
| `message_id`, `dispatch_id`, `delivery_attempt_id` | nullable until matched |
| `direction` | `inbound` (recipient → us) / `outbound` (operator → recipient) |
| `channel` | `sms` `mms` `wctp` `email` `portal` `voice_dtmf` |
| `recipient_type`/`recipient_id` | directory entry / contact / user (morph) |
| `from_address`, `to_address` | raw |
| `body`, `body_clean` | `body_clean` = quoted text and signatures stripped (email) |
| `message_entry_id` / `email_message_id` | the raw transport row |
| `matched_by` | `token`, `sender_number`, `wctp_ref`, `email_header`, `email_local_part`, `single_open`, `operator` |
| `match_confidence` | `exact` / `inferred` / `manual` |
| `intent` | `ack`, `keyword`, `free_text`, `consent`, `unmatched` |
| `keyword_id` | FK to `reply_keywords` |
| `actions_taken` | JSON list (acked, task created #, escalated, opted out) |
| `sent_by_user_id` | outbound only |
| `received_at` | provider timestamp when available |

**`unmatched_replies`** (platform table, **not** `BelongsToTeam`,
staff-only): replies on a platform dispatch endpoint that match no open
reference. Holds the raw entry/email id, from, body, candidate message
ids (only ids, rendered only to platform staff), `resolved_by_user_id`,
`resolved_reply_id`. Replies on a client-owned endpoint that don't match
are not "unmatched" — they fall through to today's conversation
pipeline (it's that client's number; a text from a doctor is still a
text to the client).

**`reply_keywords`** (`BelongsToTeam`, `team_id` null = platform
default, same pattern as plan 03 templates)

| column | notes |
|---|---|
| `keyword`, `aliases` | normalised like `MessagingOptOut::classify` (`:165`) |
| `menu_digit` | optional: `1`, `2`, `3` for "Reply 1=OK 2=CALL ME" |
| `action` | `ack`, `call_me`, `decline`, `snooze`, `forward_to_backup`, `note` |
| `acknowledges` | bool — does it also count as ack (default true for `call_me`, false for `decline`) |
| `params` | e.g. snooze minutes, task priority |
| `confirmation_text` | optional reply ("Got it — an operator will call you shortly.") |
| `is_active`, `sort` | |

Validation (form request + model `saving` guard): a keyword or alias
may not equal any of `MessagingOptOut::STOP_KEYWORDS`,
`START_KEYWORDS`, `HELP_KEYWORDS`. This rules out **YES** and **CANCEL**
as ack/decline words — the plan's defaults use `OK`, `1`, `ACK`, `CALL`,
`CALL ME`, `NO`, `DECLINE` and digits, none of which collide.

Seeded platform defaults: `OK`/`ACK`/`1` → ack; `CALL ME`/`CALL`/`2` →
call_me (acknowledges); `NO`/`DECLINE`/`3` → decline; `LATER 15`/`4` →
snooze 15.

**`operator_tasks`** (`BelongsToTeam`) — the minimum plan 05's
dispatcher queue needs and plan 18 extends. If plan 05/18 land first
with their own table, use theirs and add the `type` values below.

`type` (`callback_requested`, `reply_review`, `contact_method_failed`,
`unmatched_reply` — the last one with `team_id` null, platform only),
`message_id`, `message_reply_id`, `dispatch_id`, `target_address`,
`target_label`, `priority` (from message urgency), `due_at`,
`status` (`open/claimed/done/cancelled`), `claimed_by_user_id`,
`claimed_at`, `completed_at`, `outcome`, `expires_at` (plan 18).
Claim uses the same single conditional `UPDATE` as
`MessageThread::claimFor` (`MessageThread.php:123`).

**`dispatch_attempts` / plan 05 `acknowledgements`** — no schema change
beyond `acknowledgements.message_reply_id` and `via` values `sms_reply`,
`wctp_reply`, `wctp_read` (if policy treats read as ack),
`email_reply`, `portal_reply`.

### Provider contract changes (`app/Services/Messaging/Contracts/MessagingProvider.php`)

Additive; drivers that don't support a feature return the neutral value.

- `supportsPinnedSender(): bool` — can `send()` honour `$from` inside a
  pool. Twilio: yes (pass `From` alongside `MessagingServiceSid`; the
  number must belong to the service).
- `send()` honours `$options['pin_sender'] = true` by sending `From`.
  Default stays pool-only, so today's behaviour and its compliance
  reasoning (`TwilioProvider.php:194-208`) are unchanged.
- `listPoolNumbers(array $options): ?array` — for `messaging_pool_numbers`
  sync (Twilio Messaging Service PhoneNumbers resource).
- `parseDeliveryReceipts()` may return status `read`; Twilio maps
  `read` → `MessageEntry::STATUS_READ` instead of dropping it.
- `InboundMessage` gains `?string $inReplyToRef` (WCTP
  `responseToMessageID`, RCS reply-to) and `?string $choice` (WCTP
  multiple-choice response).
- `TwilioProvider` always sets `StatusCallback` to our own webhook URL
  (route `/api/messaging/inbound/twilio`) unless config overrides it, so
  receipts don't depend on an admin remembering a JSON key.

New driver **`WctpProvider`** (`config/messaging.php` `drivers.wctp`)
— WCTP 1.3 over HTTPS: `wctp-SubmitClientMessage` with
`notifyWhenDelivered`/`notifyWhenRead`, `allowResponse`, multiple-choice
responses built from the client's `reply_keywords` menu;
`wctp-StatusInfo` (delivered/read) → receipts; `wctp-MessageReply`
→ `InboundMessage` with `inReplyToRef`. Pull-mode carriers: a
`PollWctpGatewayJob` every minute. `requiresSenderPool()` false.
This is the WCTP transport plan 05 sized at ~1 week; this plan adds
only the reply/read half (~2 days on top).

### Services (`app/Services/Messaging/Replies/`)

- **`ReplyReferenceIssuer`** — called by plan 05 transports before
  rendering. For SMS: picks a token (retry on unique violation) and,
  for `sender_rotation`, a pool number not currently carrying an open
  reference to this recipient (`SELECT … NOT IN open refs`, ordered by
  least recently used); if none free, falls back to token. Writes
  `dispatch_reply_references`. Returns what the template renders:
  `{{ reply.token }}`, `{{ reply.menu }}` ("Reply 1=OK 2=CALL ME"),
  `{{ reply.instructions }}`.
  Token placement: prefixed **only when** the recipient has ≥ 2 open
  references on that endpoint, or always when the client sets
  `always_show_reply_code`. A single-message recipient sees no code —
  shorter texts, and the 160-char budget from plan 03 holds.
- **`ReplyMatcher`** — `match(InboundMessage|EmailMessage, endpoint): MatchResult`.
  Order, first hit wins:
  1. **Transport reference** — WCTP `inReplyToRef` → `external_ref`;
     email `In-Reply-To`/`References` → `external_ref`.
  2. **Email local part** — function suffix `r-<token>` from
     `LocalPartParser` (`{account}.r-<token>@inbound`). Case-insensitive
     (some MTAs lowercase local parts).
  3. **Token in body** — regex at start or end of the body:
     `^\s*#?([A-Z0-9]{3})\b` / `\b#?([A-Z0-9]{3})\s*$`, case-folded,
     only accepted if that token is open for
     `(endpoint, sender address key)`. A three-letter word that is not
     an open token ("ack", "yes") is not consumed.
  4. **Sender number** — the number they texted (`InboundMessage::$to`)
     equals an open reference's `sender_address` for this recipient.
  5. **Single open** — exactly one open reference for
     `(endpoint, recipient)` → match, `confidence = inferred`.
  6. Otherwise **ambiguous** (≥ 2 open) or **none**.
  The recipient check is always on: a token from a different phone
  number never matches (tokens are per recipient, so a 3-char space is
  safe). For email, steps 1–2 match, but the reply only counts as an
  **ack** if `From` matches the recipient's address on file (or a
  verified alternate); otherwise it's recorded as `free_text` with a
  "sender not verified" badge — inbound email is trivially spoofable
  and an ack stops escalation on a possibly urgent message.
- **`ReplyClassifier`** — after stripping a matched token, normalise
  like `MessagingOptOut::classify` and look up the client's
  `reply_keywords` then platform defaults (client wins). Accepts
  `KEYWORD [args]` (`LATER 30`). Anything else is `free_text`. No LLM
  on this path. (Optional later: LLM *suggests* an intent on free text
  for the operator reviewing it; never auto-acts — README "deterministic
  first".)
- **`ReplyActionRunner`** — executes the keyword's action inside one
  DB transaction with a row lock on the dispatch:
  - `ack` → plan 05 `acknowledge(dispatch, via, reply)`; cancels
    pending tier jobs. Idempotent (second "OK" logs a reply, no second
    ack).
  - `call_me` → `operator_tasks` `callback_requested`, target = the
    address they replied from if it's a phone, else the recipient's
    primary phone from the directory; `acknowledges` → ack as above.
    Due now; urgent messages get `priority = high` and ring the
    supervisor board after `config('messaging.call_me_sla_minutes', 5)`.
  - `decline` → plan 05 `escalateNow(dispatch, reason)`; no ack.
  - `snooze` → push current tier's timer by N minutes (bounded by
    policy max), no ack; confirmation text tells them when.
  - `forward_to_backup` → escalate to the next tier recipient, record
    who declined.
  - `free_text` → timeline only, plus `reply_review` task when the
    client setting `review_free_text_replies` is on (default on for
    urgent messages) — a doctor writing "pt allergic to PCN, tell the
    family" needs an operator to read it.
  - Optional `confirmation_text` goes back through
    `OutboundMessageService::replyOnDispatchThread()` (same opt-out
    chokepoint).
- **`DispatchReplyRecorder`** — writes `message_replies`, links the raw
  row, logs `ConversationActivity` on the **Message** (see Audit).

### Jobs and pipeline changes

`ProcessInboundMessageJob` (existing) becomes:

1. Route (`InboundMessageRouter::route`) — now returns
   `purpose`. For a **platform dispatch** endpoint, `team_id` is null at
   this point.
2. **Consent first, unchanged position** (`:118`). For a platform
   endpoint, the opt-out is recorded at the endpoint level (see
   *Opt-out interplay*). Then, if intent ≠ none, we still record a
   `message_replies` row with `intent = consent` against every open
   reference for this recipient on this endpoint (so the timeline shows
   "Dr Smith texted STOP"), and stop — no keyword actions, no AI.
3. If the endpoint's purpose is `dispatch` or `both`:
   `ReplyMatcher` → on match, resolve/create the per-client dispatch
   thread, write the entry (same idempotency as `:123-148`), then
   `HandleDispatchReplyJob` (queue `dispatch`, plan 05's high-priority
   queue). On ambiguous/none:
   - platform endpoint → no thread is created (`message_threads.team_id`
     stays required); the raw payload goes into `unmatched_replies` with
     an `unmatched_reply` task. Optional auto-reply listing open codes
     (see Edge cases).
   - `both` endpoint → fall through to today's conversation path.
4. Conversation endpoints: unchanged.

New jobs:

- `HandleDispatchReplyJob(message_entry_id | email_message_id)` —
  classify → act → record. Unique per raw row (`ShouldBeUnique`) so a
  carrier retry can't double-act.
- `SyncPoolNumbersJob` — hourly, per dispatch endpoint.
- `ExpireReplyReferencesJob` — every 5 min; closes references past
  `expires_at` or whose message is closed. Late replies after expiry
  go through the matcher's "closed reference" branch (Edge cases).
- `PollWctpGatewayJob` — only for pull-mode WCTP endpoints.

Email: `ProcessInboundEmailJob` (existing) gets one branch after
routing: if `$routed['function']` starts with `r-`, or the
`In-Reply-To` matches a `dispatch_reply_references.external_ref` for
that team, hand to `HandleDispatchReplyJob` and **do not** create an
`EmailThread` (it would land in the client's email inbox as a new
conversation). The `EmailMessage` row is kept for the raw record with
`routing_status = 'dispatch_reply'`. Plan 05's email transport sends
from `{account}@domain` (as `OutboundReplyService.php:177` does), with
`Reply-To: {account}.r-{token}@domain` and our own Message-ID stored as
`external_ref`. Quoted-text stripping: a small `ReplyBodyCleaner`
(common "On … wrote:", `>` blocks, Outlook `From:` separators,
signatures after `-- `); keyword detection reads only the first
non-empty line of `body_clean`.

Portal: a recipient who is also a portal user (contact with portal
access) can reply from the message detail page; that is a
`message_replies` row with `channel = portal` (no matching needed — the
reply is made on the message itself), and the same classifier runs on it so "CALL ME" typed in the portal behaves the
same.

### Read receipts

Status is per **attempt**, mirrored to `MessageEntry.delivery_status`
for SMS and to `delivery_attempts.status` for every transport.

| Channel | Delivered | Read | Source |
|---|---|---|---|
| SMS (Twilio) | yes (carrier DLR) | no | `MessageStatus` |
| RCS / WhatsApp (Twilio, later) | yes | yes (`read`) | `MessageStatus=read` |
| WCTP | yes (`notifyWhenDelivered`) | yes on devices that support it (`notifyWhenRead`) | `wctp-StatusInfo` |
| Email | SMTP accept only | **link click** on the plan 17 secure link / "view message" link; no tracking pixels (blocked by most clients, a privacy problem with PHI) | our link route |
| Portal | n/a | first view of message detail by that recipient | portal page |
| Secure app (plan 17) | app-reported | app-reported | plugin |

Rules:

- Read is shown, never assumed. "Delivered" on SMS is labelled
  "Delivered to phone", not "Seen".
- Whether **read counts as ack** is a dispatch-policy flag (plan 05
  tier field `read_is_ack`, default false). IS sites differ; hospitals
  usually require an explicit ack.
- `InboundMessageController::finality` (`:141`) gets `read => 4`. A
  `read` after `delivered` advances; a late `delivered` after `read`
  doesn't regress.

### Opt-out interplay

1. **Client-owned endpoint** (purpose `dispatch`/`both`): today's
   per-client suppression applies unchanged. A doctor who texts STOP
   to the clinic's own number is suppressed for that clinic.
2. **Platform dispatch pool**: the carrier enforces STOP across the
   whole Messaging Service — i.e. for every client sharing the pool.
   Orbital records it the same way so its own view matches the
   carrier's: `messaging_opt_outs` gets `scope` (`team` | `endpoint`);
   endpoint-scoped rows have `team_id` null and
   `messaging_endpoint_id` set. Replace the unique
   `(team_id, address_key)` with two partial unique indexes.
   `OptOutRegistry::isSuppressed` (`OptOutRegistry.php:33`) gets an
   endpoint-aware overload; `sendDispatch` checks both scopes.
   Platform-scope opt-out rows are visible to platform staff only; each
   affected client sees "SMS to this contact is blocked (they opted out
   of texts from the answering service)" on the directory entry and in
   plan 05's dispatch screen, without seeing other clients.
3. **What happens to open dispatches**: a STOP immediately marks every
   open SMS attempt to that address on that endpoint as
   `undeliverable (opted out)`, closes the references, and calls plan
   05 `failover(attempt)` → next method in the tier (pager, voice,
   email) or next tier. A `contact_method_failed` task tells the
   operator / client admin to fix the contact procedure. We **never**
   send an SMS asking them to reconsider.
4. **START/YES** lifts the suppression (existing `optIn`), and is
   **never** treated as an ack — `YES` stays a consent word
   (`MessagingOptOut.php:45`), which is why keywords can't use it.
5. **HELP** — carrier answers; we record a consent reply, no action.
6. **`CANCEL`** is a STOP word (`:40`). A recipient replying "CANCEL"
   meaning "cancel the appointment" opts out. We don't change the
   CTIA list; we (a) keep CANCEL out of every keyword set, and (b) show
   the opt-out prominently on the message timeline so an operator can
   call the recipient. Open question 4.
7. Opt-out is **transport-scoped**: SMS STOP doesn't stop WCTP pages or
   email; email has its own unsubscribe rules (not applicable to
   transactional dispatch to the client's own staff — open question 5).
8. Suppressed-at-send is recorded as a failed attempt (as
   `OutboundMessageService.php:83-100` already does) so the plan 05
   engine fails over instead of waiting for an ack that can't come.

### UI — operators and supervisors

- **Message timeline** (operator message view from plan 03, and the
  workspace message list `app/Filament/Operator/Pages/Workspace.php`
  ~`:359`): one chronological list merging plan 03 revisions, plan 05
  attempts/escalations/acks, and `message_replies`. Each reply shows
  channel icon, who, matched-by badge (`exact`/`inferred`/`manual`),
  intent chip (ACK / CALL ME / DECLINE / note / STOP), the action taken,
  and media (via `MessageEntry::mediaItems()`, signed links,
  `MessageEntry.php:128`). Read/delivered ticks on each outbound attempt.
- **Reply to recipient**: composer on the message timeline, channel
  defaults to the one they last replied on; uses the same pinned sender
  / token so their answer threads back. Blocked with the existing
  banner copy when suppressed (`ViewMessageThread.php:98-104` pattern).
  Not claim-gated like `ViewMessageThread::canReply` (`:85`); instead
  gated on permission + "message is open".
- **Task list**: "Callbacks & replies" page in the operator panel
  (beside `MessageInbox`), polling like the inbox, sidebar badge cached
  like `MessageInbox::getNavigationBadge` (`:72`). Columns: client,
  type, recipient, message summary, age vs SLA, claimed by. Claim →
  one-click call via softphone (plan 08 patching later) → outcome
  (`reached`, `left voicemail`, `no answer`) → done. "No answer"
  re-queues with a back-off.
- **Unmatched replies** (platform staff/supervisors only): shows the
  reply and the candidate open messages *for that sender only*; actions
  "attach to message", "not about a message", "spam". Attaching runs
  the classifier as if it had matched (`matched_by = operator`).
- **Supervisor board** (`docs/plans/live-call-monitoring.md`): count of
  open CALL ME tasks past SLA, unmatched replies, recent STOPs on the
  platform pool.

### UI — client portal

- Message detail (plan 03) gets the same timeline, filtered: replies,
  acks, delivered/read, escalations — not internal notes, not
  `unmatched_replies`, not other clients' anything. `free_text` replies
  shown verbatim; media links only with `portal.view_messages`.
- Optional "Reply" for client staff who were recipients, gated on a new
  `portal.reply_messages` permission (seeded for `client_admin` only).
- Client admin settings: reply keywords (override platform defaults),
  `always_show_reply_code`, `review_free_text_replies`, confirmation
  texts. Validation forbids consent keywords (see model).

### Permissions and tenant isolation

- New models: `MessageReply`, `DispatchReplyReference`, `ReplyKeyword`,
  `OperatorTask` use `BelongsToTeam`. `ReplyKeyword` with `team_id`
  null is a platform default and needs the same "or null" read scope
  plan 03 uses for shared templates.
- Platform-only models: `UnmatchedReply`, `MessagingPoolNumber` — no
  `BelongsToTeam`, access only via super_admin/supervisor policies, and
  never queried from portal code.
- **Team derivation rule**: on a platform endpoint, the team comes from
  the matched `dispatch_reply_references` row; nothing is written to a
  tenant table before a match. On a client endpoint, the team is the
  endpoint's, and a matched reference must have the same `team_id` or
  the match is rejected (logged as a security event).
- Permissions (Spatie, registered alongside existing ones):
  `operator.tasks.work`, `operator.messages.reply_recipient`,
  `operator.replies.triage` (platform), `admin.reply_keywords.manage`
  (platform defaults), `portal.manage_reply_keywords`,
  `portal.reply_messages`.

### Audit

- `Message` gets `HasConversationActivities`
  (`app/Models/Concerns/HasConversationActivities.php`) so the existing
  `ConversationActivity::log` works on it: actions
  `reply_received`, `reply_matched_manually`, `acknowledged_by_reply`,
  `callback_task_created`, `task_claimed`, `task_completed`,
  `escalated_by_decline`, `recipient_opted_out`, `reply_sent`,
  `read_receipt`.
- Every automatic action records the reply id and keyword in
  `metadata`; manual actions record the user. `unmatched_replies`
  resolution records who attached it to which message.
- Raw transport rows are never deleted by this plan; retention follows
  message retention.

## Edge cases

- **Two open messages, bare "OK"** — ambiguous. No ack on either and
  nothing written to either message; create an `unmatched_reply` task
  and (per-client setting, default on) auto-reply once per 10 minutes:
  "You have 2 open messages. Reply with the code: K7Q (Smith,
  callback) or M3P (Jones, refill)." Every listed message was already
  sent to this same recipient, so nothing new is disclosed; summaries
  use the template's SMS summary line, and PHI-flagged templates
  (plan 17) show only "message from <client name>" plus the code.
- **Rotation pool exhausted** (more concurrent open messages to one
  person than numbers): fall back to tokens on the extras; log a
  capacity warning on the endpoint.
- **Sticky sender vs pinned sender**: pinning `From` bypasses sticky
  sender only for that send; carrier-side STOP still applies per
  service. Verify A2P campaign allows multiple numbers per recipient
  (open question 2).
- **Reply after expiry / after ack**: reference closed → matcher checks
  recently closed refs (last 7 days) for that recipient; match is
  recorded as a late reply on the timeline with no actions except
  CALL ME (still creates a task — a doctor asking to be called is never
  ignored).
- **Reply from a different number** (doctor texts from personal
  phone): no match by design; goes to unmatched triage where an
  operator can attach it.
- **Shared directory entries** (`BelongsToTeamOrSharedPool`,
  `app/Models/Concerns/BelongsToTeamOrSharedPool.php`): the same doctor
  serves several clients. Matching is by reference → message →
  team, never by directory entry, so this is safe.
- **Carrier retries / duplicate webhooks**: entry idempotent on
  `(provider, provider_message_id)` (existing unique index), job
  `ShouldBeUnique`, ack idempotent.
- **Out-of-order**: reply arrives before our outbound row commits
  (fast phones, slow DB) — `HandleDispatchReplyJob` retries with
  back-off when the reference isn't found and the sender has an attempt
  created in the last 60 s.
- **MMS reply** (photo of a wound): `FetchMessageMediaJob` unchanged;
  media shown on timeline/portal via signed links.
- **Multi-segment / concatenated SMS**: carriers deliver as one body
  normally; if split, two entries within 5 s from the same sender are
  merged for classification.
- **Email auto-replies / out-of-office**: `Auto-Submitted`,
  `X-Autoreply`, `Precedence: bulk` → recorded as `free_text`, flagged
  auto, never classified as a keyword, never ack. An OOO on an urgent
  message creates a `contact_method_failed` task.
- **Forwarded email** (doctor forwards to a colleague who replies):
  sender mismatch → recorded, not an ack.
- **Keyword inside a sentence** ("ok but call me after 5"): whole-
  message match fails → free text → review task. Deliberate: the same
  reasoning as `MessagingOptOut::classify` ("stop by at four").
- **Token collides with a keyword** (`ACK` as a token): the issuer
  rejects tokens equal to any active keyword, alias or consent word
  for that client, and the alphabet has no `O`/`I` so common words
  ("OK", "HI") can't be tokens.
- **Pager devices that only send canned responses**: WCTP multiple
  choice carries the keyword set; map `choice` straight to a keyword.
- **Client disables two-way**: outbound omits reply instructions;
  inbound replies are still recorded (we can't stop people replying),
  no actions except consent.
- **Message closed while a CALL ME task is open**: task stays open; a
  doctor's callback request outlives the message lifecycle.

## Migration / rollout

1. **Phase 0 (fixes, independent)**: `resolveByPool` ambiguity guard,
   Twilio `StatusCallback` default, `read` status + finality, inbound
   job idempotency ordering (see Bugs noticed).
2. **Phase 1 — SMS replies on client endpoints** (no platform pool yet):
   schema (`purpose`, `kind`, entry FKs, references, replies, keywords,
   tasks), `sendDispatch`, issuer (token only), matcher steps 3/5,
   classifier, action runner, timeline, task list. Feature flag
   `messaging.two_way_enabled` (default off) + per-client toggle.
3. **Phase 2 — email replies**: Reply-To local-part tokens, header
   match, body cleaner, auto-reply detection.
4. **Phase 3 — platform dispatch pool**: nullable `team_id` endpoints,
   endpoint-scoped opt-outs (migration rewrites the unique index; no
   data change for existing rows, all `scope = team`), unmatched-reply
   triage, sender rotation + pool sync.
5. **Phase 4 — WCTP** replies and read receipts (with plan 05's WCTP
   transport), portal reply, optional LLM suggestion on free text.

Data migration: existing endpoints get `purpose = conversation`,
existing threads `kind = conversation`; nothing else changes. Seed
platform default keywords. Back-fill nothing — there's no historical
dispatch traffic.

Rollout at NCI: enable per client, starting with one medical client
on its own number, compare reply match rate (target ≥ 98 % auto-matched
with tokens shown only when ≥ 2 open) before enabling the shared pool.
Before enabling, re-run the Amtelco directory import to make sure
recipients' SMS numbers are populated (TODO.md "Decode the remaining
Amtelco dial macros" — pager macros are needed before WCTP replies are
useful).

## Tests

Feature tests unless noted; fake provider is the `log` driver plus a
`FakeMessagingProvider` recording pinned `From`; `travel()` for timers.

**Matching**

1. One open message to Dr A, reply "OK" from Dr A's number → ack via
   `single_open`, escalation timers cancelled, timeline shows reply.
2. Two open messages (tokens K7Q, M3P), reply "M3P OK" → acks M3P only;
   K7Q still escalates.
3. Two open, reply "ok m3p" (token at end, lower case) → same.
4. Two open, bare "OK" → no ack on either, `unmatched_reply` task,
   optional disambiguation SMS sent once (not on a second bare OK
   within 10 min).
5. Sender rotation: two open messages sent from +1555…01 and …02,
   reply "OK" to …02 → acks the second.
6. Token from a different phone number → no match.
7. Token that was open but closed 2 days ago + "CALL ME" → late reply,
   task created, no ack.
8. Email reply with In-Reply-To = our Message-ID, From = recipient →
   ack. Same with From ≠ recipient → recorded, not ack, badge shown.
9. Email reply to `12345.r-<token>@inbound` with headers stripped →
   matched by local part; local part lower-cased by MTA → still matches.
10. Outlook reply "OK\n\nFrom: Orbital …" → `body_clean` "OK" → ack.
11. Out-of-office auto-reply → not ack, `contact_method_failed` task on
    urgent message.
12. WCTP `wctp-MessageReply` with `responseToMessageID` and MC choice 2
    → CALL ME task (contract test with recorded XML fixture).

**Keywords and actions**

13. "CALL ME" → `operator_tasks` callback_requested, target = reply
    number, acked; past SLA → supervisor alert.
14. "NO" → immediate escalation to tier 2, no ack, timeline shows who
    declined.
15. "LATER 15" → tier timer moved by 15 min, capped at policy max.
16. Client keyword overrides platform default ("DONE" = ack for client
    A only); client B replying "DONE" → free text.
17. Saving a keyword `YES`, `CANCEL`, `STOP` or `HELP` → validation
    error.
18. "ok but call me after 5" → free text, `reply_review` task (urgent).
19. Duplicate webhook for the same reply → one reply row, one ack, one
    task.

**Opt-out**

20. Recipient texts STOP on client endpoint → suppression for that
    client, open SMS attempts marked undeliverable, plan 05 fails over
    to pager, `contact_method_failed` task, timeline shows STOP.
21. STOP on platform pool → endpoint-scoped suppression; client B's
    next dispatch to the same doctor skips SMS and fails over; client B
    sees "blocked" but not client A's message.
22. "YES" after STOP → opt-in, no ack on any open message.
23. "CANCEL" → treated as STOP (documented behaviour), operator sees it.
24. `sendDispatch` to a suppressed address → failed attempt recorded,
    provider never called (assert fake provider received nothing).

**Read receipts**

25. Twilio `MessageStatus=read` → entry `read`; later `delivered`
    doesn't regress it.
26. WCTP read notification with `read_is_ack = true` → ack; with
    false → only status.
27. Email secure-link click → read; portal first view by recipient →
    read; view by a different portal user → not the recipient's read.

**Tenant isolation**

28. Doctor on a shared directory serving clients A and B, both with
    open messages via the platform pool, replies with A's token → only
    A's message changes; B's portal shows nothing new.
29. Reply on client A's endpoint that carries a token belonging to
    client B's reference → rejected, security log, no write to B.
30. Portal user of client A cannot load B's `message_replies`, tasks or
    references (direct URL / Livewire action tampering).
31. `unmatched_replies` not reachable from the portal panel or by an
    `operator` without `operator.replies.triage`.
32. Disambiguation SMS on the platform pool lists only that recipient's
    own open messages.

**Operator UI (Livewire)**

33. Two operators claim the same callback task simultaneously → one
    wins (conditional UPDATE).
34. Operator replies to recipient from the message timeline → sent from
    the same pinned number / with token; recipient's answer threads back.
35. Supervisor attaches an unmatched reply to a message → classifier
    runs, ack applied, audit records the supervisor.

**Answering-service scenarios (end to end, fake transports)**

36. After-hours urgent: AI takes message → SMS to on-call (plan 04) →
    "CALL ME" → operator task → operator completes with "reached" →
    message timeline complete, portal shows reply and outcome.
37. Two urgent messages to the same on-call within 2 minutes → both
    texts carry codes → doctor acks one, declines the other → second
    escalates to backup only.
38. No reply in 10 min → tier 2 → reply arrives on tier 1 SMS after
    tier 2 started → still matched, acks, cancels tier 2 pending
    attempts.

## Open questions

1. Does NCI want the **shared platform pool** (one set of numbers, all
   clients) or a number per client? The shared pool is cheaper and is
   how Amtelco sites usually page; per-client is simpler for
   isolation and opt-out. The design supports both; Phase 3 is only
   needed for the shared pool.
2. A2P 10DLC: does NCI's campaign registration allow sending the same
   recipient messages from several pool numbers (sender rotation), or
   should we use tokens only? Tokens alone are enough functionally.
3. Should **read count as ack** by default for WCTP pagers? IS sites
   vary; default proposed: no.
4. `CANCEL` as STOP: keep the CTIA set unchanged (proposed), or let
   dispatch-only endpoints narrow it? Narrowing has regulatory risk;
   ask counsel.
5. Email dispatch to clients' own staff — transactional, so no
   unsubscribe link? Confirm with NCI's compliance view.
6. Should free-text replies be forwarded to the **client** (e.g.
   email to the office manager) in addition to the portal timeline?
7. Which secure-messaging app (MiSecure, seen in the directory import)
   needs read/reply interop? Belongs to plan 17, but the reply model
   here should accept it as a `channel`.
8. Plan 05 and 18 naming: if they define the dispatcher queue table
   first, `operator_tasks` merges into it.

## Bugs noticed while reading the code

- `MessagingEndpoint::resolveByPool` (`MessagingEndpoint.php:109-121`)
  returns `first()` without uniqueness on `(provider, sender_pool_id)`
  — two endpoints sharing a pool route every inbound to an arbitrary
  one, i.e. client A's customer's texts into client B's inbox. Needs a
  unique index or the ambiguity guard above.
- `ProcessInboundMessageJob` resolves (and saves) the thread at `:108`
  before the idempotency check at `:123-133`, so a carrier retry of an
  already-processed message reopens a closed thread and clears its
  assigned operator (`MessageThreadResolver.php:57-61`).
- `InboundMessageController::applyDeliveryReceipt` compares with `<`
  (`:128`), so equal-finality states overwrite each other: a late
  `failed` turns `delivered` into `failed` and vice versa.
- Twilio delivery receipts only arrive if `status_callback` is set in
  the endpoint's JSON config (`TwilioProvider.php:219`) or on the
  Messaging Service in Twilio's console; nothing sets it by default, so
  per-entry delivery state can silently stay `sent`.
- Portal permissions `portal.view_calls`, `portal.view_messages`,
  `portal.view_recordings` are granted
  (`ClientProvisioner.php:50-68`) and described in `RoleResource`
  (`:233-236`), but no portal page checks them (`CallHistory`,
  `TextConversations` have no `canAccess`) — a `client_user` role
  stripped of them still sees those pages. Worth confirming.
- `ProcessInboundMessageJob` stores `occurred_at => now()` (`:146`)
  rather than the provider timestamp, so delayed retries reorder the
  thread.

## Size estimate

| Piece | Estimate |
|---|---|
| Phase 0 fixes | 1–2 days |
| Schema + models + factories | 2 days |
| `sendDispatch`, reference issuer (tokens), matcher, classifier, action runner | 4–5 days |
| Inbound job integration + `HandleDispatchReplyJob` | 2 days |
| Operator timeline, reply composer, task list | 4 days |
| Portal timeline + keyword settings | 2–3 days |
| Email replies (local part, headers, body cleaner, auto-reply detection) | 3 days |
| Platform pool: endpoint-scoped opt-outs, unmatched triage, rotation + pool sync | 4–5 days |
| WCTP reply/read half (on top of plan 05's WCTP transport) | 2 days |
| Tests (≈40 cases above) | spread, ~4 days |

**~5–6 weeks** total; Phase 1 alone (SMS replies, client endpoints,
tasks, timeline) is **~2.5 weeks** and delivers most of the visible IS
parity.
