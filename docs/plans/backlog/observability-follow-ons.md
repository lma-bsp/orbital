# Plan: Observability follow-ons

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

The observability stack shipped in four pieces: metrics and dashboards,
error reporting, tracing, and alerting (`TODO.md:256-438`). Each one
left a "Remaining" list. There are also two standalone backlog items,
Horizon metrics (`TODO.md:745-748`) and portal metrics embedding
(`TODO.md:806-825`), one item that no longer applies (MinIO Console
OIDC, `TODO.md:793-805`), and one open bug: `METRICS_PER_CLIENT_LABELS=false`
may emit duplicate samples that Pushgateway rejects (`TODO.md:207-208`).

This plan collects all of them. Checking them against the code turned
up three more problems that weren't on any list:

- **Queue depth is always zero.** The collector reads the wrong Valkey
  keys, so `OrbitalQueueNotDraining` can never fire. Details are under
  Horizon below.
- **Laravel's own log lines never reach Loki.** The app logs to a file
  inside the container and Promtail only tails container stdout. Adding
  a trace id to log lines would do nothing until this is fixed.
- **Traces-to-logs is broken whatever the log format.** The Tempo
  datasource maps `service.name` to the Loki `service` label, and those
  two values never match.

## What exists today (checked in code)

### Metrics pipeline

- `orbital:collect-metrics` merges `PlatformMetricsCollector` and
  `TelephonyMetricsCollector` into one `Exposition` and PUTs it to
  Pushgateway (`app/Console/Commands/CollectMetricsCommand.php:45-80`).
  PUT replaces the whole group, so **one rejected push freezes every
  pushed series**. `OrbitalMetricsPipelineStale` would fire after five
  minutes (`docker/prometheus/rules/orbital.yml:36-37`).
- `Exposition::sample()` appends lines without deduplicating
  (`app/Services/Metrics/Exposition.php:123-135`). Two samples with the
  same name and label set render as two identical series.

### The per-client label bug (confirmed)

- `clientLabel()` returns `[]` when `metrics.per_client_labels` is false
  (`PlatformMetricsCollector.php:576-583`).
- Six loops emit one sample per team through it:
  - `orbital_calls_recent` (`:97-104`)
  - `orbital_messages_taken_recent` (`:137-144`)
  - `orbital_messages_undelivered` (`:152-159`)
  - `orbital_emails_received_recent` (`:166-173`)
  - `orbital_chat_sessions_active` (`:221-228`)
  - `orbital_messages_exchanged_recent` (`:238-245`)
- With labels off, a deployment with N active clients emits N unlabelled
  samples per family. Pushgateway rejects the whole body when it
  contains duplicate series, so turning the option off breaks every
  pushed metric. The bug is real, not just possible.
- The config docblock also says the label is `client` and that
  "platform-wide totals stay either way" (`config/metrics.php:93-101`).
  The label is actually `client_id`, and no separate total is emitted.
- Every dashboard panel that reads these families already wraps them in
  `sum()`, and no rule or panel references `client_id`. Collapsing to a
  single sample therefore changes no query.

### Horizon / queue metrics

- `queues()` (`PlatformMetricsCollector.php:400-441`) reads
  `llen($queue)`, `zcard($queue.':delayed')` and
  `zcard($queue.':reserved')` on the `default` Redis connection, with
  `$queue` = `default`, `inbound-mail`, `inbound-messages`.
  - Laravel's Redis queue stores jobs under `queues:<name>`
    (`vendor/laravel/framework/src/Illuminate/Queue/RedisQueue.php:672-675`).
  - So `orbital_queue_depth`, `_delayed` and `_reserved` read keys
    nothing writes, and are always 0.
  - That means `OrbitalQueueNotDraining` (`orbital.yml:190-202`) and the
    "Queue depth" and "Jobs reserved and delayed" panels on the
    Application dashboard (panels 20 and 21) are dead.
  - No test covers it. Nothing under `tests/` references
    `orbital_queue_depth`.
- `orbital_horizon_workload_pending` runs `zcard('horizon:recent_jobs')`
  on the `default` connection. That connection has the
  `REDIS_PREFIX` prefix (`config/database.php:152`), but Horizon swaps
  in its own `horizon:` prefix on its own connection
  (`vendor/laravel/horizon/src/Horizon.php:132-153`). The key probably
  never matches. Its name is also misleading: it counts recent jobs, not
  pending ones.
- `orbital_failed_jobs_total` is a gauge of the `failed_jobs` row count
  (`:428-433`). It goes down on retry or flush.
  - `OrbitalFailedJobsClimbing` uses `increase()` on it
    (`orbital.yml:204-216`). `increase()` treats a drop as a counter
    reset.
  - Retrying 10 jobs out of 50 therefore reads as "40 failed". The alert
    gives false positives exactly when someone is cleaning up.
