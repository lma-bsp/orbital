# Plan 21: Appointment scheduling — booking into client calendars

**Status:** proposed · part of [IS parity](README.md) · expands survey item O in [07](07-additional-features.md) · depends on [01](01-script-runtime.md) (engine, live `web_call`), [06](06-schedules-and-routing.md) (schedules); uses [03](03-message-model.md), [05](05-delivery-and-escalation.md), [16](16-two-way-messaging.md), [19](19-scheduled-outbound-calls.md)

## Problem

### What IS does

Amtelco's IS lets an operator book, move and cancel appointments for a
client while the caller is on the line: the script shows the client's
open times, the operator picks one, the booking is written to the
schedule, the caller gets a confirmation, and reminders go out before
the visit. Answering services sell this to clinics, dentists, vets,
salons, home-service trades and property managers ("book a viewing").
Most of those clients already keep a calendar somewhere — Google,
Microsoft 365, an iCloud/CalDAV account, or a practice-management
system (PMS) with its own API — and expect the booking to land
*there*, not in a second calendar they have to check.

### What Orbital has today (checked in the code)

Nothing books appointments. Specifically:

- **No calendar model or integration.** No model, migration or service
  mentions appointments or calendars (`grep -ri appointment|calendar
  app/ database/migrations` only hits the `gather_date` icon and demo
  copy, e.g. `database/seeders/DemoClientSeeder.php:304`). No Google /
  Microsoft / CalDAV client is installed: `composer.json:8-34` has no
  `google/apiclient`, `microsoft/microsoft-graph`, `sabre/*` or
  `laravel/socialite`; `vendor/google/` contains only `protobuf`.
  There is no RRULE/iCalendar library.
- **Script steps that come close are stubs or post-call only.**
  - `schedule_callback` (`database/seeders/IntakeGoalLibrarySeeder.php:973-988`)
    only captures a "callback window" into a slot.
  - `gather_date` / `gather_datetime` (`IntakeGoalLibrarySeeder.php:215-256`)
    capture a value; nothing checks it against availability.
  - `web_call` (`IntakeGoalLibrarySeeder.php:692-712`) declares
    `response_mapping` and exits `ok / http_error / network_error`, but
    the only executor, `PostCallActions::webCall()`
    (`app/Services/Flows/PostCallActions.php:308-363`), runs **after the
    call** (`ACTION_KEYS`, `:41`; `run()`, `:60-84`) and never reads
    `response_mapping` — the response is only previewed (`:354`). So a
    PMS can be *told* about a booking after hang-up, but the caller can't
    be offered times from it.
  - `db_lookup_single` / `db_save` (`IntakeGoalLibrarySeeder.php:605-690`)
    have no executor; `ClientDbConnection` (`app/Models/ClientDbConnection.php`)
    is referenced nowhere outside its model and migration.
- **Endpoint auth that a PMS integration would reuse.**
  `ClientWebEndpoint` (`app/Models/ClientWebEndpoint.php:17-65`,
  `BelongsToTeam`, `credentials` cast `encrypted:array` at `:54`) defines
  `none / basic / bearer / api_key / oauth2` (`:22-38`), but
  `PostCallActions::authenticate()` (`PostCallActions.php:365-378`)
  implements only the first four and throws for `oauth2` (`:376`).
  There is no Filament page to manage endpoints — only the model,
  migration (`database/migrations/2026_04_23_230100_create_client_web_endpoints_table.php`)
  and `PostCallActions` reference `ClientWebEndpoint`.
- **Templates and expressions are ready.** `TemplateEvaluator::render()`
  (`app/Services/Flows/TemplateEvaluator.php:26-51`) renders
  `{{ expr }}` with the IS-style `ExpressionEvaluator`, which already has
  `dateadd`, `datediff`, `datepart`, `formatdate`, `weekday`,
  `daterange`, `timerange` (`app/Services/Flows/ExpressionEvaluator.php:347-358`).
  Event titles, confirmation texts and reminder bodies can use them.
- **Time zone.** `Team::displayTimezone()` (`app/Models/Team.php:268-273`)
  exists, but `PostCallActions` builds `now` in `config('app.timezone')`
  (`PostCallActions.php:100`, `:228`), not the client's zone — booking
  must not copy that.
- **AI worker.** The worker's tools are `set_field`, `advance_step`,
  `search_knowledge`, `lookup_account` (stub returning "not yet wired",
  `agent-worker/tools/compiled_flow.py:209-221`), `send_sms` (stub,
  `:224-230`), `transition_to_flow` (log only) and transfer/handoff.
  Tool schemas are generated in `AgentFlowCompiler::toolSchema()`
  (`app/Services/Flows/AgentFlowCompiler.php:945-985`). The worker
  authenticates to Laravel with the shared bearer token checked in
  `CallSessionController::isWorker()` (`app/Http/Controllers/Api/CallSessionController.php:278-283`).
- **Credential encryption pattern** is consistent: `encrypted` /
  `encrypted:array` casts on `ClientWebEndpoint.credentials`,
  `ClientDbConnection.password`, `MessagingEndpoint.provider_config`
  (`app/Models/MessagingEndpoint.php:61`), `Team.passcode` and
  `Team.voicemail_transcription_config` (`app/Models/Team.php:76,80`),
  `SipTrunk.password`, `Extension.sip_password`. Platform-wide secrets
  live in `SettingsRegistry` entries flagged `secret` (encrypted,
  masked; `app/Services/Settings/SettingsRegistry.php:17,154`).
  `config/services.php` holds env-backed provider keys but nothing for
  calendar OAuth apps.
- **Outbound SMS** exists only as thread replies
  (`OutboundMessageService::replyAsOperator/replyAsAgent`,
  `app/Services/Messaging/OutboundMessageService.php:45,59`), with
  opt-out enforcement in its private `send()` (`:71`). There is no
  "text this number a confirmation" API yet — plan 05 adds it.
- **Portal** has Dashboard, Call History, Text Conversations and Roles
  (`app/Filament/Portal/`); portal permissions are `portal.view_home /
  view_calls / view_messages / view_recordings / manage_users /
  manage_roles` (`database/seeders/PermissionCatalogSeeder.php:44-52`).

