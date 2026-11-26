# Plan: Messaging channel follow-ons

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

The SMS/MMS channel works end to end on one carrier. TODO.md
"Messaging channel follow-ons → Remaining" lists three open items, and
the partial-message entry leaves a fourth:

1. **More transports.** Twilio is the only real driver. Telnyx and
   Bandwidth are the common alternatives a platform operator will ask
   for. SMPP and WCTP (paging) matter to answering services in
   particular, because that is how their recipients' pagers and some
   carriers' bulk links are reached.
2. **Outbound-initiated conversations.** Every thread starts with an
   inbound message. An operator can't text a customer first.
3. **AI-side intake capture.** `ProcessMessageWithAgentJob` only
   replies. It never fills `MessageThread.fields` and never writes a
   `Message`, so an SMS intake that the AI runs produces nothing for
   the client, and the partial-message policy has nothing to govern on
   that path.
4. **The worker doesn't report its intake goal.** On voice,
   `SessionMessageWriter::goalFor()` always returns null, so the
   per-goal partial override never fires.

## What exists today (checked in code)

### Provider contract and registry

- `MessagingProvider` (`app/Services/Messaging/Contracts/MessagingProvider.php:30-140`)
  has nine methods: `key`, `requiresSenderPool` (`:53`),
  `senderPoolLabel` (`:60`), `verify` (`:72`, must fail closed),
  `parse` (`:83`, returns a list), `parseDeliveryReceipts` (`:95`),
  `send` (`:103-109`), `fetchMedia` (`:126`) and `deleteMedia`
  (`:139`). Its docblock already names Telnyx's Messaging Profile and
  Bandwidth's Application as sender pools, and SMPP/WCTP as transports
  with no pool (`:42-51`).
- Drivers are listed by hand in `config/messaging.php:29-37`
  (`twilio`, `log`) and resolved by `ProviderRegistry::get()`
  (`app/Services/Messaging/ProviderRegistry.php:28-43`). Credentials
  are platform-wide in `config/messaging.php:49-78`. An endpoint can
  override them through its encrypted `provider_config`
  (`app/Models/MessagingEndpoint.php:61`, merged with the pool id in
  `providerOptions()` `:84-88`).
- One public webhook, `POST /api/messaging/inbound/{provider}`
  (`routes/api.php:89-91`), throttled per source IP
  (`app/Providers/AppServiceProvider.php:72-74`).
  `InboundMessageController` verifies first (`:52`), applies receipts
  inline (`:61-65`, `:111-136`, never moving a status backwards) and
  queues `ProcessInboundMessageJob` per parsed message (`:75-90`).
- `TwilioProvider` (`app/Services/Messaging/Providers/TwilioProvider.php`)
  is the reference driver: HMAC-SHA1 signature over URL + sorted POST
  params (`:56-98`), refuses to send without a Messaging Service
  (`:192-207`), media fetch and delete with basic auth behind a host
  allowlist (`:253-355`, `config/messaging.php:64-67`).
- **Gap:** `TwilioProvider::verify()` checks the signature against the
  platform token only (`:58`). An endpoint that brings its own Twilio
  account through `provider_config` can send, but its inbound webhooks
  fail verification, because the controller verifies before it knows
  which endpoint the request is for. Every new driver hits the same
  problem.
- The admin form takes the pool label and requirement from the driver
  (`app/Filament/Resources/ClientResource/Pages/ManageClientMessagingEndpoints.php:72-94`),
  and `provider_config` is a free key/value field (`:150-155`).
- `MessageQueue` already declares `smpp`, `wctp` and `paging`
  protocols (`app/Models/MessageQueue.php:42-46`) that no driver
  produces.

### Consent

- `OptOutRegistry` (`app/Services/Messaging/OptOutRegistry.php:28`)
  answers "may we text this person for this client"
  (`isSuppressed()` `:33`, `isThreadSuppressed()`). Opt-outs are per
  client.
- `OutboundMessageService::send()` is the one send path and checks
  suppression first (`app/Services/Messaging/OutboundMessageService.php:71-101`).
  It only sends a reply on an existing `MessageThread`
  (`replyAsOperator` `:45`, `replyAsAgent` `:59`). There is no way to
  start a thread outbound.
- `ProcessInboundMessageJob::applyConsentKeywords()`
  (`app/Jobs/ProcessInboundMessageJob.php:178-226`) records STOP/START
  and stops processing (`:160-166`). It sends no confirmation text. The
  comment at `:157-159` says why: the carrier's sender pool answers
  STOP/HELP with the registered campaign text. That holds for Twilio.
  It does not hold for a transport with no carrier pool (SMPP), where
  nobody would send the confirmation the CTIA guidelines expect.

