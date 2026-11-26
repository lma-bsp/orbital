# Plan: Web chat through queues, with an operator chat inbox

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

`docs/roadmap.md:64-67` says chat queues, integration types and an AI
chat surface exist, and three things are missing:

1. chat traffic that passes through its queue instead of going straight
   to a persona
2. an operator chat inbox
3. session persistence

Until those exist, chat is an AI-only channel. A visitor can't reach a
person. Nothing the AI collects becomes a message for the client. Staff
can't read transcripts at all.

The public page is also the platform's only unauthenticated LLM surface,
and it has no abuse controls. TODO.md "Known bugs" lists the hardening:
"Public chat: team lookup `id OR name` (500 on Postgres), no rate
limit/length cap, raw exception shown" (`ChatController.php:27-29`,
`ChatConsole.php:56`), found while writing
[IS plan 24](../is-parity/24-client-web-scripting.md). This plan ships
that hardening as phase 0.

## What exists today (checked in code)

**Public entry point.**

- `GET /chat/{client}/{persona}` (`routes/web.php:99-104`) leads to
  `ChatController::show` (`app/Http/Controllers/ChatController.php:25-61`).
- It finds the team with `Team::where('id', $client)->orWhere('name', $client)`
  (`:27-29`). On Postgres, a non-numeric `{client}` compared with the
  bigint `id` raises `invalid input syntax`, which surfaces as a 500.
  Names aren't unique, so name lookup is ambiguous. Both URL segments
  can be guessed, even though the route comment calls the URL "the
  shared secret" (`routes/web.php:101-102`).
- It then loads the persona by id for that team (`:31-35`), bypassing
  any queue.
- **A `ChatSession` row is created on every GET** that has no session
  cookie (`:37-54`), so crawlers and link unfurlers create rows.
- The token is kept in the Laravel session under
  `chat_session:{team}:{persona}` (`:39-53`). The session cookie is
  `same_site=lax` (`config/session.php:202`) with a 120-minute lifetime
  (`:35`). In a cross-site `<iframe>` the cookie isn't sent, so **the
  page can't be embedded** on a client's website today. Even on the
  direct link, the transcript is lost when the session expires or the
  visitor changes device.

**Chat page and AI turn.**

- `resources/views/chat/show.blade.php` is a full-page Livewire view. It
  loads `app.js`, hard-codes an indigo avatar and has no AGPL source
  footer (plan 24, bug 5).
- `ChatConsole::send` (`app/Livewire/ChatConsole.php:40-61`) calls
  `ChatService::handleTurn` **synchronously inside the Livewire request**.
  There is no length cap and no rate limit. On any exception it shows
  `$e->getMessage()` to the anonymous visitor (`:56`).
  `ChatService::callAnthropic` puts the provider's HTTP status and full
  response body in that message
  (`app/Services/Chat/ChatService.php:87-91`), so provider error text
  leaks to the public.
- `ChatService::handleTurn` compiles the persona's default flow
  (`AgentFlowCompiler::compile($persona)`) and makes a single no-tools
  call with a hard-coded model (`ChatService.php:31-47,76-85`). The
  compiler already produces `functionSchemas`: `set_field`,
  `advance_step`, `transition_to_flow`, `transfer_to_operator`,
  `search_knowledge` and others
  (`app/Services/Flows/AgentFlowCompiler.php:866-1021`,
  `app/Services/Flows/CompiledFlow.php:33-39`). Chat ignores them, so
  `fields` is never written.

**Storage.**

- `ChatSession` stores the whole transcript as a JSON array on the row
  (`app/Models/ChatSession.php:19-35,61-72`; migration
  `2026_04_11_100031_create_chat_sessions_table.php:31-37`).
- The token is a 40-character `Str::random` (`ChatSession.php:37-44`),
  stored in plain text with a unique index.
- `ChatSession` has no `BelongsToTeam`, no queue, no status, no
  assignee, and no `HasConversationActivities`, although that concern's
  docblock names it (`app/Models/Concerns/HasConversationActivities.php:11`).
- **No staff page reads `chat_sessions`.** The only references are the
  controller, the Livewire component and the service.

**Queues.**

- `ChatQueue` (`BelongsToTeam`) has `strategy` (`manual`, `round_robin`,
  `longest_idle`, `ai_first`), `overflow_agent_persona_id`,
  `agent_group_id`, `orchestration_id`, `integration_type`
  (`web_widget`, `slack`, `teams`) and a free-form `integration_config`
  (`app/Models/ChatQueue.php:26-57`).