## Goal

Operators and AI agents can, during a call, find open times for a
client's bookable service, hold one while the caller decides, book it
into the client's **own** calendar (Google, Microsoft 365, CalDAV, or a
PMS via `web_call`), and send a confirmation. Callers can cancel or
reschedule by calling back. Reminders go out by SMS/email or an
outbound call (plan 19). Clients without a calendar get a minimal
native one in the portal. Every booking is traceable from call to
calendar event and back.

Non-goals: a full scheduling product (resource optimisation, waitlists
with auto-fill, payments, patient intake forms), two-way sync of
everything in the client's calendar, or vendor-specific PMS adapters in
the first release.

## Design

### Concepts

| Term | Meaning |
|---|---|
| **Calendar connection** | One authorised link to a client's calendar system (a Google account, an M365 tenant/mailbox, a CalDAV account, a `ClientWebEndpoint` acting as a PMS, or "native"). |
| **Bookable resource** | Something that can be booked: Dr Patel, Chair 2, "Viewing agent", a room. Maps to one external calendar on a connection; optionally linked to a `DirectoryEntry`. |
| **Appointment type** | What is booked: "New patient, 60 min", "Boiler service, 2 h window". Owns duration, buffers, notice, horizon, which resources, which hours, confirmation and reminder policy. |
| **Appointment** | Orbital's record of a booking (or hold), whatever system it lives in. The source of truth for *our* actions; the external calendar is the source of truth for *availability*. |

### Data model (all `BelongsToTeam`)

All tables carry `team_id` and use `App\Models\Concerns\BelongsToTeam`.
Jobs and the worker API run without an authenticated user, where the
trait's global scope does nothing (`BelongsToTeam.php:17-19`), so every
service method takes the `Team` explicitly and queries
`withoutGlobalScopes()->where('team_id', …)` — the same pattern
`PostCallActions::webCall()` uses (`PostCallActions.php:310-314`).

**`calendar_connections`**

| Column | Notes |
|---|---|
| `provider` | `google`, `microsoft`, `caldav`, `web_call`, `native` |
| `name` | "Front desk Google", unique per team |
| `status` | `pending`, `active`, `needs_reauth`, `error`, `disabled` |
| `credentials` | `encrypted:array` — shape per provider (below). Never serialised to the API, Livewire state or logs (`$hidden`). |
| `client_web_endpoint_id` | for `web_call`: the endpoint whose auth is used |
| `web_operations` | for `web_call`: JSON operation map (see PMS adapter) |
| `account_label` | e.g. the Google account email / M365 UPN shown in UI |
| `granted_scopes` | JSON, as returned by the token endpoint |
| `connected_by_user_id`, `connected_via` | `admin` / `portal` |
| `last_success_at`, `last_error_at`, `last_error` | health; drives alerts |
| soft deletes | disconnect revokes and soft-deletes |

`credentials` shapes:

- google / microsoft: `{access_token, refresh_token, expires_at, token_type, tenant?}`
- caldav: `{base_url, username, password}` (app-specific password)
- web_call / native: `{}` (auth lives on the `ClientWebEndpoint`)

**`bookable_resources`**: `calendar_connection_id`, `external_calendar_id`
(Google calendar id, Graph mailbox/calendar id, CalDAV collection URL,
PMS provider/operatory id), `name`, `directory_entry_id` (nullable —
lets operators see the doctor's directory card), `timezone` (default
`Team::displayTimezone()`), `availability_schedule_id` (plan 06
schedule: the hours this resource may be **offered**, independent of
what the calendar says is free), `is_active`, `sort`.

