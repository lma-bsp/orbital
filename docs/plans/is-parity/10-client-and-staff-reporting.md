# Plan 10: Client and staff reporting

**Status:** proposed · part of [IS parity](README.md) · depends on — for
the core; 03 (messages by type), 05 (delivery/ack times), 06 (after-hours
share) for individual reports; builds on [live call board](../live-call-monitoring.md)
for the real-time half · survey items C and T in [07](07-additional-features.md)

## Problem

### What IS does

Intelligent Series ships a large catalogue of standard reports (~140,
per Amtelco's feature list) and runs them on a schedule, delivered by
email (and fax/print). For an answering service the ones that matter:

- **Client-facing**: call volume by hour and day, answer speed, abandoned
  calls, messages taken by type and urgency, delivery and acknowledgement
  times, after-hours share, plus the **daily or per-shift message summary**
  ("14 messages since 5pm, 2 urgent, all acknowledged") sent when the
  client's office opens.
- **Staff-facing**: operator productivity (calls per hour, talk / hold /
  wrap time, messages), time in each availability state, login/logout
  log, service level per queue in 15/30-minute intervals, and a live
  supervisor wallboard.

Clients get these without asking. Missing them is noticed in the first
month after cut-over.

### What Orbital has today (verified)

**Client portal**

- `app/Filament/Portal/Pages/Dashboard.php:44-70` — a "calls today"
  count and the 5 most recent calls. Nothing else.
  - `:68` counts with `whereDate('started_at', today())`. `today()` is in
    the app timezone (`config/app.php:68` is `UTC`), not the client's, so
    a US client sees "today" roll over at 7–8pm local time.
  - `:62` formats `started_at` without converting the timezone, so the
    recent-calls list shows UTC, while Call History converts to
    `displayTimezone()` (`CallHistory.php:57`).
- `app/Filament/Portal/Pages/CallHistory.php:45-111` — a paginated
  table: when, direction, from, to, status, duration, plus transcript and
  recording actions. It has no filters, no date range, no totals and no
  export.
- `TextConversations` is the only other portal page. There is no portal
  message list (`docs/roadmap.md:49-54`, plan 03) and no charts or
  widgets in any panel: `app/Filament` contains no widget classes.
- The portal permissions `portal.view_home`, `portal.view_calls`,
  `portal.view_messages` and `portal.view_recordings`
  (`database/seeders/PermissionCatalogSeeder.php:44-48`) are granted by
  `ClientProvisioner` but **never checked**. No portal page has
  `canAccess()` except `RoleResource`, and the Play action in Call History
  checks only that a recording exists (`CallHistory.php:100-102`). A
  custom portal role without `view_recordings` can still play recordings.
  Reports must not repeat this, so fixing it is phase 0 below.

**Admin call data**

- `app/Filament/Resources/CallLogResource.php:27-29` is super-admin only,
  even though `call_log.view_any` / `call_log.export` exist
  (`PermissionCatalogSeeder.php:34`, `app/Policies/CallLogPolicy.php:52`).
  It has filters for direction, status and client (`:95-106`), but no
  date range and no export action. Nothing implements `call_log.export`.
- `call_logs` rows are built by `app/Services/Telephony/CdrIngester.php`
  from `asterisk_cdr` every minute (`routes/console.php:92-96`):
  - `answered_at` is the earliest answer across all legs (`:114`), and
    `wasAnswered` is true if any leg answered (`:117`). Queue contexts run
    `Answer()` *before* `Queue()`
    (`resources/views/asterisk/client-dialplan.blade.php:51-52`), so
    **every queued call is recorded `completed`, including callers who
    abandoned in the queue**. `answered_at` is when Asterisk picked up the
    trunk, not when an operator answered, and `billable_seconds`
    (`:138`) includes the queue wait. `call_logs` alone can't give answer
    speed or abandonment.
  - `extension_id` is set only from `Dial()` legs (`:209-219`). Queue
    member legs (`lastapp = Queue`) aren't matched, so calls answered by
    an operator through a queue have no operator attribution. The member
    channel is kept only in `metadata.legs[].dstchannel` (`:148-157`).
  - Useful facts already captured: `metadata.outcome`
    (`ai`/`extension`/`queue`/`voicemail`/`ai_unavailable`, `:145`),
    `metadata.routing_rule_id` (`:146`), `call_queue_id` from the
    `queue-<id>` exten (`:203`), and `agent_persona_id`.
- `app/Models/CallLog.php:60-66` — statuses are completed, missed, busy,
  failed and ai_unavailable. There is no `abandoned`.

**Queue metrics**

- `queue_log` is written directly by Asterisk (`docker/asterisk/logger.conf:17-20`).
  `RollUpQueueMetricsCommand` aggregates it nightly at 02:00
  (`routes/console.php:46-50`) into `queue_metrics_daily`
  (`database/migrations/2026_04_15_100001_create_queue_metrics_daily_table.php:27-60`),
  then prunes `queue_log` older than 90 days (`RollUpQueueMetricsCommand.php:133-140`).
  Shortcomings:
  - The day is a UTC day (`:39-42`).
  - Only ENTERQUEUE/CONNECT/ABANDON/COMPLETE* are counted (`:78-102`).
    `EXITWITHTIMEOUT` (the normal path to AI overflow,
    `client-dialplan.blade.php:53-56`), `EXITEMPTY` and `RINGNOANSWER` are
    ignored, so offered ≠ answered + abandoned, and overflowed calls
    disappear.
  - The `agent` column is ignored, so there are no per-operator figures.
  - There is no service-level count (answered within N seconds).
  - `avg_wait_seconds` divides by answered + abandoned (`:110,121`),
    while the live collector averages answered calls only
    (`TelephonyMetricsCollector.php:148-155`). The two definitions
    disagree.
  - `team_id` is parsed from the `t<id>_` name prefix (`:149-155`), and
    the name comes from the queue's **editable** display name
    (`app/Models/CallQueue.php:112-124`). Renaming a queue splits its
    history, and nothing maps old rows back to a `call_queues.id`.
  - **Nothing reads `queue_metrics_daily`.** The only references are the
    command, its migration and a Tools page description
    (`app/Filament/Pages/Tools.php:159`). The docblock's "the dashboard
    reads from this table" is aspirational. Survey row T in plan 07 says
    the Grafana Telephony dashboard uses it; it doesn't (see below).

**Prometheus / Grafana**

- `PlatformMetricsCollector` pushes **24-hour rolling-window gauges**
  every minute (`:33`, `:93-131`, `:134-161`): `orbital_calls_recent`,
  `orbital_messages_taken_recent`, `orbital_messages_undelivered`
  (status `new`), each with a `client_id` label
  (`:576-583`, `config/metrics.php:90-101`).
  `TelephonyMetricsCollector` adds 15-minute queue windows (`:32`,
  `:117-170`). `orbital_operators_by_availability` is a current headcount
  per status (`:376-393`).
- Four provisioned dashboards (`docker/grafana/provisioning/dashboards/orbital/`):
  Infrastructure, Application, LiveKit & AI, and Telephony. Telephony
  panels read Prometheus only (`orbital_queue_events_recent`,
  `orbital_queue_wait_seconds_*`, `orbital_calls_recent_by_status`).
  Grafana's datasources are Prometheus, Loki and Tempo
  (`docker/grafana/provisioning/datasources/datasources.yml`), with no
  Postgres.
- Grafana is reachable only from the admin panel behind `tooling.grafana`
  (`app/Providers/Filament/AdminPanelProvider.php:179-190`).
- Prometheus runs with no `--storage.tsdb.retention.*` flag
  (`docker-compose.yml:714-716`), so it uses the default **15 days**.
  Rolling-window gauges plus 15-day retention can't answer "calls by
  hour last month". That undermines the TODO plan to chart tenant metrics
  from Prometheus in the portal (`TODO.md:616-635`).

**Operator activity**

- Availability is a single overwritten value. `AvailabilitySelector`
  sets `users.availability_status` and `availability_changed_at`
  (`app/Livewire/AvailabilitySelector.php:71-74`), and
  `ResetAvailabilityOnLogin` forces `unavailable` on login
  (`app/Listeners/ResetAvailabilityOnLogin.php:55-58`). No history is
  kept, so "time in each state" can't be reported.
- `UserLogoutEvent` (`app/Models/UserLogoutEvent.php`) is written only
  from the operator panel's Sign out modal
  (`app/Providers/Filament/OperatorPanelProvider.php:194-208`):
  - The modal asks for an optional **note** (`:174`, `:187`) that's
    discarded. `user_logout_events` has no column for it
    (`database/migrations/2026_04_15_140000_create_logout_reasons_table.php:41-50`).
  - No login row is written, so session length is unknown.
  - Session expiry or a closed tab writes nothing.
  - Nothing displays these rows, although the modal tells operators "so
    the supervisor log knows why you're off the floor".
  - `user_id` cascades on delete, so removing a user erases their
    history.
- Queue membership pause is driven by rewriting `queue_members`
  (`QueueMemberSyncer::syncForOperator`, `AvailabilitySelector.php:80`).
  Realtime table edits don't produce `PAUSE`/`UNPAUSE` queue_log events,
  so state history has to come from the app.
- `TODO.md:455-466` ("Operator history events") asks for exactly this
  timeline.
- `OperatorCall` (uncommitted, multi-call desk) has `arrived_at`,
  `answered_at`, `held_at`, `ended_at`, `message_saved_at`, `closed_at` and
  `is_trial`
  (`database/migrations/2026_11_24_110000_create_operator_calls_table.php:33-55`).
  That's enough for wrap-up time (`closed_at − ended_at`). It's not team
  scoped by design (`app/Models/OperatorCall.php:15`), and `held_at` is a
  single timestamp, so cumulative hold time is lost.

**Messages**

- `app/Models/Message.php:29-37` — status `new`/`read`/`archived` and
  urgency `normal`/`urgent`. Who took it is `created_by_user_id` or
  `agent_persona_id`. There's no message type (plan 03 adds templates)
  and no delivery or ack timestamps (plan 05 adds `dispatches`,
  `delivery_attempts`, `acknowledgements`).

**Plumbing**

- `league/csv` is a direct dependency, and `openspout/openspout` is
  installed through Filament (`composer.lock`). There's no PDF library.
  There's no `exports` / `job_batches` table, so Filament's built-in
  ExportAction isn't wired.
- The only mailables are `ClientInvitationMail`, `ThreadForward`,
  `ThreadReply` and `VoicemailReceived`.
- `BelongsToTeam`'s global scope does nothing when there's no
  authenticated user, and super-admins bypass it
  (`app/Models/Concerns/BelongsToTeam.php:16-35`). Scheduled report jobs
  run with no user, so **every report query must filter `team_id`
  explicitly**, as the portal pages already do (`CallHistory.php:17-25`).
- `Team::timezone()` exists (`app/Models/Team.php:265-272`).
- `OperatingHour` rows exist but nothing reads them (plan 06).

## Goal

1. Correct, durable per-call and per-operator **facts** in Postgres, from
   which every report is computed. Prometheus stays for health.
2. A fixed **report catalogue** for clients (portal) and staff (admin),
   each report parameterised by period, granularity and filters, with
   table + chart + CSV/XLSX export.
3. **Scheduled delivery** by email, including the daily / per-shift
   message summary, timed in the client's timezone.
4. Staff reports on operator productivity, handle time, availability
   states and queue service level, consistent with the live board's
   numbers.
5. Tenant isolation that doesn't depend on auth context, and an audit
   trail of who saw or exported what.

Non-goals: a free-form SQL / drag-and-drop report builder (see
"Catalogue vs builder"); billing invoices (plan 09 owns rating and
period close, though it reuses these facts); the live wallboard itself
(live-call plan), beyond the KPIs it shares with this plan.

## Design

### Overview

```
asterisk_cdr ─┐                         ┌─ client_stats_hourly ─┐
queue_log ────┼─► CallFactBuilder ─► call_logs (+fact cols) ─┤                       ├─► ReportRegistry ─► portal / admin pages
OperatorCall ─┘                         │  operator_call_legs ──┼─ queue_stats_interval ┤                  ─► exports
messages / dispatches (05) ─────────────┤                       ├─ operator_stats_daily ┘                  ─► scheduled emails
OperatorStateRecorder ─► operator_sessions, operator_state_intervals ┘
```

Three layers: **facts** (one row per call, operator leg, state interval
and message, all in UTC), **rollups** (pre-aggregated in the client's
local time for cheap reads), and **reports** (code-defined queries over
rollups and facts).

### 1. Facts

#### 1a. Call facts: extend `call_logs`

Add the columns to `call_logs` rather than a parallel table. It's
already the per-call record (one row per `linked_id`) that portal and
admin use, and `CdrIngester` rebuilds it idempotently. New columns:

| Column | Source |
|---|---|
| `handled_by` enum `operator`/`ai`/`ai_then_operator`/`voicemail`/`none` | outcome + `CallSessionState.handed_off_at` |
| `answered_by_user_id` (nullable FK users, `nullOnDelete`) | queue_log CONNECT `agent` → extension → user; or `Dial()` extension → user |
| `queue_entered_at`, `queue_wait_seconds` | queue_log ENTERQUEUE / CONNECT.data1 / ABANDON.data3 / EXITWITH*.data3 |
| `operator_answered_at` | `queue_entered_at + wait`, or the extension leg's answer |
| `queue_result` enum `answered`/`abandoned`/`timeout_overflow`/`exit_empty`/`exit_key`/null | last queue_log event for the callid |
| `talk_seconds`, `hold_seconds`, `wrap_seconds` | COMPLETE*.data2; `operator_call_legs` |
| `channel` enum `voice`/`sms`/`chat`/`email` | `voice` for CDR-built rows (other channels: §1d) |
| `after_hours` bool, nullable | schedule evaluation at `started_at` (plan 06; fallback below) |
| `local_date`, `local_hour` (smallint) | `started_at` in the client's tz at build time |
| `is_test` bool | test extensions / `OperatorCall.is_trial` / platform test DIDs |
| `facts_version` smallint | allows re-deriving after a definition change |

`status` gains **`abandoned`**: a caller who hung up in the queue
before an operator answered, regardless of the trunk-leg `ANSWERED`.
`billable_seconds` stays as it is (carrier-billed duration) and plan 09
decides what it bills on. The UI's "Duration" stays `duration_seconds`.

**`CallFactBuilder`** (`app/Services/Reporting/CallFactBuilder.php`) is
called from `CdrIngester::record()` after the base attributes are built:

- Reads `queue_log` rows where `callid = <uniqueid of the caller
  channel>` (queue_log `callid` is the caller channel's uniqueid; the
  ingester already has `$first->uniqueid`).
- Maps queue_log `agent` (the member interface `PJSIP/<endpoint id>`,
  since `log_membername_as_agent` is not set) → `Extension` →
  owning user. `membername` is the operator's display name
  (`QueueMemberSyncer.php:194`), so it isn't used for attribution.
- **Shared extensions**: if the extension isn't owned by one user, use
  the `operator_sessions` row whose interval covers `operator_answered_at`
  for that extension.
- Also fixes `extension_id` for queue-answered calls by parsing
  `dstchannel` of `lastapp = Queue` legs (the gap at
  `CdrIngester.php:209-219`).
- Pure and deterministic given (cdr rows, queue_log rows, session row,
  team schedule). It's unit-tested with fixtures.

**Late data**: queue_log and CDR rows can land after the first ingest.
The builder runs on every rebuild of the call, and a call's rollup
buckets are marked dirty (§2).

#### 1b. Operator legs: `operator_call_legs`

`BelongsToTeam`, but nullable during ringing in the multi-call desk.
There's one row per (call, operator) so that transfers and handoffs
credit each operator:

`call_log_id` (nullable until CDR ingest links it via `linked_id` /
`sip_call_id`), `linked_id`, `user_id`, `operator_call_id` (nullable),
`offered_at` (rang this operator), `answered_at`, `ended_at`,
`ring_seconds`, `talk_seconds`, `hold_seconds`, `hold_count`,
`wrap_seconds`, `message_id`, `outcome`
(`answered`/`rang_no_answer`/`declined`/`transferred_out`).

- Written from queue_log (`CONNECT`, `RINGNOANSWER`, `COMPLETE*`,
  `TRANSFER`/`BLINDTRANSFER`/`ATTENDEDTRANSFER`) by `CallFactBuilder`.
- Enriched from `OperatorCall`: wrap is `closed_at − ended_at`, and the
  message link comes from `message_saved_at` / `message_id`.
- Hold needs intervals, not one `held_at`. Add an `operator_call_events`
  append-only table (hold/resume/transfer/close), or reuse plan 01's
  `script_events` once operator calls run through the script engine.
  Hold seconds are the sum of hold→resume pairs. Until then,
  `hold_seconds` is null ("not measured"), not 0.
- The single-call Workspace gets the same events, written from the
  softphone hold/resume actions.

#### 1c. Operator state: `operator_sessions` and `operator_state_intervals`

These are platform-level and **not** `BelongsToTeam`. Operators work
across clients, and these tables are only read by staff reports.

- `operator_sessions`: `user_id`, `panel` (`operator`/`admin`),
  `started_at`, `ended_at`, `end_source`
  (`logout`/`session_expired`/`heartbeat_lost`/`forced`),
  `logout_reason_id`, `reason_label_snapshot`, `note`,
  `user_name_snapshot`, `ip`.
  - `user_id` is `nullOnDelete`, unlike `user_logout_events`' cascade,
    because history must survive user removal.
- `operator_state_intervals`: `user_id`, `operator_session_id`,
  `state_slug`, `state_label_snapshot`, `blocks_voice`,
  `blocks_non_voice`, `started_at`, `ended_at`, `source`
  (`selector`/`login_reset`/`logout`/`supervisor`/`system`).
  - Exactly one open interval per user, enforced by a partial unique
    index `WHERE ended_at IS NULL`.
- `OperatorStateRecorder` service with `login()`, `changeState()` and
  `logout()`. Each closes the open interval and opens the next in one
  transaction. It's called from:
  - `AvailabilitySelector::updatedStatus` (`AvailabilitySelector.php:71`)
  - `ResetAvailabilityOnLogin` (`:55`)
  - the logout action (`OperatorPanelProvider.php:194`), which finally
    stores the note
  - plan 11 / supervisor force-state actions
- **Heartbeat sweeper** (every minute): an operator panel heartbeat
  updates `users.last_seen_at` (Valkey, flushed). A session with no beat
  for N minutes (default 5) is closed with `heartbeat_lost` at the last
  beat. This catches closed tabs and crashes, which today leave the user
  "available" until next login.
- `UserLogoutEvent` stays as is for compatibility. The recorder keeps
  writing it, and a later cleanup can retire it in favour of
  `operator_sessions`.
- This **is** TODO.md's "Operator history events" for state. Call and
  message actions come from 1b and `messages`. A full per-click timeline
  (fetch account, dial) is left to plan 22 (script replay).

#### 1d. Message facts

Messages are already rows. Reports read `messages` directly, plus:

- `channel` (`voice`/`sms`/`chat`/`email`/`portal`): derived now from
  `call_log_id` / `message_thread_id`, and stored once plan 03 lands.
- `taken_by` (`operator`/`ai`): from `created_by_user_id` /
  `agent_persona_id`.
- Type is plan 03's `template_id` (a "type" is the template name). Before
  03, the report groups by urgency only and says so.
- Delivery and ack come from plan 05: first delivery attempt `sent_at`,
  first `delivered`, and the `acknowledgements` timestamp. Reports
  compute *time to first delivery* and *time to ack* from
  `messages.created_at`.
- After-hours flag copied from the originating call, or evaluated at
  `created_at` for non-call messages.

#### 1e. After-hours definition

Plan 06's `ScheduleEvaluator::isOpen(client "Office hours" schedule, at)`
gives the primary definition. Until 06 ships:

1. Use the matching `RoutingRule` time condition if one was recorded
   (`metadata.routing_rule_id`).
2. Otherwise use `OperatingHour` rows (`Team::operatingHours()`) in the
   client timezone.
3. Otherwise `null` ("not configured"). The report hides the after-hours
   split instead of reporting 0%.

The flag is computed at fact-build time, so a later schedule edit
doesn't rewrite history. A "recompute after-hours" admin action
re-derives a date range on purpose.

### 2. Rollups

Rollups are rebuilt incrementally from facts. All are keyed by
**client-local** date and hour, except the operator and platform-wide
tables, which use the platform's reporting timezone
(`config('reports.platform_timezone')`, default `app.timezone`).

- **`client_stats_hourly`** (`team_id`, `local_date`, `local_hour`,
  `channel`). PK on those four, no FK on `team_id`, kept after client
  deletion, like `queue_metrics_daily`.
  - Call counts: `calls_offered`, `answered_operator`, `answered_ai`,
    `ai_then_operator`, `abandoned`, `short_abandoned`, `overflow_ai`,
    `voicemail`, `missed`, `after_hours_calls`.
  - Wait and handle: `sum_wait_answered`, `count_wait_answered`,
    `answered_within_sl`, `max_wait`, `sum_talk`.
  - Messages: `messages_total`, `messages_urgent`, `messages_partial`,
    `messages_by_template` (JSONB counts).
  - Delivery: `sum_time_to_ack`, `count_acked`, `acked_within_target`
    (populated once 05 lands).
- **`queue_stats_interval`** (`call_queue_id`, `team_id`, `interval_start`
  UTC, `interval_minutes` 15). Same call measures per queue plus
  `sl_threshold_seconds` as it was at the time.
  - `call_queue_id` is the key, so a queue rename no longer orphans
    history.
  - The queue_log `queuename` → id mapping goes through a
    `queue_name_history` table (`call_queue_id`, `asterisk_name`,
    `valid_from`, `valid_to`) written when `CallQueue` saves.
  - An option to change `asteriskName()` to an id-based `t{team}_q{id}`
    is in open questions.
- **`operator_stats_daily`** (`user_id`, `date`, `team_id` nullable for
  the all-clients row): `logged_in_seconds`, `seconds_by_state` (JSONB
  slug → seconds), `offered`, `answered`, `rang_no_answer`, `talk`,
  `hold`, `wrap`, `messages_taken`, `concurrent_peak`.
- **Watermarks and dirty buckets**: each fact write records a dirty
  (table, key) in `report_dirty_buckets`. `RebuildReportRollupsJob`
  (Horizon `reports` queue, every minute, `withoutOverlapping`,
  `onOneServer`) recomputes dirty buckets from facts and advances
  `report_watermarks.complete_through`. That's the latest time for which
  CDR ingest and rollups are both caught up (CDR ingest lag ~1 min,
  `CdrIngester` comment). Scheduled reports wait for the watermark to
  pass the period end (§4).
- **`queue_metrics_daily`**: keep the table and the 02:00 command for
  one release, but fill it from `queue_stats_interval` with the same
  column meanings. Fix the overflow omission there, then drop the old
  aggregation from queue_log. Pruning `queue_log` at 90 days remains,
  because facts and rollups are now the long-lived record.

Volume sizing: one hourly row per client per active hour per channel is
about 1000 clients × 24 × 365 × ~1.5 ≈ 13M rows/year worst case. Rows
are narrow, partitioned by year on `local_date`, with a BRIN index on
date.

### 3. Report catalogue

**Catalogue vs builder.** IS's standard reports are a fixed catalogue
with parameters, plus a report writer few services use. Ship a **fixed
catalogue** where each report is a PHP class. Parameters cover period,
granularity (interval/hour/day/week/month), queue, operator, channel,
urgency and type. Users can save parameter sets as **saved views** and
**schedules**. Don't build a free-form builder in v1:

- The facts have subtle definitions (short abandons, SL denominators,
  AI-then-operator). A builder lets people produce numbers that disagree
  with the standard reports, which leads to "your report is wrong"
  support tickets.
- Tenant isolation is easy to prove for a fixed set of query classes,
  and hard for arbitrary queries.
- Every report has CSV/XLSX export of its underlying rows, which covers
  most "I want to slice it myself" needs in Excel.

Later, possibly: a constrained "pivot" report over a whitelisted semantic
layer (dimensions: date/hour/queue/operator/channel/type/urgency;
measures from the rollup columns). Not a SQL builder. See open questions.

**Shape** (`app/Reports/`):

```php
interface Report {
    public static function key(): string;            // 'client.call_volume'
    public static function audience(): Audience;      // Client | Staff
    public static function requires(): array;         // ['plan03'] feature flags → hidden if unmet
    public function parameters(): array;              // Filament form schema
    public function run(ReportContext $ctx, array $params): ReportResult; // columns, rows, totals, chart spec, notes
    public function exportRows(ReportContext $ctx, array $params): iterable; // detail rows for CSV/XLSX
}
```

`ReportContext` carries `teamId` (required for client audience),
`timezone`, `period` (`CarbonPeriod` in local tz), `viewer`, and
`isStaffView`. Client reports extend `ClientReport`, whose `facts()` and
`rollups()` helpers always start from
`->withoutGlobalScopes()->where('team_id', $ctx->teamId)`, the same
"filter explicitly" rule as `CallHistory`. `ReportRegistry` lists the
reports available for an audience, viewer and client.

**Client catalogue (portal and staff "view as client")**

| Key | Report | Contents | Needs |
|---|---|---|---|
| C1 | Call volume | calls by hour of day × day of week heat map; by day/week/month trend; offered/answered/abandoned/overflow | — |
| C2 | Answer speed | average speed of answer, % answered within SL threshold, longest wait, abandon rate (excl. short abandons), by interval/day | 1a |
| C3 | Call outcomes | operator vs AI vs AI→operator vs voicemail vs missed; AI unavailable count | — |
| C4 | Messages | count by type × urgency, by day; partial-message count; channel split | 03 for type |
| C5 | Delivery & acknowledgement | median/90th time to first delivery and to ack; % acked within target; unacked list; by method and recipient | 05 |
| C6 | After-hours share | % of calls and messages outside office hours, by day; urgent after-hours | 06 (fallback 1e) |
| C7 | Message summary | list of messages in period with summary line, urgency, delivery/ack state; totals header | 03 for summary line |
| C8 | Activity summary | one-page monthly overview: C1–C6 headline numbers vs previous period | — |
| C9 | Usage | calls, minutes, messages per period (the client-visible half of plan 09) | 09 for rated amounts |

**Staff catalogue (admin, cross-client)**

| Key | Report | Contents |
|---|---|---|
| S1 | Operator productivity | per operator: logged-in hours, calls answered, calls/hour, messages taken, messages/hour, occupancy, ring-no-answer count |
| S2 | Handle time | per operator / client / queue: talk, hold, wrap, AHT = talk+hold+wrap; distribution (p50/p90) |
| S3 | Availability states | per operator per day: time in each state (from `availability_reasons`), first login, last logout, logout reasons with notes |
| S4 | Login/logout log | session list with end source (logout/expired/heartbeat lost), reason, note |
| S5 | Queue service level | per queue per 15/30/60-min interval: offered, answered, abandoned, ASA, SL%, max wait, overflow to AI |
| S6 | Client SLA board | all clients, period: SL%, ASA, abandon %, vs per-client target; sortable "worst first" |
| S7 | AI vs human | per client: share handled by AI, AI→operator handoffs, AI unavailable fallbacks, messages by taker |
| S8 | Dispatch performance | overdue acks, escalations reached per tier, failures by transport (needs 05) |
| S9 | Staffing vs demand | calls offered per interval vs operators available (from state intervals) per interval — the input for scheduling staff |
| S10 | Client activity export | any client's C-reports, run by staff "as the client sees it" |

**Definitions** (written into each report's footnote and the docs, and
shared with the live board):

- *Offered* = calls that entered a queue or were routed to an operator
  or AI. Internal and test calls are excluded.
- *ASA* = mean `queue_wait_seconds` over operator-answered queued calls.
  AI-answered calls report their own ASA (ring to AI pickup).
- *Service level %* = answered within T ÷ (answered + abandoned after
  the short-abandon threshold). T is per queue
  (`call_queues.service_level_seconds`, default 30). The short-abandon
  threshold is per platform (default 5 s). Overflow-to-AI counts as
  answered for the *client* view (the caller got service) and as "not
  answered by operator" in S5. Both numbers are shown, labelled.
- *Occupancy* = (talk + hold + wrap) ÷ (logged-in time not in a
  `blocks_voice` state). On the multi-call desk, concurrent calls are
  counted once per wall-clock second (union of intervals), so occupancy
  can't exceed 100%. `concurrent_peak` is shown separately.
- *Calls/hour* = answered ÷ logged-in available hours.

### 4. Scheduled delivery and the message summary

**Model** (`BelongsToTeam`; staff schedules set `team_id` null and
`audience = staff`):

- `report_schedules`: `report_key`, `params` (JSONB, a saved view),
  `name`, `cadence` (`daily`/`weekly`/`monthly`/`shift`), `send_at`
  (local time), `weekday`/`monthday`, `timezone` (defaults to the
  client's), `period_rule` (`previous_day`, `since_last_run`,
  `previous_shift`, `month_to_date`…), `formats` (`html_inline`, `csv`,
  `xlsx`, later `pdf`), `skip_if_empty`, `is_active`, `created_by`.
- `report_schedule_recipients`: either `user_id` (a portal user of the
  same team, or staff for staff schedules) or an `email`. External
  addresses are allowed only when added by staff or a `client_admin`,
  are audited, and get a one-click unsubscribe.
- `report_runs`: `schedule_id`, `period_start`, `period_end`, `status`
  (`pending`/`waiting_for_data`/`rendered`/`sent`/`failed`/`skipped_empty`),
  `row_count`, artifact paths, `sent_at`, `error`, and per-recipient
  results. Unique (`schedule_id`, `period_start`, `period_end`) makes a
  run idempotent, so a retried job never double-sends.

**Jobs**

- `QueueDueReportRunsJob` runs every minute (`onOneServer`). For each
  active schedule whose next fire time, computed in its timezone, has
  passed, it inserts a `report_runs` row with
  `firstOrCreate(schedule, period)` and dispatches `RenderReportRunJob`.
- `RenderReportRunJob` (`reports` queue):
  - If `report_watermarks.complete_through < period_end`, it sets
    `waiting_for_data` and releases itself with a 2-minute delay. After
    30 minutes it sends anyway with a "data may be incomplete" note and
    alerts staff.
  - Otherwise it builds the `ReportContext` from the schedule's `team_id`
    (never from auth), renders, stores attachments on the recordings disk
    under `reports/{team}/{run}/`, and sends `ScheduledReportMail` per
    recipient (queued, mail relay as for voicemail).
- **Per-shift (`cadence = shift`)**: fire times are the plan 06 schedule's
  transitions. For example, at each "Office hours" open, send
  `period_rule = since_last_run`, which is everything since the last
  summary. Before 06, a shift is a list of fixed local times
  ("07:30, 17:30").

**Message summary (C7) email** is the main IS deliverable:

- A header line: "12 messages since Mon 5:30pm · 2 urgent · 11
  acknowledged, 1 awaiting".
- A table of summary lines (plan 03's per-template summary), with a link
  per message to the portal detail. Viewing it can count as check-in /
  ack (plans 05 and 12).
- **PHI-safe mode**, per client and per template redaction flags from
  plan 03: the email carries counts and links only, and content stays in
  the portal (plan 17's secure-link approach).
- `skip_if_empty` defaults off for summaries ("no messages" is itself
  information clients want) and on for other reports.

**Formats**: HTML inline (Blade, inline CSS, no images required) plus a
CSV attachment by default. XLSX uses openspout (already installed via
Filament). PDF is deferred (open question). If needed, use a headless
Chromium render of the same Blade view, with no new PHP PDF library.

### 5. Exports

- Every report has **Export CSV** and **Export XLSX** of `exportRows()`,
  and Call History gets them too (portal and admin, finally backing
  `call_log.export`).
- Under 10k rows, the export streams directly (`league/csv` writer into a
  `StreamedResponse`, cursor-paginated query). Over that, `ExportReportJob`
  writes to object storage and notifies the user (Filament database
  notification, since the notifications table exists) with a signed URL
  that expires in 24 h.
- **CSV injection**: prefix cells starting with `= + - @ \t \r` with `'`.
  Caller names and reasons are caller-controlled input.
- Times are exported in the report timezone as ISO 8601 with offset, plus
  a separate UTC column.
- Exports never include recording URLs. Transcripts are included only
  with the recordings permission and an explicit checkbox.

### 6. UI

**Portal** (`app/Filament/Portal/`):

- **Overview** (`Dashboard`) becomes KPI tiles plus charts.
  - Tiles: calls today and this week, ASA, abandon %, messages today,
    unacknowledged messages. Charts: last 14 days volume, today by hour.
  - Built as Filament widgets (`StatsOverviewWidget`, `ChartWidget`,
    with Chart.js bundled by Filament, no new JS dependency) reading
    `client_stats_hourly`.
  - Fixes the timezone bugs at `Dashboard.php:62,68`: "today" is the
    client's day.
- **Reports** page: catalogue cards (filtered by `requires()` and
  permissions), a parameter form, a result table and chart, export
  buttons, and "Save view" / "Schedule this".
- **Scheduled reports**: a list of the team's schedules and recipients
  with run history (`report_runs`), a "send now" test, and pause.
- **Call History**: date range, status (including `abandoned`), handled
  by, after-hours filters, a totals row, and export.

**Admin** (`app/Filament/`, "Reports" navigation group under Monitor):

- The staff catalogue S1–S10 with the same page component.
- **Client reports** run any C-report for any client, rendered exactly
  as the portal would show it, for answering "your report says…" calls.
- **Operator scorecard**: one operator over a period (S1–S4 combined),
  and a per-operator timeline of state intervals, sessions and calls.
- **Queue service level**: S5 with a today/intraday view.
- Client form (`ClientResource`): per-client targets (SL seconds, ack
  target minutes) and the default message-summary schedule created on
  client provisioning (off by default, see rollout).
- `CallLogResource` opens to users with `call_log.view_any` (staff
  supervisors), not just super-admins, and gains a date filter and export.

**Operator panel**: an optional "My stats today" widget (calls, AHT,
messages, time available) behind `report.view_own`. Leaderboards are
deliberately not included (open question).

**Real-time vs historical**: the live board (live-call plan) owns
*now*: callers waiting, longest wait, operators by state. This plan
adds two "today so far" tiles to that board, SL% and ASA per queue,
computed from `queue_stats_interval` for today plus the live projection.
That keeps one definition of SL across the wallboard and S5.

### 7. Relationship to Prometheus and Grafana

| | Prometheus + Grafana | Reports (this plan) |
|---|---|---|
| Purpose | platform health, alerting, ops troubleshooting | business records for clients and supervisors |
| Store | TSDB, 15-day default retention | Postgres facts + rollups, years |
| Granularity | rolling 24 h / 15 min gauges, per scrape | per call, per interval |
| Audience | super-admin / `tooling.grafana` | clients (own data), staff (per permission) |
| Correctness | approximate, sampled | exact, reproducible, auditable |

- **Don't** chart tenant data from Prometheus in the portal. This
  supersedes the tenant half of `TODO.md:616-635`. Rolling-window gauges
  and 15-day retention can't produce period reports, and two sources
  would disagree.
- **Do** push a few gauges derived from the rollups so the existing
  alerting stack can alert on business SLOs:
  `orbital_queue_service_level_ratio{queue_id}` (today so far),
  `orbital_messages_unacked_overdue{client_id}` (05). Add a Telephony
  dashboard row for them, labelled "from reporting rollups".
- Optional, staff-only: a read-only Postgres datasource in Grafana with a
  role granted SELECT on the rollup tables only, for ad-hoc staff
  exploration. Off by default, because it bypasses app permissions.
- Fix the plan 07 survey row T wording when this plan is promoted. The
  Grafana Telephony dashboard reads Prometheus `queue_log` windows, not
  `queue_metrics_daily`.

### 8. Permissions and tenant isolation

New permissions (registered in `PermissionCatalogSeeder`, cached by
`App\Support\PermissionRegistrar`):

- Staff:
  - `report.view_staff` (S-reports)
  - `report.view_client_any` (C-reports for any client)
  - `report.view_own` (operator self-stats)
  - `report.export`
  - `report.schedule` (staff schedules and client schedules on behalf of
    clients)
  - Defaults: super_admin all; supervisor all but `report.schedule` on
    external addresses; operator `report.view_own`.
- Portal:
  - `portal.view_reports` (default `client_user`)
  - `portal.export` (default `client_user`)
  - `portal.manage_report_schedules` (default `client_admin`)
  - Message summary content additionally requires
    `portal.view_messages`, and transcript columns require
    `portal.view_recordings`.

Isolation rules:

1. **Phase 0 prerequisite**: enforce the existing `portal.view_*`
   permissions with `canAccess()` on every portal page and gated actions.
   Do this before adding anything that relies on them.
2. Client report queries are always built from `ReportContext::teamId`
   via `ClientReport`'s helpers, never from the `BelongsToTeam` global
   scope. The scope is absent in jobs and bypassed for super-admins.
3. `ReportContext` for the portal is built from `current_team_id` only
   after verifying membership (`$user->belongsToTeam($team)`). For
   schedules it's built from `report_schedules.team_id`. No request
   parameter can set `teamId` in the portal.
4. Recipients of a team's schedule must be users of that team or
   explicitly added external addresses. Changing a schedule's team is
   impossible: it's immutable after create.
5. Client reports show "Operator" / "AI agent", not operator names, by
   default (setting per platform, open question). Staff-only fields
   (operator ids, cross-client comparisons) don't exist on client report
   classes at all.
6. Export and attachment storage paths include `team_id`. Signed URLs
   are tied to the requesting user, and the download route re-checks
   permission and team.
7. Rollup tables have no FK to teams (history survives), but every
   reader filters on `team_id`. Deleting a client hides its rows from
   every surface except super-admin S-reports.

### 9. Audit

- `report_access_log` (append-only): `user_id`, `team_id`, `report_key`,
  `params`, `action` (`viewed`/`exported`/`scheduled`/`schedule_changed`/
  `run_sent`), `row_count`, `ip`, `at`.
  - Exports and external-recipient changes are what compliance asks
    about: "who downloaded six months of caller numbers".
- Schedule changes record a before/after diff.
- `report_runs` keeps the sent artifact for 90 days (configurable), so
  "what exactly did we send the client on the 3rd" is answerable.
- Operator session and state rows are themselves the audit of operator
  activity. Supervisor-forced state changes record the supervisor in
  `source`/`changed_by`.

## Edge cases

- **DST**: hourly buckets are local wall-clock hours. The spring-forward
  day has 23 rows and the fall-back day has 25, with hour 1 appearing
  twice (bucket key includes the UTC offset). Daily totals equal the sum
  of hours. Shift fire times that fall in the skipped hour run at the
  next valid minute, and fall-back fires once.
- **Client timezone change**: existing rollups keep their old local keys.
  An admin "rebuild client rollups" action re-derives from facts, offered
  on timezone save.
- **Midnight-spanning calls** are attributed to the arrival
  (`started_at`) bucket. Operator time spanning midnight is split across
  days in `operator_stats_daily`.
- **Late CDR / queue_log rows** re-dirty buckets. A report viewed before
  data is complete shows "data complete through 10:42".
- **AI → operator handoff** counts once as a call. `handled_by =
  ai_then_operator`. The AI leg's time isn't operator handle time, and
  ASA is measured to the AI pickup.
- **Queue timeout overflow to AI** (`EXITWITHTIMEOUT` then `Dial(@livekit)`)
  counts as an operator miss in S5 and as answered (by AI) in C2, both
  labelled. `ai_unavailable` after overflow counts as unanswered.
- **Abandoned-in-queue** calls become `abandoned` (fixing today's
  `completed`). Abandons under the short-abandon threshold are excluded
  from SL and abandon % but shown as a count.
- **Transfers**: each operator leg is credited separately. A call
  transferred to another client's queue (rare) stays with the original
  `team_id`, and the second leg is noted in `metadata`.
- **Multi-call desk concurrency**: occupancy uses the interval union, and
  per-call AHT still counts each call's full talk time.
- **Shared or unowned extensions** fall back to session lookup. If that's
  unresolved, `answered_by_user_id` is null and the call shows as
  "Unattributed" in S1, not dropped.
- **Test traffic** (`is_trial`, operator test extensions, platform test
  DIDs) is excluded by default, with a staff-only "include test calls"
  toggle.
- **Teamless calls** (unrouted DIDs) are already skipped by `CdrIngester`.
  They appear only in a staff "unrouted calls" count.
- **Messages without calls** (SMS/chat/email intake, plan 03) count in C4
  and C7 with their channel, and never in call reports.
- **Message edits** (plan 03 revisions) don't create new messages. Type
  and urgency are reported as of the latest revision, with a footnote.
- **Partial messages** are counted separately and never hidden.
- **Deleted operators** keep their name via `user_name_snapshot`, and
  deleted availability reasons keep `state_label_snapshot`.
- **Renamed queues** are handled by `queue_name_history`.
- **Empty periods** render zero rows (generated series), not gaps, so
  charts don't interpolate across closed hours.
- **Schedule edge cases**:
  - A schedule for a paused or deleted client stops firing, and so does
    one whose last recipient is removed (status `skipped_no_recipients`).
  - Bounced external addresses are flagged after 3 hard bounces.
  - Two scheduler replicas can't double-send, because of the unique
    `report_runs` key plus `onOneServer`.
- **Large clients**: report queries read rollups. Detail exports use a
  cursor, are queued over 10k rows, and are capped at 1M rows per export.
- **Retention**: `queue_log` pruning at 90 days and `asterisk_cdr` raw at
  30 days (`CdrIngester::KEEP_RAW_DAYS`) mean facts can't be rebuilt
  from raw data older than that. Rebuild tooling refuses with a clear
  message, and rollups are the record.

## Migration / rollout

0. **Prerequisite fixes** (small, ship independently):
   - Enforce `portal.view_calls` / `view_recordings` / `view_home`.
   - Fix the portal Dashboard timezone.
   - Add the abandoned status and queue-leg extension attribution in
     `CdrIngester`, behind `facts_version`.
   - Store the logout note.
   - Open `CallLogResource` to `call_log.view_any`.
1. **Facts** (1a–1c, `OperatorStateRecorder`, heartbeat):
   - Migrations add nullable columns, so there's no rewrite of existing
     rows on deploy.
   - Backfill command `orbital:reports:backfill --from=` re-runs
     `CallFactBuilder` over `call_logs` whose raw CDR and queue_log rows
     still exist (≤30 / ≤90 days). Older calls get only what `call_logs`
     already has: no wait, no operator, `handled_by` from
     `metadata.outcome`.
   - Operator state history starts at deploy. Only logout events can be
     imported.
   - Announce to supervisors that productivity reports begin on the
     deploy date.
2. **Rollups**: build and backfill from facts. Point
   `queue_metrics_daily` at the new source. Verify that rollup totals
   equal fact counts for the backfilled window (`orbital:reports:verify`).
3. **Portal**: Overview widgets, Reports page with C1–C3, C8, Call
   History filters and export. Behind a per-client feature flag, enabled
   first for internal or template clients.
4. **Scheduling**: `report_schedules`, runs, mail, and the message summary
   (C7, using today's three message fields until plan 03).
   - Off by default per client. NCI onboarding turns on the summaries
     that match what each client receives from Amtelco today (open
     question 1).
5. **Staff**: S1–S7, S9, the operator scorecard, the Queue SL page, the
   pushed SLO gauges and the live-board tiles.
6. **Dependent reports** switch on when their plans land: C4 type split
   (03), C5 and S8 (05), C6 accurate after-hours (06), C9 rated amounts
   (09).

## Tests

Facts are pure functions of fixtures, so most of the suite runs without
telephony. It follows the README's "scripted fixtures in, event log out"
approach.

**CallFactBuilder** (unit, table-driven; fixture = CDR rows + queue_log
rows + session state):

- Queued call answered by operator after 12 s → `completed`,
  `handled_by=operator`, `queue_wait_seconds=12`, `answered_by_user_id`
  from the member interface, `extension_id` set, within SL at T=30.
- Caller abandons after 40 s → `abandoned` (not `completed`), wait 40,
  counted in abandon %. Abandons at 3 s → `short_abandoned`, excluded from
  SL.
- Queue timeout then AI answers → `queue_result=timeout_overflow`,
  `handled_by=ai`. AI unavailable → `ai_unavailable`.
- AI answers and hands off to an operator → `ai_then_operator`, one call,
  an operator leg created.
- Direct DID to extension (`Dial(PJSIP/201)`) → operator attributed via
  extension owner.
- Shared extension with two operator sessions → the one covering the
  answer time wins. Neither covers it → unattributed, not dropped.
- Late queue_log row arriving after the first ingest → a rebuild updates
  the facts and marks the bucket dirty.
- A test call (`is_trial`) is flagged `is_test` and excluded from C1.
- Queue renamed between two calls on the same day → both rows land on
  the same `call_queue_id`.

**Time and calendars** (frozen clock):

- Client in America/New_York: a call at 2026-03-08 03:30 UTC lands on
  local 2026-03-07 22:00. The spring-forward day has 23 hour buckets
  and the fall-back day 25, and daily totals match.
- Portal Overview "calls today" at 21:00 New York counts local-day calls
  only (regression for `Dashboard.php:68`).
- After-hours flag: open/closed boundaries, overnight schedule, holiday
  exception (06). With nothing configured → `null`, and C6 is hidden.

**Operator state**:

- Login → `unavailable` interval opened. Select available → closed and
  opened. Logout with reason and note → session closed, note stored,
  `UserLogoutEvent` still written.
- No heartbeat for 5 min → session closed `heartbeat_lost` at the last
  beat. The next login doesn't leave two open intervals (partial unique
  index).
- S3 totals per day equal logged-in time, and intervals spanning
  midnight split correctly.
- Multi-call desk: two overlapping 5-min calls → occupancy counts 5 min
  wall-clock if fully overlapped, `concurrent_peak=2`, AHT per call
  unaffected.

**Rollups and reports**:

- A seeded day of mixed traffic gives C1, C2, C3 and S5 numbers equal to
  hand-computed expectations. A golden-file test per report renders the
  `ReportResult` JSON.
- `orbital:reports:verify`: rollup sums equal fact counts for random
  periods (property-style).
- Zero-traffic hours appear as zero rows.
- ASA and SL in S5 for today equal the live board tile for the same
  fixture (one shared definition).

**Tenant isolation** (explicitly, per README principle):

- Portal user of client A requesting any report while authenticated, as
  a forged Livewire call with `teamId=B` → only A's data. There's no
  parameter path to B.
- A scheduled run for A executes in a queue worker with no auth user and
  contains only A's rows (guards against relying on the global scope).
  Same for a super-admin-triggered "send now".
- A schedule recipient list can't include a user of client B
  (validation). Changing `team_id` on a schedule is rejected.
- Export download URL for A's export, opened by a user of B or a signed
  URL that has expired → 403.
- Portal user without `portal.view_recordings` can't play recordings or
  export transcripts. Without `portal.view_reports` the Reports nav and
  page are hidden and return 403. These are regression tests for the
  phase 0 fix.
- Architecture test: every class in `App\Reports\Client` extends
  `ClientReport`, and no client report references `User` names or other
  teams' ids.

**Scheduling and delivery** (`travel()`, `Mail::fake()`):

- Daily summary at 07:30 local fires once per day across DST changes.
  Two scheduler invocations in the same minute → one `report_runs` row
  and one email per recipient.
- Watermark behind period end → `waiting_for_data`, then sends when data
  is complete. Still behind after 30 min → sends with the incomplete
  notice and alerts staff.
- Per-shift summary with `since_last_run` covers exactly the messages
  between the two runs: no gap, no overlap, including a message created
  in the same second as the run.
- `skip_if_empty` on vs off. A summary with zero messages still sends
  "no messages" by default.
- PHI-safe client: email contains counts and links, no caller names or
  reasons.
- CSV export: a caller name `=HYPERLINK(...)` is escaped. Timestamps
  carry an offset. XLSX opens with the expected header row.

**Audit**: viewing a report, exporting, and changing a schedule each
write one `report_access_log` row with the right params and row count.

## Open questions

1. Which reports do NCI's clients receive from Amtelco today, at what
   cadence and in what format (email, fax, PDF)? The answer decides the
   default schedules created at import and whether PDF is phase 4 or
   later. (Plan 07 question 2.)
2. Service-level targets: one platform default (e.g. 80% in 30 s) or
   per-client contractual targets? Should overflow-to-AI count as met?
3. Should clients see operator names (or first names / operator IDs) on
   messages and reports, or only "Operator" / "AI"?
4. Do operators see their own stats, and is a team leaderboard wanted
   (morale vs pressure)?
5. Fax delivery of reports: needed for any client? It would reuse plan
   05's fax transport.
6. Change `CallQueue::asteriskName()` to an id-based name
   (`t{team}_q{id}`) so renames don't change the Asterisk queue at all?
   This is cleaner than `queue_name_history`, but it's a config
   regeneration with live members to migrate.
7. Retention of facts and rollups (forever vs N years), and whether a
   deleted client's history must be purged (contract or privacy) rather
   than kept for billing.
8. Is a constrained pivot/builder wanted at all, or is export-to-Excel
   enough? Revisit after 3 months of usage data from
   `report_access_log`.
9. Multi-site or supervisor scoping: should some supervisors see only a
   subset of clients or operators in S-reports?
10. Is the staff Postgres datasource in Grafana wanted, given it bypasses
    app permissions?

## Size estimate

| Piece | Estimate |
|---|---|
| Phase 0 prerequisite fixes | 2–3 days |
| Call facts + `CallFactBuilder` + backfill + fixture suite | 1–1.5 wk |
| Operator sessions/state recorder + heartbeat + operator legs/events | 1 wk |
| Rollups, dirty buckets, watermarks, verify command | 0.5–1 wk |
| Report framework + C1–C3, C7, C8 + portal Overview/Reports/Call History filters | 1–1.5 wk |
| Exports (stream + queued, CSV/XLSX, audit) | 3 days |
| Scheduling, runs, mail templates, per-shift summary | 1 wk |
| Staff reports S1–S7, S9, scorecard, queue SL page, live-board tiles, SLO gauges | 1–1.5 wk |
| C4/C5/C6/S8 wiring once 03/05/06 exist | 2–3 days each |

**Total ≈ 6–8 weeks** for one developer for the core (phases 0–5),
plus about 1.5 weeks spread across the dependent plans. A useful first
cut is phases 0–3 plus C7 email summaries, in about 3.5–4 weeks.