- `horizon:snapshot` isn't scheduled anywhere in `routes/console.php`.
  Horizon's own Metrics tab (throughput and runtime per queue) therefore
  never fills in.
- Laravel 12 has the right primitives on `RedisQueue`: `pendingSize()`,
  `delayedSize()`, `reservedSize()` and
  `creationTimeOfOldestPendingJob()` (`RedisQueue.php:131-153,329`).
- Horizon's wait thresholds are set per queue: default 60 s,
  inbound-mail 120 s, inbound-messages 60 s (`config/horizon.php:99-105`).

### Logs and tracing

- `.env.example:313-314` sets `LOG_CHANNEL=stack`, `LOG_STACK=single`.
  The Helm configmap sets `LOG_CHANNEL: stack` and leaves `LOG_STACK`
  unset, which falls back to `single` (`config/logging.php:57`,
  `helm/orbital/templates/configmap-app-env.yaml:20`).
  - Application logs go to `storage/logs/laravel.log` inside the
    container.
  - `docker/promtail/promtail-config.yaml:25-42` tails Docker container
    logs only, and nothing in `docker/laravel/` or `docker/8.4/` tails
    the file to stdout.
- A `stderr` channel exists, with an optional formatter
  (`config/logging.php:97-106`).
- The Loki `derivedFields` regex already matches `trace_id` in either
  `key=value` or JSON form
  (`docker/grafana/provisioning/datasources/datasources.yml:40-44`).
- The Tempo datasource's `tracesToLogsV2` has `filterByTraceID: false`
  and a tag mapping `service.name` → `service`
  (`datasources.yml:64-72`).
  - PHP spans carry `service.name=orbital` (`config/observability.php`,
    `tracing.service_name`), and the worker's spans carry
    `orbital-agent-worker` (`agent-worker/observability.py:109`).
  - The Loki `service` label is the compose service name: `orbital.test`,
    `horizon`, `scheduler`, `agent-worker` (`docker-compose.yml:62,285,307,638`).
  - The generated `{service="orbital"}` selector matches no stream.
- `Tracer::traceparent()` and `hasActiveTrace()` exist
  (`app/Services/Observability/Tracer.php:270-285`). Nothing feeds the
  trace id into the log context.

### Error reporting

- `configureErrorReporting()` forces `sentry.dsn` to null unless the
  feature is enabled and has a DSN
  (`app/Providers/ObservabilityServiceProvider.php:98-145`). Scope tags
  are channel and commit only (`:376-382`).
- There is no GlitchTip overlay. `compose/` holds only `ha-*.yml` and
  `obs-tracing.yml`. The tracing overlay is the model to follow: an
  opt-in file, a bucket on SeaweedFS, HAProxy frontends, a health card.

### About page and health

- `About` (`app/Filament/Pages/About.php:48-111`) shows release, support
  and an environment table: PHP, Laravel, env, DB, cache, queue. It says
  nothing about error reporting, tracing or alert delivery. Any
  authenticated admin-panel user can open it (`:48-51`).
- `SystemHealthService` probes Tempo (optional) and Alertmanager
  reachability (`app/Services/Health/SystemHealthService.php:86-102,202-203`).
  A reachable Alertmanager with no receivers still looks healthy.

### Alerting

- Receivers are email (when `ALERT_EMAIL_TO` is set) and a generic
  webhook, rendered by `envsubst`, then `amtool check-config`
  (`docker/alertmanager/entrypoint.sh:20-86`).
- TODO says "webhook covers" Slack and PagerDuty. It doesn't. Slack
  incoming webhooks need `{"text": ...}`, PagerDuty Events v2 needs a
  `routing_key` envelope, and Alertmanager's webhook body is a fixed
  schema (`TODO.md:428-433`). Both have native Alertmanager receivers
  (`slack_configs`, `pagerduty_configs`).
- Rules exist in two copies, `docker/prometheus/rules/orbital.yml` and
  `helm/orbital/files/monitoring/orbital-rules.yml`. They're identical
  today, but nothing enforces that. The four dashboards are duplicated
  the same way under `helm/orbital/files/monitoring/dashboards/`.
- CI is `.forgejo/workflows/application-tests.yml`. It runs Pint, the
  frontend build and `composer test` on SQLite. There's no promtool,
  amtool or rules step.

### Portal metrics and Grafana

- Grafana is reached only through Laravel's auth proxy
  (`routes/web.php:150-153`, `tool:tooling.grafana`) with
  `X-WEBAUTH-USER` and `GF_AUTH_PROXY_*`, auto-role Viewer
  (`docker-compose.yml:924-949`).
