# Plan: Inbound email follow-ons

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

Inbound email works end to end. Haraka accepts SMTP, Laravel parses and
routes the message, groups it into a thread, and an operator or AI
persona replies. TODO.md "Inbound email follow-ons" lists nine items that
were left out of phases 1–4:

1. tenant-custom inbound domains
2. a rich-text reply composer
3. inline previews for image and PDF attachments
4. Postgres full-text search on threads
5. anti-spam plus SPF/DKIM/DMARC checks at Haraka
6. bounce handling for outbound replies
7. a per-tenant email volume widget on `/admin`
8. thread merging
9. IMAP/POP3 pull from tenants' existing mailboxes

`docs/roadmap.md:56-62` adds a tenth: email routing rules have no
editor. [IS plan 06](../is-parity/06-schedules-and-routing.md) covers the
*call* routing editor only (DID rules, schedules, `GotoIfTime`). It says
nothing about `EmailRoutingRule`, so this plan includes the email editor.

The list is unordered, and some items look optional when they aren't.
Today any internet host can get mail into a client's inbox and, if the
thread is assigned to an AI persona, get an automatic reply. That makes
items 5 and 6, plus a loop guard the TODO doesn't list, a gate for
publishing an MX record. The other items aren't.

## What exists today (checked in code)

**Haraka.**

- Haraka runs as a pure SMTP-to-HTTP shim, Haraka 3.3.5 built with
  `--omit=optional` (`docker/haraka/Dockerfile:346`). Its only plugin is
  `orbital-webhook`, plus `tls` when a certificate is mounted
  (`docker/haraka/config/plugins:15`, `docker/haraka/entrypoint.sh:311-319`).
- The plugin has two hooks. The `rcpt` hook calls
  `GET /api/mail/validate-recipient` and maps the response to an SMTP
  code: 200 accepts, 404 returns `550`, anything else returns `DENYSOFT`
  (`docker/haraka/plugins/orbital-webhook.js:56,61-116`). The `queue` hook
  POSTs the raw RFC822 message plus the envelope as multipart (`:118-226`).
- **dnsbl, spf and dkim_verify are deliberately off.** Haraka sees the
  proxy's address, not the sender's, so IP checks would judge the wrong
  host (`docker/haraka/config/plugins:10-13`). The Helm chart publishes
  port 25 through a Traefik `IngressRouteTCP` with plain passthrough and
  no PROXY protocol (`helm/orbital/templates/haraka.yaml:102-124`).
  Deployments that front port 25 with a host TCP proxy have the same
  problem.
- **Known break on this branch and on `main`:** the plugin calls
  `rcpt.address()` (`orbital-webhook.js:67,146,155`). In Haraka 3.1+,
  `address` is a getter, so on the pinned 3.3.5 every RCPT is refused.
  The fix (an `addressOf()` helper) is commit `ac77110`, which exists
  only on the local branch `chore/inbound-mail`. It has to reach `main`
  before anything below.

**Laravel intake.**

- `InboundMailController::store` writes the raw message to the `s3` disk
  at `mail-raw/{ulid}.eml`, creates a stub `EmailMessage` with
  `routing_status=pending`, and dispatches `ProcessInboundEmailJob` on the
  `inbound-mail` queue
  (`app/Http/Controllers/Api/InboundMailController.php:41-88`). Auth is a
  shared bearer token (`app/Http/Middleware/VerifyInboundMailToken.php:25-34`).
  The token is compared with `!==`, not `hash_equals`. That is the
  security plan's housekeeping item.
- **`validateRecipient` ignores the domain.** It parses only the local
  part and accepts any address whose numeric local part matches a
  non-personal team's `account_number`
  (`InboundMailController.php:105-129`, `app/Services/Mail/LocalPartParser.php:41-81`).
  `12345@anything` is accepted whenever the sending MTA delivers to us.
  Non-numeric local parts (`support@`, `bounces+…@`) always get a `550`.
- `ProcessInboundEmailJob` parses the message with `beyondcode/laravel-mailbox`
  (zbateson). It fills in headers and bodies, routes through
  `InboundRouter`, resolves the thread with `ThreadResolver`, and stores
  attachments under `mail-attachments/{message_id}/…` with `inline` and
  `content_id` (`app/Jobs/ProcessInboundEmailJob.php:56-262`).
- **No loop or auto-reply guard.** When a message lands on a thread that
  has `assigned_agent_persona_id`, the job dispatches
  `ProcessEmailWithAgentJob` unconditionally (`:180-182`). That job
  projects the whole thread into an LLM call and replies via
  `OutboundReplyService` (`app/Jobs/ProcessEmailWithAgentJob.php:96-132`).
  Nothing in `app/` checks `Auto-Submitted`, `Precedence`,
  `List-Id`/`List-Unsubscribe`, a null sender or `MAILER-DAEMON` (grep
  finds nothing). An out-of-office reply to an AI reply gets an AI reply.
- `routing_status` is a DB enum: `pending`, `routed`, `unrouted`, `failed`
  (`database/migrations/2026_04_15_110000_create_email_messages_table.php:61-63`).

**Routing.**

