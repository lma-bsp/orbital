# Plan 09: Usage and billing

**Status:** proposed · part of [IS parity](README.md) · depends on — (the
first phase stands alone; dispatch metering needs 05, patch/callout legs
need 08, message counting gets better with 03; 10 reads the same roll-up)

## Problem

### What IS does

Answering services bill their clients per call, per minute of operator
(work) time, per message, and per dispatch unit (page, SMS, fax,
callout), usually against a monthly plan with included units and
overage. IS keeps per-account statistics for this, groups sub-accounts
under a **billing account number** so one invoice covers several
accounts, and produces billing reports / export files that the service
feeds into its own invoicing or accounting system. Month-end is a
report run, then an import into accounting.

### What Orbital has today (verified)

**Calls.** The raw numbers exist; nothing totals or prices them.

- `call_logs.billable_seconds` is a column
  (`database/migrations/2026_04_11_100007_create_call_logs_table.php:31`),
  fillable and cast on the model (`app/Models/CallLog.php:34`, `:54`).
  It is computed once per call by `CdrIngester::record()` as first
  answer → last end across *all* of the call's CDR rows
  (`app/Services/Telephony/CdrIngester.php:138`). `duration_seconds` is
  start → end (`:137`).
- The per-leg breakdown is kept: `metadata.legs[]` stores each CDR row's
  `app`, `data`, `dstchannel`, `disposition` and `billsec`
  (`CdrIngester.php:148-157`). This is enough to split AI time from
  operator time after the fact — nothing does.
- Queue extensions `Answer()` before `Queue()`
  (`resources/views/asterisk/extensions.blade.php:74-75`,
  `resources/views/asterisk/client-dialplan.blade.php:51-52`), so
  `billable_seconds` includes hold time in queue. Normal for answering
  services, but it must be visible as its own number.
- The only staff surface is a "Billable" column in `CallLogResource`
  (`app/Filament/Resources/CallLogResource.php:88`). `CallLogPolicy::export()`
  exists (`app/Policies/CallLogPolicy.php:56-62`) and the
  `call_log.export` permission is seeded
  (`database/seeders/PermissionCatalogSeeder.php:34`), but there is **no
  export action** anywhere (no `ExportAction`/exporter in `app/`).
- `direction` is only ever `inbound` or `internal`
  (`CdrIngester.php:131`): outbound is never recorded.
- A call gets a client only through the `orbital-cdr-inbound` tag set on
  trunk-inbound calls (`resources/views/asterisk/from-trunk.blade.php:138-147`)
  or a `tenant_N` dialplan context (`CdrIngester.php:172-187`); anything
  else is skipped. Operator callbacks, future dispatch callouts and AI
  handoffs that leave through `[from-livekit]`
  (`from-trunk.blade.php:96-118`, a fresh channel with no team tag)
  appear to produce no `call_logs` row at all — **unbillable minutes**.
  To confirm on a live handoff before relying on it.

**AI vs operator.** `call_logs.agent_persona_id` is set when an AI
session or AI dial leg is found (`CdrIngester.php:141`, `:209-215`).
`CallSessionState` (not `BelongsToTeam`, isolation by controller —
`app/Models/CallSessionState.php:10-18`) carries `created_at`,
`handed_off_at`, `ended_at`, `operator_owned`. The multi-call desk's
`OperatorCall` (uncommitted; `app/Models/OperatorCall.php`, migration
`2026_11_24_110000_create_operator_calls_table.php`) has `answered_at`,
`ended_at`, `team_id` (nullable), `is_trial`. `PlatformMetricsCollector::ai()`
counts "AI-fronted" calls as any call with a persona
(`app/Services/Metrics/PlatformMetricsCollector.php:320-345`), so a call
the AI hands to an operator counts only as AI — fine for a gauge, wrong
for billing.

**Messages.** `Message` records who took it (`agent_persona_id` vs
`created_by_user_id`), `call_log_id`, `message_thread_id`, `is_partial`
(`app/Models/Message.php:39-54`). No `dispatched`/delivery state yet
(plan 05).

**Dispatch / SMS.** `MessageEntry` records outbound SMS with `provider`,
`provider_message_id` and `delivery_status`
(`app/Models/MessageEntry.php:56-71`;
`database/migrations/2026_08_17_110000_create_messaging_tables.php:130-165`)
but **no segment count and no price**. `SendResult` carries no cost
(`app/Services/Messaging/SendResult.php:20-24`). No other dispatch
transport exists (plan 05).

**Other units.** `Voicemail.duration_seconds` (`app/Models/Voicemail.php:31-45`),
`CallRecording.size_bytes` / `duration_ms`
(`app/Models/CallRecording.php:57-69`) — storage is one of the roadmap's
"numbers that matter".