- `GF_SECURITY_ALLOW_EMBEDDING` isn't set. Grafana's default sends
  `X-Frame-Options: deny`, and `GrafanaProxy::buildResponse()` passes
  upstream headers through unchanged (`app/Services/Auth/GrafanaProxy.php:171-187`).
  An iframe would be blocked today.
- Dashboards have stable uids (`orbital-app`, `orbital-telephony`,
  `orbital-infrastructure`, `orbital-livekit-ai`) and explicit panel ids.
- The admin panel registers no widgets (`AdminPanelProvider.php:95`
  `->widgets([])`), and there is no `app/Filament/Widgets/` directory.
- [IS parity plan 10](../is-parity/10-client-and-staff-reporting.md) §7
  already decides the tenant half. It says not to chart tenant data from
  Prometheus in the portal, because of rolling 24 h gauges and 15-day
  default retention. Tenant charts read Postgres rollups
  (`client_stats_hourly`) through Filament widgets instead. It
  explicitly "supersedes the tenant half of" the TODO item.

### MinIO

- MinIO was removed in the move to SeaweedFS
  (`app/Services/Bootstrap/Bootstrappers/SsoSecretsBootstrapper.php:85-91`).
- SeaweedFS's filer and master UIs are proxied behind
  `tool:tooling.seaweedfs` (`routes/web.php:160-168`).
- Stale references remain at `routes/web.php:134`,
  `app/Console/Commands/CreateOidcClient.php:13`,
  `config/telephony.php:146` ("local dev uses MinIO") and
  `docker-compose.yml:87`.

## Goal

1. Every pushed metric is valid whatever `METRICS_PER_CLIENT_LABELS` is
   set to, and `Exposition` can't render a duplicate series.
2. Queue health is real: depth, oldest-job age (wait), failures per
   queue, and Horizon worker state. These go on the Application
   dashboard, with alerts that would catch a stalled `inbound-mail`
   queue.
3. Rules and Alertmanager config are tested in CI against a real
   `promtool` and `amtool`, including rule unit tests and a check that
   every metric a rule names is actually emitted.
4. App logs reach Loki as JSON carrying `trace_id`, and traces-to-logs
   works in both directions.
5. An operator can see on the About page whether error reporting,
   tracing and alert delivery are live, and can prove it with a test
   event, all without causing a real error.
6. Native Slack and PagerDuty receivers.
7. An opt-in self-hosted GlitchTip overlay.
8. A few Grafana panels embedded in the admin dashboard for users with
   `tooling.grafana`.
9. The TODO is cleaned up. The MinIO item is closed, and the tenant
   metrics half is handed to plan 10.

Non-goals:

- Tenant-facing metrics. Plan 10 owns them, and nothing here builds
  `PrometheusQueryService` for the portal.
- Shipping a monitoring stack in the Helm chart. The chart's stated
  posture is that the cluster brings its own (`helm/orbital/values.yaml:485-495`).
- Browser (JavaScript) error reporting.
- The OpenTelemetry PECL extension.

## Design

### 1. Per-client labels: emit valid output in both modes

- Make `clientLabel()` private to one helper: `perClient(Exposition, name,
  array<teamId,int> counts, help)`.
  - With labels on, it emits one sample per team, as today.
  - With labels off, it emits **one** unlabelled sample with the sum.
  - All six loops switch to it. `orbital_messages_undelivered`'s
    grouped query feeds the same helper.
- Defence in depth in `Exposition`: track a set of
  `name + rendered label string` per family.
  - A second sample with the same key **throws** in the testing
    environment.
  - In production it logs a warning and sums gauge values. Dropping the
    duplicate would hide the bug. Throwing in production would empty the
    whole push, which is exactly the failure being fixed.
- Fix the `config/metrics.php:93-101` docblock: the label is
  `client_id`. Plan 10 supersedes the portal use, so per-client series
  now exist for super-admin "which client is noisy" queries. Keep the
  default at `true`. Six families × ~1000 clients is ~6000 series, which
  is fine.

### 2. Horizon metrics and alerting

**Collector** (`queues()` rewritten):

- Read sizes through the queue API, not hand-built keys:
  `app('queue')->connection('redis')` → `pendingSize($q)`,
  `delayedSize($q)`, `reservedSize($q)`,
  `creationTimeOfOldestPendingJob($q)`.
  - This still talks to Valkey directly, so it keeps working when
    Horizon is down, which is the property the current docblock wants.
  - It also picks up the cluster hash-tag form
    (`RedisQueue.php:683-688`) for free.
  - The queue list still comes from `horizonQueues()`.