- `InboundRouter::route` takes the first envelope recipient whose account
  number resolves to a client. It then walks that client's active
  `EmailRoutingRule` rows by `priority, id`. Match types are `function`,
  `from_pattern`, `subject_pattern` and `default`. Destinations are queue,
  operator, agent persona and discard
  (`app/Services/Mail/InboundRouter.php:57-211`,
  `app/Models/EmailRoutingRule.php:30-44`).
- **There is no editor.** The only rule writer is
  `ClientProvisioner::seedDefaultEmailQueue`, which seeds one catch-all
  per client (`app/Services/Clients/ClientProvisioner.php:221`). The docs
  confirm this (`docs/admin/email-routing.md`, "Rules are not yet
  editable").
- **`discard` isn't honoured.** The job creates a thread for every
  message that resolves to a team, whatever the destination
  (`ProcessInboundEmailJob.php:96-104`). A discard rule therefore leaves
  a queue-less thread, and the inbox shows queue-less threads to
  everyone (`app/Filament/Operator/Pages/EmailInbox.php:160-167`).
- **`EmailQueue.matched_addresses` / `matched_domain` are never read.**
  Admins can edit them (`ManageClientEmailQueues.php:142-147`), and the
  docs and migration describe them as a working routing path
  (`docs/admin/email-routing.md`, "the practical route today";
  `2026_04_24_110100_add_matching_to_email_queues.php:12-19`). But
  `InboundRouter` never looks at them. Only the SMS router reads its own
  queue's `matched_addresses` (`app/Services/Messaging/InboundMessageRouter.php:114`).
- `EmailQueue` advertises `ai_first` and an overflow persona in its form
  (`ManageClientEmailQueues.php:98,122`). Nothing implements either. The
  agent job's docblock says "Phase 4 — not wired yet"
  (`ProcessEmailWithAgentJob.php:28-30`).

**Threads and inbox.**

- `ThreadResolver` tries `In-Reply-To`, then `References`, then sender
  plus subject root within 7 days, and otherwise creates a new thread,
  all scoped to the team (`app/Services/Mail/ThreadResolver.php:37-95`).
- `EmailInbox` lists visible threads. Search runs `ilike` over
  `subject_root` plus a `whereHas` across the messages' `body_text`,
  `from_address` and `subject` (`EmailInbox.php:213-224`). That is a
  sequential scan of every message body. Claiming is a plain
  `forceFill()->save()` (`:257-275`). The SMS inbox uses the atomic
  `MessageThread::claimFor()` (`app/Models/MessageThread.php:123-143`).
- `ViewEmailThread` renders each message as an infolist section showing
  **`body_text` only**. Attachments appear as a comma-joined
  "name (size)" string with no link (`app/Filament/Operator/Pages/ViewEmailThread.php:81-150`).
  A partial with a sandboxed `srcdoc` iframe for HTML bodies and download
  links exists at `resources/views/filament/operator/partials/thread-viewer.blade.php:72-130`,
  **but nothing includes it** (grep finds no reference). TODO.md says
  "the HTML body rendering … supports rich replies". That's true of the
  data, not of the page.
- The reply action is a plain `Textarea` (`ViewEmailThread.php:230-262`).
  On failure the operator sees `$e->getMessage()`. That is staff-only,
  so acceptable.
- Downloads go through `AttachmentDownloadController::download`, with an
  auth check: super-admin, any platform role, or a member of the owning
  team (`app/Http/Controllers/Mail/AttachmentDownloadController.php`, routes at
  `routes/web.php:59-64`). The MMS thread view already signs short-lived
  URLs with `Storage::temporaryUrl` and falls back gracefully
  (`app/Models/MessageEntry.php:128-175`).

**Outbound.**

- `OutboundReplyService::reply` replies to the latest inbound message's
  `from_address` with `From: {account_number}@{INBOUND_MAIL_DOMAIN}`. It
  builds `In-Reply-To`/`References`, sets its own `Message-ID`, and sends
  **synchronously** with `Mail::to()->send()`. Only after a successful
  send does it write the outbound `EmailMessage`
  (`app/Services/Mail/OutboundReplyService.php:43-167`). It already accepts
  `$bodyHtml`, and `ThreadReply` sends it as `htmlString`
  (`app/Mail/ThreadReply.php:50-60`).
- No `Return-Path` or `Sender` is set. Symfony derives the envelope
  sender from `Sender`, then `Return-Path`, then `From`
  (`vendor/symfony/mailer/DelayedEnvelope.php:83-95`). Asynchronous
  bounces therefore go to `{account}@{inbound domain}`, come back through
  Haraka and route as **new inbound mail from MAILER-DAEMON** into the
  client's inbox. If the client's default rule points at an AI persona,
  the AI answers the bounce.
- A synchronous rejection from the relay throws inside the Livewire
  action, and no outbound row is written.

**Reporting.** The admin panel registers no dashboard widgets
(`app/Providers/Filament/AdminPanelProvider.php:95`).
`PlatformMetricsCollector::email` already exports per-team received
counts, threads by status and unrouted counts to Prometheus
(`app/Services/Metrics/PlatformMetricsCollector.php:163-197`). Nothing
records a first-response time.

**Tests.** There are none for the email pipeline. No test references
`ProcessInboundEmailJob`, `InboundRouter`, `OutboundReplyService` or
`/api/mail/*`. The suite runs on SQLite (`phpunit.xml:26-27`), which has
no `ilike`.

**Libraries available.** `symfony/html-sanitizer` and `symfony/mime`
(DKIM signer) are in `vendor/`. There is no IMAP client, and PHP 8.4
unbundled `ext-imap`.

## Goal

Make it safe to publish an Orbital MX record to the internet, then make
the inbox good enough that operators work email in Orbital rather than
around it:

- **Safe.** Mail is authenticated and spam-scored at the edge. Loops and
  bounces are recognised and never reach an AI or a client inbox as
  conversations. Delivery failures reach the operator.
- **Routable.** Admins can edit rules, every advertised field does what
  it says, and clients can use their own domain.
- **Workable.** HTML bodies, inline images and PDFs render. Replies can
  be formatted. Search is indexed. Duplicate threads can be merged.
- **Measured.** Per-client volume and time to first reply are visible.

## Design

Phases are ordered by dependency and risk. The first two phases are the
gate for public inbound mail.

### Phase 0: prerequisites (gate)

- Merge `ac77110` (the Haraka `addressOf()` fix) to `main`.
- Add a feature test harness for the pipeline, built from fixture `.eml`
  files: `InboundMailController` → job → router → resolver → attachments.
  The job is synchronous in tests, and `Storage::fake('s3')` replaces the
  disk. Everything later in this plan adds to it.
- Make the email inbox's search portable (Postgres `ilike`, SQLite
  `like`) so the inbox is testable, or move the search behind the phase 4
  search service now.
