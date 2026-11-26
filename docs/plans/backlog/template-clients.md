# Plan: Template clients and the softphone phone book

**Status:** proposed · part of [Backlog plans](README.md) · the virtual-assistant template depends on [08](../is-parity/08-call-patching-and-conferencing.md) (patching) and [11](../is-parity/11-directory-status.md) (directory panel and lookups); the after-hours half of AI overflow depends on [06](../is-parity/06-schedules-and-routing.md)
**Written:** 2026-11-26

Covers three backlog items:

- `TODO.md:695-717` "Template tenants for common answering-service patterns"
- `docs/roadmap.md:124-128` "Template clients on production installs"
- `TODO.md:718-723` "Phone book / directory in the softphone widget"

## Problem

Every new client starts empty. Platform staff build the queue, the
DID destination, the persona and the orchestration by hand, following
the ten-step list in `docs/admin/clients.md` ("Setting up a new
client"). Three worked examples exist, but only on local installs,
and they are built as fake tenants with fake numbers, so they can be
read but not reused. Production has nothing to start from.

The TODO lists five templates. Three exist as seeded tenants, one
(virtual assistant) needs primitives that are planned elsewhere, and
the fifth ("whatever we have today") was never codified.

The virtual-assistant template also needs a human operator to reach
the client's directory from the phone, which the softphone can't do.

## What exists today (checked in code)

### The three seeded template tenants

`database/seeders/TemplateClientSeeder.php` creates them. It runs
only when `app()->environment('local')`
(`database/seeders/DatabaseSeeder.php:39-48`), after
`DemoClientSeeder`, because it reuses that seeder's `all-operators`
agent group and `Platform Shared Trunk` (`TemplateClientSeeder.php:49-56`).

| Template | Team name, account | What it builds |
|---|---|---|
| Voicemail only | `Template: Voicemail Only`, 900001 | DID `+15550000001` and a `RoutingRule` with `destination_type = voicemail`, mailbox = account number (`:75-110`) |
| Live operators | `Template: Live Operators`, 900002 | `CallQueue` with the operator group and no overflow persona, and a rule to it (`:117-156`) |
| Live + AI overflow | `Template: Live + AI Overflow`, 900003 | `AgentPersona` "Overflow Ava", an `ai_agent` extension, an `IntakeFlow` (name, phone, reason, `save_message`), and a queue with `overflow_agent_persona_id` set (`:165-265`) |

Problems with the seeder as a basis for production:

- **Fake tenants.** Each one is a `Team` with a contact `User` whose
  password is `password` (`:274-281`) and a fake `+1555` DID. Seeding
  that on a live install creates a loginable account and three
  "clients" that show up in every client list, report and bill.
- **Hard dependency on demo data.** Without `DemoClientSeeder` it warns
  and exits (`:50-54`).
- **Writes routing rules directly.** It creates `RoutingRule` rows
  with no `client_did_id`. The current path is the DID's own
  destination: `ClientDid` has `destination_type`/`destination_id`
  (`app/Models/ClientDid.php:23-32`) and saving it calls
  `ClientDidRouting::sync()` (`ClientDid.php:36`,
  `app/Services/Telephony/ClientDidRouting.php:46`), which owns the
  rule. The seeder's rules are orphans that the Numbers page doesn't
  know about.
- **Hard-coded persona.** "Overflow Ava" carries its own prompt, voice
  and `llm_model = claude-sonnet-4-20250514` (`:183-206`) instead of
  linking to a platform persona template (`AgentPersona.template_id`,
  resolved by `app/Services/Clients/TemplateResolver.php`).
- **Stale comment.** The voicemail docblock (`:68-74`) says the
  mailbox must be added to `voicemail.conf` by hand. The generator now
  exists (`resources/views/asterisk/voicemail.blade.php`, one mailbox
  per client with a voicemail rule).
- **"(Existing)" template.** The richest working configuration is the
  Demo Customer (`database/seeders/DemoClientSeeder.php`): FAQ
  knowledge store, seven client slots, a greet-and-route flow branching
  to maintenance, leasing and general message flows (`:348-439`). It
  is a demo, not a template.

### What is composable today

| Primitive | Where | State |
|---|---|---|
| Client provisioning | `ClientProvisioner::provision()` (`app/Services/Clients/ClientProvisioner.php:89-105`) | Idempotent. Roles, allow-list, default directory fields, a General Inbox email queue and catch-all rule, and the Default orchestration with channel triggers. Called by `CreateClient` (`app/Filament/Resources/ClientResource/Pages/CreateClient.php:35`) |
| DID destination | `ClientDid` + `ClientDidRouting` | `extension`, `queue`, `voicemail`, `orchestration` (`ClientDidRouting.php:19`) |
| Call queue + overflow | `CallQueue`, `client-dialplan.blade.php:49-59` | `Queue()` then, if `overflowAgent` is set, `Dial` to the persona's extension. Without overflow the call hangs up when the queue times out |
| Voicemail | `from-trunk.blade.php:54`, `voicemail.blade.php` | Greeting (TTS or custom), transcription, email to the **client owner** only (`app/Jobs/TranscribeVoicemailJob.php:148-159`) |
| Shared orchestration templates | `IntakeHandoffTemplateSeeder`, `IntakeMessageTemplateSeeder` (base `AiIntakeTemplateSeeder`) | Seeded on **every** install (`DatabaseSeeder.php:17-18`). Applied per client by Orchestrations → "Apply to client" (`app/Filament/Resources/OrchestrationResource.php:168-200`) through `ApplyOrchestrationTemplate::apply()` (`app/Services/Flows/ApplyOrchestrationTemplate.php:35-52`), which copies the flows into the client's Default orchestration, wires Inbound Phone and points a persona at the entry flow |
| Orchestration clone | `Orchestration::duplicate(?Team)` (`app/Models/Orchestration.php:177`), `copyFlowsInto()` (`:272`) | Deep copy with bindings |
| Persona templates | `PersonalityTemplateSeeder` (platform personas, `team_id` and `template_id` null), `TemplateResolver` | Client personas link with `template_id` and store only overrides |
| Queue strategy templates | `QueueStrategyTemplateSeeder` | Seeded on every install |
| Directory | `DirectoryEntry` (`app/Models/DirectoryEntry.php`), `SharedDirectory`, `DirectoryFieldDefinition` | The "tenant-scoped directory model" the TODO asks for already exists. Entries belong to one client or one shared directory and never log in (`DirectoryEntry.php:13-23`) |
| Transfer | `transfer_call` goal (`database/seeders/IntakeGoalLibrarySeeder.php:770-786`), `transfer_to_extension` (`:1228-1242`) | Destination types `extension`, `did`, `queue`, `external`. Only `external` has a server-chosen tool (`transfer_to_operator`, `app/Services/Flows/AgentFlowCompiler.php:914-925`, numbers whitelisted by `app/Services/Telephony/HandoffDestinations.php`). Nothing targets a directory entry |
| Time of day | `RoutingRule.time_condition`, `OperatingHour` | Stored, never evaluated (plan 06) |

### The softphone

- `app/Livewire/Softphone.php` only resolves SIP credentials
  (`:12-42`). It knows nothing about the client the operator is
  working on.
- The widget (`resources/views/components/softphone.blade.php`) has a
  dial input, a keypad and a transfer mode with a free-text target
  (`:409-425`, `:547-550`). Transfer is a blind REFER
  (`resources/js/sip-phone.js:292-300`).
- The softphone dials into `[internal]`
  (`resources/views/asterisk/extensions.blade.php:17-70`), which has
  feature codes, AI and internal extensions and, when
  `internal_did_simulation` is on, a bounce into `from-trunk`. There
  is **no outbound route**, so an operator can't dial or blind-transfer
  to an outside number at all (also recorded in plan 08, "Bugs
  noticed").
- The client in focus lives in the pages, not the phone:
  `Workspace::$activeTeamId` (`app/Filament/Operator/Pages/Workspace.php:50`,
  set by `fetchAccount()` `:94`, cleared by `releaseAccount()` `:294`)
  and, on the multi-call desk, each `OperatorCall.team_id`
  (`app/Services/Operator/MultiCallDesk.php:152`).
- No operator surface reads `DirectoryEntry` (plan 11, "What Orbital
  has today").

## Goal

1. A platform operator creating a client on **any** install can pick
   "Start from: Voicemail only / Live operators / Live + AI overflow /
   Message-taking receptionist / Virtual assistant" and get a working
   configuration, needing only a DID and a few answers.
2. No fake tenants, no fake numbers, no seeded logins on production.
3. Local installs keep their showroom tenants, built by the same code.
4. The virtual-assistant template works with an AI or a human fronting
   the call, using the same directory and the same transfer targets.
5. An operator can search the client's directory from the softphone
   and transfer or call a listed contact without changing page.

## Design

### Blueprints, not template tenants

A template is **code**, not a row to clone. Cloning a live tenant
drags along drift, test DIDs, users and whatever someone edited last
week. A blueprint is a small class that knows how to build one
pattern into a freshly provisioned client using the services that
already exist.

```
app/Services/Clients/Blueprints/
  ClientBlueprint.php          interface: key(), label(), description(),
                               version(), inputs(): array (Filament schema),
                               requires(): array (feature checks), build(BlueprintContext)
  BlueprintContext.php         team, validated inputs, created-record log
  BlueprintApplier.php         transaction, idempotency, stamping, resync
  VoicemailOnlyBlueprint.php
  LiveOperatorsBlueprint.php
  LiveWithAiOverflowBlueprint.php
  MessageReceptionistBlueprint.php    (the "(Existing)" fifth template)
  VirtualAssistantBlueprint.php       (phase 3)
```

Blueprints are registered in a config array (`config/orbital.php`
`client_blueprints`) so an install can drop or add one without a code
change elsewhere.

**Inputs** (shown on the create form, all optional unless stated):

| Input | Used by | Notes |
|---|---|---|
| DID(s) + trunk | all | If given, the `ClientDid` is created with its destination set. If not, the blueprint records its main destination and the Numbers page preselects it when a DID is added later |
| Agent group | live, overflow, receptionist, VA | Default: the install's default operator group (new platform setting, falling back to the only group if there is one) |
| Persona template | overflow, receptionist, VA | Picker over platform personas (`team_id` null, `template_id` null). The client persona links to it with `template_id`, so no prompt or model is hard-coded |
| Voicemail recipient | voicemail | Defaults to the owner. See "Gaps" for non-owner recipients |
| Greeting text | all | Written to `teams.greeting` and the persona greeting |

**What each blueprint builds** (all names deterministic, so a re-run
finds them):

| Blueprint | Builds |
|---|---|
| Voicemail only | DID destination `voicemail`, mailbox = account number. Greeting mode from input |
| Live operators | `CallQueue` "Main" with the agent group, no overflow. DID → queue |
| Live + AI overflow | Client persona linked to the chosen template, `ai_agent` extension (`9{account}` as today), `ApplyOrchestrationTemplate::apply()` with the shared **"AI Intake → Take a Message"** orchestration (so the flow is the maintained shared one, not a private copy of three steps), queue with `overflow_agent_persona_id`. DID → queue |
| Message-taking receptionist | The Demo Customer pattern without the demo data: Live + AI overflow, plus client slots (name, callback, reason) and an optional knowledge store stub. This is the TODO's "(Existing)" template |
| Virtual assistant | See below |

The `TemplateClientSeeder` becomes a thin loop: for each blueprint,
create (or find) a local showroom team and call the applier. The fake
contact user and `+1555` DIDs stay local-only. `DemoClientSeeder` is
unchanged.

### The applier

`BlueprintApplier::apply(Team $team, ClientBlueprint $bp, array $inputs, User $by)`:

1. Refuses if the team already carries a **different** blueprint
   (`teams.blueprint_key` set and not equal).
2. Refuses if the team is "not empty": it has any `CallQueue`, any
   `ClientDid` with a destination, or any persona. The provisioner's
   own defaults (General Inbox, Default orchestration, directory
   fields) don't count. Override with an explicit "apply anyway" that
   only adds missing pieces.
3. Runs `build()` in one transaction. Every write is `firstOrCreate`
   on `(team_id, deterministic name)`. Existing rows are never
   overwritten, so re-applying the same blueprint after a partial
   failure fills gaps without undoing edits.
4. Stamps `teams.blueprint_key`, `blueprint_version`,
   `blueprint_applied_at`, `blueprint_applied_by` (new nullable
   columns), and writes a `BlueprintContext` log of created record
   ids into `teams.blueprint_manifest` (jsonb) so the client page can
   show "built from Live + AI overflow v1: queue Main, persona Ava,
   extension 9100123".
5. After commit: `TelephonyObserver` already queues a config
   regenerate for `RoutingRule`, `CallQueue` and `Extension` saves
   (`app/Providers/AppServiceProvider.php:90-93`). The applier also
   runs the realtime resync the seeder runs today
   (`orbital:resync-realtime`, `TemplateClientSeeder.php:64`) as a
   queued job so queue members land.

Versions: a blueprint bumps `version()` when what it builds changes.
Existing clients are **not** migrated. The stamp only tells staff what
a client started from.

### UI

- **Create client** (`CreateClient`): a "Start from" radio list above
  the form (Blank is the default), each with its description. The
  chosen blueprint's `inputs()` appear as a section.
  `handleRecordCreation()` calls the applier after
  `ClientProvisioner::provision()` (`CreateClient.php:35`).
  Failure after the team is created leaves a provisioned blank client
  and a notification with the reason; the action can be retried from
  the edit page.
- **Edit client**: header action "Apply a template", visible when the
  client is empty (rule 2 above) or already carries that blueprint.
- **Client Details tab**: read-only "Built from" line from the stamp
  and manifest.
- `docs/admin/clients.md` "Template tenants" (`:83-95`) is rewritten:
  templates are offered on create, on every install. The roadmap entry
  is removed.

### Gaps the templates surface

Each gap is either filled here (small) or pointed at the plan that
owns it.

| Gap | Template | Owner |
|---|---|---|
| Voicemail email goes to the owner only (`TranscribeVoicemailJob.php:148`). Clients want "email the office manager" | Voicemail | **This plan**: `teams.voicemail_recipients` (array of emails, default empty = owner). Small. Plan 05 replaces it with real delivery later |
| Queue timeout with no overflow hangs up (`client-dialplan.blade.php:58`) | Live operators | **This plan**: `call_queues.timeout_destination` (`hangup`, default, or `voicemail`). One dialplan line. The blueprint sets `voicemail` |
| "AI after hours" | Live + AI overflow | Plan 06. Until then the blueprint only does overflow-when-busy and says so in its description |
| Find a person by name, check availability | Virtual assistant | Plan 11 (`ClientDirectoryBrief`, `ContactPlan`, AI path) and plan 01 (`db_lookup_single` over the directory) |
| Transfer to a directory contact (outside number) | Virtual assistant | Plan 08 (`patch_to_contact`, `PatchTargetResolver`, `[orbital-patch]` codes) |
| Same workflow for AI and human | Virtual assistant | Plan 08 operator flows + plan 02 runner; the phone book below for the human half |

### Virtual assistant

The TODO assumed this needs "a tenant-scoped directory model and a
`transfer-to-contact` agent action". The directory exists. The
transfer is planned in plan 08 as `patch_to_contact` with
`target = directory_entry`, and plan 11 gives the AI a lookup that
returns `ContactPlan` data by key, never raw numbers. This plan does
**not** add a separate `transfer_to_contact` goal. A second transfer
primitive would duplicate 08's target resolution and tenant checks.

The blueprint, once 08 phase 3a and 11 phase 2 have shipped, builds:

- Client slots `caller_name`, `callback_number`, `reason`,
  `requested_person` (entry id).
- One flow on Inbound Phone: greet → look up `requested_person`
  against the directory (11's AI lookup) → branch on availability →
  `patch_to_contact` (screened, `try = primary_then_backups`) → on
  `connected` end, on any failure exit `save_message` addressed to
  that entry.
- A persona from the chosen template, and a queue with the agent
  group in front if the client wants humans first (input: "Who
  answers first: operators / AI").
- Directory fields already exist from provisioning
  (`ClientProvisioner.php:195-213`). The blueprint marks
  `primary_phone` patchable once 08's `patchable` flag exists.

The human-fronted variant is the same flow run by plan 02's operator
runner, plus the phone book. The test that proves "same workflow" is
one scripted call, run once with the AI and once with an operator,
asserting the same patch outcome and the same message.

Until 08 ships, the blueprint is hidden (`requires()` returns false
and the radio shows "needs call patching").

### Softphone phone book

A **Directory** tab in the expanded softphone card, next to the
keypad.

**Context.** The softphone needs to know which client is in focus.

- `Workspace::fetchAccount()` and `releaseAccount()` dispatch a
  browser event `orbital-account` with `{teamId}` / `{teamId: null}`.
  The multi-call workspace dispatches the same event when the selected
  call changes or `attachClient()` succeeds.
- `Softphone` gains `public ?int $teamId` set by an `#[On]` listener.
  It's a hint, not a grant: every read re-checks that the user holds a
  platform role (operators work for every client) and that the team
  exists and is not a personal team.
- On an incoming call, the `X-Orbital-DID` header already read by
  `sip-phone.js:120-126` resolves the team through `ClientDid` when no
  page has set one. That covers an operator who answers from a page
  other than the Workspace.

**Data.** The tab calls plan 11's `ClientDirectoryBrief::for($team,
$query, 20)`. That service searches the client's own entries plus
subscribed shared directories, with explicit team and pivot filters.
The phone book is a third consumer of it, next to the two workspace
panels plan 11 adds. If this ships before plan 11, this plan builds
`ClientDirectoryBrief` without the status columns, to 11's
signature, and 11 extends it. It is not built twice.

**Rows.** Name, title/department, organization, status badge when 11
is on, then each number with a role of `phone` from
`DirectoryFieldDefinition`, plus the client's own extensions.

**Actions per number.**

| Action | Today | After plan 08 |
|---|---|---|
| Copy | yes | yes |
| Transfer (blind) | extensions only. An outside number has no route from `[internal]` | via 08: the click asks `PatchTargetResolver` for a one-time code and REFERs to it, so the browser never sends the number |
| Patch (attended) | hidden | opens 08's Patch panel pre-filled with the entry |
| Call (outbound) | hidden | via the same code path |

The free-text transfer field stays for extensions. Raw outside
numbers stay behind 08's `softphone.patch_unlisted`.

**Permissions.** The tab needs `softphone.use`. Transfer needs
`softphone.transfer`, which is seeded and granted but never checked
today (plan 08, "Permissions exist but are never checked"). This plan
starts checking it for the phone book's buttons and on the server.

**UI.** Search box with 250 ms debounce, keyboard: `/` focuses
search, arrows move, `Enter` transfers on the selected row's first
number, `Esc` closes. The card widens from `18rem` to `24rem` while
the tab is open. Results come from a Livewire method, not public
state, so a long directory doesn't serialize into every request.

## Edge cases

- **Account number collision.** Blueprints never pick account numbers.
  The create form already requires one.
- **Shared orchestration missing** (an install that deleted "AI
  Intake → Take a Message"). `requires()` fails and the radio says why.
  The applier never creates shared rows.
- **No agent group on the install.** Live blueprints require one. The
  input shows "create an agent group first" with a link.
- **Persona template deleted later.** `template_id` is
  `nullOnDelete` (`database/migrations/2026_04_11_100002_create_agent_personas_table.php:21`),
  and `TemplateResolver` then falls back to the instance's own
  columns. So the applier copies the template's values into the
  client persona's own columns as well as setting `template_id`.
  A deleted template then leaves a working persona rather than an
  empty one.
- **Re-apply after edits.** `firstOrCreate` by name means a renamed
  queue gets a second "Main" queue. The "apply anyway" path lists
  what it will create before it runs.
- **Template applied, DID added later.** The Numbers page reads the
  manifest's main destination as the default. It doesn't force it.
- **Phone book with no client in focus.** The tab shows "No account
  loaded" and the client search, the same as `Workspace::searchClients()`.
- **Two workspace tabs, one phone.** The last `orbital-account` event
  wins. The tab header always names the client it is showing.
- **Shared directory entries** appear for every subscribed client.
  Transfer resolution goes through 08's resolver, which checks the
  entry belongs to the call's client or a directory it subscribes to.

## Migration and rollout

1. Migration: `teams.blueprint_key`, `blueprint_version`,
   `blueprint_applied_at`, `blueprint_applied_by`,
   `blueprint_manifest`, `voicemail_recipients`;
   `call_queues.timeout_destination` (default `hangup`). All nullable
   or defaulted, so existing clients are unaffected.
2. Blueprints 1–4 + applier + create/edit UI. Rewrite
   `TemplateClientSeeder` on top of them. Existing local template
   tenants are left as they are; `migrate:fresh --seed` rebuilds them
   the new way.
3. Phone book tab with copy and extension transfer, using
   `ClientDirectoryBrief` (built here or by plan 11, whichever is
   first).
4. After plan 08: phone book transfer/patch/call to outside numbers.
5. After 08 and 11: Virtual assistant blueprint.

No production data migration. Nothing is seeded on production.

## Tests

- Each blueprint, applied to a freshly provisioned team: the expected
  rows exist, the DID's `RoutingRule` has `client_did_id` set, and a
  dialplan snapshot for that client matches (same harness as
  `tests/Feature/Telephony/PerTenantDialplanTest.php`).
- Applier: refuses a different blueprint; refuses a non-empty team;
  second apply of the same blueprint creates nothing; a failure
  mid-build rolls back and leaves the team blank.
- Live + AI overflow uses the shared message template:
  the client's Inbound Phone leads to a copy of its flow and the
  persona's `default_flow_id` points at it.
- Persona links to its template (`template_id` set, no hard-coded
  model).
- `timeout_destination = voicemail` emits the voicemail line after
  `Queue()`; `hangup` emits today's output exactly.
- `voicemail_recipients` set: the voicemail mail goes to them and not
  the owner; empty: owner, as today.
- `DatabaseSeeder` in a non-local environment creates no team, user
  or DID.
- Phone book: lists only the focused client's entries and its
  subscribed shared directories; a crafted `teamId` for a personal
  team returns nothing; transfer button hidden and server-rejected
  without `softphone.transfer`; an outside number has no transfer
  button before plan 08.

## Open questions

1. Is a "copy this client's setup" action also wanted (clone a real,
   tuned client into a new one), or do blueprints cover it? It would
   reuse `Orchestration::duplicate()` but has to skip DIDs, users,
   directory data and messages. Not in this plan.
2. Should the default operator group be a platform setting, or should
   blueprints always ask?
3. Virtual assistant: humans first, or AI first with humans on
   escalation? The plan leaves it as an input; NCI's practice should
   set the default.
4. Do clients want the AI to name who it's transferring to before
   the patch is accepted ("connecting you to Dr Lee")? It leaks who
   works there to any caller. Plan 11's "never read out private
   numbers" rule doesn't cover names.

## Size

- Blueprint framework, applier, four blueprints, create/edit UI,
  seeder rewrite, docs: ~1 week.
- Voicemail recipients and queue timeout destination: ~1 day.
- Phone book tab (copy + extension transfer, context events,
  `ClientDirectoryBrief` if not yet built): ~3–4 days.
- Outside-number actions after plan 08: ~1 day on top of 08.
- Virtual assistant blueprint after 08 and 11: ~2–3 days, most of it
  the AI-vs-human equivalence test.