- Its docblock says sessions are deferred (`:18-20`), and the
  admin page says the same (`ManageClientChatQueues.php:28-31`). The
  integration config is a `KeyValue` with the hint "Web widget:
  site_key …" (`:117`). Nothing reads any of it.
- `IntakeFlow::TRIGGER_INBOUND_CHAT` is a channel trigger
  (`app/Models/IntakeFlow.php:44,52-58`). But `channelEntryFlow()` is
  used only on the routing-rule path (`AgentFlowCompiler.php:157`), so a
  queue's orchestration chat trigger has no effect (plan 24, bug 6).

**Patterns to reuse from the SMS inbox.**

- `MessageInbox` covers the operator inbox page: visibility (assigned to
  me, or unclaimed in a queue whose agent group I'm in, or in an open
  queue), a cached nav badge, an availability subheading through
  `isAvailableForNonVoice()`, a 20 s poll, and bulk close and reopen
  (`app/Filament/Operator/Pages/MessageInbox.php:56-164`).
- `MessageThread::claimFor()` is an atomic conditional UPDATE, and
  `release()` and `close()` sit next to it
  (`app/Models/MessageThread.php:123-169`).
- `ViewMessageThread::takeMessage` writes a complete `Message`, or a
  partial one through `PartialMessagePolicy`
  (`app/Filament/Operator/Pages/ViewMessageThread.php:327-405`,
  `app/Services/Messages/PartialMessagePolicy.php:47-133`).
  `SessionMessageWriter` does the same from AI-captured fields for calls
  (`app/Services/Messages/SessionMessageWriter.php:49-143`).
- `User::messageQueueIds()` resolves agent groups to queue ids
  (`app/Models/User.php:307-322`).
- The named rate limiter pattern is in
  `app/Providers/AppServiceProvider.php:72-74`.
- Reverb broadcasting to per-user private channels is in place
  (`routes/channels.php`, `app/Events/AsteriskDrainInitiated.php`).

## Goal

A visitor on a client's website opens a chat widget and reaches that
client's **chat queue**. The queue decides who answers:

- the AI persona, as for `ai_first`, or as overflow;
- an available human operator, through an inbox that works like the SMS
  and email inboxes; or
- when nobody is available, a "leave a message" path that becomes a
  `Message` in the client's portal.

The AI can hand a visitor to a person, and the person can hand them back.
Conversations survive reloads, embedding and idle gaps. Staff can read
every transcript. The public surface is rate-limited and capped, and it
never shows internal errors.