- Switch the email claim to an atomic conditional UPDATE, the same as
  `MessageThread::claimFor()`. Ideally extract a shared
  `ClaimsConversation` concern, which the web chat plan also needs.

### Phase 1: loop guard and recipient checks (gate)

**`AutoMailClassifier`** (`app/Services/Mail/AutoMailClassifier.php`) is a
pure function over the parsed headers plus the envelope. It returns
`human`, `auto_reply`, `bounce`, `list` or `self`:

- `bounce`: null envelope sender (`<>`), a `MAILER-DAEMON`/`postmaster`
  local part, or `Content-Type: multipart/report; report-type=delivery-status`.
- `auto_reply`: `Auto-Submitted` other than `no`,
  `X-Autoreply`/`X-Autorespond`, `Precedence: bulk|junk|auto_reply`, or
  `X-Auto-Response-Suppress` (Exchange).
- `list`: `List-Id` or `List-Unsubscribe`.
- `self`: the `Message-ID` domain is ours, or `From` is one of our own
  sending addresses (a reflected copy of our own reply).

The job stores the class in `metadata.auto_class` and acts on it:

- `bounce` goes to the phase 2 bounce handler and never creates a thread.
- `auto_reply` and `list` attach to an existing thread when the headers
  match, but **never dispatch the AI job** and never reopen a closed
  thread. With no thread match they land as a thread tagged `auto`,
  which the inbox hides by default.
- `self` is stored for audit and nothing else happens.

An **AI reply budget** backs this up. `ProcessEmailWithAgentJob` refuses
to send more than *N* replies per thread per 24 h (default 5, configured
in `config/orbital.php`) or more than one per inbound message (keyed by
the inbound `EmailMessage` id). When it refuses, it logs a
`ConversationActivity` and moves the thread to `in_progress` with no
operator, so a person picks it up. Outbound AI mail also carries
`Auto-Submitted: auto-replied` (RFC 3834), so well-behaved autoresponders
don't answer it.

**Recipient validation** becomes domain-aware:

- `validateRecipient` accepts only domains that Orbital serves: the
  platform `services.inbound_mail.domain` plus verified tenant domains
  once phase 5 lands. Anything else gets
  `550 5.7.1 relay not permitted`.
- On the platform domain the numeric grammar is unchanged.
- The reserved local parts `bounces+…` (phase 2) and `postmaster` (RFC
  5321 requires it; route it to the platform admin unrouted view) are
  accepted.
- The cache key already includes the domain, because it uses the full
  address.

**Edge rate limits.** A per-recipient and per-sender-domain cap on
accepted messages per hour. It is a Laravel rate limiter consulted in
`validateRecipient`, which returns a status the plugin maps to
`DENYSOFT 451`. The limits stop a flood landing in one client's inbox.

### Phase 2: authentication, spam scoring and bounces (gate)

**Real client IP.** Enable PROXY protocol v2 between whatever fronts port
25 and Haraka:

- Traefik `IngressRouteTCP` gets `proxyProtocol: {version: 2}` on the
  service. A host TCP proxy needs `proxy_protocol on` or its equivalent.
- Haraka trusts PROXY headers only from the proxy's CIDR (`connection.ini`
  `[haproxy] hosts[]`; confirm the key for 3.3.5).
- Make it a chart value. Compose dev keeps it off, because swaks connects
  directly.

**Verification and scoring through Rspamd.** I recommend an Rspamd
sidecar over Haraka's individual spf, dkim and dns-list plugins:

- Rspamd does SPF, DKIM, DMARC and ARC together, plus DNSBL/URIBL,
  Bayes and fuzzy hashes, and it keeps its own Redis (Valkey works).
- Haraka keeps a thin role: `haraka-plugin-rspamd`, pinned and added
  explicitly to the Dockerfile, since `--omit=optional` dropped plugins
  before. Then rescan the image under the Harbor policy.
- Rspamd's verdict becomes headers: `Authentication-Results` and
  `X-Spam-Score`, `X-Spam-Action`.

Policy, per action:

| Rspamd action | Haraka response | Laravel |
|---|---|---|
| `reject` | `550` at end-of-DATA | never sees it |
| `soft reject` / greylist | `451` | never sees it (the sender retries) |
| `add header` / `rewrite subject` | accept | stored, `metadata.spam = {score, action, symbols}`, thread flagged |
| `no action` | accept | normal |

- DMARC `p=reject` failures from domains that publish it are rejected
  unless ARC from a trusted forwarder passes. Forwarding is common for
  answering-service clients, and SRS-less forwarders break SPF.
- Laravel parses `Authentication-Results` into
  `metadata.auth = {spf, dkim, dmarc}`. The thread view shows an
  "unverified sender" badge, the same idea as IS plan 16's "sender not
  verified" for dispatch replies.
- A new routing match type, `spam_flagged`, lets a client route flagged
  mail to a review queue or discard it.
- `routing_status` gains `quarantined` (migration: change the enum, using
  a check-constraint rewrite on Postgres). The **Failed Inbound Mail**
  page (`app/Filament/Pages/UnroutedMail.php`) gets a "Quarantined" tab
  with release and delete.
- Rspamd and the platform toggle are optional at install time. A private,
  scoped-domain deployment can run without them. The admin settings page
  shows a warning while inbound mail is enabled and Rspamd is off.

**STARTTLS** is already supported when a certificate is mounted
(`entrypoint.sh:311-315`). It belongs on the deployment checklist for a
public MX, not in this code plan.

**Bounce handling (VERP).**

- `OutboundReplyService` and `OutboundForwardService` set
  `Return-Path: bounces+{outbound_email_message_id}.{hmac8}@{inbound domain}`.
  The HMAC is over the id with `APP_KEY`, so senders can't forge bounces
  against arbitrary messages. To know the id before sending, the outbound
  row has to be created **before** the send (see below).
- Send flow change:
  1. Create the outbound `EmailMessage` with
     `delivery_status = queued` (new column:
     `queued|sent|deferred|bounced|failed`).
  2. Dispatch `SendThreadReplyJob` on the `inbound-mail` queue. Sending
     leaves the Livewire request and gets retries.
  3. `sent` on success. `failed`, with the transport error in
     `metadata.delivery_error`, on a 5xx from the relay.
- Inbound side:
  - `validateRecipient` accepts `bounces+` addresses whose HMAC is valid.
  - The job sees the bounce address (or `AutoMailClassifier=bounce`)
    *before* routing and hands it to `BounceProcessor`.
  - `BounceProcessor` parses `message/delivery-status`: `Action`,
    `Status`, `Final-Recipient`, `Diagnostic-Code`. If that part is
    missing it falls back to the original `Message-ID` from the attached
    `message/rfc822` or `text/rfc822-headers`.
  - It sets `delivery_status = bounced` (5.x.x) or `deferred` (4.x.x) on
    the outbound row and logs a `ConversationActivity` `bounced` with the
    diagnostic.
  - It moves the thread to `in_progress`, notifies the assigned operator,
    or puts the thread back in the queue when no one owns it.
  - The DSN `EmailMessage` is kept for audit with
    `routing_status = bounce` and no thread.
- UI: an outbound message shows a red "Not delivered: 550 5.1.1 user
  unknown" chip. The inbox gets a "Delivery problems" filter.
- Relay caveat: some smarthosts rewrite the envelope sender, or generate
  bounces to the account owner rather than to `Return-Path`. The admin
  setting for the mailer shows a "send test bounce" check (send to a
  known-bad address and wait for the DSN) so an operator can see whether
  the relay preserves VERP.
- **Outbound alignment**, a dependency rather than this plan's work:
  replies use `From: {account}@{inbound domain}`. For them to pass DMARC
  at the recipient, the inbound domain needs SPF covering the relay and
  DKIM signing (relay-side, or Symfony's `DkimSigner` with a per-domain
  key in settings). Add the check to the deployment docs.

### Phase 3: routing rule editor and honest queue fields

**Editor.** `ManageClientEmailRoutingRules`, a `ClientResource` sub-page
and an "Email rules" section on the Channels hub's Email tab:

- An ordered, reorderable table with name, match type, pattern,
  destination, active toggle, and `source` (`provisioned` / `admin`).
- Match types: `function`, `from_pattern`, `subject_pattern`, `default`,
  plus `spam_flagged` (phase 2) and `recipient` (phase 5, an exact
  address or local part on a tenant domain).
- The destination select depends on the type. Queue and persona are
  scoped to the client. Operator is staff. Discard requires a
  confirmation.