**Aggregation.** `PlatformMetricsCollector` emits rolling 24-hour gauges
(`WINDOW_HOURS`, `:33`) for Prometheus; nothing is persisted per period.
The one persisted roll-up is `queue_metrics_daily`
(`database/migrations/2026_04_15_100001_create_queue_metrics_daily_table.php`):
one row per queue per day, rebuilt by delete-then-insert
(`app/Console/Commands/RollUpQueueMetricsCommand.php:49-51`), and with
`team_id` deliberately *not* a foreign key "so historical metrics rows
survive tenant deletion for billing/audit" (migration `:163-169`). It
buckets by UTC day, not client day. It is a good pattern to copy.

**Client account.** `Team` has `account_number`, `timezone`, `tier`
(queue priority only), `suspended_at`, `max_concurrent_calls`
(`app/Models/Team.php:24-39`; migration
`2026_04_12_003953_add_quota_columns_to_teams_table.php:76-109`). There
is no billing account, rate plan, currency or period.

**Concurrent-call limit is not enforced.** `max_concurrent_calls` is
edited in `ClientResource` (`app/Filament/Resources/ClientResource.php:199-203`,
"Maximum number of simultaneous calls") and `QuotaObserver` says it "is
enforced at call-start time" (`app/Observers/QuotaObserver.php:17-18`),
but nothing reads it: no `GROUP_COUNT` in any dialplan template, no
reference in `app/` outside the model/form, the agent worker or config.

**Cascade deletes.** `call_logs.team_id` and `messages.team_id` are
`cascadeOnDelete()` (`…create_call_logs_table.php:15`,
`…create_messages_table.php:15`), as is `message_entries.team_id`.
Deleting a client today deletes the evidence for its last invoice.

**Licensing is out of bounds.** `SupportSubscription` must never gate a
product feature (`app/Services/Licensing/SupportSubscription.php:13-30`).
Rate plans here are the *operator billing its clients*; nothing in this
plan reads or writes licensing.