- New and changed series, all `source: pushed`:

| Series | Labels | Meaning |
|---|---|---|
| `orbital_queue_depth` | `queue` | pending jobs (fixed) |
| `orbital_queue_delayed`, `orbital_queue_reserved` | `queue` | fixed |
| `orbital_queue_oldest_pending_age_seconds` | `queue` | now − oldest pending job's creation time; 0 when empty. **This is the wait-time signal.** |
| `orbital_failed_jobs_recent` | `queue` | `failed_jobs` rows with `failed_at` ≥ now − 1 h, grouped by `queue` |
| `orbital_failed_jobs_total` | — | kept for the stat panel; no longer alerted on |
| `orbital_horizon_up` | — | 1 if any master supervisor reports `running` (`MasterSupervisorRepository::all()`), else 0 |
| `orbital_horizon_paused` | — | 1 if any master supervisor reports `paused` |
| `orbital_horizon_processes` | `queue` | sum of worker processes assigned to the queue across supervisors (`SupervisorRepository::all()`) |

- Remove `orbital_horizon_workload_pending`. Drop it from the dashboard
  if any panel uses it. None does today.
- Use oldest-pending age rather than Horizon's `WaitTimeCalculator`.
  The calculator estimates from runtime history and process count, so it
  reads 0 when there are no workers, and no workers is the case that
  matters. Age is a direct measurement.
- Schedule `horizon:snapshot` every five minutes (`onOneServer()`,
  `withoutOverlapping()`), as Horizon's docs require. This fills
  Horizon's own Metrics tab. We don't scrape it.

**Rules** (new `orbital-queues` group, replacing the two existing queue
rules):

- `OrbitalQueueBacklogAging`: warning when
  `orbital_queue_oldest_pending_age_seconds > 600` for 5 m.
  - A per-queue override for `inbound-messages` uses `> 300`, because a
    customer waiting on a text notices.
  - Thresholds are written as label-matched alternatives in one
    expression, so `horizon.waits` and the rule stay side by side in the
    docs.
  - This is the alert that catches a stalled `inbound-mail` queue at low
    volume. The old depth > 100 rule never would.
- `OrbitalQueueNoWorkers`: critical when `orbital_queue_depth > 0 and
  on(queue) orbital_horizon_processes == 0` for 5 m.
- `OrbitalHorizonDown`: critical when `orbital_horizon_up == 0` for 5 m.
  Suppressed while `orbital_horizon_paused == 1`, which is a deliberate
  state and gets a separate `OrbitalHorizonPausedLong` warning after
  30 m.
- `OrbitalQueueNotDraining`: kept. It works once depth is fixed.
- `OrbitalFailedJobsClimbing`: rewritten as
  `sum by (queue) (orbital_failed_jobs_recent) > 10`. This drops the
  `increase()` on a gauge. The runbook text names the queue.

**Dashboard** (`orbital-app.json`, then copied to the Helm copy): a
"Queues" row with these panels:

- Oldest pending job age by queue, with threshold lines at the alert
  values.
- Failed jobs in the last hour by queue.
- Workers per queue.
- A Horizon status stat (running / paused / down).

The existing depth and reserved/delayed panels become meaningful as they
are.

### 3. Rules and Alertmanager config in CI

Add a `monitoring` job to `.forgejo/workflows/application-tests.yml`
that doesn't need Docker:

1. Download pinned `prometheus` and `alertmanager` release tarballs,
   checksum-verified, and cache them.
2. `promtool check config docker/prometheus/prometheus.yml` (with rule
   paths rewritten to the checkout) and `promtool check rules
   docker/prometheus/rules/*.yml`.
3. `promtool test rules tests/monitoring/*.test.yml`: one unit test file
   per rule group, with synthetic series. Each alert gets at least:
   - a fires case
   - a doesn't-fire case
   - for `OrbitalCallsInProgressStuck`, `OrbitalQueueBacklogAging` and
     `OrbitalFailedJobsClimbing`, the boundary that motivated the
     expression (a floor that dips, an empty queue, a retry that lowers
     the row count)
4. Render `alertmanager.yml.tmpl` through `entrypoint.sh`'s render half
   for each path: unconfigured, SMTP without auth, SMTP with auth,
   webhook, and later Slack and PagerDuty. Run `amtool check-config` on
   each.
   - Splitting the script into `render.sh` (pure) and `entrypoint.sh`
     (render + check + exec) makes this testable without starting the
     binary.
   - Add an `amtool config routes test` assertion per severity.