**`appointment_types`**: `name`, `code` (short, for scripts), `duration_minutes`,
`buffer_before_minutes`, `buffer_after_minutes`, `slot_step_minutes`
(15/30/60 — where starts may fall), `min_notice_minutes` (e.g. 120),
`max_horizon_days` (e.g. 60), `max_per_day` (nullable),
`availability_schedule_id` (plan 06; intersected with the resource's),
`resource_selection` (`caller_choice` / `any_available` /
`round_robin`), `title_template` / `description_template` /
`location_template` (TemplateEvaluator), `privacy` (`full` / `minimal`
— minimal puts only "Appointment — {{ initials }}" in the external
event for PHI clients; details stay in Orbital), `required_fields`
(slot keys that must be filled before booking, e.g. `caller_name`,
`callback_phone`, `dob`), `confirmation_policy_id`, `reminder_policy`
(JSON, below), `cancel_cutoff_minutes`, `allow_caller_cancel`,
`allow_caller_reschedule`, `is_active`.
Pivot `appointment_type_resource`.

**`appointments`**

| Column | Notes |
|---|---|
| `appointment_type_id`, `bookable_resource_id`, `calendar_connection_id` | |
| `status` | `held`, `booking`, `booked`, `confirmed`, `cancelled`, `rescheduled`, `completed`, `no_show`, `failed`, `expired` |
| `starts_at`, `ends_at` | `timestamptz`, UTC |
| `blocked_range` | generated `tstzrange(starts_at - buffer_before, ends_at + buffer_after)` |
| `timezone` | zone the caller was told the time in |
| `hold_expires_at` | for `held` |
| `external_id`, `external_etag`, `external_ical_uid` | the event in the client's system |
| `idempotency_key` | unique; derived from the appointment ULID |
| `reference` | short human code read to the caller ("K7M-42"), unique per team |
| `caller_name`, `caller_phone` (E.164), `caller_email` | promoted for search and caller lookup (plan 13) |
| `fields` | JSONB snapshot of script fields used for the booking |
| `script_session_key`, `call_log_id`, `message_id` | links to plan 01 session, call, plan 03 message |
| `booked_by_type` / `booked_by_id` | `ai` (persona id), `user`, `portal_user`, `caller_sms` |
| `rescheduled_from_id` | self-reference chain |
| `cancel_reason`, `cancelled_by_*`, `cancelled_at` | |
| `sync_state` | `in_sync`, `pending_push`, `push_failed`, `external_changed`, `external_deleted` |

Postgres constraints (requires `CREATE EXTENSION btree_gist`):

```sql
ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap
  EXCLUDE USING gist (bookable_resource_id WITH =, blocked_range WITH &&)
  WHERE (status IN ('held','booking','booked','confirmed'));
```

This one constraint is what makes holds safe against two Orbital
callers racing for the same slot (see *Holds and races*).

**`appointment_events`** (append-only audit, like plan 01's
`script_events`): `appointment_id`, `type` (`held`, `hold_expired`,
`booked`, `push_ok`, `push_failed`, `confirmed_sent`, `confirmed_by_caller`,
`reminder_scheduled`, `reminder_sent`, `cancelled`, `rescheduled`,
`external_changed`, `external_deleted`), `payload` (JSON; provider
responses truncated, no tokens), `actor_type`, `actor_id`, `created_at`.

**Native-only**: `calendar_blocks` (`bookable_resource_id`, `starts_at`,
`ends_at`, `reason`, `created_by`) for time off / lunch / "not
bookable". For native connections the appointments table *is* the
calendar.

### Provider interface

`app/Services/Calendar/Contracts/CalendarProvider.php`:

```php
interface CalendarProvider
{
    /** Busy intervals (UTC) on the resource's calendar in [from, to). */
    public function busy(BookableResource $r, CarbonImmutable $from, CarbonImmutable $to): array;

    /** Create the event; idempotent on $a->idempotency_key. */
    public function create(Appointment $a, RenderedEvent $e): ExternalRef;

    public function update(Appointment $a, RenderedEvent $e): ExternalRef; // If-Match etag where supported
    public function cancel(Appointment $a): void;                           // 404/410 = already gone = success
    public function fetch(Appointment $a): ?ExternalEvent;                  // for drift checks
    public function capabilities(): ProviderCapabilities;                   // tentative events, webhooks, list calendars
    public function listCalendars(CalendarConnection $c): array;            // for the resource picker
}
```

Implementations in `app/Services/Calendar/Providers/`:

**Google Calendar** (`GoogleCalendarProvider`)
- OAuth 2.0 authorisation-code + PKCE, `access_type=offline`,
  `prompt=consent`. Scopes: `calendar.freebusy` and `calendar.events`
  (plus `calendar.calendarlist.readonly` for the picker) — not the full
  `calendar` scope.
- Busy: `POST /calendar/v3/freeBusy` for all of a type's resources in
  one call.
- Create: `events.insert` with a client-chosen event `id` (base32hex
  of the appointment ULID). A retry after a timeout gets `409` → fetch
  and treat as success. `extendedProperties.private.orbital_ref` = reference.
- Cancel: `events.delete`; `410 Gone` is success.
- Change detection: poll `events.get` for upcoming appointments (cheap,
  bounded); `events.watch` push channels are a later optimisation.

**Microsoft 365** (`MicrosoftGraphProvider`)
- Two modes: **delegated** (a client user signs in;
  `Calendars.ReadWrite offline_access`) for single mailboxes, and
  **application** (client's M365 admin grants admin consent to
  Orbital's app, restricted by an Exchange application access policy
  to listed mailboxes) for room/shared mailboxes and practices with
  many providers. Default delegated; application mode is a checkbox
  that shows the admin-consent link.
- Busy: `POST /users/{id}/calendar/getSchedule` (up to 20 mailboxes).
- Create: `POST /users/{id}/calendar/events` with `transactionId` =
  idempotency key; `showAs=busy`. `If-Match` on update via `@odata.etag`.
- Refresh tokens rotate: refresh under a per-connection lock and save
  the new refresh token in the same transaction (see OAuth below).

**CalDAV** (`CalDavProvider`) — iCloud, Fastmail, Nextcloud, Zoho,
cPanel/Zimbra hosts.
- Basic auth with an app-specific password; discovery via
  `/.well-known/caldav` → principal → calendar-home-set → collections.
- Busy: `REPORT calendar-query` with `time-range` and expand, computing
  busy from `VEVENT`s (`TRANSP`, `STATUS:CANCELLED` honoured).
  `free-busy-query` is used when advertised, but not relied on.
- Create: `PUT {collection}/{uid}.ics` with `If-None-Match: *` — a
  retry gets `412` → fetch → success. Update with `If-Match: etag`.
- Requires adding `sabre/vobject` (iCalendar parse/serialise, RRULE
  expansion). Recurring busy events must be expanded server-side or by
  vobject; test with an iCloud recurring weekly block.

**PMS via `web_call`** (`WebCallCalendarProvider`)
- No vendor code. A connection of provider `web_call` points at a
  `ClientWebEndpoint` (auth, base URL, headers) and stores
  `web_operations`:

```json
{
  "availability": {"method": "GET", "path": "/providers/{{ resource.external_id }}/slots",
                   "query_params": {"from": "{{ range.from }}", "to": "{{ range.to }}", "type": "{{ type.code }}"},
                   "result": {"list": "$.slots[*]", "start": "$.start", "end": "$.end"},
                   "semantics": "free"},
  "book":   {"method": "POST", "path": "/appointments", "body_template": "{ \"patient\": {\"name\": \"{{ caller_name }}\", …}, \"start\": \"{{ appointment.starts_at }}\" }",
             "result": {"external_id": "$.id"}, "expected_status_codes": "200,201"},
  "cancel": {"method": "DELETE", "path": "/appointments/{{ appointment.external_id }}"},
  "fetch":  {"method": "GET", "path": "/appointments/{{ appointment.external_id }}",
             "result": {"start": "$.start", "status": "$.status"}}
}
```

- `semantics: free` means the PMS returns open slots (most PMS APIs:
  NexHealth, Jane, Cliniko, Dentrix/Eaglesoft middleware, ServiceTitan
  arrival windows); `busy` means it returns appointments and Orbital
  computes the gaps like a calendar.
- Executed by the **plan 01 live `web_call` handler**, not
  `PostCallActions`. Plan 01 must therefore deliver: response mapping
  (JSONPath → slots, declared but unimplemented today), the JSON
  escaping already in `PostCallActions::jsonEscaped()` (`:401-409`), and
  `oauth2` client-credentials auth (declared in `ClientWebEndpoint`,
  throws at `PostCallActions.php:376`). This plan extracts
  `webCall()`/`authenticate()` into a shared
  `App\Services\Web\WebRequestRunner` used by `PostCallActions`, the
  plan 01 handler and this provider, so there is one implementation of
  endpoint auth and URL rules.
- Idempotency: the book body may include `{{ appointment.idempotency_key }}`;
  for PMSs that don't dedupe, a timeout is treated as **unknown** and
  goes to the reconciliation path (below), never blindly retried.
- Optional post-call-only mode for PMSs without a slots API: the
  booking is a *request* recorded in Orbital and pushed with the
  existing post-call `web_call` — the caller is told "the office will
  confirm".

**Native** (`NativeCalendarProvider`) — busy = active appointments +
`calendar_blocks`; create/cancel are local; always `in_sync`.

### Availability service

`App\Services\Calendar\AvailabilityService::slots(AppointmentType $t,
?BookableResource $r, CarbonImmutable $from, CarbonImmutable $to,
Team $team): SlotList`

1. Candidate windows = type schedule ∩ resource schedule, evaluated by
   plan 06's `ScheduleEvaluator` in the resource's zone (holidays and
   manual "closed" overrides from 06 remove whole days).
2. Clip to `now + min_notice` … `now + max_horizon_days`.
3. Subtract **external busy** (provider `busy()`), **Orbital active
   rows** for the resource (`held/booking/booked/confirmed`, with
   buffers) and native blocks.
4. Step through the remainder at `slot_step_minutes`, keeping starts
   where `duration + buffers` fits; apply `max_per_day`.
5. Return slots tagged with resource, start/end in UTC and a display
   string in the client's zone ("Tue 3 Dec, 2:30 pm").

Caching: external busy is cached per resource per day for 60 s
(`Cache` on Valkey) so an operator paging through days doesn't hammer
Google; **the cache is never trusted at commit** — the hold and the
booking re-check live (below). Provider errors return a typed
`AvailabilityUnavailable` so the script can take an `unavailable` exit
("I can't see the schedule right now — let me take your details and the
office will call you"), never an empty list that looks like "fully
booked".

Search helpers for the AI and the operator: `firstAvailable(type, after,
n)`, `slotsOn(date)`, `nearest(requestedDateTime, n)`,
`matching(partOfDay: morning|afternoon|evening, weekdays)`.

### Holds and race conditions

Three races matter: two Orbital callers/operators wanting the same slot;
someone at the client's office booking the slot in their own calendar
while our caller decides; and our own retries creating duplicates.

1. **Hold (Orbital-side lock).** `AppointmentBooker::hold(slot, session)`
   inserts an `appointments` row `status=held`, `hold_expires_at = now +
   hold_ttl` (default 5 min, per type, max 15). The exclusion constraint
   makes the insert fail if any active row overlaps → `SlotTaken`, and
   the caller is offered the next slots. Before inserting, the same
   transaction marks this resource's expired holds `expired`, so a stale
   hold never blocks. A sweeper (`orbital:expire-appointment-holds`,
   every minute in `routes/console.php`) catches the rest. One session
   may hold at most one slot per type (a new hold releases the old).
2. **Tentative external event (optional, per connection).** Where the
   provider supports it (Google/M365/CalDAV: `status=tentative`,
   `showAs=tentative`), a type may opt in to writing a tentative event
   at hold time so office staff see the slot is being offered. Off by
   default: it creates noise and needs deletion on expiry. Not used for
   `web_call` unless the PMS exposes holds.
3. **Commit.** `AppointmentBooker::book(appointment)`:
   - status `held → booking` (conditional update on `status='held' AND
     hold_expires_at > now()`; 0 rows → hold lost → re-offer).
   - **Live re-check**: provider `busy()` for exactly that interval
     (bypassing cache). Busy → `failed` with reason `taken_externally`,
     the script's `slot_taken` exit re-offers.
   - provider `create()` with the idempotency key; on success store
     `external_id/etag`, status `booked`, event `push_ok`.
   - Timeout/5xx → status stays `booking`, `sync_state=pending_push`;
     a `ReconcileAppointmentPush` job (retries with backoff, Horizon
     queue `calendar`) fetches by idempotency key / UID first and only
     re-creates if absent. The caller is told it's booked *only* after
     `booked`; if the live call can't wait (> 8 s), the script takes the
     `pending` exit: "I've reserved that time and you'll get a text
     confirming it shortly" — the hold keeps the slot locally and
     confirmation is sent when reconciliation succeeds, or the
     appointment goes to the plan 05 dispatcher queue as "booking failed,
     call the caller back" if it can't.
4. **Residual race** (office books the same slot in their calendar in
   the seconds between re-check and create) is inherent to integrating
   with systems without locks. Detected by the drift check below and by
   PMSs that reject double-booking (`409` → `failed`, re-offer if the
   call is live, otherwise dispatcher queue). Documented to clients.

### Drift, external changes and sync

- `orbital:check-appointment-drift` (every 15 min) fetches upcoming
  (next 14 days) booked appointments per connection, batched; moved →
  `external_changed` (update Orbital times, event, notify per policy);
  deleted → `external_deleted` (status `cancelled` by `external`, cancel
  pending reminders). Push webhooks (Google `events.watch`, Graph
  subscriptions) can replace polling later.
- Connection health: `invalid_grant` / `401` after refresh →
  `needs_reauth`, all of that connection's types show "calendar
  disconnected" to operators (scripts take `unavailable`), and staff +
  the client admin are notified (email now, plan 05 dispatch later).

### OAuth and credential storage

- **Platform OAuth apps** (one Google Cloud OAuth client, one Entra app
  registration, owned by the platform operator) are configured in
  `PlatformSettings` via `SettingsRegistry` entries with `secret: true`
  and `config_key` → new `config/services.php` blocks:
  `google_calendar.{client_id, client_secret}`,
  `microsoft_graph.{client_id, client_secret, authority}`. Unset → the
  provider is hidden in the UI (CalDAV, web_call and native still work).
- **Per-client tokens** live in `calendar_connections.credentials`
  (`encrypted:array`, the same pattern as
  `ClientWebEndpoint.credentials`). Never in `PlatformSetting`, never
  on `Team`.
- **Flow**: `GET /integrations/calendar/{provider}/connect?connection=…`
  (web, auth; policy: may manage this client's calendars) → provider
  consent → `GET /integrations/calendar/{provider}/callback`. `state` is
  a signed, single-use (Valkey, 10 min) blob `{connection_id, team_id,
  user_id, pkce_verifier_ref, nonce}`; the callback re-checks that the
  logged-in user still has rights on that team and that the connection
  belongs to it. A path under `/integrations/…` avoids the existing
  `/oauth/*` Passport/OIDC routes (`routes/web.php:196-200`).
- **Refresh**: `TokenStore::accessToken(connection)` refreshes when
  `expires_at - 120 s` has passed, under `Cache::lock("calendar-token:{id}", 15)`;
  re-reads the row after acquiring the lock (another worker may have
  refreshed); writes access+refresh token atomically. Needed because
  Microsoft rotates refresh tokens and two concurrent refreshes can
  invalidate each other.
- **Disconnect** revokes at the provider (Google `/revoke`; Graph has no
  per-token revoke — the client removes the app; we wipe tokens) and
  wipes `credentials`.
- **APP_KEY rotation** must use `APP_PREVIOUS_KEYS` so encrypted
  credentials stay readable; add a doc note and an `orbital:status`
  check that decrypts one connection per provider.
- Tokens and response bodies are excluded from logs, Telescope
  (`hideRequestParameters`/`hideResponseParameters` for the callback
  and provider hosts), Sentry breadcrumbs and `appointment_events.payload`.

### Script steps (plan 01 handlers)

New catalogue entries in `IntakeGoalLibrarySeeder` (category `action`,
explicit `exits`; the seeder derives `max_transitions` from exits,
`IntakeGoalLibrarySeeder.php:44-53`):

| Key | Params | Exits | Behaviour |
|---|---|---|---|
| `find_appointment_slots` | `appointment_type` (picker), `resource` (picker / slot ref / "any"), `search` (`first_available` / `on_date` slot / `near` slot / `part_of_day`), `count` (default 3), `slot` (store as) | `found`, `none`, `unavailable` | Non-interactive. Writes the offered slots to `{slot}` (list) and `{slot}_text` ("Tuesday at 2:30 or Wednesday at 9"). |
| `book_appointment` | `appointment_type`, `slot_source` (from `find_…` list), `hold_ttl`, `fields` (slot list → appointment fields), `title`/`notes` override templates, `confirm_with_caller` (bool, default on), `store_reference_as` | `booked`, `pending`, `slot_taken`, `declined`, `unavailable`, `failed` | **Interactive**: offers slots (choice inputs for operators; the AI reads them), holds on selection, reads back date/time/location and asks for confirmation, then commits. `slot_taken` loops back to offer the next slots (max 3 loops, then `failed`). |
| `find_appointment` | `lookup_by` (`caller_phone` / `reference` / `name_dob`), `slot` | `found`, `multiple`, `not_found` | Looks up the caller's upcoming appointments for this client only. |
| `cancel_appointment` | `appointment_slot`, `reason_slot`, `require_confirm` | `cancelled`, `too_late`, `not_allowed`, `failed` | Honours `cancel_cutoff_minutes` / `allow_caller_cancel`; `too_late` lets the script take a message instead. |
| `reschedule_appointment` | `appointment_slot`, then same as `book_appointment` | `rescheduled`, `kept_original`, `too_late`, `slot_taken`, `failed` | Books the new slot **before** cancelling the old (old stays held as a fallback); if the new booking fails the old is untouched. Chain via `rescheduled_from_id`. |

`schedule_callback` stays as is (a fuzzy window on a message). The
"appointment callback" template in plan 02's `ApplyOrchestrationTemplate`
list becomes: identify reason → `find_appointment` (existing?) →
`book_appointment` / `reschedule_appointment` → `save_message` (plan 03,
summary includes the booking) → end.

Template variables exposed after booking (for messages, emails, SMS):
`appointment.reference`, `appointment.starts_at`, `appointment.display_time`,
`appointment.resource_name`, `appointment.type_name`,
`appointment.location`, `appointment.status`. Plan 03 message templates
can include them; the message links `appointment_id`.

Until plan 01's engine ships, nothing here is executable in the
`prompt` runtime — the steps are flagged "engine only" in the editor
(plan 01's editor validation).

### Operator UI

- **Script runner (plan 02)**: `book_appointment` renders a slot picker:
  day strip (next 7 days with availability counts), slot buttons for
  the selected day, resource filter, "first available" button, and a
  hold countdown chip ("held 4:12"). Keyboard: arrows move days, number
  keys pick slots. Selecting a slot holds it immediately; changing it
  releases the old hold. A "Confirm booking" button commits; while
  `booking` it shows a spinner, then the reference.
- **Sidebar card** for the client: "Appointments" — upcoming
  appointments for this caller's number (plan 13 caller history uses
  the same query), with Cancel / Reschedule actions that start the
  matching steps.
- **Failure states are explicit**: "Calendar disconnected — take a
  message", "Couldn't confirm with the client's system — reserved
  locally, will retry".
- **Multi-call desk**: each call's hold belongs to its own session key;
  switching calls doesn't release holds; hanging up releases holds
  for that session (`ScriptEngine::end` hook).

### Admin UI (Filament, platform staff)

Under `ClientResource` (alongside `ManageClientMessagingEndpoints`
etc. in `app/Filament/Resources/ClientResource/Pages/`):

- `ManageClientCalendars` — connections (connect via OAuth button,
  CalDAV form with "Test connection", PMS form choosing a
  `ClientWebEndpoint` + operation editor with a **Test** button that
  runs availability for tomorrow and shows mapped slots), health,
  reconnect, disconnect.
- `ManageClientBookableResources` — pick calendars from
  `listCalendars()`, link directory entries, schedule picker (plan 06).
- `ManageClientAppointmentTypes` — durations, buffers, notice, horizon,
  templates with live preview (TemplateEvaluator), reminder policy.
- `ManageClientAppointments` — table (filters: status, sync state,
  resource, date), detail with `appointment_events` timeline, manual
  "retry push", "mark as booked externally", "cancel".
- `ManageClientWebEndpoints` — the missing CRUD for `ClientWebEndpoint`
  (credentials as masked inputs; shape per `auth_type`). Small, and a
  prerequisite for the PMS path.
- A platform-wide **"Calendar sync problems"** widget: connections in
  `needs_reauth`/`error`, appointments in `push_failed` / `pending_push`
  older than 10 min.

### Client portal

- **Appointments** page (new permission `portal.view_appointments`):
  list + week calendar of bookings Orbital made, with status, booked
  by (AI/operator name), call recording link (if
  `portal.view_recordings`), reference.
- **Manage** (`portal.manage_appointments`): cancel/reschedule an
  Orbital-made booking (goes through the same `AppointmentBooker`, so
  the external event and reminders follow).
- **Calendar settings** (`portal.manage_calendars`, client admins only
  by default): connect their own Google/M365 account (the most common
  setup step — clients shouldn't have to hand passwords to the call
  centre), edit native availability and blocks, view connection health.
  Appointment *types* stay platform-staff-managed in v1 (they shape the
  script); exposing them is a plan 24 question.
- **Native calendar**: week view per resource, add block, plus a
  private **ICS feed** URL per resource (`/ics/{token}.ics`, random
  token stored hashed, regenerate button) so the client can subscribe
  from their phone without any integration.

### AI path

Following plan 01, the AI doesn't get raw calendar tools — it gets
the engine's current step. When the current step is `book_appointment`
the step view includes the offered slots, and the worker gets two
step-scoped tools (schemas added to `AgentFlowCompiler::toolSchema()`
`AgentFlowCompiler.php:945` and built in
`agent-worker/tools/compiled_flow.py` `build_dynamic_tools()` `:29-89`):

- `search_slots(preference: str)` → `POST /api/call-sessions/{key}/appointments/search`.
  The engine parses preferences with deterministic helpers first
  (`date`/`part_of_day`/`weekday` from the LLM as structured args:
  `{date?, after?, part_of_day?, weekday?}` — the LLM fills structure,
  code resolves dates in the client's zone) and returns at most 3 slots
  with display strings. The LLM never invents times; it may only offer
  returned slot ids.
- `choose_slot(slot_id)` → hold; `confirm_booking()` → commit. Both via
  the engine's `submit_step`; responses carry the next instruction
  ("Read back: Tuesday 3 December at 2:30 pm with Dr Patel, reference
  K7M-42").

Voice specifics: times are read in the client's zone with the zone named
when the caller's area code suggests a different zone; holds are taken
as soon as the caller says "that one" (TTL covers the read-back);
`confirm_with_caller` is always on for AI. Barge-in during read-back
doesn't confirm.

Auth: same worker bearer token as the other call-session routes
(`CallSessionController::isWorker()`), plus the session must belong to a
team that owns the appointment type — the controller resolves the team
from the `call_session_states` row, never from request input.

### Confirmations and reminders

- **Confirmation** on `booked`: per type, channels `sms`, `email`, or
  none; rendered from templates (TemplateEvaluator) with an attached
  `.ics` for email. Sent via plan 05 transports (`SMS` through
  `MessagingProvider`, honouring opt-outs enforced in
  `OutboundMessageService`) — this plan adds a "send to caller"
  recipient kind to plan 05 rather than its own sender. Until plan 05
  lands, email confirmation uses `Mail` directly and SMS is unavailable.
- **Reminders**: `reminder_policy` = list of `{offset: "-24h"|"-2h",
  channel: sms|email|call, template, require_reply: bool}`. Scheduling
  creates rows in **plan 19's `scheduled_calls`** for `call` reminders
  (AI persona reads the reminder and offers "press 1 to confirm, 2 to
  cancel, 3 to talk to someone") and plan 05 delayed dispatches for
  SMS/email. Each reminder stores its job/row id on an
  `appointment_reminders` table (`appointment_id`, `channel`,
  `due_at`, `status`, `scheduled_call_id`/`dispatch_id`) so cancel and
  reschedule can withdraw them exactly.
- Reminders respect quiet hours (plan 06 schedule on the type, default
  08:00–20:00 in the *caller's* likely zone, falling back to client
  zone) and TCPA-style consent: SMS/call reminders require the caller
  agreed during booking (`reminder_consent` field set by the step's
  read-back: "Can we text you a reminder?").
- **Replies** (plan 16): SMS `C`/`CONFIRM` → `confirmed`, `X`/`CANCEL`
  → `cancel_appointment` (respecting cutoff; outside cutoff → reply
  "please call us" and notify the client), `R` → create an operator
  follow-up (plan 18). Matched by caller number + open reminder, like
  plan 05's ack matching.
- **PHI**: for `privacy=minimal` types reminders say "You have an
  appointment with {{ client.name }} on {{ display_time }}" — never the
  reason — and the secure-link option from plan 17 applies.

### Cancellations and reschedules

- Callers identified by phone match (`find_appointment` by
  `caller_phone`) and, for `name_dob` / `reference`, by the stated
  values; ambiguous matches go to the `multiple` exit for the operator
  or AI to disambiguate by date ("the one on Tuesday?").
- Cutoff enforced in `AppointmentBooker`, not in the script, so portal,
  SMS and voice behave the same. Platform staff may override with a
  reason (audited).
- Reschedule = book-new-then-cancel-old (above). Reminders of the old
  appointment are withdrawn; new ones scheduled.
- Cancellation always cancels the external event (or calls the PMS
  `cancel` operation) and records `cancelled_by` (`caller`, `user`,
  `portal_user`, `external`, `system`).
- Optional client notification on cancel/reschedule (email/SMS to the
  client's contact or resource's directory entry via plan 05) — common
  for clinics that need to know a slot opened.

### Permissions and tenant isolation

- New admin permissions in `PermissionCatalogSeeder::CATALOG`:
  `calendar_connection.{view_any,view,create,update,delete}`,
  `appointment_type.{…}`, `appointment.{view_any,view,create,update,cancel}`.
  Operators get `appointment.create/cancel` via a role (book/cancel on
  calls), supervisors get override-cutoff.
- Portal permissions: `portal.view_appointments`,
  `portal.manage_appointments`, `portal.manage_calendars` added to
  the catalog and to `ClientProvisioner`'s defaults
  (`app/Services/Clients/ClientProvisioner.php:53` area):
  `client_user` gets view; `client_admin` gets all three.
- Isolation rules (each with a test):
  - Every lookup passes the team explicitly; no service resolves a
    connection, resource, type or appointment by id alone.
  - Cross-table consistency enforced in `AppointmentBooker` and by
    composite FKs where cheap: an appointment's type, resource and
    connection must share its `team_id`.
  - OAuth `state` is bound to team + user; the callback refuses if the
    connection's team differs from the state's.
  - The worker API resolves the team from the call session, so a
    session for client A cannot search client B's calendar even with B's
    type id.
  - ICS feed tokens resolve to one resource only.
  - `find_appointment` by phone never crosses clients (the same patient
    may use two clinics on the platform).

### Audit

- `appointment_events` for every state change (actor + source surface:
  `ai`, `operator`, `portal`, `sms_reply`, `system`, `external`).
- Plan 01 `script_events` gain `action_run` entries referencing the
  appointment id, so call replay (plan 22) shows the booking moment.
- Connection lifecycle (connected, reauthorised, scopes changed,
  disconnected, by whom) recorded as `calendar_connection_events`
  (same shape) — useful when a client says "you never had access".
- Metrics (pushgateway, existing Telephony dashboard): bookings per
  client per day, hold→book conversion, `slot_taken` rate,
  provider latency/error rate, reminders sent/confirmed/cancelled.

## Edge cases

- **DST**: slot generation in the resource's zone; a 60-min slot at
  01:30 on fall-back day is ambiguous → schedules (plan 06) define wall
  time; we skip ambiguous/nonexistent local times rather than guess.
- **Caller in another zone**: read in client zone and say the zone
  ("2:30 pm Eastern"); `appointment.timezone` stores what was said.
- **All-day / out-of-office events** block the whole day only if
  `showAs/TRANSP` is busy; Google "working location" events ignored.
- **Recurring busy events** on CalDAV must be expanded (vobject); on
  Google/M365 free/busy endpoints already expand them.
- **Hold expiry during read-back**: commit fails at the conditional
  update → re-hold the same slot if still free, else re-offer.
- **Caller hangs up after selecting, before confirming**: hold released
  at session end; nothing written externally (unless tentative events
  are on — then deleted).
- **Hang-up during `booking`**: commit continues server-side; the
  confirmation SMS is the caller's proof; if it fails it lands in the
  dispatcher queue to call the caller back.
- **Duplicate booking by the same caller** (calls twice): `find_appointment`
  runs first in the template; `book_appointment` warns when the caller
  already has an upcoming appointment of the same type.
- **Calendar deleted / resource access revoked** at provider (404 on
  the calendar) → resource marked inactive, health alert.
- **Provider rate limits** (Google 429, Graph 429 with `Retry-After`):
  honour `Retry-After`; availability returns `unavailable`, not empty.
- **Client changes appointment type duration** with bookings in place:
  existing appointments keep their times; only new bookings change.
- **Connection switched** (Google → M365): existing appointments stay
  on the old connection for cancel/reschedule until they pass; the old
  connection is kept "read-only" until then.
- **Template errors**: `TemplateEvaluator` leaves `{{ expr ⚠ error }}`
  in the output on failure (`TemplateEvaluator.php:41-45`). For event
  titles and caller-facing SMS that's unacceptable; the booking service
  renders in a strict mode (new `renderStrict()` that throws) and falls
  back to the type's plain default title.
- **Test drive**: plan 01 dry-run mode uses a fake provider; for real
  connections the editor's Test button only reads availability and
  never creates events unless the author ticks "create a test event"
  (created and deleted immediately, marked `[Test]`).
- **Client off-boarded / suspended** (`Team.suspended_at`): reminders
  stop, connections disabled, tokens revoked on deletion.
- **Minors / third-party booking** ("booking for my mother"): the
  appointment's patient name field is separate from caller fields;
  the type's `required_fields` decide.

## Migration / rollout

1. **Phase A — foundation (no UI for callers)**: migrations (incl.
   `btree_gist`), models, `WebRequestRunner` extraction (behaviour-
   preserving; existing post-call `web_call` tests keep passing),
   `ManageClientWebEndpoints`, native provider, availability service,
   booker with holds, `appointment_events`. Feature flag
   `orbital.features.appointments` (config) + per-team toggle.
2. **Phase B — integrations**: Google, then M365 (delegated), then
   CalDAV (adds `sabre/vobject`), then PMS `web_call` adapter with the
   operation editor. Platform OAuth app settings in PlatformSettings.
   Each provider behind its own setting so a platform operator without
   a Google OAuth app simply doesn't see it.
3. **Phase C — scripting**: step handlers (requires plan 01 engine),
   operator runner widgets (plan 02), AI tools, the "appointment
   callback" orchestration template.
4. **Phase D — notifications**: confirmations (email first), reminders
   via plan 05/19, SMS replies via plan 16.
5. **Phase E — portal**: appointments list, manage, calendar settings,
   native calendar + ICS feed.
6. Pilot with one NCI client per provider type before general use.
   No data migration: nothing exists today. Google verification of the
   OAuth app (sensitive scopes) takes weeks — start it in Phase A.

## Tests

Clock frozen (`travel()`), providers faked behind the interface, with
recorded-fixture contract tests per real provider.

**Availability**
- Type schedule ∩ resource schedule ∩ free time; buffers; min notice;
  horizon; `max_per_day`; slot step alignment.
- Plan 06 holiday removes a day; manual closure override removes today.
- DST spring-forward day omits the 02:00 slot; fall-back day doesn't
  double-offer 01:30.
- Provider error → `unavailable` exit, not `none`.
- Cache: second call within 60 s doesn't hit the fake; commit re-checks
  live regardless.

**Holds and races**
- Two sessions hold the same slot: second gets `SlotTaken` (exclusion
  constraint, real Postgres in the test DB).
- Overlap via buffers (10:00–10:30 + 15 min buffer blocks 10:30 start).
- Expired hold doesn't block a new hold; sweeper marks it `expired`.
- Commit after expiry → re-hold or re-offer.
- Live re-check finds an external event created after the hold →
  `slot_taken` exit, next slots offered.
- Provider times out on create → `pending_push`; reconciliation finds
  the event by idempotency key and does **not** create a second one.
- Google `409` on insert retry → success; CalDAV `412` → success.
- One session holding a second slot releases the first.

**Script scenarios (answering-service suite, plan 01 fixtures)**
- "New patient books first available": fixture orchestration, caller
  inputs → expected events: `find_appointment_slots.found` →
  `book_appointment.booked` → `save_message` with reference in summary
  → confirmation SMS dispatched.
- Caller declines all offered slots → `declined` → message with
  requested window (existing `schedule_callback`).
- Caller cancels by phone inside cutoff → `too_late` → message taken,
  appointment untouched.
- Reschedule where new booking fails → original intact, `failed` exit.
- Calendar disconnected → `unavailable` → "office will call you" path.
- Operator runner (Livewire): pick day, pick slot (hold chip appears),
  confirm → reference shown; switch call on multi-call desk keeps hold.
- AI parity: same fixture through the worker tool API yields the same
  event log as the operator runner (README testing strategy #5).
- AI can only book slot ids returned by `search_slots` (made-up id →
  validation error).

**Notifications**
- Booking schedules reminders at -24h/-2h; cancel withdraws both
  (plan 19 rows cancelled, plan 05 dispatches cancelled).
- Reschedule moves reminders.
- Quiet hours push a -2h reminder for a 08:30 appointment to 08:00.
- SMS reply `C` confirms; `X` cancels; `X` after cutoff replies "call
  us" and notifies the client; two open reminders to the same number
  match the soonest.
- No consent → no SMS/call reminders; opt-out (existing
  `MessagingOptOut`) blocks confirmation SMS.
- `privacy=minimal` type: external event title and SMS contain no
  reason or caller details beyond initials.

**OAuth and credentials**
- Callback with tampered/expired `state` → 403; state for team A used
  while user's current team is B → 403.
- Credentials are encrypted in the DB (raw column ≠ plaintext) and
  absent from `toArray()`, Livewire payloads and logs.
- Concurrent refresh (two jobs) → exactly one token request; both use
  the new token.
- `invalid_grant` → `needs_reauth`, alert sent once, steps take
  `unavailable`.
- Disconnect revokes (Google fake) and wipes credentials.

**Tenant isolation**
- Worker API: session for client A with client B's type id → 404.
- `find_appointment` by phone with the same number booked at A and B
  returns only the session's client.
- Portal user of A can't list, cancel or see the ICS feed of B.
- Admin pages scoped through `ClientResource` show only that client's
  connections; operator role without `appointment.cancel` can't cancel.

**Providers (contract, recorded fixtures)**
- Google freeBusy / insert / delete / 410; Graph getSchedule / create
  with transactionId / 429 Retry-After; CalDAV discovery on iCloud and
  Nextcloud fixtures, recurring VEVENT expansion, ETag conflicts;
  web_call PMS mapping (`free` and `busy` semantics) with a fake HTTP
  server.

**Regression**
- Existing post-call `web_call` behaviour unchanged after the
  `WebRequestRunner` extraction (headers merge, JSON escaping, expected
  status codes, full-URL path).

## Open questions

1. Which NCI clients book appointments today in IS, and into what
   (IS's own scheduler, their Google/Outlook, a PMS)? This decides
   whether native or Google goes first.
2. Which PMSs appear most (Dentrix, Open Dental, Eaglesoft, athena,
   ServiceTitan, Jobber…)? Several need a paid partner API or a
   middleware (NexHealth, Sikka); is NCI prepared to hold those
   contracts, or do clients bring their own API access?
3. Does the platform operator (NCI) own the Google/Microsoft OAuth
   apps, and accept Google's sensitive-scope verification? Installable
   software means each platform operator needs its own apps — document
   this in setup.
4. Should clients be able to edit appointment types in the portal, or
   are types part of the script and therefore staff-only (plan 24)?
5. Do any clients need multi-resource bookings (provider + room) or
   group/class bookings? Out of scope for v1 as written.
6. Reminder calls via AI (plan 19) — acceptable to NCI's medical
   clients, or human-only?
7. Should IS appointment data (if NCI uses IS scheduling) be imported
   for continuity of reminders at cut-over?

## Size estimate

~5–6 weeks after plans 01 and 06, plus plan 05/19 for reminders:

| Piece | Estimate |
|---|---|
| Models, migrations, native provider, availability, holds/booker, audit | 1 wk |
| `WebRequestRunner` extraction + `ManageClientWebEndpoints` + oauth2 client-credentials | 0.5 wk |
| Google provider + OAuth framework (token store, connect/callback, settings) | 1 wk |
| Microsoft Graph provider (delegated + app mode) | 0.5–1 wk |
| CalDAV provider (vobject, discovery, recurring) | 0.5–1 wk |
| PMS `web_call` adapter + operation editor/test | 0.5 wk |
| Script handlers + operator runner widgets + AI tools | 1 wk |
| Confirmations, reminders, SMS replies (on top of 05/16/19) | 0.5 wk |
| Portal (list, manage, calendar settings, native calendar, ICS) | 0.5–1 wk |