**Docs.** `docs/roadmap.md:108-112` names the gap ("month-end is currently
somebody reading reports by hand"). `TODO.md` has no billing items.

### Amtelco data we already hold

From `data_files_inbound/NCI_Client_Data_by_Account.xlsx`, as imported
by `orbital:import-amtelco`:

| Amtelco field | Where | What it is | Today |
|---|---|---|---|
| `Billing #` (Accounts tab; also Setup `HEADER / Billing#`) | 2,103 rows: 1,825 are `0`; 278 accounts carry one of 39 non-zero numbers; only 13 equal their own account #. E.g. `9300` → 157 WestMed accounts, `81592` → 28 BCHP accounts, `8831` → 17 Urologic Oncology | Billing parent: several accounts on one invoice | Archived raw in `client_legacy_records` (`section = account`, `AmtelcoImporter.php:190`, `archive()` `:353-358`); not mapped |
| `DID Call Limit` (Setup `MAIN`) | 67 non-zero (49 at 10; others 3–50) | Simultaneous calls per account | Archived in the `setup` section; not mapped to `max_concurrent_calls` |
| `Op Call Limit` | all `0` | — | ignore |
| `Text Msgs`, `Delivered`, `Voice Msgs`, `Played`, `Specials`, `Undelivered Text` | Accounts tab | Point-in-time message *survey* counts (messages currently on file), not period usage | Used only for junk detection (`app/Services/Import/Amtelco/AccountFilter.php:86`, `:140-144`) |

The export contains **no call detail, minutes or rates** (the README tab
lists the reports included; none is a billing or call-detail report). So
there is no usage history to import; history starts with Orbital's own
call logs. Rates and plans have to come from NCI's billing system.

## Goal

1. A **usage ledger**: every billable thing that happens for a client
   becomes an idempotent, attributable usage event, with AI, operator
   and system time split so they sum to the call's billable time.
2. **Per-client, per-period totals** in the client's timezone, rolled up
   by billing account (Amtelco `Billing #`).
3. **Period close** that freezes the numbers, and late data that becomes
   an adjustment instead of silently changing a closed period.
4. **CSV export** (detail, summary, accounting import) — enough on its
   own for services that bill in their accounting system.
5. **Optional rate plans**: base fee, included minutes/messages,
   overage, per-unit fees, rounding rules — producing statement lines.
6. Enforce `max_concurrent_calls` and record capacity rejections, since
   it is the one quota the product already promises.

Non-goals: payment collection, tax, dunning, PDF invoices (export to the
accounting system does that), Orbital licensing.

## Design

### Overview

```
CallLog / Message / MessageEntry / delivery_attempts / Voicemail / recordings
        │ (afterCommit hooks + 5-min sweeper)
        ▼
UsageCollectors ──► usage_events (immutable-ish, source_key unique)
                          │ nightly + on demand
                          ▼
                    usage_daily (team × local date × metric × handler × channel)
                          │ close
                          ▼
billing_periods ──► billing_period_lines (rated or unrated) ──► exports (CSV)
```

Source tables stay the truth. The ledger is derived and can always be
rebuilt for an open period.

### Data model

All money is integer **minor units** with a `currency` (ISO 4217) on the
billing account; unit prices are `decimal(12,5)` to allow $0.0075/SMS.
Durations are stored as **seconds**; rounding into billable minutes
happens only in rating.

**`usage_events`** — `BelongsToTeam`, but `team_id` is an indexed
`unsignedBigInteger`, **not** a cascading FK (same reasoning as
`queue_metrics_daily`).

| column | notes |
|---|---|
| `team_id`, `account_number` | snapshot of the client and its number at event time |
| `billing_account_id` | snapshot; nullable |
| `metric` | see catalogue below (string, validated by `UsageMetric` enum) |
| `handler` | `ai` / `operator` / `system` / null |
| `channel` | `voice`, `sms`, `email`, `pager_wctp`, `voice_callout`, `fax`, `portal`, `webhook`, `secure_app` / null |
| `quantity` | `bigint` — seconds, count, segments or bytes depending on metric |
| `occurred_at` | UTC |
| `local_date` | the client's date in `Team::displayTimezone()` at `occurred_at` |
| `source_type`, `source_id` | e.g. `call_log`, 812 |
| `source_key` | **unique**, e.g. `call_log:812:voice.operator_seconds`. Re-collecting upserts |
| `provider_cost_minor` | nullable; what Twilio/carrier charged us, when known. Staff-only |
| `attributes` | json: persona id, operator user id, queue id, status, urgency, segments, is_partial… |
| `billing_period_id` | set on close; non-null rows are frozen |
| `adjusts_event_id`, `reason` | for post-close corrections (see Period close) |

**Metric catalogue** (`app/Services/Billing/UsageMetric.php`):

| metric | quantity | from | handler split |
|---|---|---|---|
| `calls.offered` | 1 | CallLog (team known) | — |
| `calls.answered` | 1 | CallLog `status=completed` | first handler |
| `calls.missed` / `calls.ai_unavailable` | 1 | CallLog status | — |
| `voice.billable_seconds` | s | `call_logs.billable_seconds` | — (total) |
| `voice.ai_seconds` | s | legs `Dial …@livekit` billsec | ai |
| `voice.operator_seconds` | s | legs answered by a queue member or a human extension | operator |
| `voice.system_seconds` | s | `billable − ai − operator` (queue hold, IVR, voicemail prompt, fallback) | system |
| `voice.outbound_seconds` | s | outbound callouts / patches (plan 05, 08) | ai / operator |
| `voicemail.count` / `voicemail.seconds` | 1 / s | Voicemail | system |
| `messages.taken` | 1 | Message (complete) | ai (persona) / operator (user) |
| `messages.partial` | 1 | Message `is_partial` | ai / operator |
| `dispatch.attempt` | 1 | plan 05 `delivery_attempts` | by `channel`; attributes carry status |
| `dispatch.units` | segments / pages | plan 05 transport result | by channel |
| `sms.outbound_segments` / `sms.inbound` | n / 1 | MessageEntry (conversations) | ai / operator |
| `calls.rejected_capacity` | 1 | concurrency limit (below) | — |
| `voice.peak_concurrent` | n | daily max, gauge | — |
| `storage.recording_bytes` | bytes | snapshot at period end | — (gauge) |

Gauge metrics (`peak_concurrent`, `storage.*`) are summed with `max`,
not `sum`, in roll-ups; `UsageMetric::aggregation()` says which.

**`usage_daily`** — roll-up, `BelongsToTeam`, `team_id` not a FK:
`team_id, local_date, metric, handler, channel, quantity, events,
provider_cost_minor`; unique on the five keys. Rebuilt per (team, day)
by delete-then-insert, exactly like `RollUpQueueMetricsCommand`. Used by
dashboards, plan 10 reports and count-based rating. Per-call rounding is
rated from `usage_events`, not from here.

**`billing_accounts`** — platform-level (not team-owned; staff only):
`number` (Amtelco `Billing #`, unique nullable), `name`, `currency`,
`timezone` (period boundaries), `cycle_anchor_day` (1–28),
`accounting_ref` (customer id in QuickBooks/Xero/etc.), `bill_to_email`,
`notes`, `archived_at`. `teams.billing_account_id` nullable,
`nullOnDelete`. A client with no billing account bills on its own
(an implicit account keyed by its `account_number`), so nothing is
required before phase 3.

**`rate_plans`** + **`rate_plan_components`** — platform-level.
A plan: `name`, `currency`, `base_fee_minor`, `version`,
`supersedes_id`, `locked_at` (set the first time a closed period uses
it; locked plans are copied, never edited). A component: `metric`,
optional `handler`/`channel` filter, `unit_seconds` (e.g. 60 for
per-minute; null for count metrics), `rounding` (`per_call_up`,
`per_call_nearest`, `aggregate_up`), `increment_seconds` (6/30/60),
`minimum_per_call_seconds`, `included_quantity`, `included_pool`
(`client` or `billing_account`), `unit_price`, optional `tiers` json
(`[{up_to, unit_price}]`), `description` (export line text).

**`rate_plan_assignments`** — `billing_account_id` *or* `team_id`
(team overrides account), `rate_plan_id`, `effective_from`,
`effective_to`. No overlaps per subject (validated, plus an exclusion
constraint on `daterange`).

**`billing_periods`** — `billing_account_id` (or `team_id` for implicit
accounts), `starts_on`, `ends_on` (local dates, inclusive),
`timezone` snapshot, `status` (`open` → `ready` → `closed` →
`exported`; `reopened`), `closed_at`, `closed_by`, `rate_plan_snapshot`
json, `totals` json, `checksum` (sha256 of lines), `ingest_watermark`.

**`billing_period_lines`** — `BelongsToTeam` (lines are per client even
inside a shared account): `billing_period_id`, `team_id`, `metric`,
`handler`, `channel`, `quantity`, `billable_units`, `included_applied`,
`overage_units`, `unit_price`, `amount_minor`, `description`,
`kind` (`usage`, `base_fee`, `adjustment`, `credit`). Unrated installs
get lines with quantities and null amounts.

**`usage_adjustments`** — manual credit/debit: `team_id`,
`billing_period_id` (open period it lands in), `metric` or free text,
`quantity` and/or `amount_minor`, `reason` (required), `created_by`.

**`billing_audit_events`** — there is no general audit log (only
`FailoverAuditLog`), so a narrow one: `actor_user_id`, `action`
(`rate_plan.created/copied`, `assignment.changed`, `period.closed`,
`period.reopened`, `adjustment.created`, `export.generated`,
`export.downloaded`, `billing_account.changed`), `subject_type/id`,
`before`/`after` json, `ip`, `created_at`. Append-only (no update
route; DB trigger rejecting UPDATE/DELETE is cheap and worth it).

### Services (`app/Services/Billing/`)

- **`UsageRecorder`** — `record(UsageEventData)`: upsert by
  `source_key`. If the existing row is frozen (`billing_period_id` set)
  and the quantity differs, write an **adjustment event**
  (`source_key` suffixed `#adj:<n>`, `adjusts_event_id`, quantity =
  delta) into the current open period instead, and audit it.
- **Collectors**, one per source, each `collect(Model): array<UsageEventData>`
  and each always using the source row's own `team_id` (jobs run without
  auth, so the `BelongsToTeam` scope is off — `BelongsToTeam.php:16-21`):
  - `CallUsageCollector` — from `CallLog`: counts by status, total
    billable, and the **leg split**: for each `metadata.legs[]` with
    disposition `ANSWERED`, `app=Dial` + `data` matching `@livekit` →
    AI; `app=Queue` with a `dstchannel`, or `Dial` to a non-AI extension
    → operator (attributes carry the operator's extension/user via the
    endpoint name and `OperatorCall.sip_call_id` when present).
    `system = max(0, billable − ai − operator)`; if `ai + operator >
    billable` (overlapping legs during a consult/transfer), scale the
    overlap to the handler that ended the call and log a warning. The
    invariant `ai + operator + system = billable` is what the tests pin.
    Cross-check with `CallSessionState.handed_off_at` when present.
  - `MessageUsageCollector` — `Message` created (not updated; plan 03
    revisions never re-count). Skips messages from trial desks.
  - `MessagingUsageCollector` — `MessageEntry`; segments computed by a
    `SmsSegmentCounter` (GSM-7 vs UCS-2, 160/153 vs 70/67) until the
    provider reports `num_segments`/`price` — add both to the Twilio
    status callback handling and to `SendResult` as nullable fields.
  - `DispatchUsageCollector` — plan 05 `delivery_attempts` (one event
    per attempt, `attributes.status`; whether failed attempts bill is a
    rate-plan decision, not a ledger one).
  - `VoicemailUsageCollector`, `StorageSnapshotCollector` (period end).