5. **Metric-name cross-check.** Add a PHPUnit feature test that runs
   `orbital:collect-metrics --print` against seeded data (AMI faked, as
   the telephony tests already do) and writes the exposition to a
   fixture. A small script then extracts every `orbital_*` name from the
   rule expressions and fails if any is missing from the fixture or from
   `HttpMetricsStore`'s known names. This is the check the alerting
   TODO says was done by hand.
6. `diff -r` the compose and Helm copies of the rules and the four
   dashboards, and fail on drift.

### 4. Logs to Loki with trace ids

Step 1 is a prerequisite: get the logs there.

- Default `LOG_STACK=single,stderr` in `.env.example`, and set
  `LOG_STACK: stderr` in the Helm configmap. The pod filesystem is
  ephemeral, so the file is useless there.
- Set `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`. JSON lets
  Loki's `| json` parse `trace_id`, level and channel without regex.
- supervisord already sends program stderr to the container's stderr
  (`docker/laravel/supervisord.conf:20-31`). The production image sets
  `catch_workers_output = yes` (`Dockerfile:151-152`), so FPM worker
  output reaches the container too. FPM workers, Horizon and the
  scheduler all arrive.
- Privacy: log lines now leave the container and land in Loki. Loki is
  in-house and already holds Asterisk output, which contains caller
  numbers. Note this in `docs/admin/observability.md` alongside the
  existing "read this before you enable either" section. Run the
  `Scrubber` over the log `context` array in the processor below, so the
  structured part doesn't carry `caller_phone` and the like. Message
  strings aren't rewritten.

Step 2 is the processor.

- Add `App\Logging\TraceContextProcessor`, a Monolog processor added to
  `stderr` and `single` through `tap`.
- When `Tracer::hasActiveTrace()` and the current span context is
  **sampled**, it adds `extra.trace_id` and `extra.span_id`.
  - Unsampled requests get no id. A link to a trace Tempo never received
    looks broken, and that is the same failure the current
    `filterByTraceID: false` comment warns about.
  - It costs nothing when tracing is off: `enabled()` is checked first.
- Agent worker: a `logging.Filter` in `agent-worker/observability.py`
  adds `trace_id` from `opentelemetry.trace.get_current_span()` under
  the same sampled rule. Its log format adds `trace_id=%(trace_id)s`.

Step 3 fixes the datasource.

- Replace the tag mapping with a custom query:
  `customQuery: true`,
  `query: '{stack="orbital"} | json | trace_id="${__trace.traceId}"'`,
  `filterByTraceID: true`.
  - `stack="orbital"` is the static label Promtail already sets
    (`promtail-config.yaml:40-42`).
  - This works for every process whatever the service naming.
- Keep `derivedFields` as it is. The regex already matches the JSON
  form.
- Helm: the in-cluster stack is orbital-setup's. Document the datasource
  snippet in `docs/admin/observability.md#kubernetes` instead of
  changing the chart.

### 5. Observability status on the About page

Add a new "Observability" card, visible to super-admins only. Endpoint
hostnames are infrastructure detail, and the page is open to every
admin-panel user.

- **Error reporting**:
  - Off / On.
  - DSN host and project id only, never the key.
  - Environment and sample rate.
  - Last event sent: a timestamp written to cache by the provider's
    `before_send` when it returns an event.
  - Action **Send test event**: runs `\Sentry\captureMessage('Orbital
    test event', Severity::info())` inside a scope tagged
    `orbital.test=true`, then flushes and shows the returned event id.
    A null id means the client discarded it, and the card shows why
    (disabled, no DSN, sampled out).
- **Tracing**:
  - Off / On.
  - Endpoint host and sample ratio.
  - Exporter state: the `guard()` failure path in `Tracer` (`:419-435`)
    records `tracing:last_export_error` with a timestamp in cache, so a
    stood-down exporter in any process becomes visible.
  - Action **Send test trace**: starts a root span with a forced-sampled
    parent (`traceparent` with flag `01`, the path already tested),
    records one child, flushes, and links to
    `/admin/grafana/explore?...traceId=` when `tooling.grafana` is held.
- **Alert delivery**:
  - Reads Alertmanager `GET /api/v2/status` (same host list as the
    health probe) and lists receivers that have integrations: email,
    webhook, slack, pagerduty. Says "no destination configured" plainly.
  - Action **Send test alert**: `POST /api/v2/alerts` with
    `alertname=OrbitalTestAlert`, `severity=warning`, a 5-minute
    `endsAt`.
- Put each card's data-gathering in `App\Services\Observability\StatusReport`
  so the health page can reuse it later. The page stays thin.
- Test actions are rate-limited (one per minute per user) and audited in
  the log.

### 6. Slack and PagerDuty receivers