Non-goals for this plan: Slack and Microsoft Teams transports (see
[Open questions](#open-questions)), file uploads in chat, visitor
authentication, and co-browsing.

## Design

### Phase 0: hardening the current page

This phase ships first, on its own, because the page is public now.

- **Lookup.** `ChatController::show` accepts only a numeric `{client}`
  (`ctype_digit`, otherwise 404) and drops the name branch. That removes
  the Postgres 500 right away. The proper fix, which retires the guessable
  URL, is the queue public key in phase 1.
- **Lazy session.** No `ChatSession` is created on GET. The row is
  created on the visitor's first message.
- **Rate limits.** Named limiters in `AppServiceProvider`, next to
  `messaging-inbound`:
  - `chat-start`: per IP hash and team, 10 per hour.
  - `chat-turn`: per session, 12 per minute and 200 per session; per IP,
    60 per minute.
  - A per-team daily ceiling on AI turns, `config/orbital.php`
    `chat.max_ai_turns_per_team_per_day`, default 2,000.

  The limits are applied in `ChatConsole::send` with
  `RateLimiter::attempt`, because Livewire actions don't pass through
  route throttling. When a limit trips, the visitor sees a friendly line
  ("Too many messages. Please wait a moment."), and no LLM call is made.
- **Caps.** The visitor's message is capped at 2,000 characters
  (Livewire `#[Validate('max:2000')]`). Text is NFC-normalised and
  control characters are stripped. The LLM history is limited to the
  last 40 turns or about 24k characters, whichever is smaller. A session
  is closed after 200 turns.
- **Errors.** `ChatConsole` catches the exception, logs it with the
  session id and team, and shows a fixed message: "Sorry, something went
  wrong. Please try again, or call us at {client main number}."
  `ChatService` stops putting the provider body in its exception message
  and logs it instead.
- **Footer.** Add the AGPL source footer to `chat/show.blade.php`.
- **Model.** Use `orbital.default_llm_model` instead of the hard-coded
  model string. That is a one-line bridge until
  [IS plan 25](../is-parity/25-operator-message-aids.md)'s shared
  `LlmClient` lands. `ChatService` is one of the call sites that plan
  lists.

### Phase 1: data model, persistence and the no-cookie API

**`chat_sessions` changes.** The session becomes the conversation, like
`MessageThread`.

| column | notes |
|---|---|
| `chat_queue_id` | FK, nullable only for legacy rows |
| `token_hash` | SHA-256 of the visitor token; replaces `public_token` (backfilled by hashing) |
| `status` | `new` / `in_progress` / `awaiting_reply` / `closed`, the same vocabulary as email and SMS, so the inbox code and filters carry over |
| `mode` | `ai` / `queued` / `human` / `offline`: who is expected to answer next |
| `assigned_operator_id`, `assigned_agent_persona_id` | the latter renames `agent_persona_id` and becomes nullable |
| `visitor_name`, `visitor_email`, `visitor_phone` | from the pre-chat form or captured fields |
| `origin`, `page_url`, `ip_hash`, `user_agent` | risk signals and operator context; `ip_hash` is HMAC with `APP_KEY` |
| `queued_at`, `handed_off_at`, `first_response_at`, `last_visitor_at`, `closed_at`, `end_reason` | timing for overflow, reporting and idle close |
| `fields` | unchanged (captured data, shape of `call_session_states.fields`) |

- Add the `BelongsToTeam` and `HasConversationActivities` traits. Public
  endpoints load sessions with `withoutGlobalScopes()` and always by
  `token_hash` plus queue, never by id.

**`chat_messages`** (new) replaces the JSON column:

| column | notes |
|---|---|
| `chat_session_id`, `team_id` | indexed (`chat_session_id`, `id`) |
| `role` | `visitor` / `ai` / `operator` / `system` |
| `user_id`, `agent_persona_id` | who sent it |
| `body` | text, max 4,000 |
| `payload` | JSON, optional: quick-reply buttons (for [IS plan 15](../is-parity/15-ivr-and-auto-attendant.md) menus rendered as buttons) and the button chosen |
| `client_message_id` | idempotency key from the widget, unique per session |
| `created_at`, `read_by_visitor_at`, `read_by_staff_at` | |

- The migration copies every JSON `messages` entry into rows (`user` to
  `visitor`, `assistant` to `ai`) and keeps the JSON column for one
  release before dropping it.
- `messages` gains `chat_session_id` (nullable FK, unique where not
  null), the counterpart of `message_thread_id`.

**Visitor token and persistence.**

- `POST /api/chat/{queue_key}/sessions` returns `{token, session}`. The
  token is 40 random characters, and the server stores only its hash.
- The widget keeps the token in `localStorage` under
  `orbital-chat:{queue_key}`, with fallback to `sessionStorage`, then
  memory. Inside an iframe, browsers partition storage by the top-level
  site, so one visitor on two client sites gets two sessions. That is
  the behaviour we want.
- Nothing depends on cookies, so Safari ITP and Chrome's third-party
  cookie changes don't affect it.
- `GET /api/chat/{queue_key}/sessions/{token}?after={message_id}`
  returns new messages, `mode` and `status`, and whether an operator is
  typing (phase 4). It is the resume call on load and the poll call.
- `POST …/messages` takes `{body, client_message_id}`. It enforces the
  phase 0 limits and caps, then returns `202`. The AI turn runs in a
  queued job (`ProcessChatTurnJob`, on a new `chat` Horizon queue
  separate from telephony), so no visitor request waits up to 60 s on
  the LLM.
- `POST …/end` lets the visitor end the chat.
- **Idle close.** A scheduled sweep closes sessions with no visitor
  activity for `chat_queues.idle_close_minutes` (default 30) and sets
  `end_reason=idle`. A token for a closed session resumes a read-only
  transcript with a "Start a new chat" button.
- **Retention.** `chat_messages` follow the client's retention setting,
  and a prune job runs nightly. Transcripts can carry PHI. Never put
  message bodies in logs or traces (the observability `Scrubber` already
  covers event payloads).
- **Staff transcript view.** Phase 2's `ViewChatSession` works read-only
  for closed sessions. An admin "Chat sessions" table on the client
  record lists every session with filters for queue, mode and date.

**Public key and routes.**

- `chat_queues.public_key` holds 24 random characters, rotatable. It
  replaces the free-form `site_key` hint, and the `KeyValue` config
  becomes structured fields (see phase 4).
- New public page: `/chat/w/{public_key}`.
- The old `/chat/{client}/{persona}` becomes a **signed** admin preview
  link (`URL::temporarySignedRoute`, shown on the persona page as "Test
  in chat"). Unsigned hits redirect to the client's first active
  `web_widget` queue page if there is one, and otherwise return 410.

### Phase 2: queue routing and the operator chat inbox

**`ChatRouter::start(ChatQueue $queue, ChatSession $session)`** decides
the initial `mode`:

| Queue | Operators available? | Result |
|---|---|---|
| `ai_first` with persona | any | `mode=ai`, persona answers |
| `manual` / `round_robin` / `longest_idle` | yes | `mode=queued`; round robin and longest idle auto-assign (below); manual waits for a claim |
| same | no, persona set | `mode=ai` via overflow persona |
| same | no, no persona | `mode=offline`: leave-a-message form (phase 3) |
| inactive queue | — | `offline` with the queue's offline text |

- **"Available"** means at least one user who is
  `isAvailableForNonVoice()`, belongs to the queue's agent group (or the
  queue is open), and has fewer open chats than
  `users.max_concurrent_chats` (new, default 3).
- `ChatRouter` also handles overflow while a session waits.
  `ChatOverflowSweep` runs every 15 s from the scheduler. Sessions in
  `queued` longer than `chat_queues.max_wait_seconds` (default 90) move
  to the overflow persona, or to `offline` if there isn't one. The
  visitor sees a system message for each transition.
- **Auto-assignment** for `round_robin` and `longest_idle` picks an
  eligible operator and claims for them. It uses the same conditional
  UPDATE as a manual claim, so it can't double-assign. Manual remains
  the default, the same as for email and SMS.
- **Orchestration.** When the session runs AI, compile with the queue's
  orchestration and use its `inbound_chat` trigger's `channelEntryFlow()`
  when one exists (fixes plan 24 bug 6). `AgentFlowCompiler::compile`
  gains an optional entry-flow or orchestration argument, which mirrors
  the routing-rule path at `:157`.

**Shared claim code.** Extract `MessageThread::claimFor/release/close`
into a `ClaimsConversation` concern used by `MessageThread`,
`ChatSession` and `EmailThread`. The email plan's phase 0 asks for the
same fix, so the email inbox stops using its non-atomic claim.

**`ChatInbox`** (`app/Filament/Operator/Pages/ChatInbox.php`) sits in the
Operator panel, `Inbox` group, next to Email and Messages. It works like
`MessageInbox` with the channel swapped:

- Visibility: `User::chatQueueIds()`, a copy of `messageQueueIds()`.
- Sorted by **waiting time**, oldest first, with a live "waiting 0:42"
  counter. Mode badges: `queued` in red, `ai` in grey (the AI is
  handling it, and you can take over), `human` in green.
- Poll every 5 s. Chat is answered in seconds, not minutes. A Reverb
  event `ChatSessionWaiting` on each eligible operator's
  `App.Models.User.{id}` channel triggers an immediate refresh, a toast
  and an optional sound.
- The nav badge counts sessions in `queued`, cached for 5 s.
- Claim/Release badge column, and bulk close.

**`ViewChatSession`** is the conversation page:

- Transcript with role styling. The visitor's page URL, origin,
  pre-chat details, and AI-captured `fields` show in a side panel, the
  same idea as the call workspace's captured fields.
- A plain-text composer. Enter sends, Shift+Enter adds a newline. It
  posts a `chat_messages` row with `role=operator`, sets
  `first_response_at` the first time, and moves status to
  `awaiting_reply`.
- Header actions:
  - **Claim**, **Release** (back to `queued`)
  - **Hand to AI** (phase 3)
  - **Transfer** to another of the client's chat queues
  - **Take message** (phase 3)
  - **End chat**, with an optional closing line
- Livewire polling every 2 s while the page is open. Phase 4 replaces
  it with push.
- [IS plan 02](../is-parity/02-operator-scripting.md)'s `ScriptRunner`
  can run beside the transcript when the queue has an orchestration,
  just as it will for calls. This plan doesn't depend on it.

### Phase 3: AI tool loop, AI-to-human handoff, take message

**Tool loop.** `ChatService` becomes a bounded tool-use loop of at most
5 tool rounds per turn over the compiled `functionSchemas`.

Implemented in v1:

- `set_field`, which writes `chat_sessions.fields`
- `advance_step` and `transition_to_flow`, which record the current flow
  and step in `fields._flow` the same way the voice worker does
- `search_knowledge`, which reuses the knowledge retrieval service and
  is scoped to the session's team
- `transfer_to_operator`, offered on chat **whenever the queue has human
  operators**, not only when the flow has an external handoff step. A
  visitor typing "talk to a person" is the common case.

Not offered on chat: `transfer_call`, `send_sms` and other
telephony-only tools. They are filtered out with an allow-list in
`ChatService`, in line with plan 24's per-surface step list.

**AI to human.** `transfer_to_operator` and the operator's **Take over**
both pass through `ChatHandoff::toHuman(session, reason)`:

1. A conditional UPDATE sets `mode=queued` and `handed_off_at`, only if
   `mode=ai`.
2. It posts the system message "Connecting you with a member of our
   team…".
3. It broadcasts `ChatSessionWaiting`.
4. The AI's own turn ends there.

Operators see the captured fields and the whole transcript, so the
visitor doesn't have to repeat themselves. If nobody claims within
`max_wait_seconds`, the visitor is offered the message path. The AI
does not resume, because the visitor asked for a person.

**Human to AI.** "Hand to AI" is available when the queue has a persona.
It sets `mode=ai`, assigns the persona and releases the operator. The
persona gets the transcript with operator turns projected as
`assistant`, plus a system note that "a team member handled the last
part of this conversation".

**Never both.** `ProcessChatTurnJob` reads `mode` at the start. Before
it writes the AI reply, it does a compare-and-set on
`(id, mode='ai', assigned_agent_persona_id)`. If an operator took over
during the LLM call, the reply is dropped and logged, not sent.

**Take message from chat.**

- *Operator.* `ViewChatSession::takeMessage` is a copy of
  `ViewMessageThread::takeMessage`. It pre-fills name, phone and reason
  from `fields` and visitor details, writes `Message` with
  `chat_session_id`, and follows `PartialMessagePolicy`:
  complete → save; incomplete → `keepsPartials` + `isWorthKeeping`,
  otherwise refuse with the same notices. The goal is the one the chat
  flow ran, if known, and otherwise the client default, the same seam as
  `SessionMessageWriter::goalFor()`.
- *AI.* `ChatSessionMessageWriter` is the chat counterpart of
  `SessionMessageWriter`:
  - It writes a complete `Message` as soon as `caller_name` and `reason`
    are captured.
  - When the session ends (visitor end, idle close or turn cap) with
    fields missing, it writes a partial with
    `REASON_SESSION_ABANDONED`, but only if the policy allows.
  - It is idempotent through the unique `messages.chat_session_id`, with
    no notes marker needed.
  - The existing hook points are `ProcessChatTurnJob` after each
    `set_field` and the idle-close sweep.
- *Offline form.* In `mode=offline` the widget shows name, phone,
  optional email, and "how can we help". Submitting writes a complete
  `Message`, or a partial according to policy, plus a `system` chat
  message with the text, and closes the session. This is the
  after-hours answering-service path, and where a client's "Dispatch"
  rules from [IS plan 05](../is-parity/05-delivery-and-escalation.md)
  start applying once that plan lands.

[IS plan 03](../is-parity/03-message-model.md) redesigns `Message`.
Chat writes through the same writer seam, so it moves with that plan.

### Phase 4: embeddable widget, branding, push

**Widget.** This follows plan 24's public form approach and should share
its code (see below).

- `GET /chat/w/{public_key}/embed.js` is a small loader. The client
  pastes
  `<script src="https://orbital.example/chat/w/KEY/embed.js" async></script>`.
  It adds a launcher bubble and, when opened, an `<iframe>` to
  `/chat/w/{public_key}`.
- `postMessage` carries only `{open, close, unread, height}`, and the
  origin is checked on both sides.
- The page inside the iframe is a framework-free Vite entry, with no
  Filament, Livewire or `app.js`. It talks only to the phase 1 JSON API.
- It also works as a direct link, which is the old full-page use.
- Headers:
  - `Content-Security-Policy: frame-ancestors {allowed_origins}`, or
    `'none'` when the list is empty, which means direct link only
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
- Accessibility: a focus trap in the open panel, `aria-live="polite"`
  for incoming messages, full keyboard operation, and reduced-motion
  support.

**Queue configuration.** Replace the `KeyValue` for `web_widget` with
real fields on `ManageClientChatQueues`:

- allowed origins
- title, greeting, offline text
- accent colour, avatar (per-client branding from plan 24 when it
  lands)
- pre-chat form on or off, and required fields
- `max_wait_seconds`, `idle_close_minutes`
- public key with rotate
- the embed snippet to copy

Hide `slack` and `teams` from the integration select until those
transports exist.

**Push.** Replace polling with Reverb where available:

- Visitor: a private channel `chat.session.{session_id}`, authorised by a
  custom endpoint `POST /api/chat/{queue_key}/broadcast-auth` that checks
  the token (Pusher-protocol signature, no cookie).
- Operator: the session page subscribes to the same channel through the
  normal staff auth.
- Polling stays as the fallback when the socket can't connect, for
  example on corporate proxies.
- Typing indicators ride on the socket only, as client events throttled
  to one every 3 s.

**Abuse controls** (on top of phase 0). This reuses plan 24's
`AbuseGuard`:

- honeypot field and minimum-time check on session start
- optional ALTCHA proof-of-work on start, switched on automatically for
  a queue when the start rate crosses a threshold
- `Origin` against `allowed_origins`, recorded as a risk signal only
- auto-pause of a queue that exceeds its daily caps, with staff
  notified

**Shared with plan 24.** The no-cookie embed loader, CSP handling,
`AbuseGuard`, per-client branding and the source footer partial are
common to both plans. Plan 24 asks in its open question 7 whether chat
should move onto the same embed. This plan's answer is yes. Whichever
lands first builds them in `app/Support/PublicEmbed/` and
`resources/js/public-embed/`, and the other reuses them.

## Edge cases

- **Two operators claim at once:** the conditional UPDATE lets one win.
  The other gets "Already claimed".
- **Operator takes over while an AI reply is in flight:** the
  compare-and-set drops the AI reply.
- **Visitor sends several messages while an AI turn runs:** the job
  takes all visitor messages since the last AI reply as one user turn,
  with one job per session, enforced with `ShouldBeUnique` keyed by
  session.
- **Operator goes unavailable or logs out with open chats:** the
  sessions release to `queued` and the overflow timer restarts from
  release. The visitor sees "Still connecting you…".
- **All operators at `max_concurrent_chats`:** they count as
  unavailable, so the overflow persona or offline path applies.
- **Visitor closes the tab mid-handoff:** the session stays `queued`
  until idle close, then the abandoned-partial policy runs. The operator
  inbox shows "visitor left" from `last_visitor_at` (no poll for 60 s).
- **Token copied to another device:** it resumes the same conversation.
  That is acceptable, because the token is the credential, like plan
  24's form token. Rotating the queue's public key does not invalidate
  sessions in progress, but it does stop new starts on the old key.
- **Client website embeds on an origin not in `allowed_origins`:** CSP
  blocks the frame. The direct link still works unless the list is set
  to "embed only".
- **Visitor pastes 2,000 characters of junk repeatedly:** caps and
  per-session limits apply, and the session closes at the turn cap.
- **The AI is asked about another client:** retrieval and compile are
  scoped to the session's team, the same as voice.
- **Legacy sessions with no queue:** they are readable in the staff
  transcript view and are never routed. Closed by the migration.
- **Queue deleted with open sessions:** the FK is `nullOnDelete`, and
  the sessions are closed with `end_reason=queue_removed`.
- **PHI in chat:** transcripts are excluded from logs and traces, and
  client retention applies. Email transcripts to the visitor are off
  (open question).

## Migration and rollout

1. **Phase 0** ships immediately as a patch: no schema change, no
   behaviour change beyond limits and errors.
2. **Phase 1 migrations:**
   - add columns
   - create `chat_messages` and copy the JSON
   - hash `public_token` into `token_hash`
   - add `messages.chat_session_id`
   - give each existing client with a chat queue a `public_key`

   Existing sessions get `mode=ai`, `status=closed` when idle for more
   than 30 min, and otherwise `in_progress`. The JSON column and
   `public_token` are dropped one release later.
3. **Old URLs** keep working as signed previews. Unsigned public links
   redirect to the queue page where one exists. Tell clients who pasted
   the old link to switch to the snippet.
4. **Feature flag** `chat.queue_routing`, per client on the
   `ChatQueue`, so NCI can move one client at a time. While it is off,
   the queue page behaves AI-only (`ai_first`).
5. **Horizon:** add the `chat` queue to the supervisor config and the
   Helm values.
6. **Docs:** `docs/admin/channels.md` (chat maturity paragraph), a new
   `docs/operator/chat-inbox.md`, `docs/roadmap.md` (move web chat from
   "missing" to shipped per phase), and an embed guide for clients.

## Tests

- **Phase 0:** a non-numeric `{client}` returns 404, not 500 (run on
  SQLite and in a `@group pgsql` test). No session row is created on
  GET. The 13th message in a minute is refused without calling the
  provider (`Http::fake` asserts no request). A 2,001-character message
  is rejected. A provider 500 shows the fixed text, and the body isn't
  in the rendered HTML.
- **API:** start, send, poll, end and resume by token. An unknown token
  or a token for another queue gives 404. `client_message_id` dedupe.
  Turns are queued (`Queue::fake`).
- **Router:** a table test over strategy, availability and persona
  presence for the starting `mode`. The overflow sweep moves to the
  persona or offline after `max_wait_seconds` (frozen clock).
  Concurrency caps.
- **Inbox:** visibility by agent group and open queue. Atomic claim
  race (two claims, one wins). Release returns the session to the queue.
  The badge counts `queued` only.
- **Handoff:** `transfer_to_operator` tool call leads to `mode=queued`
  with a system message and a broadcast (`Event::fake`). The
  compare-and-set drops an in-flight AI reply after a claim. Hand-back
  leads to `mode=ai` with operator turns as assistant in the projected
  history.
- **Tool loop:** `set_field` persists. Telephony tools are not offered.
  The loop stops at 5 rounds.
- **Messages:** operator take-message (complete, partial allowed,
  partial refused, not worth keeping). The AI writer writes a complete
  message once and stays idempotent. Idle close writes an abandoned
  partial only under the policy. The offline form writes a `Message`.
- **Embed:** CSP `frame-ancestors` reflects `allowed_origins`.
  `broadcast-auth` signs only for the matching token.
- **Migration:** JSON transcripts become identical `chat_messages` rows.
  Tokens are hashed and resume still works.

## Open questions

1. Should chat sessions appear in the multi-call operator workspace
   (`MultiCallWorkspace` / `OperatorCall`) next to calls, or only in the
   inbox? Answering-service operators often handle a chat while on a
   call. The inbox is the smaller first step.
2. Is `max_concurrent_chats` per operator, per agent group, or both?
3. Slack and Teams: build them, or remove them from `ChatQueue`? They
   are a different shape (the visitor is an authenticated workspace user
   and the transport is a bot API) and probably serve a different buyer
   than an answering service's web visitors.
4. Should visitors be able to get an emailed transcript? It's
   convenient, but it sends PHI to an address nobody verified.
5. Should the pre-chat form be required by default? It improves message
   capture and costs some starts.
6. Is a chat turn billable? [IS plan 09](../is-parity/09-usage-and-billing.md)
   needs a `usage_events` type for chat sessions and AI turns.
7. Who sees the closed-session transcripts in the client portal, and
   with which `portal.view_*` permission (see plan 24, bug 1)?

## Size

| Phase | Work | Estimate |
|---|---|---|
| 0 | Lookup fix, lazy session, limiters, caps, safe errors, footer | 2–3 days |
| 1 | Schema + JSON migration, token hashing, JSON API, turn job, idle close, staff transcript view | 1.5 weeks |
| 2 | ChatRouter + overflow sweep, auto-assign, shared claim concern, ChatInbox, ViewChatSession | 1.5 weeks |
| 3 | Tool loop, handoff both ways, compare-and-set, take message (operator, AI writer, offline form) | 1–1.5 weeks |
| 4 | Embed loader + widget page, queue config fields, Reverb push + auth, AbuseGuard reuse | 1–1.5 weeks |

Total: about **5½–6½ weeks**. If plan 24 has already built the shared
embed and abuse code, phase 4 shrinks by about half. Phase 0 is
independent and should ship now.