- **`UsageRollup`** — rebuild `usage_daily` for a set of (team, date).
- **`UsageRater`** — pure: `(RatePlanSnapshot, events/daily rows,
  period) → lines`. Handles per-call rounding, minimum per call,
  included pools (per client or pooled across a billing account, with
  pool consumption ordered by `occurred_at` so it is deterministic),
  tiers, proration when an assignment changes mid-period (split the
  period at `effective_from`, rate each part with its own plan, prorate
  base fee by days).
- **`PeriodCloser`** — see below.
- **`UsageExporter`** — `league/csv` is already a dependency
  (`composer.json:21`). Writes to the configured disk (MinIO) under
  `billing-exports/{period}/…`, returns a download via signed temporary
  URL. Formats:
  - *Detail*: one row per usage event (call id, linked id, caller,
    DID, started, answered, ended, billable s, AI s, operator s,
    message id, channel…). This is also the missing `call_log.export`.
  - *Summary*: one row per client × metric (what most NCI staff will
    open in a spreadsheet).
  - *Accounting*: one row per statement line with `accounting_ref`,
    item code (per component, configurable), quantity, rate, amount —
    shaped for QuickBooks Online / Xero CSV invoice import. Column maps
    live in `config/billing.php`, so a new accounting target is config.
  - Optional *webhook push* of the summary JSON to an endpoint (reuse
    `ClientWebEndpoint`-style signed auth) for services with their own
    billing system — later.