### Threads and the AI job

- `MessageThreadResolver::resolve()` only takes an `InboundMessage`
  (`app/Services/Messaging/MessageThreadResolver.php:44-91`). Threads
  are keyed by (endpoint, remote address) with a reopen window
  (`:93-118`, `config/messaging.php:96`).
- `MessageThread.fields` is a JSON column
  (`database/migrations/2026_08_17_110000_create_messaging_tables.php:112-116`,
  cast at `app/Models/MessageThread.php:59`). The migration comment
  says it has the same shape as `call_session_states.fields`. Nothing
  writes to it.
- `ProcessMessageWithAgentJob` (`app/Jobs/ProcessMessageWithAgentJob.php`)
  compiles the persona's flow (`:113`) but uses only
  `llmInstructions` (`:133`), not `functionSchemas`. It makes a single
  text-only Anthropic call with no tools (`:292-335`) and sends the
  reply (`:145`). It drops leading outbound turns from the history
  (`:214-220`). That is fine today, but it would lose the opening text
  of an outbound-initiated thread.
- The operator's "Take message" (`app/Filament/Operator/Pages/ViewMessageThread.php:327-405`)
  already applies `PartialMessagePolicy` to a thread and writes
  `messages.message_thread_id`. It passes a null goal (`:367-372`).

### Partial policy and goals

- `PartialMessagePolicy` (`app/Services/Messages/PartialMessagePolicy.php`)
  has `keepsPartials(team, goal)` (`:47-54`), `isWorthKeeping` (`:66`),
  `isComplete` (`:100`) and `attributesFor` (`:119`). The reasons are
  `caller_hung_up`, `session_abandoned` and `operator_saved_partial`
  (`:33-37`).
- `SessionMessageWriter::goalFor()` returns null
  (`app/Services/Messages/SessionMessageWriter.php:181-184`). The
  session row has `entry_flow_id` and `active_step`
  (`app/Models/CallSessionState.php:21-38`), but `active_step` is only
  a counter that `advance` increments
  (`app/Http/Controllers/Api/CallSessionController.php:177-196`). It
  doesn't say which goal is active. The worker's `set_field` sends
  `{key, value, extension}` (`agent-worker/tools/compiled_flow.py:104-118`),
  and the worker keeps no record of which goal is active.
