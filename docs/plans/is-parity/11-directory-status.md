# Plan 11: Directory status and status-based contact

**Status:** proposed · part of [IS parity](README.md) · depends on 04
(contact methods, on-call resolver) and 05 (dispatch) for the dispatch
half; the status model, operator view and SMS/portal/IVR updates ship
without them · uses 01 (script access), 06 (time parsing in the
client's zone), shares PIN handling with 12
**Written:** 2026-11-24 · expands survey items D and I of
[07](07-additional-features.md)

## Problem

### What IS does

In Intelligent Series every directory listing has a **status**: "In",
"Out", "In surgery", "With patient", "On vacation until 12/28", "Do not
disturb". A status carries a **return time** and a free-text **note**
("covering: Dr Patel"). Each client defines its own status list. Every
status has its own **contact order**: in clinic, call the office and
then the pager; at home, text and then the cell; on vacation, send to
the covering doctor. Staff change their own status by calling a status
line and entering an ID and PIN, by text, on the web, or by asking an
operator. When the return time passes, the status goes back to the
default without anyone doing anything. Operators see the status
whenever they open the listing, and dispatch uses it automatically.

### What Orbital has today (checked in code)

- **No status anywhere in the directory.** `DirectoryEntry` has
  `team_id`, `shared_directory_id` and a `values` JSONB column, and
  nothing else (`app/Models/DirectoryEntry.php:33-37`). Field roles are
  `name, email, phone, organization, none`
  (`app/Models/DirectoryFieldDefinition.php:170`). There is no status
  role, and no field type with an expiry. A client could add a
  `select` field called "Status" (`DirectoryFieldDefinition.php:156-168`),
  but it would never expire, nothing would read it, and every change
  would overwrite the last one with no history.
- **Tags are static.** `DirectoryTag` (`app/Models/DirectoryTag.php:207-231`)
  is meant for routing hints such as "after-hours". It has no time
  bound and no actor, so it can't stand in for a status.
- **Ownership is team or shared pool.** Entries and field definitions
  use `BelongsToTeamOrSharedPool`, not plain `BelongsToTeam`
  (`DirectoryEntry.php:26`, `app/Models/Concerns/BelongsToTeamOrSharedPool.php:45-101`).
  Rows belong to exactly one client or to one `SharedDirectory`
  (CHECK constraint, on pgsql only, in
  `database/migrations/2026_04_14_100001_create_directory_tables.php:55-62`).
  Shared directories (such as the "NCI Providers (Amtelco)" master
  index, `app/Services/Import/Amtelco/DirectoryImporter.php:41`) are
  attached to many clients, so one provider's status is seen by every
  client that subscribes. The global scope does nothing when there is
  no authenticated user (`BelongsToTeamOrSharedPool.php:50-53`), which
  is the case in jobs, webhooks and dialplan callbacks. Those paths
  must scope by hand.
- **Directory entries never log in.** This is stated at
  `DirectoryEntry.php:13-15` and `ManageClientDirectory.php:35-36`.
  "Staff update their own status in the portal" therefore can't mean a
  portal account per doctor.
- **Admin UI.** `ManageClientDirectory` (`app/Filament/Resources/ClientResource/Pages/ManageClientDirectory.php:76-141`)
  has a name column, tags, one hidden-by-default column per field
  definition, and create/edit/delete/import. There is no status column,
  filter or action. It loads only the client's own definitions
  (`:149-153`), so shared entries are managed elsewhere
  (`SharedDirectoryResource`).
- **Operators never see the directory during a call.** `ClientCallBrief`
  (`app/Services/Operator/ClientCallBrief.php:25-46`) returns the
  greeting, operator note, passcode, alias accounts and info pages,
  with nothing from the directory. `ClientSearch`
  (`app/Services/Operator/ClientSearch.php:69-96`) searches clients
  only. The single-call sidebar (`resources/views/filament/operator/pages/workspace.blade.php:108-157`)
  and the multi-call aside (`resources/views/livewire/multi-call-workspace.blade.php:309-357`)
  render the brief and info pages only. Outside import and admin code,
  no class under `app/` reads `DirectoryEntry`. The AI's
  `lookup_account` tool is a stub
  (`agent-worker/tools/compiled_flow.py:209-220`). TODO.md:529
  ("Phone book / directory in the softphone widget") is still open.
- **Inbound SMS** routes by the number that was texted
  (`app/Services/Messaging/InboundMessageRouter.php:47-84`), so the
  client is known and the sender is not authenticated. The process is
  in `app/Jobs/ProcessInboundMessageJob.php`: consent keywords
  (`:118`), the entry write (`:123-148`), then the AI auto-reply
  (`:165-169`). Consent keywords match only when they are the whole
  message (`app/Models/MessagingOptOut.php:165-188`). The reserved
  words include `cancel`, `end`, `yes` and `info`
  (`MessagingOptOut.php:39-57`). A status keyword list must never
  contain these. `OptOutRegistry::isSuppressed()`
  (`app/Services/Messaging/OptOutRegistry.php:198`) is the gate for
  every send. `OutboundMessageService` can only reply into a thread as
  an operator or as an agent (`app/Services/Messaging/OutboundMessageService.php:45,59`).
  It has no system or confirmation sender. `messaging_endpoints.team_id`
  is NOT NULL (`database/migrations/2026_08_17_110000_create_messaging_tables.php:48`),
  so there can't be a platform-wide SMS status number without a
  "house" client.
- **No IVR primitives.** The generated dialplan has no `Read()`,
  `Background()` or AGI (`resources/views/asterisk/*.blade.php`).
  `from-trunk` switches on the destination types `extension`, `queue`,
  `orchestration`, `voicemail` and a default
  (`resources/views/asterisk/from-trunk.blade.php:36-81`), and already
  allows a rule with no client ("platform", `:33`). The Asterisk image
  enables `res_ari`/`res_stasis`, but `func_curl` and `res_agi` are not
  explicitly enabled (`docker/asterisk/Dockerfile:55-111`). libcurl
  headers are installed (`:22`), so `func_curl` probably builds by
  default, but nobody has checked. The callback pattern from Asterisk
  to Laravel already exists: a shared-secret POST from
  `docker/asterisk/notify-voicemail.sh` to `/api/voicemail/received`
  (`routes/api.php:95-99`).
- **The import loses the status annotations that do exist.** The
  Amtelco export (`data_files_inbound/nci_directories.json`, 11,307
  listings in 1,635 directories) has no structured status field.
  Operators wrote status into free text instead:
  - About 200 contact methods carry "NOT O/C" (not on call) as a
    prefix on the raw number.
  - About 25 carry "AWAY", "AWAY TIL 11/13" or "ON LEAVE"
    (e.g. `5242 FISHER#3`: home `"AWAY TIL 11/13 2124399843"`, cell
    `"NOT O/C!!!!19175092428"`).
  - Procedures and names add "RETIRED", "LEFT GROUP! PATEL O/C" and
    "RACHANA/AWAY!".
  - The `seasonal` flag marks "VACATION HME" numbers, which are really
    a per-status contact method.

  `DirectoryListingMapper::placeNumber()` takes `number` before `raw`
  (`app/Services/Import/Amtelco/DirectoryListingMapper.php:253`), so
  these prefixes are dropped silently. Only `private`/`inactive`-style
  flags and the procedure text survive (`:95-101`, `:258-262`).
- **Permissions.** The catalogue has no directory permissions at all
  (`database/seeders/PermissionCatalogSeeder.php`). The portal
  allow-list is `portal.view_home/calls/messages/recordings` plus
  `manage_users/roles` (`app/Services/Clients/ClientProvisioner.php:49-69`).
- **Audit.** There is no general audit log. The model classes are
  domain-specific (`FailoverAuditLog`, `ConversationActivity`).

## Goal

Every directory contact can have a time-bounded status from a
client-defined list. The status decides how the contact is reached
(contact order, cover person, hold). Staff can change it themselves by
phone (ID + PIN), by SMS keyword, or from a no-login web link, and
client admins and operators can change it too. It reverts by itself
when the return time passes. Operators (single and multi-call), the AI,
scripts (01), on-call (04) and dispatch (05) all read the same resolved
status, and every change is attributable.

## Design

### Data model

The new tables follow the directory's ownership model. Each row
belongs to a client (`team_id`) **or** to a shared directory
(`shared_directory_id`), never both, and uses
`BelongsToTeamOrSharedPool` with the same constants as `DirectoryEntry`.
Status rows always take their owner from their entry, so a shared
provider has one status that every subscribing client sees.

**`directory_status_types`** is the client-defined list (a status
list for a shared directory is defined by platform staff):

| column | notes |
|---|---|
| `team_id` / `shared_directory_id` | owner, exactly one (CHECK on pgsql, as for entries) |
| `key`, `label` | `in_surgery`, "In surgery". Unique per owner |
| `availability` | `available` · `limited` · `unavailable`. Dispatch and on-call act on this, not on the label |
| `color`, `sort_order`, `is_active` | Types are never hard-deleted once referenced |
| `is_default` | Exactly one per owner ("In"). This is what an entry reverts to |
| `requires_until` | e.g. "On vacation" must have a return time |
| `default_duration_minutes` | Used when the setter gives no time, e.g. "With patient" = 30 |
| `max_duration_days` | Sanity cap. Beyond it the setter must confirm (operator) or is refused (SMS/IVR) |
| `sms_keywords` | `["OUT","O"]`. Validated on save against `MessagingOptOut::STOP/START/HELP_KEYWORDS` and unique per owner |
| `ivr_digit` | 1–9, unique per owner |
| `contact_order` | Default ordered list of contact-method **kinds** for this status (`["office","pager","cell"]`), overridable per entry (below) |
| `delivery_mode` | `normal` · `cover` (send to the cover entry) · `hold` (hold until the status ends, see plan 05 `held`) · `operator` (dispatcher queue, call by hand) |
| `operator_instruction` | Shown with the badge: "Do not page in surgery; take message unless STAT" |
| `notify_on_expiry` | Text the person "You're back to In" when it lapses |

**`directory_status_periods`** holds the actual statuses, past,
current and scheduled:

| column | notes |
|---|---|
| `directory_entry_id` | FK, cascade. Owner columns are copied from the entry on create |
| `status_type_id` | FK to `directory_status_types`, restrict delete |
| `starts_at`, `ends_at` | timestamptz. `ends_at` null means "until changed". A scheduled vacation is just a future `starts_at` |
| `note` | ≤ 500 chars. Shown to operators and to the AI |
| `cover_entry_id` | Optional covering contact. Must resolve within the same owner, or within a shared directory the owning client subscribes to |
| `source` | `operator` · `admin` · `portal` · `self_link` · `sms` · `ivr` · `ai` · `script` · `import` · `api` |
| `set_by_user_id` / `set_by_entry_id` | Who did it: a user, or the person themselves |
| `via_team_id` | Which client's channel the change came through (SMS endpoint, IVR rule). Matters for shared entries |
| `cancelled_at`, `cancelled_by_*` | Replaced or cleared early. Rows are never deleted |
| `expiry_processed_at` | Set once by the sweeper (below) |

Index: `(directory_entry_id, starts_at desc) where cancelled_at is null`.

**The status is derived, not stored.** The effective status at time
*T* is the non-cancelled period with the greatest `starts_at` such that
`starts_at <= T < coalesce(ends_at, ∞)`. With no such period, the
owner's default type applies. So the revert needs no job: an expired
status stops matching the moment its `ends_at` passes, even if every
worker is down. Setting a new status "now" cancels any other open-ended
current period. It leaves future scheduled periods alone, so a vacation
booked for next week survives "OUT 3PM" today.

**`directory_entry_status_orders`** is an optional per-entry override
of the contact order: `(directory_entry_id, status_type_id,
contact_method_keys jsonb)`. It covers "for Dr Kulcsar, In surgery
means text only". It refers to plan 04's contact methods by a **stable
key**. This is the one requirement this plan puts on 04: each element of
its ordered `[{method, address, label, hours?}]` list needs a `key`
(uuid or slug) that survives reordering. Without that, the override
falls back to kind-level matching only.

**`directory_status_credentials`** is self-service identity
(`directory_entry_id` unique):

- `login_id`: 4–8 digits, unique installation-wide, so one status line
  can serve every client.
- `pin_hash` (argon2id), `pin_set_at`.
- `failed_attempts`, `locked_until`.
- `sms_numbers` (E.164 list allowed to change status by text; defaults
  to the entry's `cell_phone`/`text_to`).
- `sms_requires_pin` (bool; for clients that want "OUT 3PM 4321").
- `self_link_token_hash`, `self_link_expires_at`.

Plan 12 (phone check-in) uses the same `PinVerifier` service. Operators
never see PINs. Admins can only reset them, and the new PIN is sent
once by SMS to an allowed number.

**`directory_status_events`** is the append-only audit, one row per
action:

- Types: `set`, `scheduled`, `cleared`, `cancelled`, `expired`,
  `edited_note`, `auth_failed`, `rejected`, `cover_changed`,
  `credential_reset`.
- Columns: actor (user / entry / system), source, `via_team_id`,
  caller ID or sender address, before and after JSON, `created_at`.
- `auth_failed`/`rejected` rows can have a null entry (an unknown ID or
  number). They carry the owner of the channel they arrived on.
- Nothing updates or deletes these rows. Retention follows the call
  logs.

Separate tables (rather than keys in `values`) are deliberate.
`DirectoryImporter --refresh` replaces every field it owns
(`DirectoryImporter.php:228-233`, TODO.md:493), so a status kept in
`values` would be wiped by a re-import.

### Services (`app/Services/Directory/Status/`)

- **`StatusResolver::at(DirectoryEntry, CarbonImmutable $at):
  ResolvedStatus`** returns the type, period, until, note, cover,
  source, set by and set at, and `isDefault`. It is pure, takes an
  injected clock, and has a batch form `forEntries(Collection, $at)`
  that runs one query for a whole sidebar list. Callers pass the owner
  explicitly. It never relies on the auth scope.
- **`ContactPlan::for(DirectoryEntry, $at, ?Urgency)`** is the single
  answer to "how do I reach this person now":
  - the resolved status;
  - ordered contact methods (per-entry override, else the type's
    `contact_order`, else plan 04's base order; contact-method `hours`
    still apply);
  - the effective target after following `cover`. The chain depth is
    capped at 3, cycles are detected, and each hop is recorded as
    "Dr X (On vacation until 12/28) → Dr Y";
  - `delivery_mode`;
  - the operator instruction.

  Operators, the AI tool, plan 01 and plan 05 all call this.
- **`StatusWriter::set(entry, type, until?, note?, cover?, source,
  actor, viaTeam, startsAt = now)`** validates:
  - `requires_until`, `max_duration_days`;
  - `until` must be in the future;
  - the cover belongs to the same owner, or to a shared directory the
    via-team subscribes to.

  It writes the period and the event in one transaction, then
  dispatches `DirectoryStatusChanged` (see Live updates). `clear()`
  cancels the current period, and `cancelScheduled()` cancels a future
  one. Concurrent writes take `SELECT … FOR UPDATE` on the entry row.
  Last writer wins, and both writes are logged.
- **`StatusTimeParser::parse(string, CarbonTimeZone, $now)`** handles
  `3PM`, `3:30p`, `1500`, `NOON`, `2H`, `90M`, `TOMORROW`, `MON`,
  `12/28`, `12/28 8AM`, `UNTIL MON 7A`. It returns an absolute instant
  plus a human echo ("Tue Nov 25, 3:00 PM").
  - Times of day that have already passed today mean tomorrow.
  - A bare date means the start of the client's business day on that
    date. Plan 06 schedules are used when they exist, otherwise 07:00
    (open question 3).
  - The zone is `Team::displayTimezone()` (`app/Models/Team.php:268`).
    Shared directories get a new `timezone` column that falls back to
    `app.timezone`.
  - Parsing is DST-safe: wall-clock time is resolved in the zone.
- **`SmsStatusCommand::handle(InboundMessage, MessagingEndpoint,
  MessageThread): ?Reply`** covers the grammar, identification and
  replies (see the SMS section).
- **`StatusSweeper`** (`orbital:directory-status-sweep`, every minute,
  the same scheduling style as `routes/console.php:83-93`) finds
  periods where `ends_at <= now()` and `expiry_processed_at is null`.
  For each one it writes an `expired` event, releases messages held for
  that entry (plan 05 `held` → re-run dispatch), sends the optional
  "back In" SMS, and broadcasts. The update is guarded by
  `where expiry_processed_at is null`, so the sweeper is idempotent.
  Correctness doesn't depend on it, because resolution is derived.
- **`StaleStatusReport`** (daily) lists `unavailable` statuses with no
  end that are older than N days, and statuses whose cover is itself
  unavailable. It goes to platform supervisors and, when the client
  enables it, to client admins.

### Operator surfaces

A new shared service, **`ClientDirectoryBrief::for(Team, string $query,
int $limit)`**, sits beside `ClientCallBrief`. It searches the client's
own entries and active shared-directory subscriptions, queried with
explicit `team_id`/pivot filters and `withoutGlobalScope('team')` as
`ClientCallBrief.php:27-30` does. It batch-resolves status and contact
plan. Both workspaces read it per render, not from public Livewire
state.

- **Single-call Workspace**: add a "Directory" section under "Call
  handling" in `workspace.blade.php` (after `:142`), fed by a new
  `Workspace::directory()` method next to `callBrief()`
  (`app/Filament/Operator/Pages/Workspace.php:141-150`).
- **Multi-call desk**: add a block to the `mcw-side` aside
  (`multi-call-workspace.blade.php:309`). It is passed from
  `MultiCallWorkspace::render()` (`app/Livewire/MultiCallWorkspace.php:277-297`),
  per selected call's team. The existing `wire:poll.15s`
  (`multi-call-workspace.blade.php:8`) keeps it fresh even without
  broadcasting.
- **Row layout**: name · title/department · **status badge** (colour
  from the type) · "until 3:00 PM (in 40 min)" in the client's zone ·
  note · cover ("→ Dr Patel") · contact order for *this* status as
  click-to-dial or copy buttons · source hint ("set by self via SMS,
  2 h ago"). Unavailable entries sort last and are dimmed. Statuses
  older than the stale threshold show a warning icon.
- **"Set status" action** (permission `directory_status.update`): a
  modal with the type, until (free text through `StatusTimeParser` with
  a live echo, or a datetime picker), the note and the cover picker.
  "Clear (back to In)" is one click. Every change is shown back on the
  row immediately.
- **Message form hint**: once plan 02/03's recipient picker exists,
  choosing an unavailable recipient shows the status inline, so the
  operator can tell the caller "Dr X is in surgery until 3".

### Admin surfaces (Filament, platform staff)

- **`ManageClientDirectoryStatuses`**: a new ClientResource sub-page
  next to `ManageClientDirectoryFields` (registered at
  `ClientResource.php:493-519`). It holds the CRUD for the status list:
  reorder, default flag, keywords with collision validation, IVR
  digits, contact order (drag list of contact kinds), delivery mode and
  instruction. New clients are seeded from a platform template
  (In · Out · With patient · In surgery · On vacation · Off duty) by
  `ClientProvisioner`.
- **`ManageClientDirectory`** gains:
  - a status badge column (always visible) and an "until" column;
  - a filter by status or availability;
  - row action "Set status", bulk action "Set status" (e.g. the whole
    practice is closed for a holiday);
  - row action "Status history", which shows the events timeline;
  - row action "Self-service" to issue or reset an ID and PIN, set
    allowed SMS numbers, and send the self link.
- **`SharedDirectoryResource`** gets the same status-list page and
  columns for shared directories.
- Per-client toggle `teams.directory_status_enabled`, off by default
  until rollout. While it is off, the status UI is hidden and
  `ContactPlan` returns the base order.

### Client portal

Portal panel pages live in `app/Filament/Portal/`:

- **"Staff status" page**: a list of the client's own entries with
  status, until and note. People with `portal.manage_directory_status`
  can set and clear status and schedule future periods (vacations).
  The status list itself stays admin-managed in v1 (open question 5).
- Shared-directory entries appear **read-only** in the portal. Client A
  must not be able to change a provider's status that client B depends
  on.
- **History tab** on each entry, from `directory_status_events`.

### Staff self-service (no login)

- **Self link.** A signed URL
  (`/s/{token}`, a 32-byte random token stored hashed, 30-day expiry,
  revocable) opens a one-entry, mobile-first page: current status, big
  buttons for each type, "until" quick picks (1 h, 2 h, end of day,
  tomorrow 8 AM, pick date), and a note. A person can request the link
  by texting `LINK` from an allowed number, or an admin can send it.
  It is rate-limited and every use is audited (`source = self_link`).
- **SMS keywords** (see below) and the **status line** (see below).

### SMS status updates

Wiring: in `ProcessInboundMessageJob::handle()`, after the entry is
persisted (`:148`) and only when consent returned `none` (`:165`), call
`SmsStatusCommand`. If it handles the message:

- it closes the thread with a `ConversationActivity` note "Status
  update applied", so it doesn't sit in an operator inbox, and returns
  before `maybeAutoReply()`;
- it sends the confirmation through a new
  `OutboundMessageService::replyAsSystem(thread, body)`, which still
  checks `OptOutRegistry`.

Running after the consent check means STOP/START/HELP always win.

**Identification.**

1. The endpoint fixes the client (`InboundMessageRouter`).
2. The sender is matched against `directory_status_credentials.sms_numbers`,
   then against the phone and text fields of entries owned by that
   client or by a shared directory it subscribes to. Matching uses
   `MessagingEndpoint::addressVariants`-style normalisation.
3. No match: the command doesn't handle the message, and it flows on as
   a normal conversation. Strangers never get a hint that a status
   system exists.
4. Several matches (spouse listings, a shared office cell): reply with
   "Reply 1 for Dr Ender, 2 for Sharon Ender", and keep a pending
   choice for 10 minutes keyed on the thread.
5. `sms_requires_pin`: the last token must be the PIN, otherwise the
   message is rejected and a `rejected` event is written.

**Grammar** (case-insensitive; the first token must be a keyword of
the matched owner's status types or a command word):

```
<KEYWORD> [<time-expr>] [NOTE <text> | <free text>]
IN | BACK                          → clear to default
STATUS | ?                         → reply with current + scheduled
VAC 12/20-12/28 [note]             → scheduled period
LINK                               → send self link
```

Examples: `OUT 3PM`, `SURG 2H covering Patel`, `VAC 12/28`,
`IN`. The reply always echoes the absolute result: "Dr Kulcsar: In
surgery until Tue 3:00 PM. Reply IN when back." If the sender didn't
own the number, the real owner sees that confirmation. A message that
starts with a keyword but has an unparseable time gets a reply with
the accepted formats, and nothing changes.

Command words (`IN`, `BACK`, `STATUS`, `LINK`) and every client
keyword are validated against `MessagingOptOut` reserved words, so
nobody can define `END` or `CANCEL` as a status. A seeder test asserts
the template list is clean.

**Platform-wide status number** (optional, phase 2): an endpoint owned
by a designated "house" client, because `messaging_endpoints.team_id`
is NOT NULL. Matching then runs across all clients, and a sender found
in several clients gets the numbered disambiguation above.

### Phone status line (IVR)

New `RoutingRule.destination_type = 'status_line'`. `team_id` may be
null for a platform line (the dispatcher already labels these
"platform", `from-trunk.blade.php:33`); with a team it is a per-client
line. A new case in the `@switch` at `from-trunk.blade.php:36` does a
`Goto(orbital-status-line,s,1)`. A static context in the same
generated file:

```
[orbital-status-line]
s,1  Answer, Set(TRIES=0)
     ; caller-ID shortcut: CURL lookup by ${CALLERID(num)} → maybe pre-fill ID
     Read(ID,orbital/status-enter-id,8,,2,6)
     Read(PIN,orbital/status-enter-pin,8,,2,6)
     Set(R=${CURL(${STATUS_API}/auth,…id,pin,callerid,did…)})   ; shared-secret header
     → on fail: TRIES++, max 3, then Playback(goodbye)
     → menu: 1 hear status · 2 change status · 3 schedule absence · 9 back to default
     change: Background(per-type prompts) → WaitExten digit = ivr_digit
             Read(HHMM / # for none / * for date entry MMDD)
     Set(R=${CURL(${STATUS_API}/set,…session,type,until…)})
     Playback(result prompt) + SayUnixTime(until)
```

- **Transport.** Dialplan `CURL()` calls new endpoints under
  `/api/telephony/status-line/*`. They are authenticated with a
  shared-secret header, the way `/api/voicemail/received` is
  (`routes/api.php:95-99`), and run with an explicit team/owner scope.
  `auth` returns a short-lived opaque session token, so the PIN never
  crosses twice. Prerequisite: add `--enable func_curl` explicitly to
  `docker/asterisk/Dockerfile` menuselect and check it with
  `module show like curl`. If func_curl is unacceptable, the fallback
  is an ARI Stasis app (`res_ari`/`res_stasis` are already enabled),
  but that needs a long-running ARI client that Orbital doesn't have
  today.
- **Prompts.** Fixed prompts are rendered once with TTS. Each status
  type's label ("In surgery") is rendered to
  `/var/spool/asterisk/prompts/status/{owner}/{type}.wav` on save, the
  same pattern as `VoicemailGreetingRenderer`
  (`app/Services/Telephony/VoicemailGreetingRenderer.php`) and
  `AiFallbackPrompt`. Times are read with `SayUnixTime` in the owner's
  zone (`STATUS_TZ` returned by the API). This avoids live TTS on a
  DTMF path.
- **Security.**
  - Lockout after 5 failed PINs per `login_id` (15 min), plus a
    per-caller-ID rate limit (Laravel `RateLimiter`).
  - Every failure writes `auth_failed`. Prompts are identical for
    "unknown ID" and "wrong PIN".
  - A lockout notifies the entry's allowed SMS number.
- **Later (plan 15 / 01):** the same flow can be rebuilt as an
  orchestration once the plan 01 engine exists, with
  `set_directory_status` as a step handler. The API endpoints stay.

### AI path

- **AI receptionist (caller asks for someone).** Plan 01's
  `db_lookup_single`/`db_picklist` handlers and the interim
  `lookup_account` replacement (`agent-worker/tools/compiled_flow.py:209`)
  return `ContactPlan` data: status label, availability, until (in the
  client's zone), note, cover name, and delivery mode. The prompt
  template for the step says what to do: "Dr X is in surgery until
  3 PM. Offer to take a message, or to reach Dr Patel who is covering."
  The AI never reads out private numbers. Contact methods are passed by
  key, not by value.
- **AI status line** (optional, after the DTMF line works): route a DID
  to an orchestration with the step `set_directory_status`. The AI
  gathers "I'm in surgery till about three". The engine still requires
  the ID and PIN by DTMF (`gather_masked`, plan 01) before calling
  `StatusWriter` with `source = ai`. The LLM never decides
  authentication.
- **Script access (plan 01).** Variables are `entry.status.key`,
  `entry.status.label`, `entry.status.until`, `entry.available`
  (bool), `entry.cover`. Handlers: `set_directory_status` (operator or
  AI scripts, e.g. "Doctor calls in to sign out") and a branch on
  availability through the ordinary `branch_if`. Expression function:
  `status("Dr Smith")`.

### Dispatch and on-call (04, 05)

Plan 04 and plan 05 get hooks here. Their designs don't change.

- **On-call (04).** `OnCallResolver::who()` gains
  `respectStatus: true` (default). A shift or override whose person has
  `availability = unavailable` at *T* is marked skipped with a reason
  ("Dr Shukla: On vacation until 12/2"). The next position fills the
  role, or the status's cover if one is set and the group allows
  covers. The result keeps the skipped people so operators see *why*.
  `limited` does not skip; it only reorders methods through
  `ContactPlan`.
- **Dispatch (05).** Recipient expansion for a tier calls
  `ContactPlan::for(recipient, now, urgency)`:
  - `normal`: use the status's method order (overriding the base
    order);
  - `cover`: dispatch to the cover, and log the redirect on the
    attempt;
  - `hold`: the dispatch goes `held` with `release_at = until`. The
    sweeper releases it early if status changes. Urgent messages never
    hold; they escalate to the next tier instead (policy flag);
  - `operator`: go straight to the dispatcher queue with the
    instruction.

  Status is re-evaluated **per tier**, not only once per message, so a
  doctor who comes out of surgery mid-escalation is reached on the "In"
  order. `delivery_attempts` gets a `status_snapshot` JSON column (type,
  until, period id), so "why wasn't I paged?" can be answered from the
  record.
- **Plan 12 check-in.** Holding for check-in and holding for status are
  the same `held` mechanism. They release on different triggers.

### Live updates

`DirectoryStatusChanged` is broadcast on a private per-owner channel
(`ShouldBroadcastNow`, like `app/Events/InboundMailFailed.php:17`).
Operator components listen and re-render the directory block. The
15-second poll remains the fallback.

### Permissions and tenant isolation

- New catalogue entries (`PermissionCatalogSeeder::CATALOG`):
  `directory_status.view`, `directory_status.update` (operators),
  `directory_status.manage_types` and
  `directory_status.manage_credentials` (admins), and
  `portal.view_directory_status`, `portal.manage_directory_status`.
- Add both portal permissions to
  `ClientProvisioner::DEFAULT_TENANT_ALLOW_LIST`, and only the view
  permission to `DEFAULT_TENANT_USER_PERMISSIONS`. Add copy in
  `RoleResource::allowedPermissionDescriptions()`.
- Every service takes the owner explicitly and filters by
  `team_id`/`shared_directory_id`. Each one is tested with no
  authenticated user, because the global scope is a no-op there.
- Cover, contact-order override and credential rows must have the same
  owner as their entry. An entry owned by a shared directory may be
  used as a cover only by a client subscribed to it (`is_active` pivot).
  This is validated in `StatusWriter` and in a model `saving` hook.
- Channels are scoped by the channel's owner:
  - SMS on client A's endpoint can only change A's entries and shared
    entries A subscribes to.
  - A per-client status line works the same way.
  - The platform line authenticates by `login_id`, which identifies
    exactly one entry.
- Portal users can never write to shared entries.

### Audit

Every write goes through `StatusWriter` and produces exactly one
`directory_status_events` row in the same transaction. The row
records:

- the actor: user id or entry id for self-service, or `system` for
  expiry;
- the source, `via_team_id`, the caller ID or sender address, the IP
  for self-link and portal changes, and before/after.

Events are visible in admin (per entry and per client), in the portal
(own entries only), and on the operator row tooltip ("set by self via
SMS 14:02"). Dispatch attempts (05) and script events (01) refer to the
period id, so a message's timeline can show "Dr X was In surgery
(set 13:10 by self via SMS)".

## Edge cases

- **Scheduled vacation plus a same-day change**: "OUT 3PM" while a
  vacation starts tomorrow. Only open-ended or overlapping *current*
  periods are cancelled. The future period stays. `STATUS` replies list
  both.
- **Overlapping scheduled periods**: the latest `starts_at` wins during
  the overlap. The UI warns before saving.
- **Until in the past, or "3PM" sent at 3:05 PM**: tomorrow 3 PM,
  echoed explicitly. An explicit date in the past is rejected.
- **DST**: "until 2:30 AM" on spring-forward night resolves to 3:30 AM
  local and is echoed. Durations ("2H") are absolute.
- **Shared provider status**: one status across all clients. The
  instruction text is the shared directory's. A client that needs
  different handling for that provider uses its own on-call cover rules
  (04), not a per-client status (open question 2).
- **Entry deleted or soft-deleted with an active status**: the
  resolver ignores deleted entries. Periods remain for audit. Restoring
  the entry restores the status if it is still in range.
- **Status type deactivated while in use**: current periods keep
  showing it (greyed). New sets can't choose it. A type can't be
  deleted while referenced.
- **Cover is also unavailable**: follow the chain to depth 3, stop on a
  cycle, and if the chain ends with nobody reachable, go to the
  dispatcher queue with the full chain shown.
- **Default type missing** (misconfigured list): treat as `available`
  with label "Available" and flag it in `orbital:status` output.
- **SMS from a number shared by several entries**: numbered
  disambiguation. If unanswered after 10 minutes, nothing changes.
- **SMS from an opted-out number**: the status still applies (they
  wrote to us), but no confirmation is sent, because
  `OptOutRegistry` refuses it. The event notes "confirmation
  suppressed".
- **Keyword collision with consent words**: blocked at configuration
  time. Consent runs first in any case.
- **Long or multi-part SMS, MMS with a photo**: only the text body is
  parsed. A message over 320 characters is treated as a conversation.
- **IVR caller hangs up mid-flow**: nothing is written unless the
  final `set` call completed. Session tokens expire after 5 minutes.
- **Brute-force on the status line**: per-ID lockout plus a per-caller
  limit, alerting after N lockouts per hour.
- **Status changed while a message is mid-escalation**: the next tier
  re-resolves. An attempt already sent isn't recalled.
- **Clock skew between Asterisk and app**: all time maths happens in
  Laravel. Asterisk only reads back the returned epoch.
- **Re-import (`--refresh`)**: statuses live outside `values`, so they
  are untouched.
- **Operator and self-update racing**: row lock, last write wins, both
  audited. The operator sees a toast if the row changed under the open
  modal (compare `period_id` on submit).

## Migration and rollout

1. **Schema + resolver + parser + admin UI** behind
   `teams.directory_status_enabled` (default false). Seed the template
   status list for every existing client and shared directory in a
   data migration. Seeding is idempotent and keyed on `key`.
2. **Operator directory panel** (both workspaces). This is useful
   without status (it closes TODO.md:529's operator half) and shows
   status where it is enabled.
3. **Amtelco status import** (`orbital:import-amtelco-directory-status
   --dry-run`):
   - "AWAY TIL m/d" and "ON LEAVE" in raw or procedure text become a
     period on the matched entry. A date in the past is dropped and
     reported. Unparseable text is reported, not guessed.
   - "NOT O/C" is **not** a person status. It is a per-number
     restriction and belongs to plan 04's contact method (`hours` or a
     `not_for_on_call` flag).
   - `seasonal` "VACATION HME" numbers become the "On vacation"
     contact order for that entry.
   - Prerequisite fix, which can ship on its own: make
     `DirectoryListingMapper::placeNumber()` keep any non-numeric
     prefix of `raw` (e.g. "NOT O/C", "AWAY TIL 11/13") in the notes,
     so it stops vanishing. Then re-run with the TODO.md:493 safeguards.
4. **SMS keywords** per client endpoint. Then **self link** and
   **portal page**.
5. **Status line** (dialplan + func_curl + prompts) on one platform
   DID, piloted with one medical client.
6. **On-call and dispatch hooks**, as soon as 04/05 land. Until then,
   `ContactPlan` is already what the operator panel shows, so behaviour
   is consistent from the start.

Back-out: turning the flag off hides the UI and makes `ContactPlan`
ignore status. The data is kept.

## Tests

Pest. The suite runs on in-memory SQLite (`phpunit.xml:26-27`), so
anything that depends on the pgsql CHECK constraint or on timestamptz
also needs a pgsql-tagged test.

**Resolver and parser (unit, frozen clock)**

- No periods → default type. A current period → that type. Expired →
  default with no job run. A future scheduled period → not active until
  `starts_at`.
- Latest `starts_at` wins in an overlap. A cancelled period is ignored.
- Parser table: `3PM` before and after 15:00, `1500`, `NOON`, `2H`,
  `90M`, `TOMORROW`, `MON` on a Monday (→ next Monday), `12/28` (→
  business-day start), `12/28 8AM`, `VAC 12/20-12/28`, garbage →
  error. Spring-forward and fall-back nights in `America/New_York`. A
  client in `America/Los_Angeles` vs the app zone.
- `max_duration_days` and `requires_until` enforcement.

**ContactPlan**

- An entry override beats the type order, which beats the plan 04 base
  order. Method `hours` are still honoured.
- Cover chain: A→B works. A→B→C with B unavailable. A→B→A cycle stops.
  Depth over 3 → dispatcher queue.
- `hold`/`operator` modes for normal vs urgent.

**Tenant isolation** (each run both with and without an auth user)

- Client A's resolver, operator panel and portal never return client
  B's entries, types or periods.
- A shared entry is visible to every subscribed client. It is not
  visible once the pivot has `is_active = false`.
- A portal user of A cannot set status on a shared entry (403), or on
  B's entry by id (404).
- A cover entry from an unsubscribed shared directory is rejected.
- An SMS on A's endpoint from a number that exists only in B's
  directory → not handled, becomes a normal conversation.

**SMS** (`ProcessInboundMessageJob` with a fake provider)

- `OUT 3PM` from a matched cell → a period is created, the confirmation
  echoes the absolute time, the thread is closed, the AI auto-reply is
  not dispatched.
- `STOP` from a staff number → opt-out recorded, no status change.
- Keyword `END` rejected on the status type form.
- Two entries share the number → disambiguation, `2` picks the second,
  a reply after 10 minutes is ignored.
- `sms_requires_pin`: missing or wrong PIN → `rejected` event, no
  change.
- A duplicate webhook (same provider id) doesn't create a second period.
- `STATUS` reply lists current and scheduled periods. `LINK` sends a
  self link once per rate window.

**Self link and portal**

- Token works once per session and expires. A revoked token → 410.
  Every action is audited with `source = self_link` and the IP.
- A portal user with view only sees the list without actions. With
  manage, they can schedule a vacation and see it in history.

**Status line API** (HTTP tests against `/api/telephony/status-line/*`)

- Wrong secret → 401.
- Correct ID and PIN → a session token. Five wrong PINs → locked, the
  same prompt code as unknown ID, and the lockout SMS is sent.
- `set` with digit and HHMM → a period. The epoch and zone are returned
  for `SayUnixTime`.
- An expired session token is rejected.

**Dialplan snapshots**

- A `status_line` rule renders the `Goto` and the context. The
  per-type prompt paths exist after a type is saved.

**Sweeper**

- A period expiring at 14:00, sweep at 14:01: one `expired` event, a
  held message is released, the "back In" SMS is sent if configured.
  Running the sweep twice does nothing more.
- A crash between the event and release → the next sweep completes.

**Operator UI (Livewire)**

- The Workspace directory section shows the badge, until in the
  client's zone, and the cover. "Set status" writes a period and an
  event with the operator as actor.
- On the multi-call desk, two calls for different clients each show
  only their own directory.
- A stale period changed by SMS while the modal is open → conflict
  toast.

**On-call and dispatch** (when 04/05 exist; scenario tests with
`travel()`)

- The on-call primary is on vacation → the backup is resolved and the
  skip reason is shown.
- An urgent message to an "In surgery" doctor (hold for routine,
  escalate for urgent) → goes to tier 2. A routine one → held, then
  released when the doctor texts `IN`.
- A status change mid-escalation → the next tier uses the new order.
- `delivery_attempts.status_snapshot` is recorded.

**Answering-service scenario fixtures** (shared with README testing
strategy)

- "After-hours call for Dr X; Dr X texted `VAC 12/28` yesterday" →
  the script branch, the message and the dispatch all go to the cover.
  The AI and the operator runner produce the same path.

**Import**

- The Fisher listing (`AWAY TIL 11/13`) on a frozen date before 11/13
  → a period until 11/13. After 11/13 → reported as dropped.
- "NOT O/C" → no person status, kept on the number.

## Open questions

1. Does NCI use statuses in Amtelco today (the IS Directory Status
   feature), or only the free-text annotations seen in the export? If
   they do, can we get the status lists per account, which the export
   lacks?
2. Shared providers: one global status (proposed), or should a client
   be able to keep its own view (e.g. "covering for us only")?
3. What does a bare return date mean? Start of the business day
   (proposed, 07:00 without a plan 06 schedule), midnight, or ask?
4. Platform-wide status line and number, or one per client? A single
   number needs a "house" client for the SMS endpoint.
5. May client admins edit their own status *list* in the portal, or
   only set statuses? (v1: only set.)
6. PIN policy: 4 or 6 digits; does `sms_requires_pin` default on for
   medical clients?
7. Should an "unavailable" status ever block a direct patch (plan 08),
   or only warn the operator?
8. Retention of `directory_status_events` and `auth_failed` rows: the
   same as call logs, or shorter?

## Size estimate

| Piece | Effort |
|---|---|
| Schema, resolver, parser, writer, sweeper, audit | 1 wk |
| Admin pages (status list, directory columns/actions, history, credentials) | 0.5–1 wk |
| Operator directory panel in both workspaces + set-status modal + broadcast | 1 wk |
| SMS keywords (job hook, identification, disambiguation, system replies) | 0.5–1 wk |
| Self link + portal page | 0.5–1 wk |
| Status line: API, dialplan, func_curl, prompt rendering, lockout | 1 wk |
| Amtelco status import (+ mapper raw-prefix fix) | 0.5 wk |
| On-call / dispatch hooks (once 04/05 exist) | 0.5 wk |

**Total ≈ 5.5–7 weeks.** The first useful slice is the schema, admin
UI and operator panel: about 2.5 weeks.
