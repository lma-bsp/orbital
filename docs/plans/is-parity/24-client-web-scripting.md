# Plan 24: Client web scripting — portal-run scripts and public web forms

**Status:** proposed · part of [IS parity](README.md) · expands survey
item R in [07](07-additional-features.md) · depends on 01 (engine),
02 (runner), 03 (messages); dispatch needs 05, on-call lookups need 04,
time branches need 06

## Problem

### What IS does

Amtelco's IS lets a script run **outside the operator console**. A
client's own staff log in through a browser and run a script
(e.g. a clinic's front desk enters "after-hours patient request"
before leaving for the night). The script asks questions, branches,
looks up who is on call, saves a message and dispatches it, exactly as
if an operator had taken the call. The same scripts can be published
as web forms on the client's website, so a member of the public can
submit a service request that becomes a message and goes through the
client's normal dispatch.

### What Orbital has today

Checked against the code on `fix/sip-edge-desktop-phones`:

**The portal is read-only by design and has no "run" surface.**

- The portal panel (`app/Providers/Filament/PortalPanelProvider.php:39-136`)
  discovers pages in `app/Filament/Portal/Pages` and resources in
  `app/Filament/Portal/Resources` (`:64-65`). There are three pages,
  `Dashboard`, `CallHistory` and `TextConversations`, and one
  resource, `RoleResource`. Every page is a view. `TextConversations`
  says outright that the portal is read-only
  (`app/Filament/Portal/Pages/TextConversations.php:17-24`).
- `docs/roadmap.md:130-132` (Client self-service) says the portal is
  "deliberately read-only" and suggests clients could maintain an
  on-call rota or FAQ "without giving them routing controls".
  `docs/roadmap.md:146-149` rules out clients taking their own calls.
  Entering a request is neither of those things: no client staff member
  answers a call and no routing changes. It is **an inbound trigger
  whose caller happens to be logged in**, which fits the product line.
- Portal pages filter explicitly on `current_team_id` and don't rely on
  the `BelongsToTeam` scope (`Dashboard.php:20-22,50-53`;
  `CallHistory.php:20-25,48-51`; `TextConversations.php:26-30`). This
  plan keeps that pattern.

**Permissions exist but don't gate anything yet.**

- The client permission vocabulary is `portal.view_home`,
  `view_calls`, `view_messages`, `view_recordings`, `manage_users` and
  `manage_roles` (`app/Services/Clients/ClientProvisioner.php:50-55`;
  the default invitee subset is at `:65-68`;
  `database/seeders/PermissionCatalogSeeder.php:45-53`).
- Client admins assign these to roles through `RoleResource`. The
  options are limited to the operator-managed allow-list
  `client_permission_grants` (`RoleResource.php:208-221`), and all
  writes go through `ClientPermissionGatekeeper`. The descriptions shown
  next to each permission come from a hard-coded map
  (`RoleResource.php:230-242`).
- Only `portal.manage_roles` is checked (`RoleResource.php:178-191`).
  No portal page checks any `portal.view_*` permission (see Bugs).

**Web chat is the only public, unauthenticated surface, and it's AI-only.**

- `GET /chat/{client}/{persona}` (`routes/web.php:99-104`) →
  `ChatController::show` (`app/Http/Controllers/ChatController.php:25-61`).
  It resolves the team by id *or name* (`:27-29`), creates a
  `ChatSession` with a 40-character `public_token`
  (`app/Models/ChatSession.php:37-44`) that is stored in the Laravel
  session, and renders `resources/views/chat/show.blade.php`. That is a
  standalone page with no embed snippet, no iframe or CSP handling and
  no client branding (it hard-codes an indigo avatar).
- `ChatSession` does **not** use `BelongsToTeam` (`ChatSession.php:17`)
  because it's anonymous, so `team_id` is set by hand.
- `ChatConsole::send` (`app/Livewire/ChatConsole.php:40-61`) calls
  `ChatService::handleTurn`, which compiles the persona's flow into a
  prompt and calls Anthropic with no tools
  (`app/Services/Chat/ChatService.php:14-23,31-46`). Nothing it gathers
  turns into a `Message`.
- `ChatQueue` defines `web_widget` / `slack` / `teams` integrations
  (`app/Models/ChatQueue.php:34-44`) with a free-form
  `integration_config` (the admin hint mentions a `site_key`,
  `ManageClientChatQueues.php:117`). Its own docblock says sessions are
  deferred (`ChatQueue.php:18-20`), and `ChatController` doesn't consult
  it. `docs/roadmap.md:64-67` confirms that chat doesn't yet go through
  its queue.

**`ClientWebEndpoint` is outbound, not inbound.** It's a named
REST/SOAP target for the `web_call` primitive with encrypted
credentials (`app/Models/ClientWebEndpoint.php:12-16,22-38,54`). It
can't receive a form submission, so we shouldn't reuse it or its name
for one (see Design → naming).

**Trigger types: `manual` is declared but unused, and there is no
web-form trigger.**

- `IntakeFlow` declares `TRIGGER_INBOUND_CHAT` and `TRIGGER_MANUAL`
  (`app/Models/IntakeFlow.php:44,50`). Only the five telephony and
  messaging channels are in `CHANNEL_TRIGGERS` (`:52-58`).
  `TRIGGER_MANUAL` is referenced nowhere else in `app/` or
  `resources/js/`.