- **Finding:** `intake_goals` is a platform-wide catalogue. Its
  `team_id` was dropped in
  `database/migrations/2026_04_22_210100_drop_team_id_template_id_from_intake_goals.php:28-37`.
  So `intake_goals.keep_partial_messages` (`app/Models/IntakeGoal.php:46`)
  is set once for every client that uses the goal. Wiring
  `goalFor()` as it stands would let one platform setting override
  every client's own default. Placing the goal in a client's flow
  (`intake_flow_steps.step_params`, where the flow is team-owned
  through `IntakeFlow`'s `BelongsToTeam`) is the client-scoped place
  for an override.

## Related plans: what this plan owns and what it defers

| Topic | Owner | This plan |
|---|---|---|
| Telnyx, Bandwidth drivers | **this plan** | full design |
| SMPP driver and bind gateway | **this plan** | full design. Plan 05 lists no SMPP transport. |
| WCTP driver: submit, status, poll, auth | **this plan** (design). Built when [plan 05](../is-parity/05-delivery-and-escalation.md) starts | Plan 05 sizes "Pager — WCTP" at ~1 week. That week is this section, counted once. Plan 05's pager transport becomes a thin adapter over this driver. |
| WCTP replies, read receipts, multiple choice, `inReplyToRef` / `choice` on `InboundMessage` | [plan 16](../is-parity/16-two-way-messaging.md) (`WctpProvider` section, phase 4) | deferred. The driver here leaves those hooks in place and doesn't implement them. |
| SNPP / TAP paging | [plan 05](../is-parity/05-delivery-and-escalation.md) ("later") | out of scope. Whether NCI needs them depends on the Amtelco macro decoding in [amtelco-import-follow-ons.md](amtelco-import-follow-ons.md). |
| Dispatch to recipients, escalation, ack | plan 05 | out of scope. Compose here is operator-to-customer only. |
| Pinned sender, sender rotation, platform dispatch pool, reply tokens | plan 16 | out of scope. Bandwidth's `from` handling below is kept compatible with plan 16's `pin_sender` option. |
| Secure-messaging transport, PHI egress | [plan 17](../is-parity/17-secure-messaging.md) | out of scope. New drivers are listed in plan 17's PHI allowlist only once a BAA exists. |
| Mass texting / code calls | [plan 23](../is-parity/23-code-calls-and-mass-notification.md) | out of scope. Compose is one recipient at a time. |
| Message custom fields / builder | [plan 03](../is-parity/03-message-model.md) | The SMS intake writer here is a thin caller of `PartialMessagePolicy`, shaped so it can move onto plan 03's builder (plan 03 already names this TODO as a builder client). |

## Goal

- Telnyx and Bandwidth are drivers on a par with Twilio. SMPP and WCTP
  drivers exist when plan 05 needs them. Each is a `MessagingProvider`
  plus a config entry, and the routing pipeline is unchanged.
- An operator can start a text conversation with one person for one
  client. The consent check is unchanged, and the operator must record
  why they're allowed to text.
- When the AI runs an SMS intake, it fills `MessageThread.fields` and
  writes a complete or partial `Message` under the same policy as
  voice and the operator.
- The voice worker reports the step it's on, and the partial override
  resolves step → goal → client.

## Design

### A. Contract changes (shared by all new drivers)

Keep the interface narrow. Add only what at least two drivers need:

1. **`handlesConsentReplies(): bool`.** True when the carrier answers
   STOP/HELP/START itself (Twilio Messaging Service; Telnyx Messaging
   Profile with auto-response; Bandwidth only if confirmed, see open
   questions). False for SMPP and Log. When it's false,
   `ProcessInboundMessageJob` sends the client's confirmation text
   after recording the keyword. It goes through a new
   `OutboundMessageService::sendConsentConfirmation()`: the only send
   that bypasses suppression, it is used only for the one message that
   confirms a STOP, and its body comes from config, never from an
   operator or the LLM. Defaults:
   `messaging.consent_texts.stop` / `.help`, with a per-endpoint
   override in `provider_config`.
2. **Per-endpoint verification.** Add an optional route segment:
   `POST /api/messaging/inbound/{provider}/{endpointKey?}`.
   `endpointKey` is a random 32-character `messaging_endpoints.webhook_key`
   (new column, unique). It's not a secret. It only says which
   endpoint's credentials `verify()` should use. The contract gains
   `verify(Request $request, array $options = [])`. The controller
   passes `providerOptions()` when the key resolves to an active
   endpoint of that provider, and `[]` otherwise. No key means the
   platform credentials, which is today's behaviour. This also fixes
   bring-your-own Twilio accounts. The admin form shows the full
   webhook URL to paste into the carrier console.
3. **Media host allowlist per driver.** This is Twilio's
   `media_hosts` pattern (`config/messaging.php:64-67`) lifted into a
   small shared helper (`MediaUrlGuard::allows(url, hosts)`), so each
   driver states its hosts in config and nobody re-implements the
   check.
4. **Status vocabulary.** Every driver maps to the existing
   `MessageEntry::STATUS_*`. Anything unknown maps to null and is
   dropped, as Twilio does at `TwilioProvider.php:158-170`. Plan 16
   adds `read`. Nothing here depends on it.
5. **A shared contract test.** `tests/Feature/Messaging/ProviderContractTest`
   runs every registered driver against recorded fixtures. It checks
   that `verify` fails closed on a missing or altered signature, that
   `parse` returns `[]` for a receipt-only payload, that `send` refuses
   when `requiresSenderPool()` is true and no pool is set, and that
   `fetchMedia` refuses foreign hosts.

### B. Telnyx (`TelnyxProvider`, key `telnyx`)

- **Pool:** Messaging Profile. `requiresSenderPool()` true, label
  "Messaging Profile ID". `send()` refuses without one, which matches
  Twilio.
- **Send:** `POST https://api.telnyx.com/v2/messages` with a bearer API
  key and `{messaging_profile_id, to, text, media_urls[], webhook_url?}`.
  When a profile has a number pool, Telnyx picks the sender, and we
  record it from the response's `from.phone_number` into
  `SendResult::$sender`.
- **Verify:** Ed25519 signature in `telnyx-signature-ed25519` over
  `"{telnyx-timestamp}|{raw body}"`, checked against the account
  public key with `sodium_crypto_sign_verify_detached`. Reject a
  timestamp more than 5 minutes off, because a replayed webhook would
  otherwise pass. Config: `TELNYX_API_KEY`, `TELNYX_PUBLIC_KEY`, with
  per-endpoint overrides through A.2.
- **Parse:** a JSON event envelope. `message.received` →
  `InboundMessage`, with `from.phone_number`, the first `to[]`, `text`,
  `media[]` (url, content_type) and `messaging_profile_id` as
  `senderPoolId`. Group MMS (several `to`) routes on the profile id and
  is threaded on `from` only. The other recipients are recorded in
  `raw`.
- **Receipts:** `message.sent` and `message.finalized`, with
  `to[].status` mapped as follows: `queued`/`sending` → queued,
  `sent` → sent, `delivered` → delivered, `delivery_failed` → failed,
  `sending_failed` → failed, `delivery_unconfirmed` → sent (not
  delivered, so it doesn't overstate).
- **Media:** fetch with the API key if the URL is on the configured
  Telnyx media hosts. Confirm against a live account whether inbound
  media URLs need auth. Delete is a no-op that returns false unless
  Telnyx offers a delete for inbound media (open question).
- **10DLC:** the campaign is registered on Telnyx and attached to the
  Messaging Profile. That's configuration, not code. The admin help
  text says so.

### C. Bandwidth (`BandwidthProvider`, key `bandwidth`)

- **Pool:** the Application ID. `requiresSenderPool()` true, label
  "Messaging Application ID". Unlike Twilio, Bandwidth's send needs an
  explicit `from` number as well as `applicationId`. So the driver
  sends `from = $from` (`endpoint.address`). It refuses when the
  address is empty, and it refuses when there's no application id.
  This doesn't reopen the bare-`From` hole Twilio's refusal closes:
  the application ties the number to its campaign and callbacks.
  Whether Bandwidth enforces STOP at the network for that number is
  open question 2. Until it's confirmed, `handlesConsentReplies()`
  returns false, so Orbital sends its own confirmation and enforces
  its own list.
- **Send:** `POST https://messaging.bandwidth.com/api/v2/users/{accountId}/messages`,
  `{applicationId, to: [..], from, text, media[]}`, authenticated
  either with an API token pair (basic) or with OAuth2 client
  credentials (`https://id.bandwidth.com/api/v1/oauth2/token`, token
  cached until it expires). Support whichever the account has, behind
  one `auth_mode` config key.
- **Verify:** Bandwidth callbacks carry no HMAC. They use the basic
  auth credentials configured on the Application (callback user and
  password). `verify()` compares both with `hash_equals` and fails
  closed when they're unset. An optional CIDR allowlist
  (`BANDWIDTH_CALLBACK_CIDRS`) adds defence in depth. This is weaker
  than a signature, and the admin help text says so.
- **Parse:** the callback body is a JSON array of events.
  `message-received` → `InboundMessage` (with `message.applicationId`
  as `senderPoolId`). `message-delivered`, `message-failed` and
  `message-sending` → receipts keyed on `message.id`, with
  `errorCode`/`description` as the error.
- **Media:** inbound media sits under
  `messaging.bandwidth.com/api/v2/users/{accountId}/media/...` and
  needs the same auth. `fetchMedia` uses it, and `deleteMedia` sends a
  DELETE to the same URL. Both apply the host guard (A.3).

### D. SMPP (`SmppProvider`, key `smpp`) plus an `smpp-gateway` service

SMPP is a long-lived TCP bind, not HTTP, and PHP request and queue
workers can't hold one. Keep the contract intact by putting a small
gateway in front of it:

- **`docker/smpp-gateway/`**: a Python service (the stack already runs
  Python for `agent-worker/`) that holds one transceiver bind per
  configured SMSC, keeps it alive with `enquire_link`, reconnects with
  backoff and respects the SMSC's window and throughput limits. It
  exposes an internal-only HTTP API on the cluster network:
  - `POST /send {bind, from, to, text}` → the gateway submits
    `submit_sm`. It splits long text with SAR/UDH, encodes as GSM 03.38
    when the text fits and UCS-2 otherwise, and waits up to 5 s for
    `submit_sm_resp`. It returns `{message_id}` or an error.
  - Inbound `deliver_sm` (a message or a delivery receipt, told apart
    by `esm_class`) is POSTed to
    `/api/messaging/inbound/smpp/{endpointKey}` as JSON, HMAC-SHA256
    signed with a shared secret (`SMPP_GATEWAY_SECRET`). Concatenated
    parts are reassembled first.
- **`SmppProvider`:** `requiresSenderPool()` false, label "SMPP bind".
  `handlesConsentReplies()` false. `verify()` checks the gateway's
  HMAC and a timestamp. `parse()` and `parseDeliveryReceipts()` read
  the gateway JSON. DLR states map as `DELIVRD` → delivered,
  `UNDELIV`/`REJECTD`/`EXPIRED` → undelivered, `ACCEPTD`/`ENROUTE` →
  sent, `UNKNOWN` → null. `send()` calls the gateway. `fetchMedia` and
  `deleteMedia` return null and false, because SMPP has no MMS.
- **Config per endpoint (`provider_config`):** `bind` (the name of a
  gateway-side bind, never the SMSC password itself). Bind credentials
  (`system_id`, `password`, host, port, TLS) live in the gateway's
  Kubernetes secret, so the web tier never handles them.
- **Ops:** the gateway exports bind state and the submit/DLR rate to
  the existing Pushgateway, and a bind that stays down raises an
  alert. A Helm chart value enables it. It's off by default.
- **Compliance:** without a carrier pool, Orbital is the only opt-out
  enforcement on this transport. The suppression check in
  `OutboundMessageService` already applies. The admin form warns that
  an SMPP endpoint for US person-to-person traffic still needs a
  registered campaign upstream.

### E. WCTP (`WctpProvider`, key `wctp`), designed here and built with plan 05

Plan 16 (§"New driver `WctpProvider`") and plan 05 (§Transports) both
describe this driver. To avoid designing it twice: **this section owns
the transport half, and plan 16 owns the reply/read half.**

- WCTP 1.3 XML over HTTPS. `send()` builds `wctp-SubmitClientMessage`
  with `wctp-SubmitClientHeader` (`senderID`, `securityCode`, and
  `recipientID` = the pager id), payload `alphanumeric` (or
  `transparentData` for numeric-only pagers), and
  `notifyWhenDelivered="true"`. The carrier's `wctp-ClientMessageReply`
  gives the `trackingNumber`, which becomes `providerMessageId`.
- **Status:** push-mode carriers POST `wctp-StatusInfo` to
  `/api/messaging/inbound/wctp/{endpointKey}`. Pull-mode carriers are
  polled with `wctp-ClientQuery` by a scheduled
  `PollWctpStatusJob` (every minute, only for endpoints with
  `provider_config.mode = pull`, and only for messages sent in the
  last 24 h that aren't yet final). Plan 16's `PollWctpGatewayJob`
  extends this same job to fetch replies. It doesn't add a second one.
- **Verify:** WCTP has no request signing. Push callbacks are accepted
  only on the per-endpoint URL (A.2), with an optional shared
  `securityCode` echoed in the body and a CIDR allowlist. Fail closed
  if neither is configured.
- `requiresSenderPool()` false, label "WCTP sender ID".
  `handlesConsentReplies()` is irrelevant because pagers don't send
  STOP. It returns true so no confirmation text is sent.
- **One endpoint per paging carrier gateway.** Which carrier a pager id
  belongs to is exactly what the undecoded Amtelco `.NNS"<id>".0.1.1M`
  macro families probably encode. Those must be confirmed first
  ([amtelco-import-follow-ons.md](amtelco-import-follow-ons.md)).
  Until then a directory entry's pager can't be routed to a gateway
  without a guess, which is why WCTP waits for plan 05 and the macro
  decoding.
- Pager endpoints aren't customer conversation numbers. They need the
  platform-owned (null `team_id`) endpoints from plan 16 phase 3, or a
  per-client endpoint where a client has its own paging account. This
  plan doesn't decide which. Plan 05/16 do.

### F. Outbound-initiated conversations

**Surface.** A "New text" action:

- in the operator message inbox (`MessageInbox`), where the operator
  picks a client and a number;
- on a call log row and in the operator workspace after a call,
  prefilled with the caller's number for that call's client ("Text
  this caller");
- on a portal-visible `Message` ("Text the caller back"), operator
  side only in v1.

The form fields:

- **Client.** Fixed when launched from a call or message.
- **Endpoint.** The client's active endpoints for SMS. When there are
  several, the default is `messaging_endpoints.is_default_outbound`
  (a new boolean, at most one per team).
- **To.** Normalised to E.164 with `MessagingEndpoint::addressVariants`.
- **Body.**
- **Consent basis** (required). One of:
  - `caller_requested`: the person asked to be texted on this call or
    message.
  - `existing_relationship`: an earlier conversation with this
    client, within the reopen window or older.
  - `client_instructed`: the client's own procedure says to text, with
    a reference to the info page.
  - `other`: a free-text note is required.

  The basis is stored on the thread and in the audit log.

**Service.** `OutboundConversationService::start(team, endpoint, to, body, basis, user)`:

1. `OptOutRegistry::isSuppressed(team, to)` → refuse and show the
   suppression banner. This is the same check as today, done before
   anything is created.
2. Quiet hours: refuse between 21:00 and 08:00 in the recipient's
   likely time zone (from the area code, falling back to the client's
   time zone). An operator can override with a reason. It's a guard
   against obvious harm, not legal advice.
3. Resolve or create the thread with a new
   `MessageThreadResolver::forOutbound(endpoint, to, queue)`. It reuses
   an open thread, or one closed inside the reopen window, for the same
   (endpoint, address). Otherwise it creates one with
   `initiated_direction = outbound`, `initiated_by_user_id`,
   `consent_basis` and `consent_note` (new nullable columns on
   `message_threads`), `status = awaiting_reply`, and
   `assigned_operator_id` = the sender (claimed, so the AI doesn't pick
   it up).
4. Send through the existing `OutboundMessageService::replyAsOperator`,
   so the suppression chokepoint stays the only one.
5. The first message on a new outbound thread must identify the client
   and say how to opt out. The service prepends
   `"{client name}: "` when the body doesn't start with it, and appends
   "Reply STOP to opt out." to that first message only. The client can
   change both strings in endpoint settings. They can't be removed.

**10DLC / A2P.** The registered campaign's use case and sample
messages have to cover operator-initiated customer-care texts, or the
carriers will filter them. This is the installer's responsibility. The
feature is off per client until an admin ticks
`messaging_endpoints.outbound_initiation_enabled`, with help text
naming the campaign requirement. Toll-free numbers need toll-free
verification. Same flag, same help text.

**Permission.** `messaging.start_conversation`, granted to operators
and supervisors through `PermissionCatalogSeeder`. There's a per-user
rate limit of 30 new threads per hour, so a mistaken bulk use is
bounded. Mass sending is plan 23.

**AI after an outbound start.** `ProcessMessageWithAgentJob` would
drop the opening turn (`:214-220`). When the thread was initiated
outbound, put the first outbound body in the system prompt instead
("You opened this conversation with: …"), so the AI knows what it's
answering.

### G. AI-side intake capture

**Tool use in the job.** `ProcessMessageWithAgentJob` sends
`set_field` (from `CompiledFlow::$functionSchemas`, filtered to
`set_field` only, with no transfers or lookups in v1) as an Anthropic
tool. It runs a bounded loop:

1. Call the API with the history, the system prompt and the tools.
   Then add the fields captured so far ("Already collected: …, don't
   ask again").
2. For each `tool_use` `set_field{key, value, step?}`, write it to
   `MessageThread.fields` under a row lock. Keys are validated against
   the reachable goals' `data_fields` and unknown keys are rejected
   with a tool error (this also covers part of TODO bug [25] for this
   path). Return `tool_result`.
3. Repeat until the model returns text, with at most 4 rounds, then
   send the text as today.

`fields` has the same shape as `call_session_states.fields`. A
reserved `_intake` key holds `{round, step_key, completed_at}`.

**Writer.** `ThreadMessageWriter::write(MessageThread $thread, bool $ended): ?Message`
mirrors `SessionMessageWriter::write()`:

- `SessionMessageWriter::normalise()` moves into a shared
  `CapturedFieldNormaliser`, and both writers use it. The synonym
  table stays in one place.
- If the capture is complete, write or update the message now
  (`is_partial = false`).
- If it's incomplete and not ended, do nothing.
- If it's incomplete and ended, apply `keepsPartials` and
  `isWorthKeeping` and write with a new reason,
  `PartialMessagePolicy::REASON_CONVERSATION_ABANDONED` ("Texter
  stopped replying before giving a reason.").
- **Idempotency key:** a new nullable `messages.intake_key`, unique
  per team: `thread:{id}:{round}`. A complete message is never
  downgraded, which is the same rule as `SessionMessageWriter:153-157`.
  A later text that completes a partial upgrades it in place.
- `messages.message_thread_id` is set, the same as the operator's
  "Take message". The callback number defaults to the thread's remote
  address when the texter gives none, because that's the one thing
  this channel always knows. This matches the operator path.

**When is an SMS intake "ended"?** There's no hangup. It ends when any
of these happens:

- an operator closes the thread;
- the customer sends STOP (the message is kept if the policy says so,
  and it's marked abandoned);
- or `last_inbound_at` is older than `messaging.intake_abandon_minutes`
  (default 120) with an incomplete capture.

A scheduled `FinalizeStaleThreadIntakesJob` (every 5 minutes) handles
the timeout. After a message is final (complete, or a partial past the
timeout), `_intake.round` is incremented and the data fields are
cleared. So a customer who texts again next week starts a new message,
not an edit of the old one.

**Operator interplay.**

- When an operator claims the thread, the AI stands down as today
  (`:104-111`).
- "Take message" is prefilled from `thread.fields`. If this round
  already has an AI-written message, the action edits that message
  (same `intake_key`) instead of creating a second one.
- An operator-saved message closes the round.

**Goal.** Message queues carry an orchestration, not a goal
(`ViewMessageThread.php:367-372`). The writer resolves the goal from
`_intake.step_key` using the resolver in H. When there's none, the
client default applies, which is the same as today.

### H. Reporting the active intake goal (voice and SMS)

- The `set_field` schema (`AgentFlowCompiler::buildFunctionSchemas`,
  `app/Services/Flows/AgentFlowCompiler.php:861`) gains an optional
  `step` argument, described to the model as "the bracketed key of the
  step you are on". The prompt already lists steps as `[key]`
  (`:446-448`).
- The worker passes it through
  (`agent-worker/tools/compiled_flow.py:104-118`, one argument added).
  `CallSessionController::captureField` validates `step` as a nullable
  string of up to 64 characters and stores the resolved step on a new
  `call_session_states.active_intake_step_id`.
- **`IntakeStepResolver::resolve(entryFlowId, stepKey): ?IntakeFlowStep`**
  finds the step only among the flows reachable from the session's
  entry flow (the same walk as `collectReachableFlows`). A key from
  another tenant's flow, or one the model made up, resolves to null.
  Nothing is trusted just because the LLM said it.
- **Policy order** (changes `PartialMessagePolicy::keepsPartials`):
  `step_params.keep_partial_messages` (client-scoped, because the flow
  is team-owned) → `intake_goals.keep_partial_messages` (the
  platform-wide catalogue default) → `teams.keep_partial_messages`.
  The step-level toggle goes on the flow editor's step form. Because
  of the finding above, the goal-level select in `IntakeGoalResource`
  (`:200`) is relabelled "Platform default for every client".
- `goalFor()` becomes a step lookup, and the method keeps its name and
  role as the one seam. The SMS path uses the same resolver with
  `_intake.step_key` and the queue orchestration's entry flow.

## Edge cases

- **A webhook without `endpointKey` for a BYO-credentials endpoint:**
  verified with platform credentials, so it fails with 403, and the
  carrier retries and alerts. The admin form shows the keyed URL so
  this only happens through misconfiguration.
- **Telnyx and Twilio both configured for one number** (porting
  mid-way): `messaging_endpoints` is unique on (address, protocol)
  (`create_messaging_tables.php:74`), so only one endpoint can claim
  the number. Inbound from the other carrier routes by pool id, or it
  is dropped and logged. The porting runbook says to swap the
  endpoint's provider at cut-over.
- **SMPP gateway down:** `send()` returns `failed("SMPP gateway
  unavailable")`, which is recorded and visible, the same as any
  provider failure. Inbound queues at the SMSC until the bind is back.
- **SMPP DLR before our entry commits:** the controller already
  ignores unknown ids (`InboundMessageController.php:118-123`), and
  the gateway retries DLR posts on non-2xx for 10 minutes.
- **Long SMPP text:** split by the gateway. One `MessageEntry` per
  logical message, and the DLR of the last part counts.
- **WCTP numeric-only pager:** a non-digit body is refused at send
  with a clear error instead of being truncated.
- **Outbound start to a number that's mid-conversation with another
  client:** threads are per endpoint, so that's a separate thread, and
  the opt-out check is the target client's.
- **Outbound start to a landline:** the carrier fails it, and the
  failed entry and banner show that. A Lookup API call is optional
  (cost) and is open question 4.
- **The STOP confirmation itself:** sent through
  `sendConsentConfirmation` exactly once per opt-out event. A repeat
  STOP doesn't re-send it, because the `opted_out_at` clock not moving
  (`OptOutRegistry.php:83-88`) tells us it's a repeat.
- **The AI writes a field after the operator claimed the thread:** the
  job checks the claim again before each tool round and stops.
- **A model sets `caller_phone` to something that isn't a number:**
  the value is stored as given (it's the customer's words), and the
  writer falls back to the thread address for `caller_phone` when the
  value has no digits.
- **The voice worker on an old version sends no `step`:** null step,
  client default, which is today's behaviour.

## Migration and rollout

1. Contract changes (A) with Twilio updated. The `verify` options
   argument defaults to `[]`, so behaviour is unchanged. Add
   `messaging_endpoints.webhook_key` (backfilled with random values),
   `is_default_outbound` and `outbound_initiation_enabled`.
2. Telnyx, then Bandwidth, each behind its config entry. A driver
   whose credentials aren't set is still registered, but its webhooks
   fail closed.
3. H (goal reporting) on its own. It's small, and it makes the
   existing partial policy correct on voice. Deploy the worker and the
   API together. The API accepts both old and new payloads.
4. G (AI intake) behind the existing `MESSAGING_AUTO_REPLY_ENABLED`
   plus a new per-queue `capture_intake` toggle (default off). Pilot on
   one client's queue.
5. F (outbound start), per endpoint flag, with the operator permission
   seeded but granted only to supervisors at first.
6. SMPP gateway when an installer has an SMSC to bind to. WCTP when
   plan 05 starts and the Amtelco pager macros are confirmed.

Migrations add only nullable columns or columns with defaults. No data
changes except the `webhook_key` backfill.

## Tests

- Provider contract suite (A.5) over every registered driver.
- Telnyx: Ed25519 verify with a fixture key pair, a stale timestamp, a
  body changed by one byte, and group MMS parsing.
- Bandwidth: the basic-auth callback check, the event array with a
  mixed message and receipt, OAuth token caching (`Http::fake`), and
  refusal without an application id.
- SMPP: `SmppProvider` against a faked gateway (HMAC, DLR mapping).
  The gateway gets its own pytest suite against an SMPP simulator
  container: concatenation, GSM/UCS-2, rebind after a drop.
- WCTP: XML fixtures for submit, StatusInfo and ClientQuery, plus the
  pull job's selection window.
- Consent: an SMPP STOP sends exactly one confirmation, a Twilio STOP
  sends none, and a repeat STOP sends none. A suppressed address can't
  be started outbound, even by a supervisor.
- Outbound start: an existing open thread is reused, the client prefix
  and opt-out footer appear on the first message only, the quiet-hours
  refusal and its override are covered, the rate limit is covered, and
  the AI doesn't reply on a claimed outbound thread.
- AI intake: with a faked Anthropic response containing `tool_use`,
  fields are written, a complete message is created once across job
  retries, the abandon sweep writes a partial only when the policy
  allows, a later completion upgrades it in place, and the next round
  creates a new message.
- Goal reporting: the step resolver ignores keys outside the reachable
  flows, the policy order is step → goal → team, and an old worker
  payload with no step still works.

## Open questions

1. Does NCI (or the first platform operator after them) actually have
   Telnyx or Bandwidth accounts, or is this speculative? That decides
   the order of B and C.
2. Bandwidth: does the network block sends to numbers that texted STOP
   for 10DLC/toll-free on our account type, and does it send the
   confirmation? This decides `handlesConsentReplies()`.
3. Telnyx: are inbound media URLs authenticated, and can they be
   deleted?
4. Outbound start: pay for a carrier lookup (landline/mobile) before
   the first text, or let the carrier fail it?
5. Consent basis: is the four-value list enough for NCI's clients, and
   does any client need written-consent capture (portal-uploaded
   opt-in lists)? That would be plan 23's territory.
6. SMS intake abandon timeout: is 2 hours right, and should it be per
   client?
7. Does any NCI client need SMPP (an SMSC bind), or is SMPP only for a
   future operator? If nobody does, D stays designed and unbuilt.

## Size

| Piece | Estimate |
|---|---|
| Contract changes, keyed webhooks, consent confirmations, contract test suite | 3 d |
| Telnyx driver | 3 d |
| Bandwidth driver | 3–4 d |
| SMPP gateway service + driver + chart + metrics | 1.5–2 wk |
| WCTP driver (transport half; replaces plan 05's WCTP week) | 1 wk |
| Outbound-initiated conversations | 1 wk |
| AI intake capture (tool loop, writer, sweep, operator interplay) | 1–1.5 wk |
| Goal/step reporting + policy order | 2 d |

That's about 3 weeks without SMPP and WCTP, and 5.5–6 weeks for all
of it.