### Hooks, jobs and schedule

- `CdrIngester::record()` dispatches `CollectCallUsage($log->id)`
  `afterCommit` after saving. Because ingest rebuilds a call when a late
  CDR row arrives, the collector re-runs and upserts — idempotent by
  `source_key`.
- `Message`, `MessageEntry`, `Voicemail`, plan 05 `DeliveryAttempt`:
  `created`/`updated` observers dispatch the matching collector job
  (queue `usage`, low priority, Horizon).
- `orbital:usage-sweep` every 5 minutes: collects any source row whose
  `updated_at` is past the last sweep watermark (belt and braces when a
  job is lost). `withoutOverlapping()->onOneServer()` like
  `orbital:ingest-cdrs` (`routes/console.php:92-96`).
- `orbital:usage-roll-up` nightly (after `roll-up-queue-metrics`) and on
  demand for touched days.
- `orbital:billing-close-periods` daily: moves periods whose
  `ends_on + grace` has passed to `ready`, or closes them when
  `billing.auto_close` is on.
- `orbital:usage-backfill --from= --team=` chunked, resumable.

### Period close

1. **Ready check** for the period's clients: no `asterisk_cdr` rows with
   `ingested_at IS NULL` older than `ends_on` (end of day, period tz);
   no `call_logs` in the period with `ended_at IS NULL` (stuck calls are
   listed for a human, see Edge cases); no un-run collector jobs for the
   window (sweeper watermark past period end); grace period elapsed
   (setting, default 2 days, so carrier SMS receipts land).
2. **Close** in one transaction under a Postgres advisory lock on the
   billing account: stamp `billing_period_id` on the events, rate, write
   lines, snapshot plan(s) and timezone, compute totals and checksum,
   `status=closed`, audit. Create the next `open` period.
3. **Late data** after close becomes adjustment events in the open
   period (`attributes.late_for_period_id`), shown on the next statement
   as "Adjustment for October: +3 min".
4. **Reopen** (permission `billing.reopen`, reason required): unstamps,
   deletes lines, `status=reopened`, audit. Refused once `exported`
   unless forced with a second confirmation, and the old export stays
   on disk, marked superseded.

### Concurrency limit (`max_concurrent_calls`)

Not strictly billing, but it is the only quota the UI promises and it
is unimplemented. In the `orbital-cdr-inbound` gosub (which already
knows the team), generated from `Team.max_concurrent_calls`:
`Set(GROUP(orbital_team)=${ARG1})`, then
`GotoIf($[${GROUP_COUNT(${ARG1}@orbital_team)} > limit]?over)`. Over the
limit: per-client behaviour (`busy`, `overflow to AI persona`, `queue
anyway`), recording a `calls.rejected_capacity` CDR tag the collector
turns into an event. One Asterisk node counts its own calls; with
several backends (HA) the limit is per node — document it, or move the
check to the Kamailio edge (`dialog` module profiles) later. Amtelco
`DID Call Limit` maps here (dry-run report first).

### UI — staff (admin panel, "Billing" navigation group)

- **Usage** page: filter by client / billing account / date range;
  table of metrics with AI / operator / system split, calls, messages,
  dispatches by channel, provider cost (staff only); drill into the
  events behind any number (links to Call History and messages).
  "Export CSV" action (detail/summary).
- **ClientResource → Usage tab**: current period so far, last 6
  periods, included vs used, peak concurrency vs `max_concurrent_calls`.
- **Billing accounts** resource: members (attach clients), Amtelco
  number, accounting ref, currency, cycle day, assigned plan.
- **Rate plans** resource: components editor (Filament repeater),
  "copy to new version", preview "what would October cost on this
  plan" (rater run against an open or closed period without saving).
- **Billing periods** page: open / ready / closed / exported, with the
  ready-check results; **Close** shows a preview of lines and a diff
  against the last period (±% per metric, flags > 50% swings); Reopen;
  exports list with who downloaded when.