- `ChannelTriggerSeeder::CHANNELS`
  (`app/Services/Flows/ChannelTriggerSeeder.php:30-36`) and the flow
  editor's `CHANNELS` (`resources/js/flow-editor/App.svelte:103`) list
  only those five.
- The step library has trigger primitives for phone, email, message,
  chat and outbound phone
  (`database/seeders/IntakeGoalLibrarySeeder.php:1258-1320`). There is
  none for manual/portal or web form.
- `inbound_chat` trigger flows are seeded but not used at runtime.
  `ChatService` compiles the persona's default flow and never resolves
  the chat trigger's `channelEntryFlow()` (`IntakeFlow.php:110`). The
  only caller of `channelEntryFlow()` is the routing-rule path in
  `AgentFlowCompiler.php:157`.

**The step catalogue** has about 65 primitives in 8 categories (counted
from the seeder). Most of them have no meaning for a web user:
transfer, park, hold, recording, queue entry, DID/extension matching.
There's no per-surface allow-list, and step exits are derived only
from category (`IntakeGoalLibrarySeeder.php:100-105`).

**Operator runner.** `CompiledFlowViewer` is a read-only checklist
(`app/Livewire/CompiledFlowViewer.php:13-24`). With no persona it falls
back to *any* client's first active persona (`:104-112`), which is the
known wrong-client bug. Plan 02 replaces it with `ScriptRunner`, and
this plan reuses that runner. It must never reuse the viewer's
fallback.

**Branding is platform-wide only.** `PortalBranding` is super-admin
only (`app/Filament/Pages/PortalBranding.php:59-62`) and sets one
portal name, logo, favicon and colour through `Branding::portal*`
(`app/Support/Branding.php:66-112`), which feeds
`PortalPanelProvider.php:47-57`. That is the *answering service's*
brand. A form on a clinic's website needs the *clinic's* brand, and
no per-client branding exists (`Team` has `timezone`, but no logo or
colour).

**Existing pieces to reuse:** the per-client API key middleware and
throttle for the public v1 API (`routes/api.php:188`); the named rate
limiter pattern (`AppServiceProvider.php:72-74`, `messaging-inbound`);
`ConversationActivity` for polymorphic activity rows; and the AGPL
source footer partial
(`resources/views/filament/partials/source-footer.blade.php`).

## Goal

1. **Portal-run scripts.** Client staff with permission pick a
   published script in the portal and run it through the plan 02
   `ScriptRunner` in a restricted web mode. The plan 01 engine executes
   it, it produces a plan 03 message, and that message dispatches
   through plan 05. The reference scenario: a clinic's front desk
   enters an after-hours request. The script branches on urgency, looks
   up the on-call doctor (plan 04) and pages them; a routine request is
   held until morning (plan 06).
2. **Public web forms.** The same kind of script, published as a web
   form, is embedded on the client's website. It's a new inbound
   trigger with spam, abuse and rate-limit protection, and its
   submissions become messages and dispatches.
3. **Headless submissions.** The client's own backend can POST field
   values with its API key. The engine runs the script non-interactively.
4. All three are a **surface over the same engine**. There's no second
   form builder and no separate "web message" model. Script authoring
   stays with platform staff.

Non-goals: clients editing script logic or routing; file uploads (v1);
payments; turning web chat into forms (chat stays conversational and
AI-led); a no-code page builder.

## Design

### Concepts and naming

- **Web script**: the binding of one orchestration entry point to a
  web surface, plus that surface's settings. A client can have several,
  e.g. "After-hours request", "Maintenance request" and
  "Contact us (public)".
- **Surface**: `portal` (authenticated client staff), `public`
  (anonymous embed) or `api` (server-to-server, API key). One web
  script can enable `portal`, `public` and `api` independently. They
  share the graph but have separate security settings.
- Avoid "web endpoint" (taken by the outbound `ClientWebEndpoint`) and
  "web chat" (conversational).

### Triggers

- Use the existing `IntakeFlow::TRIGGER_MANUAL` for portal runs ("a
  person starts it by hand"). It's unused today, so there is no
  migration risk.
- Add `IntakeFlow::TRIGGER_INBOUND_WEB_FORM = 'inbound_web_form'` for
  the public and API surfaces.
- Add both to `CHANNEL_TRIGGERS`, `ChannelTriggerSeeder::CHANNELS` and
  the editor's `CHANNELS` (`App.svelte:103`, with labels and icons in
  `FlowNode.svelte:61`). Add library primitives `trigger_manual`
  ("Started from portal") and `trigger_inbound_web_form` ("Web form")
  in the same style as the existing ones
  (`IntakeGoalLibrarySeeder.php:1258-1320`).
- A web script points at a trigger flow, and the session starts at
  `channelEntryFlow()`. That's the same resolution phone uses, so web
  scripts don't get a resolution path of their own. Several web scripts
  can share one orchestration through separate trigger flows, e.g. one
  `manual` trigger for staff and one `inbound_web_form` trigger for the
  public version with a shorter graph.

### Data model (all `BelongsToTeam`, `declare(strict_types=1)`)

**`web_scripts`**