- Pattern validation runs a test compile with `@preg_match` on save,
  rejects patterns that fail, and caps length at 500. The router keeps
  its runtime suppression.
- **"Test a message" panel**: paste or upload an `.eml`, or enter
  from/to/subject, and see which rule fires and why. It calls
  `InboundRouter::routeForTeam` with no side effects, the same as the
  call-routing editor's "what happens at <time>" preview in IS plan 06.
- Guard rail: deleting or deactivating the last `default` rule needs
  confirmation and shows "unmatched mail will go to Failed Inbound Mail".
- Permission: the same as queue management. Admin-side only. Clients
  don't edit rules.

**Honour every field:**

- `discard`: create no thread. Set `routing_status = discarded` (add it
  to the enum) and keep the message for the unrouted view's audit tab.
- `EmailQueue.matched_addresses` / `matched_domain`: pick one option.
  1. Implement them. After rules, `InboundRouter` matches the recipient
     local part (glob) and domain against active queues, the same as
     `InboundMessageRouter`.
  2. Drop them in favour of `recipient` rules.

  I recommend option 2: one place to route is easier to explain. The
  migration converts any non-empty values into `recipient` rules that
  point at the queue, then removes the fields from the form. Fix
  `docs/admin/email-routing.md` either way.
- `ai_first` and the overflow persona: wire them or hide them.
  - Wiring `ai_first` is cheap. When the routed queue's strategy is
    `ai_first` and it has an overflow persona, stamp
    `assigned_agent_persona_id` the same way as a persona rule.
  - Time-based overflow (no operator claimed within *N* minutes) needs a
    scheduled sweep and is closer to the chat and SMS overflow work. Hide
    the option until it exists, so the form doesn't promise behaviour
    that isn't there.

### Phase 4: operator inbox (HTML, attachments, composer)

**HTML bodies.** Render `body_html` in `ViewEmailThread` with the existing
`thread-viewer` partial pattern, as a custom infolist entry or view
component:

- Keep `sandbox` without `allow-scripts`/`allow-same-origin`.
- Inject a CSP `<meta>` into `srcdoc`:
  `default-src 'none'; img-src data: {signed-url-origin}; style-src 'unsafe-inline'`.
  Remote images (tracking pixels, and a privacy issue with PHI) stay
  blocked until the operator clicks "Load remote images" for that
  message, which re-renders with `img-src https:`.
- Sanitise with `symfony/html-sanitizer` before rendering (allow-list
  formatting, tables and images; drop forms, `base` and `meta refresh`).
  Store nothing new: sanitise at render time, cached per message id.
- A text and HTML toggle as in the partial. Text stays the default for
  messages without HTML.

**Attachments and inline previews.**

- `EmailAttachment::previewUrl()` signs a `temporaryUrl` with a 15-minute
  TTL and `ResponseContentDisposition=inline`, falling back to the
  authenticated download route. It reuses the `MessageEntry::temporaryUrl`
  pattern. Every viewer passes the same `authorizeAccess` check first,
  because the signed URL is a bearer credential.
- Preview types are an allow-list: `image/png|jpeg|gif|webp` as `<img>`,
  and `application/pdf` in an `<iframe sandbox>` or a new tab, using the
  browser's viewer. SVG and HTML attachments are **never** inline,
  because they can carry script. Those download only, with
  `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.
  The download route should force these too; today it passes the stored
  `content_type` through unchanged.
- `cid:` images: before rendering, rewrite `src="cid:X"` to the signed
  URL of the attachment with `content_id = X` on the same message.
  Attachments that are inline and referenced are hidden from the
  attachment list.
- Size guard: images over 10 MB show a link instead of a preview.
- Deployment note: signed URLs need the S3 endpoint to be
  browser-reachable, which MMS media already relies on. Where it isn't,
  the fallback (stream through Laravel) is automatic.

**Rich-text composer.**

- Replace the reply `Textarea` with Filament's `RichEditor`, restricted
  to bold, italic, underline, link, bulleted and numbered lists,
  blockquote and undo. No images in v1, and no attachments (a separate
  small follow-up uses `FileUpload` to the `s3` disk).
- On submit:
  1. Sanitise the HTML with the same policy, outbound variant.
  2. Derive `body_text` with a small HTML-to-text pass: links become
     `text <url>`, lists become `- `, blockquotes get `> `.
  3. Call `OutboundReplyService::reply($thread, $text, $html, $user)`.
- Quoting: append the previous inbound message as
  `<blockquote type="cite">`, collapsed in the composer and on by
  default, with an "On {date}, {from} wrote:" header.
- Forward gets the same editor.
- Per-client **signature** (`teams.email_signature_html`, edited on the
  Channels hub) is appended to operator and AI replies. AI replies stay
  plain text. They get the signature's text form plus an HTML part built
  from paragraphs.

### Phase 5: search and volume widget

**Full-text search.** A Postgres-only migration (`DB::getDriverName()`
guard, which `2026_11_04_100000_route_dids_to_orchestration_inbound_phone.php`
already uses):

```sql
ALTER TABLE email_messages ADD COLUMN search_vector tsvector
  GENERATED ALWAYS AS (
    setweight(to_tsvector('simple', coalesce(subject, '')), 'A') ||
    setweight(to_tsvector('simple', coalesce(from_address, '') || ' ' || coalesce(from_name, '')), 'A') ||
    setweight(to_tsvector('english', left(coalesce(body_text, ''), 200000)), 'B')
  ) STORED;