- **Adjustments**: create from a period or a client, reason required.
- `CallLogResource` gains the export action its policy already
  defines.

### UI — client portal

- New page **Usage** (`app/Filament/Portal/Pages/Usage.php`) gated by
  `portal.view_usage`: current period so far (calls, minutes, messages,
  dispatches), included vs used when a plan exists, past closed
  statements with lines and a CSV download of *their own* detail.
- Never shows `provider_cost_minor`, other clients in the same billing
  account, or AI/operator splits unless the platform setting
  `billing.portal_show_handler_split` is on (some services sell
  "live operator" and don't want to show AI minutes).
- Amounts are shown only when `billing.portal_show_amounts` is on;
  otherwise quantities only (the invoice comes from accounting).

### Permissions and tenant isolation

New catalogue entries in `PermissionCatalogSeeder`: `billing.view`,
`billing.export`, `billing.manage_accounts`, `billing.manage_rate_plans`,
`billing.close_period`, `billing.reopen`, `billing.adjust`,
`portal.view_usage` (granted to `client_admin` by default, not
`client_user`; `ClientProvisioner` seeds it with the other portal perms).

- `usage_events`, `usage_daily`, `billing_period_lines`,
  `usage_adjustments` use `BelongsToTeam`: portal users only ever see
  their team's rows.
- `billing_accounts`, `rate_plans`, `billing_periods` are platform
  tables behind policies requiring the `billing.*` permissions;
  the portal reaches a period only through its own lines.
- The `BelongsToTeam` scope is a no-op when the user has no
  `currentTeam` (`app/Models/Concerns/BelongsToTeam.php:31-36`). The
  portal Usage page must `abort_unless($user->currentTeam)` and filter
  by `team_id` explicitly too — belt and braces for money data.
- Collectors never infer a team from auth; always from the source row.
  `CallSessionState` and `OperatorCall` are not team-scoped models, so
  the collector reads their `team_id` and drops rows where it is null.
- Exports are generated per request, stored under a path containing
  the period id, served only via signed URLs that expire, and logged in
  `billing_audit_events`.

### Audit

Everything that changes money is in `billing_audit_events`: plan
create/copy, assignment change, billing-account membership change,
adjustment, close, reopen, export generated/downloaded. Usage events
themselves are their own audit: they are never deleted while a period
references them, and corrections are new rows that point at the old.

## Edge cases

- **Calls across midnight / period end** — attributed to `answered_at`
  (or `started_at` if unanswered) in the client's timezone. One call is
  never split across periods.
- **DST** — `local_date` is computed with the client's tz; a period is
  whole local days, so a 23- or 25-hour day is just a day.
- **Client timezone changes mid-period** — events keep the `local_date`
  computed at collection time; the period's tz is snapshot at open.
  Re-collection uses the snapshot, not the new tz.
- **Billing account change mid-period** — events carry the account at
  event time; the period close for each account takes its own events.
- **Rate-plan change mid-period** — rater splits by assignment dates;
  base fee prorated by days (configurable: prorate / full / none).
- **Client deleted** — `call_logs`/`messages` cascade-delete, but
  `usage_events`/`usage_daily`/lines have no FK and keep
  `account_number` and a name snapshot in `attributes`, so the final
  statement still exports. Deleting a client with an open period should
  warn and offer "close now".
- **Late CDR rows** — ingest rebuilds the call (`CdrIngester` is
  idempotent); collector upserts; post-close it becomes an adjustment.
- **Duplicate CDRs** (HA, two backends) — `call_logs` is unique per
  `linked_id` (`firstOrNew`, `CdrIngester.php:161`); events key on the
  log id, so no double count.
- **Stuck calls** (`ended_at` null; `PlatformMetricsCollector` already
  warns about this, `:126-130`) — block "ready" and are listed; staff can
  close anyway, the call is then billed when it resolves (adjustment).
- **AI → operator handoff** — split by legs; AI handoff to an outside
  number through `[from-livekit]` currently has no team tag → needs
  `Set(CDR(orbital_team_id))` on the handoff extension (the template
  already knows `$handoff['team_id']`) so those minutes land.
- **Outbound on a client's behalf** (callback, dispatch callout, patch)
  — needs the same tag and `direction=outbound`. Until 05/08 land,
  operator-dialled outbound calls are not attributable; documented gap.
- **Consult / conference overlap** (plan 08) — operator and patched
  party legs overlap; the invariant handling above caps the split at
  billable time; `voice.outbound_seconds` counts the patch leg
  separately so it can be billed as a patch.
- **AI unavailable fallback** (`status=ai_unavailable`) — counted as
  `calls.ai_unavailable`; billable seconds still recorded; whether it is
  charged is a plan decision (default component: free).
- **Unanswered / missed** — `calls.offered`/`calls.missed`, zero
  seconds; some services charge per call offered.
- **Test and trial calls** — no team → not logged (`CdrIngester.php:105-108`);
  `OperatorCall.is_trial` rows skipped; internal DID simulation
  (`extensions.blade.php:33-49`) is off in production but tags a real
  team when on — mark those CDRs (`orbital_test=1`) and exclude.
- **Partial messages** — separate metric so a plan can price them or
  not.
- **Message revisions** (plan 03) / status changes — never re-count.
- **SMS** — multi-segment, Unicode, MMS; failed vs undelivered attempts
  still cost carrier money (provider cost recorded) but billable per
  plan; inbound STOP/opt-out replies not billed.
- **Dispatch escalation** (plan 05) — every attempt is an event;
  dedupe by `delivery_attempt_id`; webhook replays don't create new
  attempts.
- **Suspended clients** — usage still recorded (calls still arrive);
  base fee per plan setting.
- **Pooled included minutes** across a billing account (157 WestMed
  accounts on `9300`) — deterministic allocation by time, lines show
  which client consumed what.
- **Very long or zero-length answered calls** — floor at 0, flag calls
  over N hours on the close preview.
- **Rounding** — per-call round-up to 60 s versus 6-second increments
  produce very different totals; rater tests pin both.
- **Currency** — one currency per billing account; mixing refused.

## Migration / rollout

Additive migrations only; this runs against production (k8s `orbital`
namespace), so every backfill/mapping command has `--dry-run`, and a
DB backup comes first.

1. **Ledger and export (export-only mode)** — `usage_events`,
   `usage_daily`, collectors for calls/messages/SMS/voicemail, hooks,
   sweeper, `orbital:usage-backfill` over existing `call_logs` (raw
   `asterisk_cdr` is pruned after 30 days, `CdrIngester.php:38`, but
   `call_logs.metadata.legs` keeps what the split needs), staff Usage
   page, detail/summary CSV, `CallLogResource` export. Fix the
   `[from-livekit]` handoff team tag. Behind `billing.enabled`.
2. **Billing accounts + Amtelco mapping** — `billing_accounts`,
   `teams.billing_account_id`; `orbital:import-amtelco-billing
   --dry-run` reads `client_legacy_records` (no xlsx re-read): creates a
   billing account per non-zero `Billing #`, attaches members, reports
   numbers that match no imported client (skipped junk accounts) and
   clients whose `Billing #` disagrees between the Accounts and Setup
   tabs. Same command proposes `max_concurrent_calls` from `DID Call
   Limit` (report only until step 5 ships).
3. **Periods, close, statements** — periods/lines/adjustments/audit,
   unrated close (quantities only) first; NCI runs one month in
   parallel with their current Amtelco-based billing and compares.
4. **Rate plans and rating** — behind `billing.rating_enabled`;
   accounting CSV; portal Usage page.
5. **Concurrency enforcement** — dialplan change, per-client over-limit
   behaviour, `calls.rejected_capacity`. Ship with all limits null
   (unlimited) and turn on per client.
6. **Dispatch / outbound / patch metering** as plans 05 and 08 land.

## Tests

Feature tests unless noted; all use `RefreshDatabase` and factories,
fake queues off (collectors run sync) unless stated.

**Ledger and split**
- An AI-only call (one `Dial …@livekit` leg, billsec 120, billable 120)
  → `voice.ai_seconds=120`, `operator=0`, `system=0`, `calls.answered=1`.
- Queue call: `Answer`, 40 s hold, operator answers for 200 s →
  billable 240, `operator=200`, `system=40`.
- AI 90 s then handoff to queue, operator 150 s, 10 s hold → AI 90,
  operator 150, system 10, sum = billable (invariant asserted for every
  fixture).
- Overlapping consult legs exceeding billable → split capped, warning
  logged, sum still equals billable.
- Missed call → `calls.offered=1`, `calls.missed=1`, zero seconds.
- `ai_unavailable` call → its own count, seconds recorded.
- Re-ingesting the same linkedid with a late extra CDR row updates the
  events in place (same `source_key`, count of rows unchanged).
- Message by persona → `messages.taken` handler ai; by operator → operator;
  partial → `messages.partial`; editing a message creates nothing.
- Trial `OperatorCall` and team-less CDRs produce no events.
- SMS: 161 GSM-7 chars → 2 segments; 71 chars with an emoji → 2
  segments; provider-reported segments override the estimate.

**Time**
- Call answered 23:59:30 local, ended 00:01 next day → one event on the
  first day.
- Client in `America/New_York`, call at 03:30 UTC on Nov 1 → local date
  Oct 31, lands in October period.
- DST fall-back day: period totals equal the sum of events (no lost or
  doubled hour).

**Roll-up**
- `usage_daily` equals a group-by over `usage_events`; rebuilding a day
  twice gives identical rows; gauge metrics roll up with max.

**Period close**
- Close blocked while an un-ingested `asterisk_cdr` row predates the
  period end, and while a call in the period has no `ended_at`.
- Close stamps every event, writes lines, checksum stable on recompute.
- Late call after close → adjustment event in the next period with
  `late_for_period_id`; closed period's lines unchanged.
- Reopen requires permission and reason; audit row written; re-close
  reproduces the same checksum when nothing changed.
- Two concurrent closes of the same account (parallel jobs) → one wins,
  the other no-ops (advisory lock).

**Rating** (unit, table-driven over `UsageRater`)
- 100 included minutes, $0.95/min overage, per-call round up to 60 s:
  fixtures of 61 s calls vs aggregate rounding.
- 6-second increments with 30-second minimum.
- Pooled included minutes across a 3-client billing account, consumed
  in time order; lines per client.
- Tiered message pricing; per-dispatch fee only for `delivered`
  attempts vs all attempts.
- Plan change on the 15th → two partial ratings and prorated base fee.
- Locked plan can't be edited; copy creates version 2.

**Export**
- Detail CSV columns and row count match events; summary totals match
  lines; accounting CSV uses `accounting_ref` and item codes from
  config; amounts formatted in minor-unit-safe decimals.
- Export download writes an audit row; signed URL expires.

**Isolation and permissions**
- Portal user of client A sees only A's usage even when A and B share a
  billing account; cannot fetch B's export by id.
- Portal user without a current team gets 403, not unscoped data.
- `portal.view_usage` absent → page hidden and 403; provider cost never
  rendered in the portal (assert absent in HTML).
- Staff without `billing.close_period` can view but not close.
- Deleting a client keeps its usage events and closed lines.

**Amtelco mapping**
- Fixture legacy records: three accounts with `Billing # 9300`, one with
  `0`, one with its own number → one billing account with three
  members, one standalone client, one single-member account; dry run
  writes nothing; re-run is idempotent.
- `DID Call Limit = 10` reported as proposed `max_concurrent_calls`.

**Concurrency**
- Generated dialplan contains the `GROUP`/`GROUP_COUNT` check only for
  clients with a limit; over-limit behaviour per setting; rejected
  calls produce `calls.rejected_capacity`.

**Answering-service scenarios** (end-to-end with the plan 01 engine
fixtures once available): "after-hours medical call, AI takes message,
dispatch by SMS (2 segments) then page, acknowledged" → expected usage
events for call, message, two dispatch attempts and units.

## Open questions

1. How does NCI bill today — per call, per minute, per message unit, or
   flat plans with included units? Which Amtelco reports feed it, and
   into which accounting system (QuickBooks Desktop/Online, Xero,
   other)? Decides whether phase 4 is needed at all (07 question 1).
2. Is `Billing #` really "bill these accounts together"? 9300 covering
   157 WestMed accounts suggests yes; confirm, and whether included
   units pool across the group.
3. Does NCI bill "work time" (operator time including after-call
   message writing) rather than talk time? Then `OperatorCall`'s
   `ended_at → message_saved_at/closed_at` wrap-up time needs to be a
   metric (`voice.operator_wrap_seconds`).
4. Rounding conventions: per-call round-up to the minute, 6-second
   increments, minimum charge per call?
5. Are AI-handled calls priced differently from live-operator calls,
   and should clients see the split?
6. Billing cycle: calendar month for everyone, or anniversary dates?
7. Are dispatch attempts billed on attempt or only on success; are
   escalation repeats billed?
8. Should AI provider cost (LLM/TTS tokens) be metered per client? The
   agent worker reports no usage to Laravel today; it would be a new
   worker → API call per session.
9. Is the per-node concurrency limit (multi-backend HA) acceptable, or
   must the limit be global from day one (edge enforcement)?

## Size estimate

| Phase | Work | Estimate |
|---|---|---|
| 1 | Ledger, collectors (call split, messages, SMS, voicemail), sweeper, roll-up, backfill, staff Usage page, CSV detail/summary, CallLog export, handoff CDR tag | 1.5–2 wk |
| 2 | Billing accounts, Amtelco mapping command + report | 3–4 days |
| 3 | Periods, ready check, close/reopen, adjustments, audit log, period UI | 1–1.5 wk |
| 4 | Rate plans, rater, accounting CSV, portal Usage page | 1.5–2 wk |
| 5 | Concurrency enforcement in dialplan + rejected metric | 3–4 days |
| 6 | Dispatch/outbound/patch metering (after 05/08) | 2–3 days each |

Export-only (phases 1–3): **~3–4 weeks**. With rate plans and portal:
**~5–6 weeks**.