| Column | Notes |
|---|---|
| `team_id` | FK, indexed |
| `orchestration_id`, `trigger_flow_id` | the trigger flow must belong to the orchestration *and* the team (validated on save and at session start) |
| `name`, `description`, `slug` | slug unique per team, for portal URLs |
| `portal_enabled`, `public_enabled`, `api_enabled` | booleans, default false |
| `status` | `draft` / `published` / `paused` (kill switch) / `archived` |
| `public_key` | 32-char random, unique, rotatable; the public URL key. Never a team id or name |
| `layout` | `wizard` (one step per page) / `single_page` (allowed only when the reachable graph is linear up to the first save; validator) |
| `allowed_origins` | JSON list of `https://host[:port]`, used for CSP `frame-ancestors` and the Origin check |
| `abuse_settings` | JSON: `captcha` (`off` / `altcha` / `turnstile` / `hcaptcha`), `min_fill_seconds` (default 3), `per_ip_per_hour` (10), `per_form_per_hour` (200), `per_team_per_day` (1000), `auto_pause_threshold` |
| `public_dispatch_mode` | `direct` / `review_first` (**default** for public) / `review_urgent_only` |
| `branding` | JSON overrides: logo asset, primary colour, heading, intro text, success text, `show_reference` |
| `consent_text` | optional; shown as a required checkbox on public forms |
| `retention_days` | for abandoned or spam submissions (default 30) |
| `published_at`, `published_by_user_id` | |

**`web_script_role`** (pivot): `web_script_id`, `role_id`. Lets a
client admin limit a portal script to certain roles, e.g. "Nurses".
An empty pivot means anyone with `portal.run_scripts`. Role ids are
validated against `roles.team_id = web_scripts.team_id`.

**`web_submissions`**

| Column | Notes |
|---|---|
| `team_id`, `web_script_id` | |
| `surface` | `portal` / `public` / `api` |
| `script_session_key` | → plan 01 session (`CallSessionState`, channel `web`) |
| `submitted_by_user_id` | portal only; the *impersonator* id, if any, in `impersonated_by_user_id` |
| `api_key_id` | api surface only |
| `status` | `in_progress` / `submitted` / `held_for_review` / `rejected` (spam or abuse) / `abandoned` / `failed` |
| `reference` | short human reference shown to the submitter, e.g. `W-7KQ2-9M`; unique per team |
| `message_id` | set by `save_message` |
| `idempotency_key` | unique per `(web_script_id, idempotency_key)`; stops double submits and API retries creating duplicates |
| `ip_hash`, `user_agent`, `origin`, `page_url` | IP stored as HMAC(app key, ip, day) so we can rate-limit and correlate without keeping raw IPs |
| `risk` | JSON: honeypot hit, fill time, captcha result, duplicate-of id |
| `started_at`, `submitted_at`, `last_activity_at` | |

**Team branding** (new nullable columns on `teams`): `brand_logo_path`,
`brand_primary_color`, `brand_display_name`. These are the default for
every web script of the client; `web_scripts.branding` overrides them.
They're kept separate from the platform `Branding::portal*`, which
stays the answering service's brand on the authenticated portal.

**`messages`** (plan 03 already adds `fields`, `template_id`,
`script_session_key`): add `source` (`call`, `sms`, `chat`, `portal`,
`web_form`, `api`) and `web_submission_id`. Don't put portal users in
`created_by_user_id`. `PlatformMetricsCollector.php:354-358` counts
non-null `created_by_user_id` as "taken by a human (operator)", so
client staff would inflate operator numbers. Use
`web_submissions.submitted_by_user_id` instead.

### Engine integration (plan 01)

- **Channel:** `ScriptEngine::start()` gets `channel = web` and a
  context block:
  `{surface, web_script_id, submitter: {user_id?, name?, email?}, origin?, page_url?}`.
  `caller.*` is empty. Scripts that need a callback number gather it.
  `now` is in the **client's** timezone (`Team::timezone()`), never
  the browser's. That matters for `branch_on_time_of_day` and the
  plan 06 hold-until-morning logic.
- **Per-surface allow-list on step handlers.** Extend plan 01's
  `StepHandler` interface with `surfaces(): array` (`voice`,
  `operator`, `sms`, `chat`, `portal`, `public`, `api`). It defaults to
  `['voice', 'operator']`, so a new handler is unavailable on the web
  until someone opts it in.
- **Publish-time validation:** walk every flow reachable from the
  trigger, including `call_action_group` targets. The web script
  can't be published for a surface if any reachable step doesn't
  support that surface, or if some path ends without `save_message`.
  The editor shows the failing step. At runtime an unsupported step
  (for example after a later edit) gives a `web_unsupported_step`
  event and the safe fallback exit, and the submission still becomes a
  partial message (`PartialMessagePolicy`). Nothing fails silently.
- **Response shaping:** the engine's `StepView` for `public` and `api`
  goes through a `PublicStepPresenter` whitelist. It passes only the
  prompt text, input definitions, choice labels and exits. It never
  passes `context`, other fields, lookup results, on-call names,
  directory data or `web_call` responses. Portal users see lookup
  results only where the step is marked `show_result_to_submitter`.