CREATE INDEX email_messages_search_gin ON email_messages USING gin (search_vector);
CREATE INDEX email_messages_team_thread ON email_messages (team_id, thread_id);
```

- `left(…, 200000)` keeps each vector under the 1 MB tsvector limit for
  very long bodies.
- `subject_root` on threads is short enough that the message-level
  subject covers it.
- Use the `simple` config for addresses and subjects (no stemming of
  names or ticket numbers) and `english` for bodies. A future
  `teams.locale` can choose the body config per client. Open question.
- `EmailThreadSearch::apply($query, $term)` on Postgres:
  `whereIn('id', select thread_id from email_messages where search_vector @@ websearch_to_tsquery(...))`,
  ordered by `max(ts_rank)` when a search is active, otherwise by
  `last_message_at`. Terms that look like an address (contain `@`) use a
  prefix match on `from_address`.
- On SQLite (tests) the fallback is `like`.
- Highlight matches in the thread view with `ts_headline` on the open
  thread only, never in the list.

**Volume widget.**

- Add `email_threads.first_response_at`, stamped by
  `OutboundReplyService` the first time an operator or AI reply goes out
  (bounced sends count as replies; failed ones don't). Backfill it from
  existing outbound rows.
- `EmailVolumeWidget` is a `TableWidget` on the admin dashboard. It is
  the first registered widget, so it needs a `widgets([...])` entry and a
  `canView()` limited to platform staff.
- Per client over the last 7 days it shows: threads opened, inbound
  messages, mean and median time to first reply, open and awaiting
  counts, and bounce count. Sort by volume, top 20, with a link to the
  inbox filtered to that client.
- Use one grouped query with a 5-minute cache, the same pattern as the
  inbox badge cache.
- Export `orbital_email_first_response_seconds` (p50/mean per team) from
  `PlatformMetricsCollector::email` so Grafana gets the same number.
  [IS plan 10](../is-parity/10-client-and-staff-reporting.md) owns
  client-facing reporting. This widget is the staff operations view and
  should read from plan 10's daily rollups once they exist.

### Phase 6: tenant-custom inbound domains

The TODO describes this as "a `tenant_domains` lookup table in Haraka's
`rcpt` hook". Haraka already asks Laravel per recipient, so the table and
lookup live in Laravel and **Haraka doesn't change**.

**Model.** `email_domains` (`BelongsToTeam`):

| column | purpose |
|---|---|
| `domain` | unique, lowercased, IDN converted to punycode |
| `mode` | `mx` (MX points at Orbital) or `forward` (the client's mail system forwards to their platform address) |
| `verification_token` | for `_orbital-verify.{domain}` TXT |
| `verified_at`, `mx_checked_at`, `mx_ok` | DNS state |
| `outbound_enabled`, `dkim_selector`, `dkim_private_key` (encrypted) | send-as support |

**Address map.** `recipient` routing rules from phase 3 are scoped to a
domain. A pattern is an exact local part or `*`.

**Flow.**

1. An admin adds the domain on the Channels hub.
2. Orbital shows the TXT record, the MX target, and the SPF include plus
   DKIM CNAME/TXT to publish.
3. A `VerifyEmailDomainsJob` runs on demand and every hour. It resolves
   the TXT and MX records with `dns_get_record` and stamps the state.
4. Unverified domains are rejected at RCPT, so they can't be used to take
   mail for a domain the client doesn't control.

**Routing.**

- `validateRecipient` and `InboundRouter` check the domain first. A
  verified tenant domain resolves the team from `email_domains` and uses
  the full local part as the address, ignoring the numeric grammar
  (`100002@customer-a.com` must never route to account 100002).
- The platform domain keeps the numeric grammar.
- Unknown local parts on a tenant domain get a `550`, or go to the
  catch-all if the client has a `*` recipient rule.

**Replies.** When the thread arrived on a tenant domain and that domain
has `outbound_enabled`, the reply goes out as `From: {original recipient}`
with a per-domain DKIM signature. `Return-Path` stays VERP on the
platform inbound domain. Otherwise it falls back to
`{account}@{inbound domain}` with `Reply-To` set to the original
recipient. Store the "arrived on" address on the thread
(`email_threads.inbound_address`).

**`forward` mode** needs no DNS change at the client beyond a forwarding
rule. It is the low-effort option and the one most clients will take.
The router records the original `To:` (from `Delivered-To` or `X-Original-To`
when present) so `recipient` rules still match. ARC handling in phase 2
matters here.

### Phase 7: thread merging

- A "Merge into…" header action on `ViewEmailThread`, for staff with
  queue-management permission. It opens a picker of open threads for
  **the same client**, with search and a cross-client guard. The thread
  you are on is merged *into* the picked one.
- `EmailThreadMerger::merge(EmailThread $from, EmailThread $into, User $by)`
  runs in one transaction:
  - re-point `email_messages.thread_id`
  - re-point `conversation_activities` (subject morph)
  - union `participants`
  - `into.last_message_at = max`
  - status is the "most open" of the two
  - keep `into`'s assignment and queue
  - mark `from` with `merged_into_thread_id` and `status = closed`, with
    activity `merged` on both threads
- `ViewEmailThread::mount` redirects a merged thread to its target.
  `ThreadResolver` needs no change, because messages moved.
- No unmerge in v1 (rare, manual). The activity metadata records the
  moved message ids, so a later unmerge is possible.

### Phase 8: IMAP pull (separate feature)

A different shape: Orbital acts as a client of the tenant's mailbox and
not as an MX. It is useful for clients who won't touch DNS or forwarding.

- `email_mailboxes` (`BelongsToTeam`):
  - host, port, encryption, auth type (`password` | `oauth_google` |
    `oauth_microsoft`), encrypted credentials or refresh token
  - folder (default `INBOX`), `uidvalidity`, `last_uid`
  - `post_fetch`: `mark_seen` | `move_to:{folder}` | `none`
  - `routing_address`: the platform address the mail is treated as sent
    to, so the existing rules apply
  - `last_polled_at`, `last_error`
- Library: `webklex/php-imap`, which implements IMAP over sockets and
  doesn't need the unbundled `ext-imap`.
- **OAuth2 is required in practice.** Microsoft 365 has turned off basic
  auth for IMAP, and Google requires app passwords or OAuth. Build both
  OAuth flows as a Filament action that stores the refresh token.
- `PollMailboxJob` is scheduled every 2 minutes per active mailbox,
  `withoutOverlapping` per mailbox, on the `inbound-mail` queue:
  1. Fetch `UID > last_uid` (on a `uidvalidity` change, rescan the last
     7 days and dedupe by `Message-ID`).
  2. For each message, store the raw message under `mail-raw/`, create
     the stub `EmailMessage` with
     `metadata = {source: imap, mailbox_id, envelope_to: [routing_address]}`,
     and dispatch `ProcessInboundEmailJob`.
  3. Advance `last_uid` per message, so a crash re-fetches at most one.
- The rest of the pipeline is unchanged. Loop guard and spam metadata
  still apply. Rspamd can't run IP checks on pulled mail, so verdicts
  come from the mailbox provider's own `Authentication-Results`, which
  are trusted for these mailboxes only.
- Replies: by default, platform `From` with `Reply-To` set to the
  mailbox address. Optionally, SMTP submission credentials on the same
  record so replies go out as the mailbox. Then also `APPEND` a copy to
  the mailbox's Sent folder, so the client's own mail client shows the
  conversation.
- **POP3: don't build it.** No UIDs worth trusting, no folders, and every
  provider that offers POP3 also offers IMAP. Close the TODO half of
  this item as "won't do" unless a real client needs it.

## Edge cases

- **Bounce for a message we never sent**, or with a forged VERP: the
  HMAC fails, so the RCPT is rejected.
- **A bounce without a parsable DSN** (some Exchange servers send plain
  text): fall back to the original `Message-ID` lookup. If that fails
  too, store it as `bounce` unmatched in the unrouted view.
- **Delayed DSN (4.x.x) followed by success:** `deferred` is not final.
  Clear it when no further DSN arrives within 72 h. Mark `delivered` only
  if the relay reports it, which most don't, so the UI says "no problem
  reported" rather than "delivered".
- **Auto-reply on a thread the AI owns:** the message is attached, but
  the AI is not triggered and the thread stays as it is. An operator
  sees the out-of-office message in the timeline.
- **A mailing list sends to a client address:** it lands tagged `list`
  and hidden. The client can add a `from_pattern` discard rule.
- **Forwarded mail failing SPF:** DMARC and ARC handle it. A client's own
  forwarder without ARC: add the forwarder to a per-domain "trusted
  forwarder" list (`email_domains.trusted_forwarders`), which downgrades
  failures to a flag.
- **A tenant domain also used by another tenant:** `domain` is unique
  across all teams, and verification proves control.
- **Tenant domain removed while threads are open:** replies fall back to
  the platform `From` plus `Reply-To`, and inbound mail to the domain
  starts returning `550`.
- **Merging threads that both have AI personas:** keep `into`'s persona
  and log it. The merge never triggers an AI reply.
- **Full-text search across many clients for a platform operator:** the
  query stays scoped to the visible-threads set first, then the
  full-text filter, so the GIN index works with the `team_id` predicate
  through a bitmap AND.
- **A huge HTML body (a newsletter):** sanitise with a 2 MB cap and show
  "message too large to render, view raw" beyond that.
- **The same IMAP mailbox configured on two clients:** unique on
  (host, username, folder).

## Migration and rollout

- Phases 0–2 ship together, behind `services.inbound_mail.public` (off
  by default). While it is off, the platform runs exactly as today for
  internal and scoped-domain use. Turning it on in settings requires
  Rspamd to be reachable and PROXY protocol to be configured (a health
  check confirms the client IP is not the proxy's own).
- Enum changes on `email_messages.routing_status` (`quarantined`,
  `discarded`, `bounce`) are Postgres check-constraint rewrites with no
  backfill.
- `delivery_status` on outbound rows: backfill existing outbound rows to
  `sent`.
- The `matched_addresses` conversion to `recipient` rules is a one-off
  migration, logged per client. It runs before the form field is
  removed.
- Generated `search_vector`: adding a stored generated column rewrites
  the table, so schedule it in a maintenance window if `email_messages`
  is large. Otherwise use `CREATE INDEX CONCURRENTLY` in a
  non-transactional migration.
- Docs to update: `docs/admin/email-routing.md` (editor, removed queue
  fields, tenant domains, IMAP), `docs/operator/email-inbox.md` (HTML,
  previews, composer, merge, delivery problems), `docs/roadmap.md`
  (remove the email half of "Routing rule editors"), and the deployment
  guide (MX, PROXY protocol, Rspamd, SPF/DKIM for the inbound domain).

## Tests

- **Pipeline harness (phase 0):** fixture `.eml` files for a plain
  message, HTML plus inline cid, attachments, a reply with
  `In-Reply-To`, a reply without headers, and an unknown account. Assert
  the stored rows, thread grouping and attachments.
- **Haraka plugin:** a Node unit test for `addressOf()` with both shapes,
  and the rcpt mapping for 200, 404 and 5xx (`node --test`, no Haraka
  needed).
- **AutoMailClassifier:** a table test over header sets (RFC 3834
  values, Exchange headers, `List-Id`, null sender, multipart/report).
- **Loop guard:** an AI-assigned thread receives an auto-reply, so no AI
  job is dispatched. The reply budget is hit, so the job refuses and
  writes an activity.
- **validateRecipient:** foreign domain gives 550, the platform domain
  with a numeric part gives 200, `bounces+` with a good or bad HMAC
  gives 200 or 404, a verified tenant domain resolves by full local
  part, and `100002@tenant-a.com` never resolves to account 100002.
- **Bounce:** a DSN fixture from Postfix, Exchange and Gmail marks the
  right outbound row `bounced` with the diagnostic and creates no new
  thread. A relay 5xx during the send job marks it `failed`.
- **Spam metadata:** `Authentication-Results` and `X-Spam-*` parse into
  metadata. `spam_flagged` rules route.
- **Routing editor:** an invalid regex is rejected. The "test a message"
  preview matches `InboundRouter` for the same input. Discard creates no
  thread.
- **Rendering:** the sanitiser strips script and forms. A cid rewrite
  points at the signed URL. SVG never renders inline. Remote images are
  blocked until the toggle is used.
- **Composer:** HTML-to-text output for lists, links and quotes. The
  signature is appended.
- **Search** (`@group pgsql`, run in CI against Postgres): stemming
  matches in the body, an address prefix match, team scoping holds.
- **Widget:** first response time computed from fixture timestamps;
  backfill.
- **Merge:** messages, activities and participants move; the redirect
  from a merged thread works; cross-client merges are refused.
- **IMAP:** a fake IMAP server (`webklex` has a test double, or an
  in-process Dovecot fixture in CI) checks UID advance, the
  `uidvalidity` reset and `Message-ID` dedupe.

## Open questions

1. Rspamd sidecar, or Haraka's own spf, dkim and dns-list plugins?
   Rspamd is better and adds a container plus Redis use. The plugins are
   lighter, but DMARC and ARC support in the Haraka plugin ecosystem is
   thin.
2. Should DMARC `p=reject` failures be hard-rejected at SMTP, or
   quarantined so staff can release them? Hard reject is the standard.
   Quarantine is friendlier while forwarders are being sorted out.
3. Is `forward` mode enough for NCI's clients, making `mx` mode and
   per-domain DKIM signing a later step?
4. Remove `EmailQueue.matched_addresses` (recommended) or implement it?
5. Full-text configuration: fixed `english`, or per client locale?
6. Should AI replies also be HTML, or stay plain text?
7. IMAP: is there a concrete client asking? If not, keep phase 8 parked.

## Size

| Phase | Work | Estimate |
|---|---|---|
| 0 | addressOf merge, pipeline test harness, portable search, atomic claim | 2–3 days |
| 1 | AutoMailClassifier, AI reply budget, domain-aware RCPT, edge limits | 3–4 days |
| 2 | PROXY protocol, Rspamd + plugin, quarantine, VERP bounces, async send job | 1.5–2 weeks |
| 3 | Rule editor + preview, discard, queue-field cleanup, ai_first | 4–5 days |
| 4 | HTML render + sanitiser, previews + cid, rich composer + signature | 1–1.5 weeks |
| 5 | Full-text search, first-response stamp, volume widget, metric | 4–5 days |
| 6 | Tenant domains (verify, routing, send-as + DKIM, forward mode) | 1.5 weeks |
| 7 | Thread merging | 2–3 days |
| 8 | IMAP pull with Google/Microsoft OAuth | 1.5–2 weeks |

**Before public inbound mail:** phases 0, 1 and 2, about 2.5–3 weeks.
Phases 3–7 can follow in any order after that, except that 6 depends on
3 (`recipient` rules) and on 2 (ARC for forward mode). Phase 8 is
independent and optional.