- New env pairs in `entrypoint.sh`, each rendered only when set, the
  same way email works:
  - `ALERT_SLACK_WEBHOOK_URL` and optional `ALERT_SLACK_CHANNEL` →
    `slack_configs` with a title and text template:
    `[{{ .Status | toUpper }}] {{ .CommonLabels.alertname }}`, then
    summary, description and runbook per alert.
  - `ALERT_PAGERDUTY_ROUTING_KEY` → `pagerduty_configs` (Events v2) with
    `severity` mapped from the label.
- Write secrets to files (`/etc/alertmanager/secrets/slack_url`,
  `pagerduty_key`) and reference them with `api_url_file` and
  `routing_key_file`, so they never appear in the rendered YAML or in
  `GET /api/v2/status` (which the About page reads).
  - Email's existing inline `auth_password` gets the same treatment
    (`auth_password_file`).
- Routing: PagerDuty gets `severity="critical"` only, through a child
  route with `continue: true` after the default receiver. Slack and
  email get everything. A warning that pages someone at 3 am teaches
  them to ignore the pager.
- Helm: add the values keys to the documented orbital-setup secret list.
  The chart itself ships no Alertmanager.
- Pin `prom/alertmanager` in `docker/alertmanager/Dockerfile` to a
  version instead of `:latest`, so `slack_configs`/`*_file` support
  can't regress under us.

### 7. Self-hosted GlitchTip overlay

- `compose/obs-errors.yml`, opt-in, documented exactly like
  `compose/obs-tracing.yml`. It holds `glitchtip-web` and
  `glitchtip-worker`, from the same pinned `glitchtip/glitchtip` image.
  - **Database**: a `glitchtip` database on the existing cluster through
    HAProxy, created by a one-shot `glitchtip-init`, the same way
    Grafana uses a `grafana` database (`docker-compose.yml:954-959`).
  - **Cache and queue**: a dedicated Valkey logical DB on the existing
    Valkey (`VALKEY_URL=redis://valkey:6379/5`). If the pinned GlitchTip
    version can run its queue on Postgres alone, prefer that and drop
    the dependency (open question 3).
  - **File uploads** (attachments, sourcemaps; little used for PHP): a
    SeaweedFS bucket `glitchtip-uploads`, using the tracing overlay's
    "create the bucket first" note.
  - **Mail**: the platform relay (`EMAIL_URL` from the same `MAIL_*`
    env).
- Login: GlitchTip supports OpenID Connect through django-allauth.
  - Register it as an OIDC client of Orbital's Passport provider by
    adding a `glitchtip` entry to `SsoSecretsBootstrapper::OIDC_CLIENTS`
    (`SsoSecretsBootstrapper.php:78-92`, pgAdmin shape).
  - Gate it with a new `tooling.glitchtip` permission and a Control
    Panels nav item.
- Exposure: HAProxy `glitchtip_fe` for the UI behind admin auth, and the
  ingest endpoint on the internal network only.
  - The app's DSN points at `http://<key>@glitchtip-web:8000/<id>`.
  - Nothing reports in from the internet, because browser reporting is a
    non-goal.
- Health: an optional `glitchtip` probe in `SystemHealthService` (like
  Tempo), plus a Prometheus scrape of its `/_health/`, if it exposes
  one.
- First run: `orbital:glitchtip:bootstrap` creates the organisation and
  project through GlitchTip's API using a bootstrap token. It prints the
  DSN and offers to store it in Settings → Platform → Error Reporting.
  This saves the operator from copying a DSN by hand.

### 8. Admin-side Grafana panel embedding

- Grafana: set `GF_SECURITY_ALLOW_EMBEDDING=true` in compose.
  - `GrafanaProxy::buildResponse()` then sets
    `Content-Security-Policy: frame-ancestors 'self'` on every proxied
    response, so the only page that can frame Grafana is Orbital's own,
    same-origin through the proxy.
  - The raw `127.0.0.1:13000` port stays unframeable from elsewhere,
    since it's loopback-only.
- `App\Filament\Widgets\GrafanaPanelWidget` is a configurable base
  class.
  - Properties: `dashboardUid`, `slug`, `panelId`, `from` (default
    `now-6h`), `height`, `refresh` (minimum `1m`).
  - It renders
    `<iframe loading="lazy" src="{{ route('admin.grafana.forward', ['path' => "d-solo/$uid/$slug"]) }}?panelId=..&from=..&to=now&theme=..&refresh=..">`.
  - `theme` follows Filament's dark mode.
  - `canView()` uses `AdminPanelProvider::userCanAccessTool('tooling.grafana')`.
    The permission is the gate, not super-admin. Grafana itself trusts
    the proxy as Viewer.