- **Prompt text:** talking points are spoken lines ("Thank you for
  calling…"), which are wrong on a form. Steps get an optional
  `web_label` / `web_help` in `step_params`, falling back to the slot
  label.

### Step types allowed per surface

| Category / step | Portal | Public | API | Why |
|---|---|---|---|---|
| `gather_text`, `_number`, `_boolean`, `_choice`, `_date`, `_datetime`, `_duration`, `_email`, `_phone`, `_address`, `gather_intake_fields` | ✓ | ✓ | ✓ (from payload) | Plain typed inputs; plan 01 validation applies |
| `gather_masked` | ✓ | ✗ | ✗ | Cards and SSNs don't belong on an anonymous form we don't control the page around |
| `verify_caller` | ✓ (verifies a *patient or account*, not the staff member) | ✗ | ✗ | Would turn the form into an account-enumeration oracle |
| `identify_reason`, `answer_question` (LLM) | ✓ (opt-in) | ✗ v1 | ✗ | LLM cost and abuse on an anonymous surface; public uses `gather_choice` |
| `branch_if`, `branch_on_time_of_day`, `pause` (no-op on web) | ✓ | ✓ | ✓ | Deterministic, server-side |
| `call_action_group` | ✓ | ✓ | ✓ | Validated recursively |
| `lookup_on_call` (plan 04) | ✓ | ✓ (result hidden) | ✓ (result hidden) | Drives dispatch; never shown to the public |
| `db_lookup_single`, `db_picklist` | ✓ | picklist only, from lists marked `public_safe` | ✗ | Stops directory scraping |
| `db_save`, `db_iterate` | ✓ | ✗ | ✗ | Data writes from anonymous users need review first |
| `web_call` | ✓ | ✓ (response hidden) | ✓ | Server-side to `ClientWebEndpoint`; reuses its auth |
| `parse_json`, `parse_xml` | ✓ | ✓ | ✓ | Pure |
| `get_history` / `save_history` (plan 13) | ✓ | ✗ | ✗ | Caller history would leak to whoever types a phone number |
| `save_message`, `save_summary`, `save_keyword`, `save_call_tracker_event` | ✓ | ✓ | ✓ | `save_message` is required on every completed path |
| `auto_dispatch`, `send_email`, `send_sms`, `send_page`, `send_wctp`, `send_fax`, `enter_dispatcher_queue` | ✓ | gated by `public_dispatch_mode` | gated | See abuse controls |
| `mark_message_sent` / `_delivered` | ✗ | ✗ | ✗ | Dispatch-engine owned (plan 05) |
| `schedule_callback` (plan 19) | ✓ | later | later | Creates outbound work for the call center |
| `transfer_*`, `enter_call_queue`, `enter_email_queue`, `park_call`, `toggle_hold`, `toggle_recording`, `play_hold_music`, `assign_to_persona`, `match_did`, `match_extension`, `match_email_address`, `change_account`, `handle_dispatch_event` | ✗ | ✗ | ✗ | Telephony or routing; no meaning on the web, or would cross clients |

### Services (`app/Services/WebScripts/`)

- `WebScriptResolver`: `forPortal(User, slug)` and `forPublicKey(key)`.
  This is the **only** place public lookups drop the team scope:
  `WebScript::withoutGlobalScope('team')->where('public_key', $key)->published()`.
  It returns a `WebScriptContext` value object (`teamId`, `webScript`,
  `surface`). Everything downstream takes `teamId` from that object.
  Nothing downstream reads `auth()` on public routes.
- `WebSubmissionService`: `start(ctx, meta)`, `submit(ctx, token, inputs, idempotencyKey)`,
  `abandon()`. It wraps `ScriptEngine`, writes `web_submissions`,
  enforces the surface allow-list again on every step (defence in
  depth), and links the message.
- `AbuseGuard`: rate limits, honeypot, min fill time, captcha
  verification, duplicate fingerprint, origin check, auto-pause. Each
  check returns a reason code that gets stored in `risk`.
- `WebScriptValidator`: the publish-time walk described above.
  `OrchestrationController` calls it when the graph is saved, so editing
  a published script can't make it invalid without warning. The save
  goes through, but the web script drops to `paused` with a banner.
- `PublicFormRenderer`: builds the public page config (branding, CSP
  header and fields) from a `StepView`.

### UI: portal

- New page `app/Filament/Portal/Pages/RunScript.php` in a new nav
  group "Requests". It lists published portal-enabled web scripts the
  user may run (permission plus role pivot) as cards, then mounts
  `ScriptRunner` (plan 02) with `mode: 'web'`. Web mode hides talking
  points, Jump, the operator sidebar (Info Pages, directory, on-call
  names) and the call controls, and shows `web_label` / `web_help`.
  Back stays.
- Draft resume: an `in_progress` submission can be resumed by the same
  user for 24 hours ("You have an unfinished After-hours request").
  After that, a sweeper marks it `abandoned` and applies partial-message
  rules only if the script opts in.
- Confirmation screen shows the reference, a summary of fields (plan 03
  summary template) and the dispatch status: "Paged Dr. Patel 21:04,
  awaiting acknowledgement" (plan 05 timeline, with the permission
  below).