- First widgets on the admin `Dashboard`, registered in
  `AdminPanelProvider` `->widgets([...])`. Keep it to four, because each
  iframe boots Grafana's JS and every request goes through a PHP-FPM
  worker in the proxy:
  - Requests/sec (`orbital-app` panel 2)
  - Queue health (the new oldest-age panel)
  - Active channels (`orbital-telephony` panel 2)
  - Targets down (`orbital-infrastructure` panel 2)
- Hidden when Grafana isn't reachable (cached probe from
  `SystemHealthService`), so a Helm install without `grafanaUrl` doesn't
  show four broken frames.
- A test asserts that every `(uid, panelId)` a widget references exists
  in `docker/grafana/provisioning/dashboards/orbital/*.json`, so a
  dashboard edit can't silently orphan a widget.

### 9. Tenant-facing metrics: handed to plan 10

There is no Prometheus-backed portal widget. Plan 10 §6 builds the
portal Overview from `client_stats_hourly`, and §7 argues why: rolling
24 h gauges and 15-day retention can't answer period questions, and two
sources would disagree.

What this plan owns:

- Rewording `TODO.md:806-825` so the tenant bullet points at plan 10.
- The admin-side embedding above.
- Keeping `client_id` labels as a staff diagnostic.

If plan 10's pushed SLO gauges (`orbital_queue_service_level_ratio`,
`orbital_messages_unacked_overdue`) land, their rules join the CI tests
in §3 automatically.

### 10. MinIO Console OIDC: close

The target no longer exists. Remove the TODO item and fix the four stale
comments listed above. If SeaweedFS ever needs SSO, the bootstrapper
comment already says how.

## Edge cases

- **Labels off with zero clients active**: the helper emits a single
  `0` sample, not no sample, so `sum()` panels show 0 rather than "No
  data".
- **Duplicate guard in production**: summing duplicate gauges is right
  for count-by-team families. Any other family that hits it is a bug,
  and the warning names the family.
- **Queue name with a hash tag on a Valkey cluster**: handled by the
  queue API, as described in Design §2. Covered by a test using a
  `{tag}` queue name.
- **Horizon paused for deploy**: `orbital_horizon_paused` suppresses
  `OrbitalHorizonDown`. Backlog age still rises and alerts after 10 m,
  which is right, because a deploy that leaves Horizon paused is an
  incident.
- **Delayed jobs** aren't pending and don't count towards oldest age.
  Long `release()` delays don't trip the backlog alert.
- **Clock skew** between the scheduler and Valkey: age comes from the
  job payload's `createdAt`, written by the dispatching app host, and
  is compared with the collector's clock. Clamp negative values to 0.
- **Failed-jobs window after `queue:retry all`**: rows vanish and
  `orbital_failed_jobs_recent` drops. That's the intended behaviour, not
  a false alert.
- **Unsampled requests** carry no trace id in logs, by design. The About
  card says "trace ids appear on sampled requests only (5%)", so nobody
  files that as a bug.
- **Log volume**: JSON on stderr in addition to the file roughly doubles
  log I/O on compose. Keeping `single` in the compose default is for
  `tail -f` habits. Drop it if disk matters.
- **Test event with sampling < 1.0**: `captureMessage` obeys
  `sample_rate`. The test action temporarily sets the client's sample
  rate to 1.0 for that one call.
- **Alertmanager status endpoint on a pair**: query both nodes and show
  a mismatch (different receivers) as a warning, because it means one
  node was started with different env.
- **Grafana unreachable inside an iframe**: the proxy returns 502 HTML.
  The widget overlays "Grafana unavailable" when the probe says down,
  rather than showing a proxy error page.
- **PagerDuty without `ALERT_EMAIL_TO`**: valid. The default receiver
  may have no integrations, and critical alerts still page.

## Migration and rollout

1. **Bug fixes**, shipped alone: per-client labels + `Exposition`
   guard; queue key fix. Release notes say queue panels and
   `OrbitalQueueNotDraining` were dead before this release.
2. **CI**: promtool / amtool job, rule unit tests, metric cross-check,
   compose/Helm drift check. Land this before step 3, so the new rules
   are tested from day one.
3. **Horizon metrics**: collector additions, `horizon:snapshot`, the
   new rule group (old two rules replaced in the same change),
   dashboard row, Helm copies.
4. **Logs**: stderr JSON default, processor, worker filter, datasource
   switch. Existing installs need `LOG_STACK` changed in `.env`. The
   upgrade notes say so, and the About card shows "app logs not shipped
   to Loki" when `stderr` isn't in the stack.
5. **About page status** + test actions.
6. **Slack / PagerDuty** receivers, `*_file` secrets, pinned
   Alertmanager.