- New page `Submissions` (`portal.view_submissions`). It's a
  table filtered on `current_team_id`, the same way `CallHistory`
  does it. Columns: reference, script, submitted by, status, message
  link, and dispatch state. The default view shows only the user's own
  submissions; `portal.view_all_submissions` shows the whole team's.
- Client-admin page `WebForms` (`portal.manage_web_forms`) for
  operational settings only: pause/resume, allowed origins, rotate the
  public key, intro and success text, logo and colour, the role pivot,
  and the embed snippet to copy. Graph and dispatch policy stay
  admin-side.

### UI: admin (platform staff)

- `ClientResource` sub-page `ManageClientWebScripts`, next to the
  existing `ManageClientChatQueues`. It does full CRUD, publish (runs
  the validator), abuse settings, `public_dispatch_mode` and a
  "test as public" preview. The preview runs the engine with
  side-effects faked, following plan 01's dry-run mode.
- A review queue for `held_for_review` public submissions, which plugs
  into plan 05's dispatcher queue. The operator sees the submission
  plus its risk signals. **Release** runs the held dispatch steps;
  **Reject as spam** marks it `rejected`, records a
  `ConversationActivity` and still keeps the message for the client
  (filed, not dispatched).

### UI: public form and embed

**Why it doesn't reuse the chat page:** `chat/show.blade.php` is a
Livewire page that depends on the Laravel session cookie and the CSRF
token. Inside a third-party iframe on `clinic.example`, Safari ITP and
Chrome's third-party cookie phase-out drop that cookie, so Livewire
requests fail. The public form therefore uses **no cookies at all**:

- `GET /f/{public_key}` serves a small, framework-free HTML page with
  inline CSS and JS built by Vite as a separate entry. It doesn't load
  Filament, Livewire or `app.js`. It uses client branding and
  `layout`, and includes the AGPL source footer (an unauthenticated
  network user is exactly who section 13 covers; reuse the
  `source-footer` partial's content).
- `GET /f/{public_key}/embed.js` is a loader. The client pastes
  `<script src="https://orbital.example/f/KEY/embed.js" async></script><div data-orbital-form="KEY"></div>`.
  It inserts an iframe and resizes it automatically with
  `postMessage`. The message origin is checked on both sides, and
  messages carry only `{height}` and `{submitted: reference}`. A plain
  `<iframe>` snippet and a direct link ("open in new tab") are also
  offered.
- JSON API under `routes/api.php`, with no session middleware:
  - `POST /api/web-forms/{public_key}/sessions` → `{token, step}`.
    `token` is an opaque 40-character random string held in JS memory
    and `sessionStorage`. It maps to `web_submissions` and is the only
    credential.
  - `POST /api/web-forms/{public_key}/sessions/{token}/step` with
    `{inputs | exit, idempotency_key}` → next `step`, or
    `{done: true, reference, success_text}`.
  - `POST …/back`.
- Headers: `Content-Security-Policy: frame-ancestors <allowed_origins>`
  (or `'none'` when the list is empty, which means direct link only),
  `X-Content-Type-Options: nosniff` and `Referrer-Policy:
  strict-origin-when-cross-origin`. CORS on the JSON API is limited to
  the Orbital origin, because the page itself is served from Orbital
  inside the iframe.
- Accessibility: real `<label>`s, error messages tied to inputs with
  `aria-describedby`, keyboard-only completion, and no reliance on
  colour alone. Clinics and property managers face ADA claims over
  their websites.

### UI: API surface

`POST /api/v1/web-scripts/{web_script}/submissions` sits inside the
existing `team.api-key` + throttle group (`routes/api.php:188`). The
body is `{fields: {...}, idempotency_key}`. The engine runs headless:
each interactive step takes its value from `fields`. If a required
value is missing or invalid, the response is `422` with
`{step, missing: [...], errors}` and nothing is persisted beyond a
`failed` submission row. The web script must belong to the key's team,
or the response is a 404 (not a 403, so ids can't be probed).

### Security and abuse protection (public surface)

These controls are layered; no single one is trusted on its own.

1. **Rate limits.** Add named limiters in `AppServiceProvider` next to
   `messaging-inbound`:
   - `web-form-start`: per (key, ip_hash) and per key.
   - `web-form-step`: per token, 60 per minute.
   - Per-team daily cap.

   Limits come from `abuse_settings`, with platform maximums in
   `config/orbital.php`. A 429 renders a friendly "please call us at
   …" message using the client's main number.
2. **Honeypot plus minimum fill time.** The first response carries a
   server-signed `issued_at` (HMAC). A submit sooner than
   `min_fill_seconds` after it, or with the hidden field filled, is
   accepted with a 200 and a reference, so bots learn nothing. It is
   recorded as `rejected`, and nothing is dispatched.
3. **Captcha, optional per form.** The default is **ALTCHA**
   (self-hosted proof-of-work, so no third-party calls), because
   Orbital is self-hostable and works offline. Turnstile and hCaptcha
   are offered as options, with platform-level secrets set in the
   `SettingsRegistry`. Server-side verification happens on the final
   submit, not on every step.
4. **Origin/Referer check** against `allowed_origins`. It's only a
   signal, recorded in `risk`, because API callers can forge it. CSP
   `frame-ancestors` is what actually stops framing.
5. **Input limits:** per-type max lengths (text 2,000, total payload
   32 KB), Unicode normalised (NFC), control characters stripped, plan
   01's maximum steps per session, and a 2-hour session lifetime.
   Values are stored as data and always escaped on output: portal,
   operator, email and SMS bodies from plan 03 templates.
6. **Dispatch gating.** This matters most for an answering service,
   because one spam submission to an urgent medical form could page a
   doctor at 3 a.m. `public_dispatch_mode`:
   - `review_first` (default): no dispatch until an operator releases it.
   - `review_urgent_only`: routine dispatches go out, and paging or
     voice-callout tiers wait for release.
   - `direct`: only after the client signs off in writing (recorded in
     `ConversationActivity` with the operator who enabled it).
7. **Duplicate fingerprint.** If the same normalised fields arrive on
   the same form within 10 minutes, the submission is linked as
   `duplicate_of` and doesn't dispatch again.
8. **Auto-pause.** When rejections in an hour pass
   `auto_pause_threshold`, the form goes to `paused`, shows the
   fallback phone number, and alerts platform staff (pushgateway
   metric `orbital_web_form_rejections_total`, plus a notification).
9. **Key rotation.** Rotating `public_key` invalidates old embeds
   immediately, and the portal shows the new snippet.

**PHI.** Medical clients will receive PHI through public forms. Use
HTTPS only (redirect, and HSTS from the edge). Field values live in
`messages.fields` (plan 03), so encryption at rest is governed there.
Delivery to recipients follows plan 17 (secure link instead of
content) when the template is PHI-flagged. Submitter IPs are hashed.
Abandoned and rejected submissions are purged after `retention_days`.

### Permissions and tenant isolation

- **New permissions,** added to `PermissionCatalogSeeder`, to
  `ClientProvisioner`'s allow-list constant, and to the descriptions in
  `RoleResource.php:230-242`:
  - `portal.run_scripts`: run published portal scripts.
  - `portal.view_submissions`: see own submissions and their dispatch
    status.
  - `portal.view_all_submissions`: see every submission for the
    client.
  - `portal.manage_web_forms`: the operational settings page above.

  Only `run_scripts` and `view_submissions` go in the default invitee
  subset. Ship them in a migration that follows
  `2026_04_21_150000_seed_portal_manage_permissions.php`: seed the
  catalogue, add them to every tenant's allow-list, re-sync
  `client_admin`. The platform operator can remove any of them per
  client through `ClientPermissionGatekeeper`.
- **Every new portal page** implements `canAccess()` with the
  permission plus the team-owner fallback, as
  `RoleResource::viewerCanManageRoles()` does. That fixes the gap for
  this plan's pages; see Bugs for the existing ones.
- **Explicit team filtering:** portal queries use
  `->where('team_id', current_team_id)`, as today's portal pages do.
  The run page refuses users with no `current_team_id`. That's
  important because `BelongsToTeam`'s global scope **adds no filter**
  when the user has no `currentTeam` or there's no authenticated user
  (`app/Models/Concerns/BelongsToTeam.php:16-35`).
- **Team binding at session start:** the session stores `team_id`.
  Every later step checks
  `session.team_id === user.current_team_id` (portal) or
  `=== WebScriptContext.teamId` (public and API). A user who switches
  team in another tab gets a "this request belongs to another account"
  error rather than a cross-client write.
- **Cross-references validated on save:** `orchestration_id`,
  `trigger_flow_id`, role ids, `ClientWebEndpoint`s used by `web_call`,
  and directories and on-call groups reachable from the graph must all
  belong to the web script's team, or be platform-shared and bound to
  it.
- **Super-admin impersonation:** allowed, recorded as
  `impersonated_by_user_id`, and shown in the timeline.

### Audit

- **Engine events** (plan 01 `script_events`): actor is `user:{id}`
  (portal), `web_public` or `api_key:{id}`, and every step, field,
  branch and dispatch is recorded, the same as on calls.
- **`web_submissions`** is the envelope (who, where, when and risk).
  The message gets `ConversationActivity` rows:
  `created_via_portal`, `created_via_web_form`, `released_by`,
  `rejected_as_spam`.
- **Config changes** to `web_scripts` (publish, pause, origins, key
  rotation, dispatch mode, role pivot) go in `web_script_revisions`:
  who, when, before/after JSON. Client admins see the
  client-editable subset of that history.

## Edge cases

- **Clinic after hours:** the front desk submits at 16:55. The script
  branches on the client's *business hours* (plan 06), not on the
  submitter's clock. A request that's "routine" at 16:55 is delivered
  to the office, but one submitted at 17:01 is held until 08:00. DST
  boundaries are covered by the plan 06 tests.
- **No one on call** (empty rota or override gap): the script takes the
  `lookup_on_call` fallback exit, which by default goes to the plan 05
  dispatcher queue. The submitter sees "Received", not an internal
  error.
- **Graph edited mid-session.** Plan 01 has no orchestration versioning.
  The session pins a hash of the reachable graph at start. If it changes
  and the current step no longer exists, the engine takes the fallback
  exit and records `graph_changed`. (Proper versioning is an open
  question.)
- **Web script paused or archived mid-session:** steps already in
  progress can finish within 15 minutes, and new starts are refused.
- **Orchestration soft-deleted or client deactivated:** the public page
  shows a generic "This form isn't available. Please call …" message
  and returns HTTP 410. The client's name is never disclosed.
- **Double submit or network retry:** the idempotency key returns the
  original result.
- **Browser back button:** the public page uses `history.pushState`
  per step and maps popstate to the `back` endpoint.
- **Third-party cookies blocked:** no cookies are used. A
  `sessionStorage` token survives an in-iframe reload; losing it
  starts a fresh session.
- **JavaScript disabled:** the `/f/{key}` direct link shows a
  `<noscript>` message with the client's phone number. A no-JS
  form isn't offered in v1.
- **Portal user loses permission or membership mid-run:** the next step
  returns 403. The submission becomes `abandoned`, and partial rules
  apply.
- **Same person, two tabs:** two separate submissions. The duplicate
  fingerprint links them if the fields are identical.
- **Very long or odd input** (emoji, RTL text, pasted HTML): stored
  normalised and escaped on output. SMS templates truncate at
  grapheme boundaries (plan 03 short form).
- **Spam burst during an outage:** auto-pause, and the fallback number
  is shown.
- **Dispatch failure:** this never fails the submission. The message
  exists and the plan 05 timeline shows the failure.
- **`allowed_origins` empty but embed attempted:** the browser refuses
  to frame the page, and a console hint says to add the origin in Web
  Forms.
- **Client with web forms but no dispatch policy:** the message is
  `filed` and appears in the portal message list (plan 03). The
  validator warns about this.

## Migration / rollout

1. **Phase 0, after plans 01, 02 and 03 land:**
   - migrations for `web_scripts`, `web_script_role`, `web_submissions`,
     `web_script_revisions`, team branding columns, `messages.source` /
     `web_submission_id`;
   - the permissions migration;
   - trigger constants, the seeder and editor changes;
   - `surfaces()` on handlers.

   No behaviour changes yet.
2. **Phase 1, portal-run:** the `RunScript` and `Submissions` pages
   behind `config('orbital.web_scripts.portal')`. Seed an
   orchestration template "Medical after-hours request (portal)" via
   `ApplyOrchestrationTemplate`. Pilot with one NCI medical client.
   Dispatch is plan 05 if it has landed, otherwise messages are
   `filed` and visible in the portal.
3. **Phase 2, public forms:** the `/f/` page, embed loader, JSON API
   and `AbuseGuard`, with `review_first` forced on for the first
   clients. The admin review queue ships with it.
4. **Phase 3, API surface and `direct` dispatch mode** for clients
   who sign off.
5. **Backfill:** set existing messages to `source = call | sms` based on
   `call_log_id` / `message_thread_id`.

## Tests

Answering-service suite (`tests/Feature/WebScripts/`), built on plan 01
fixtures (orchestration builder + frozen clock):

**Engine and allow-list**

1. A portal run of the "after-hours request" fixture at 22:00 client
   time with urgency `urgent` produces events
   `step_entered…branch_taken(urgent)…lookup_on_call…save_message…dispatch_queued`.
   The message has `source=portal` and fields matching the inputs.
2. The same fixture at 22:00 with `routine`: the message is `held`
   until 08:00 (plan 06). `travel()` to 08:00 queues the dispatch.
3. The same fixture submitted at 01:30 UTC for a client in
   `America/Chicago`: the branch uses Chicago time. A DST-change night
   is a separate case.
4. Publish validation:
   - a graph with `transfer_to_extension` reachable from the web
     trigger can't be published for `public`;
   - `gather_masked` is allowed for `portal`;
   - a path with no `save_message` is rejected;
   - a step inside a `call_action_group` subroutine is also checked.
5. A runtime unsupported step (graph edited after publish) takes the
   fallback exit, records a `web_unsupported_step` event and creates
   a partial message.
6. Parity: the same fixture and inputs through the operator runner
   (plan 02), the portal and the API give identical `branch_taken`
   sequences and identical message fields.

**Public form and abuse**

7. Start → steps → done returns a reference. The JSON responses never
   contain the on-call name, directory values, `context` or
   `web_call` response (assert on keys recursively).
8. The honeypot is filled: response 200 with a reference, the
   submission is `rejected`, no message is dispatched and no plan 05
   transport is called.
9. A submit under `min_fill_seconds` is rejected the same way. A
   tampered `issued_at` signature is rejected.
10. Rate limits: the 11th start from the same IP within an hour on the
    same key returns 429. A different key is unaffected. The per-team
    daily cap returns 429 across forms.
11. `review_first`: no transport fake receives anything until an
    operator releases. After release, tier 1 fires once.
12. `review_urgent_only`: the email tier goes out at once, and the page
    tier waits for release.
13. Duplicate fingerprint: an identical second submit within 10 minutes
    is linked and not dispatched again.
14. Idempotency: the same key posted twice returns the same reference
    and leaves one message.
15. Auto-pause: crossing the threshold flips the status to `paused`,
    the next start gets a 503 with the fallback phone number, and the
    metric increments.
16. Headers: CSP `frame-ancestors` equals `allowed_origins`, with
    `'none'` when the list is empty. Key rotation makes the old key
    return 404.
17. Captcha (ALTCHA) with an invalid solution fails the final submit.
    The provider HTTP calls are faked.

**Permissions and tenant isolation**

18. A portal user without `portal.run_scripts` gets 403 on `RunScript`.
    A user with it but not in a role on the pivot doesn't see the
    script.
19. A client A user can't start or resume a client B web script by
    slug, session token or submission id (404).
20. A user whose `current_team_id` changes mid-session gets an error on
    the next step, and nothing is written to either team.
21. A user with no `current_team_id` is refused (regression for the
    `BelongsToTeam` fail-open).
22. Saving a web script whose `trigger_flow_id` / `orchestration_id`,
    role id or `ClientWebEndpoint` belongs to another team fails
    validation.
23. API: a client A key posting to a client B web script id gets 404.
    Missing fields get 422 with the `missing` list and no message.
24. `Submissions` shows only the user's own rows unless they have
    `view_all_submissions`, and never another team's rows.
25. Impersonated run: the submission records the
    `impersonated_by_user_id`.
26. Metrics: portal-created messages don't increment
    `orbital_messages_recent_by_handler{handler="human"}`.

**Audit**

27. Publishing, pausing, origin edits and key rotation each write a
    `web_script_revisions` row with before and after values.
28. Releasing or rejecting a held submission writes
    `ConversationActivity` on the message, with the operator id.

**Browser (Dusk or Playwright, optional)**

29. The embed loader in a page on an allowed origin resizes the iframe.
    The same page on a disallowed origin is blocked from framing it.

## Open questions

1. Which NCI clients enter requests through IS web scripting today, and
   which publish forms on their own websites? That decides whether
   phase 2 is needed at launch.
2. Should public forms **ever** page directly, or does NCI always want
   an operator to verify first? This decides the default for
   `public_dispatch_mode`.
3. Orchestration **versioning**: pinning a graph hash is a stopgap.
   Should plan 01 add published or draft versions, which web forms need
   more than calls do because their sessions are longer?
4. Should client admins edit **web labels and help text** (copy only),
   or does every text change go through platform staff?
5. File or photo uploads (property-management damage photos) are a
   common ask. Bring them in v2 through the Artifact-style asset store,
   with virus scanning?
6. Which CAPTCHA does NCI accept? A self-hosted ALTCHA default, or
   Turnstile, given its privacy and HIPAA posture?
7. Should web chat (`ChatSession`) move onto the same no-cookie embed
   and the same `AbuseGuard`, as a follow-up to roadmap "Web chat"?
8. Billing: is a web submission a billable event? Plan 09 needs a
   `usage_events` type for it.

## Size

About **3½–4½ weeks** after plans 01, 02 and 03 (dispatch features need
05):

- data model, permissions migration, triggers, `surfaces()` and
  validator: 1 week;
- portal pages in web mode (`RunScript`, `Submissions`, `WebForms`):
  1 week;
- public page, embed loader and JSON API: 1 week;
- `AbuseGuard`, review queue and auto-pause: 0.5–1 week;
- API surface and audit trail: 0.5 week.

The tests are spread across all of these.

## Bugs noticed while writing this plan

Not fixed here; they belong in TODO.md "Known bugs":

1. **The portal `view_*` permissions are never enforced.**
   - `Dashboard`, `CallHistory` and `TextConversations` have no
     `canAccess()`. `portal.view_calls`, `view_messages`,
     `view_recordings` and `view_home` are referenced only in the
     provisioner, the seeder and the RoleResource copy.
   - Removing `portal.view_calls` from a role therefore doesn't hide
     call history.
   - `portal.manage_users` is meant to gate a "portal Users page"
     (`2026_04_21_150000_seed_portal_manage_permissions.php:15`), but no
     such page exists under `app/Filament/Portal`.
2. **`BelongsToTeam` fails open:**
   `app/Models/Concerns/BelongsToTeam.php:30-35` adds no filter when an
   authenticated non-super-admin has no `currentTeam`, so the query
   returns every team's rows.
3. **`ChatController::show` matches `Team::where('id', $client)->orWhere('name', $client)`**
   (`ChatController.php:27-29`):
   - On PostgreSQL, a non-numeric `{client}` compared to the bigint `id`
     probably raises `invalid input syntax` and returns a 500.
   - Lookup by team name is ambiguous, since names aren't unique.
   - The route comment calls the URL "the shared secret"
     (`routes/web.php:101-102`), but both segments are sequential or
     guessable.
4. **The public chat has no rate limit or length cap.**
   - `ChatConsole::send` calls Anthropic for every message
     (`ChatConsole.php:40-61`), so anyone can run up LLM spend.
   - Every visit without a session cookie creates a `ChatSession` row.
   - `$e->getMessage()` is shown to anonymous users (`ChatConsole.php:56`).
5. **The public chat page has no AGPL source footer.** The portal
   renders `source-footer`, but `resources/views/chat/show.blade.php`
   doesn't.
6. **`inbound_chat` trigger flows are never used at runtime:**
   `ChatService` compiles the persona's default flow, so the chat
   trigger's wiring in the editor has no effect.
7. **`CompiledFlowViewer` dev fallback** (`CompiledFlowViewer.php:104-112`)
   loads another client's persona. This is the known wrong-client bug,
   and the reason plan 02's runner must not inherit it.