7. **Grafana embedding** + `ALLOW_EMBEDDING` + CSP header.
8. **GlitchTip overlay** + OIDC client + bootstrap command.
9. **TODO cleanup**: close the MinIO item and the per-client bug, point
   the portal item at plan 10, and strike the "Remaining" bullets as
   each step lands.

There are no database migrations.

## Tests

- **Exposition**: a duplicate name+labels sample throws under testing,
  and is summed with a logged warning otherwise. Distinct label sets are
  unaffected.
- **PlatformMetricsCollector**:
  - With `per_client_labels=false` and three teams with calls, there is
    exactly one `orbital_calls_recent` line, and its value is the sum.
    Repeat for each of the six families.
  - With labels on, the output is unchanged from today.
- **Queue metrics**:
  - Fake a `RedisQueue` connection returning known pending, delayed,
    reserved and oldest-time values, and assert the four series per
    queue.
  - A regression test asserts the collector calls the queue API and
    never `llen` on a bare queue name.
  - Empty queue → age 0.
  - Seeded `failed_jobs` rows inside and outside the hour → correct
    per-queue counts.
- **Horizon state**: fake master supervisor and supervisor repositories
  for running, paused and none.
- **promtool rule tests** (CI): one file per group, covering fires and
  doesn't-fire for every alert. Inhibition is tested with
  `amtool config routes test` plus a rendered config.
- **Metric cross-check** (CI): it fails when a rule names a metric the
  collectors don't emit. Prove it with a deliberately misspelled
  fixture rule in the test's own setup.
- **TraceContextProcessor**:
  - Sampled active trace → `trace_id`/`span_id` present and
    32/16 hex.
  - Unsampled or tracing off → absent.
  - Context keys like `caller_phone` are redacted by the scrubber.
- **Datasource YAML**: a test parses `datasources.yml` and asserts
  `filterByTraceID: true` and a query using `${__trace.traceId}`. Cheap,
  and stops someone reverting half of it.
- **About page**:
  - The card is hidden from non-super-admins.
  - DSN key never rendered.
  - Test event with reporting off → "disabled" message, no client call.
  - Test trace with tracing on and an in-memory exporter → one exported
    root span whose trace id the action returns.
- **Alertmanager render** (shell, CI): each env combination renders to
  YAML that `amtool check-config` accepts. Slack and PagerDuty secrets
  appear only in the secret files, never in the YAML.
- **Grafana widget**:
  - `canView()` follows `tooling.grafana`.
  - The src is built from the proxy route with the expected
    `d-solo/<uid>/<slug>` and `panelId`.
  - Every referenced panel exists in the dashboard JSON.
  - The proxy response carries `frame-ancestors 'self'`.
- **GlitchTip overlay**: `docker compose config` with the overlay
  validates in CI (no daemon needed). The bootstrap command is tested
  against `Http::fake()`.

## Open questions

1. Queue backlog thresholds: are 10 min (5 min for texts) right for
   NCI's volumes, or should they track `horizon.waits` × N directly?
2. Should PagerDuty or Slack be configurable from Settings → Platform
   instead of env? The alerting design deliberately keeps delivery
   independent of the app being monitored, and env is consistent with
   that. A UI would need to write a file Alertmanager reads, which
   brings back the coupling.
3. Which GlitchTip version to pin? Does it still need Valkey, or can it
   queue on Postgres? This decides whether the overlay touches Valkey at
   all.
4. Should GlitchTip go in `compose/ha-obs.yml` as a pair? It's
   stateless apart from Postgres, but error reporting is optional and a
   single instance may be enough.
5. Is `single` still wanted in the compose `LOG_STACK` once stderr
   ships to Loki?
6. Should the About card's test actions be limited to super-admins, or
   to a narrower `observability.test` permission for on-call staff?

## Size

| Piece | Estimate |
|---|---|
| Per-client label fix + Exposition guard | 0.5 day |
| Queue key fix, Horizon series, `horizon:snapshot`, rules, dashboard row | 2–3 days |
| CI: promtool/amtool job, rule unit tests, metric cross-check, drift check | 2 days |
| Logs to Loki, trace processor, worker filter, datasource fix | 1.5–2 days |
| About page status card + three test actions | 1.5 days |
| Slack / PagerDuty receivers, secret files, pinned image | 1 day |
| Grafana embedding (widget, CSP, four panels, tests) | 1.5 days |
| GlitchTip overlay, OIDC client, bootstrap command, docs | 2 days |
| MinIO cleanup + TODO edits | 0.5 day |

**Total ≈ 2.5–3 weeks** for one developer. Steps 1–2 (the two bug fixes
and CI) take about 3 days and are worth doing first on their own: they
turn two dead alerts back on and stop the next one from shipping dead.
