# Plan: Remaining HA gaps and a disaster-recovery site

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

TODO.md, "HA / DR / BC — active-active horizontal scaling + DR site"
(TODO.md:835-950), asks for two things: active/active inside one site,
so a node can be taken out for maintenance without customers noticing,
and a separate DR site, kept in sync, that can be promoted if the
primary site is lost. It also asks for two admin surfaces: a node
health and maintenance-mode page, and a heavily gated "DR failover"
action with a runbook.

`docs/roadmap.md` lists single-site active/active HA as shipped and
the DR site as Planned. Most of the HA half is done. The DR half has
some building blocks but no design. This plan has three parts:

1. Check the TODO's HA list against the code.
2. Plan the HA gaps that are still open.
3. Design the DR site, for both docker-compose and Helm.

## What exists today

### HA: the TODO's list against the code

| TODO bullet | Status | Evidence |
|---|---|---|
| Kamailio dispatcher in front of Asterisk, health-checked | **Done** | `docker/kamailio/kamailio.cfg:92-158` (dispatcher, `ds_probing_mode 1`), `:409-415` (`ds_select_dst`, 503 when none). The list is generated from `AsteriskBackend` rows (`app/Services/Telephony/AsteriskDispatcherWriter.php`). Helm edge VMs pull it from `GET /api/edge/dispatcher` (`routes/api.php:179`, `EdgeDispatcherController`). |
| Two Kamailios behind a VRRP floating IP (keepalived) | **Partial** | `kamailio-2` exists (`compose/ha-telephony.yml:128`). Keepalived is not shipped. It appears only in comments (`compose/ha-telephony.yml:112`, `compose/ha-edge.yml:6`). On-prem Helm gets a floating IP from `orbital-setup`, outside this repo (`helm/orbital/values-onprem-site.yaml:1-11`). |
| Two or more Asterisks on shared ARA realtime | **Done** | `asterisk-2` (`compose/ha-telephony.yml:31`). Helm `asterisk.replicas: 2` on VKE and on-prem. Add/remove nodes at `/admin/asterisk-backends`. |
| Laravel N replicas behind a load balancer | **Done** | `orbital.test-2` (`compose/ha-app.yml:22`), Helm `laravel.replicas: 2`. |
| Multiple Horizon supervisors | **Gap** | Compose has one `horizon` (`docker-compose.yml:307`) and no `horizon-2` in `compose/ha-app.yml`. Helm defaults `horizon.replicas: 1` (`helm/orbital/values.yaml:81`), and VKE keeps 1 (`values-vultr-vke.yaml:134`). The HA doc says Kubernetes should run 2+, but nothing sets that. |
| Postgres primary + replicas, Patroni + etcd | **Done (compose) / gap (Helm)** | Three Patroni nodes and three etcd (`compose/ha-data.yml:88-300`), sync standby (`docker/patroni/patroni.yml.tmpl:46-48`), Barman (`:155`). Helm in-cluster is a single replica (`templates/postgres-statefulset.yaml:24`). On-prem relies on Longhorn rescheduling the pod. VKE uses managed PG. |
| Valkey Sentinel | **Done (compose) / gap (Helm)** | Three Valkey and three Sentinel (`compose/ha-data.yml:316-415`). Helm in-cluster is a single replica (`templates/valkey-statefulset.yaml:20`). |
| LiveKit distributed via Redis | **Done, no drain** | `livekit-2` (`compose/ha-telephony.yml:80`). No drain control (`docs/admin/high-availability.md`, "Known gaps"). |
| Agent worker horizontal | **Done** | `agent-worker-2` (`compose/ha-app.yml:77`), Helm replicas. |
| Object store distributed | **Done (compose) / gap (Helm)** | SeaweedFS with 3 masters, 2 volumes and 2 filers (`compose/ha-data.yml:416-570`). Helm in-cluster is a single `weed server` pod (`templates/seaweedfs-statefulset.yaml:27`). **Risk:** filer metadata lives in Valkey db 2 (`docker/seaweed-ha/filer.toml`). Losing all of Valkey loses the path-to-blob map. The HA doc calls this "treat as restore S3 from off-site". |
| Haraka horizontal | **Done** | `haraka-2` (`compose/ha-app.yml:113`). |
| Observability per site | **Done** | `compose/ha-obs.yml` (Grafana, Prometheus, Loki, Alertmanager and Tempo pairs). |
| Scheduler safe with more than one replica | **Done** | Tasks in `routes/console.php` carry `->onOneServer()`. Compose and Helm both run one replica. |
| Node health + maintenance-mode page | **Partial** | `/admin/failover` (`app/Filament/Pages/FailoverCentral.php`): Patroni, Sentinel, SeaweedFS and HAProxy health, Patroni switchover, Sentinel failover, rtpengine drain. `/admin/sip-proxy` (`app/Filament/Pages/SipProxy.php`): Asterisk drain/activate/disable via `AsteriskDrainService` (Kamailio `dp` state plus HAProxy `asterisk_wss_be` disable), with per-node channel and contact counts from `AsteriskClusterActivity`, and rtpengine drain. All actions are audited to `failover_audit_logs`. **Missing:** drain for app nodes (nginx-tls upstream), LiveKit drain, SeaweedFS volume drain, Patroni/Sentinel pause toggles, a single list of nodes per tier, and a link to the system health dashboard. |

### DR: building blocks that already exist

- **Off-site WAL.** Barman mirrors every WAL to `PRIMARY_S3_*`. If
  `BARMAN_OFFSITE_S3_*` is set, it also mirrors to a second destination
  (`compose/ha-data.yml:165-182`, `docker/barman/entrypoint.sh:135-144`).
  Patroni's `restore_command` already uses `barman-wal-restore`
  (`docker/patroni/patroni.yml.tmpl:84`). The permanent `barman_streaming`
  slot is declared in DCS (`:55-56`).
- **Off-box logical backups.** `orbital:backup` / `orbital:restore`,
  encrypted, with an APP_KEY fingerprint in the manifest and staleness
  alerts (TODO.md "Configuration + data backups",
  `docs/admin/backups.md`). Object storage is **not** backed up (same
  section).
- **Config is derived from the DB.** `orbital:generate-config {--push}`
  (`app/Console/Commands/GenerateTelephonyConfig.php:12-14`), the
  `asterisk` restart handler (`app/Services/Settings/ServiceRestartCatalog.php`),
  `orbital:bootstrap` (bucket provisioning and so on).
- **Per-deployment pinning of settings.** `ORBITAL_MANAGED_SETTINGS`
  keeps chosen platform settings at their environment value even when a
  DB row exists (`SettingsRegistry::isManagedByDeployment`,
  `app/Services/Settings/SettingsRegistry.php:1109`). A DR site will need this.
- **Failover tier modes** (`config/failover-tiers.php`) and the audit
  table (`failover_audit_logs`).
- **Tenant DIDs carry a label** such as "Disaster Recovery"
  (`database/migrations/2026_04_11_100009_create_tenant_dids_table.php:40`).
  Only the label exists. Nothing acts on it.
- **Trunks** (`sip_trunks`) record `host`, `register` and so on. There
  is no field for DR or a failover destination
  (`database/migrations/2026_04_11_100001_create_sip_trunks_table.php:13-34`).

There are no standby or promotion mechanics, no site identity, no
object-store cross-site sync, and no drills.

## Goal

**HA:** close the gaps in the table above, so that any single node in
the primary site can be drained and restarted from the admin panel,
or by a documented one-liner where an admin page would be overkill,
with no customer-facing outage. This applies to both compose and Helm.

**DR:** a second, single-stack site in another location.

- Target RPO ≤ 60 s for the database and ≤ 15 min for objects, while
  the replication links are healthy.
- Target RTO ≤ 30 min from the decision to promote until calls flow
  through DR.
- Promotion is one gated action, or one CLI command, that follows a
  scripted, logged sequence. It is never automatic. Losing a whole
  site is rare, and a split brain is worse than a short wait.
- It is exercised by an automated monthly drill that does not
  interrupt replication, and by a real cutover twice a year.
- The same compose files and chart, with different runtime config.

Out of scope: active/active across sites, moving calls mid-call
between sites, and multi-region Postgres writes.

## Design, part 1: remaining HA gaps

In priority order. Each item is small and can ship on its own.

1. **Horizon redundancy.** Add `horizon-2` to `compose/ha-app.yml`.
   Default Helm `horizon.replicas: 2` in the VKE and on-prem values, with
   a PodDisruptionBudget (`templates/poddisruptionbudgets.yaml` already
   exists). Leave the scheduler at 1. It is cheap to respawn, and
   `onOneServer` already makes 2 safe.
2. **SeaweedFS filer metadata off Valkey.** Move the filer store to
   Postgres (SeaweedFS's `postgres2` store) on the Patroni cluster,
   through HAProxy `pgsql_rw_be`. Valkey then holds only data that
   can be lost (sessions, cache, queues), which the DR design below
   depends on. Migration is `weed shell` `fs.meta.save` from the old
   store, then `fs.meta.load` into the new one, during a maintenance
   window. Rehearse it on staging.
3. **Keepalived for compose production.** Ship
   `compose/ha-keepalived.yml` (host-network keepalived for the Kamailio
   SIP VIP, the nginx-tls public VIP and the HAProxy internal VIP) with
   `track_script` health checks. It is opt-in, because docker bridge
   networks in dev don't carry VRRP. The rtpengine edge plan's
   Phase 3 ([rtpengine-edge.md](../rtpengine-edge.md#phase-3--activeactive-edge-via-cluster-valkey))
   makes the edge active/active over DMQ and the cluster Valkey, which
   removes the need for the Kamailio VIP. Don't build both. If Phase 3
   is scheduled, keepalived covers only nginx-tls and HAProxy.
4. **Helm data-tier HA.** In-cluster Postgres and Valkey stay single
   replica for "fresh install just works". Add an opt-in
   `postgres.inCluster.ha` that deploys a Patroni StatefulSet (3 pods,
   Kubernetes DCS through Patroni's `kubernetes` mode, so no etcd), and
   `valkey.inCluster.ha` with Sentinel. On VKE, managed services remain
   the answer. Large: own PR, own staging soak.
5. **Node maintenance page.** Extend `/admin/failover` instead of
   adding another page:
   - A "Nodes" tab listing every backend per tier in a single list:
     Asterisk (from `AsteriskBackend` + `AsteriskClusterActivity`),
     rtpengine, HAProxy servers by backend, nginx-tls upstreams, LiveKit
     nodes, SeaweedFS volumes, Patroni members and Valkey nodes. Each
     row shows state, live load and a link to the action that drains it.
   - **App node drain.** The HAProxy-side part already exists
     (`HAProxyStatsClient::disableServer`). Add an `orbital_app_be` on
     HAProxy in front of the app replicas, or have nginx-tls read an
     upstream state file. Reuse the Asterisk drain lifecycle: drain,
     wait for zero in-flight requests and WebSocket connections, safe
     to restart, activate.
   - **LiveKit drain.** Stop routing new rooms to a node (LiveKit node
     selector / `region` sysload limit) and show its room count. Once
     the count reaches zero, it is safe to restart.
   - **Pause toggles.** `patronictl pause` / `resume` through the Patroni
     REST API (`PATCH /config {"pause": true}`), and the Sentinel
     equivalent. Both are listed as gaps in
     `docs/admin/high-availability.md`.
   - **SeaweedFS volume drain:** `volume.mark -readonly` plus
     `volume.evacuate` from `weed shell`, behind a typed confirmation.
   - A link to the system health dashboard.

## Design, part 2: the DR site

### Topology

```
PRIMARY SITE (active)                       DR SITE (standby)
──────────────────────                      ──────────────────
edge: Kamailio+rtpengine (public IPs A)     edge: Kamailio+rtpengine (public IPs B), idle
app/telephony tier (running)                app/telephony: stopped, or "standby console" only
Patroni cluster  ─── streaming WAL ───────► Patroni standby_cluster (read-only)
   └─ Barman → off-site S3 (WAL + base) ──► (restore_command fallback)
SeaweedFS        ─── weed filer.sync ─────► SeaweedFS (or provider CRR when external S3)
Valkey (local)                              Valkey (local, empty, NOT replicated)
```

The DR site runs the same compose files or chart. A new variable,
`ORBITAL_SITE` (`primary` | `dr`), together with
`ORBITAL_SITE_ROLE` (`active` | `standby`), decides what runs.

### Site identity: what must not replicate blindly

The replicated DB carries the primary's view of its own
infrastructure. Promoting it unchanged would leave DR's app dialing the
primary's Asterisk hosts. Two fixes:

- **Site-scoped rows.** Add a `site` column (default `primary`) to
  `asterisk_backends` and `rtpengine_nodes`, and to any later node
  registry such as LiveKit nodes. `AsteriskDispatcherWriter`,
  `AsteriskPeerResolver`, FailoverCentral and SipProxy filter on
  `config('orbital.site')`. DR's rows are created from the primary's
  admin panel ahead of time and reach DR through replication. Helm's
  `orbital:sync-asterisk-backends` (pod discovery) writes rows with its
  own site value.
- **Site-scoped settings.** Add a `site_scoped: true` flag to registry
  entries whose values are per site: `app.url`,
  `telephony.asterisk.wss_url`, `telephony.asterisk.sip_domain`,
  `telephony.livekit.local.url`, `services.agent_worker.url`,
  `broadcasting.reverb.host`, `services.ollama.url`. Store DR values
  as `dr:<key>` rows, edited on the primary under Platform Settings
  in a "DR site" column. `RuntimeConfigOverrideProvider` prefers
  `<site>:<key>` over `<key>` for flagged keys. The values replicate,
  so they are already in place on promotion. `ORBITAL_MANAGED_SETTINGS`
  still wins for anything the DR deployment pins in env.

### Postgres

- **Mechanism: Patroni `standby_cluster`.** The DR site runs its own
  Patroni cluster (compose: the same `ha-data.yml`, with its own etcd;
  Helm: the opt-in Patroni from HA item 4, or a single PG pod) whose
  bootstrap config has:
  ```yaml
  standby_cluster:
    host: <primary pgsql_rw VIP or replication endpoint>
    port: 5432
    primary_slot_name: dr_site
    create_replica_methods: [basebackup, barman_cloud]
    restore_command: barman-cloud-wal-restore … <offsite bucket> …
  ```
  The DR "standby leader" streams from the primary and falls back to the
  off-site WAL archive (the Barman offsite mirror that already exists)
  when the link drops. Its own replicas cascade from it.
- On the primary, add a permanent `dr_site` slot to the DCS `slots`
  next to `barman_streaming` (`patroni.yml.tmpl:55-56`), so WAL is kept
  for DR across leader changes. Add a **slot size guard**:
  `max_slot_wal_keep_size` (for example 50 GB). A dead DR link must not
  fill the primary's disk. If the slot is invalidated, the standby
  falls back to the WAL archive, and if that has a gap, it gets
  re-cloned.
- **Replication transport.** Postgres TLS (`hostssl replication` in
  `pg_hba`) over a site-to-site VPN or WireGuard. The replication user
  is separate from Barman's.
- **Helm with managed Postgres (VKE).** Use the provider's cross-region
  read replica and its promote API. The promote step in the runbook
  becomes "call the provider", and the plan's site abstraction stays
  the same.
- **Promotion:** `patronictl edit-config --set standby_cluster=null`.
  Patroni promotes the standby leader, and its replicas follow.

### Object storage

- **SeaweedFS ↔ SeaweedFS.** Run `weed filer.sync -a <primary filer>
  -b <dr filer> -isActivePassive` as a service on the DR site. It
  replicates data and metadata into DR's own filer store. Its
  checkpoint is in the target filer, so a restart resumes. Covers every
  bucket: app, `loki-chunks`, `tempo-traces`, `orbital-backups`. Lag
  metric: filer.sync's offset compared with the source filer's latest
  timestamp, exported as `orbital_dr_object_sync_lag_seconds`.
- **External S3**, after [pluggable backends](pluggable-backends.md):
  use the provider's cross-region replication (AWS CRR, R2 does not
  support it, and so on), or `rclone sync` on a schedule as a fallback.
  This is the main reason the TODO wants pluggable backends to land
  first. "Point DR at the replica bucket" then becomes a settings value
  (`dr:storage.external.*`) rather than new plumbing.
- **Not replicated: local spools.** Recording-daemon and MixMonitor
  files waiting for `UploadCallRecordingJob`, and voicemail spool WAVs
  (`docker-compose.yml:83-100`), live on the primary's nodes. If the
  site is lost, recordings not yet uploaded are lost too. Write it in
  the runbook, and count them in the drill report (the CallLog has a
  recording flag but no `CallRecording` row).
- **Compose prompts volume.** `asterisk-prompts` is a local named
  volume on compose, so DR doesn't have it. On promotion, re-render every
  disclosure and AI-fallback prompt (`RenderDisclosurePromptJob`,
  `RenderAiFallbackPromptJob`) as part of the sequence. On Helm,
  prompts are in object storage and replicate.

### Valkey: not replicated, and jobs must survive that

Promotion starts DR with an empty Valkey. That means everyone is
logged out, the cache is cold, Horizon queues are empty and the
scheduler locks are clear. With HA item 2 done, no object-store metadata
is lost. Rather than trying to replicate queues, make the DB the
source of truth for any work that matters, and add a reconciler:

- `orbital:dr:requeue` (also run in the promote sequence) finds rows in
  a non-terminal state and dispatches their jobs again:
  `email_messages.routing_status = pending`
  (`2026_04_15_110000_create_email_messages_table.php:60-61`), inbound
  messages and media not yet fetched (`ProcessInboundMessageJob`,
  `FetchMessageMediaJob`), voicemails without a transcript
  (`TranscribeVoicemailJob`), and post-call actions
  (`RunPostCallActionsJob`). Delivery attempts come from IS-parity
  [plan 05](../is-parity/05-delivery-and-escalation.md), which must
  store them as `delivery_attempts` rows for this to work.
- **Idempotency audit.** For each job in `app/Jobs/`, document and
  test that running it twice is harmless. `UploadCallRecordingJob`
  already is (`app/Jobs/UploadCallRecordingJob.php:29-33`). The rest
  need checks, especially anything that sends to a person: an
  "already sent" marker in the DB, checked inside the same transaction
  that records the send.
- **Queued notifications** that exist only in Valkey are lost.
  Anything a client is paying for (message delivery, escalation) must
  not be a bare queued notification. That is a constraint on plan 05,
  recorded here.

### Standby mode on the DR site

While standby, the DR site runs:

- Data tier: the Patroni standby cluster, SeaweedFS with filer.sync,
  local Valkey.
- Edge: Kamailio and rtpengine, up, with an empty dispatcher answering
  `503`. Haraka answers `451 4.3.2 try again later` to every RCPT, so
  a secondary MX (below) defers mail instead of bouncing it.
- **A standby console:** one Laravel container with
  `ORBITAL_SITE_ROLE=standby`. The app boots against the read-only
  replica. A middleware renders only the DR page and health
  endpoints. Login works read-only: the session is in DR's local
  Valkey, and writes that login normally triggers are skipped in this
  mode, with tests. No Horizon, no scheduler, no Reverb, no agent
  workers. (Compose: those services sit behind a `site-active` profile.
  Helm: `site.role: standby` sets their replicas to 0.)
- A small `dr-drill` runner (cron on compose, CronJob on Helm). See Drills.

The standby console shows replication lag (DB and objects), slot
health on the primary (when reachable), last drill result, and the
**Promote** action.

### The promote sequence

`orbital:dr:promote` is the single implementation. The admin button
calls it. Each step is logged with output and timing to a local JSON
log on the DR host. Once the DB is writable, the log is copied into
`failover_audit_logs`.

1. **Gate.** A super-admin is logged into the standby console. They
   type the site name, and then the DR secret, which is checked against
   `ORBITAL_DR_PROMOTE_SECRET_HASH` in the DR site's env, not the DB.
   The DB is a replica, and a secret kept there would be the
   primary's to change. The CLI asks for the same secret.
2. **Fence the primary.** If the primary API is reachable, call its
   `orbital:dr:fence` (stop Horizon and the scheduler, set Laravel to
   maintenance mode, Patroni pause, set every Asterisk to drain). If it
   is not reachable, show the operator a checklist they must confirm:
   "the primary site is down or cut off; carriers are not sending to
   it". This step exists to prevent a split brain, and the
   tool cannot make that call for them.
3. **Record the replay position.** Record the last replayed LSN and
   timestamp, and the object-sync lag. These are the RPO actually
   achieved, shown in the report.
4. **Promote Postgres.** Remove `standby_cluster`, or call the provider
   API, then wait for `pg_is_in_recovery() = false`.
5. **Stop filer.sync.** The DR filer is already writable.
6. **Flip the site role** to `active`. Start the `site-active` services
   (compose: `docker compose --profile site-active up -d`; Helm: `helm
   upgrade --set site.role=active`). The CLI prints the exact
   command. Running it is up to the operator, because the app does not
   control containers.
7. **Rewrite site state.** Mark `site=primary` backends inactive,
   regenerate the dispatcher list, run `orbital:generate-config --push`
   and `orbital:bootstrap`, re-render prompts (compose), and
   `config:clear` followed by a queue restart.
8. **Requeue.** Run `orbital:dr:requeue`.
9. **Smoke test.** Use the same checks as the drill: login, a test call
   to a drill DID through the DR edge, a test email
   (`app/Console/Commands/SendTestEmail.php`), and reading a recent
   recording.
10. **Cut traffic over.** Use the DNS and carrier steps below, then
    post the operator banner.

### Carriers, DNS and operators

- **Carriers.** Each trunk is in one of three modes, and the plan
  adds `sip_trunks.dr_mode` (`dns` | `carrier_failover` | `registration`
  | `manual`) and `dr_verified_at`:
  - `registration` (`sip_trunks.register = true`): DR's Asterisk
    registers once promoted, and the carrier follows the newest
    registration. Nothing to do.
  - `carrier_failover`: the carrier's origination has a secondary URI
    pointing at DR's edge (Twilio, Telnyx and Bandwidth all support
    priority/weight origination). When the primary stops answering,
    calls fail over by themselves. The primary must answer 503 rather
    than time out when it is drained, and Kamailio already does.
  - `dns`: the carrier targets a hostname. Lower the TTL and move the
    record, or use SRV records with a DR target at lower priority.
  - Outbound: the carrier's IP allowlist must include DR's edge IPs
    **before** a disaster.
  A **DR readiness** panel on the primary lists every active trunk with
  its mode, verified date and the IPs to allowlist. The panel is red
  until every trunk is verified.
- **Messaging webhooks** (Twilio and similar) point at the primary
  URL. Configure the carrier's fallback URL to point at DR. Same
  readiness panel.
- **Inbound email.** Publish a secondary MX for DR's Haraka. While
  standby it defers mail (above). Once promoted, it accepts. Senders
  retry within their normal queue time.
- **Operators.** Use one canonical name (`orbital.example`) with a
  low TTL, moved at promotion. Keep `dr.orbital.example` as a permanent
  alias that always points at DR, published in the operator docs and
  linked from the login page as "if this page doesn't load". Moving the
  canonical name matters for passkeys: WebAuthn's RP ID is bound to the
  domain (see [2FA, passkeys and email OTP](2fa-passkeys-and-email-otp.md)),
  so on a different hostname passkeys wouldn't work. TLS: DR's acme
  issues for both names ahead of time, using DNS-01 so it works
  while DR isn't serving the canonical name. OIDC and Grafana redirect
  URIs list both hosts. Hardware SIP phones: configure a backup
  outbound proxy pointing at DR's edge, or use SRV.

### Failback

After a real event, the old primary is rebuilt as a standby **of DR**
(`standby_cluster` pointing the other way, with `pg_rewind` when its
timeline allows and a fresh clone otherwise). filer.sync runs in
reverse. Then a planned switchover, the same as the twice-yearly
cutover, moves traffic home. The sequence code is direction-agnostic:
"primary" and "dr" are site names, not roles.

### Drills

- **Monthly, automated, without interrupting replication.** The
  drill runner on the DR site:
  1. Clones the standby without touching it. Patroni: `pg_basebackup`
     from a DR replica into a throwaway instance. Managed: the
     provider's snapshot-restore. Then promote the clone.
  2. Starts a throwaway stack (compose project `orbital-drill`, or Helm
     release `orbital-drill` in its own namespace) with
     `ORBITAL_SITE=dr`, pointed at the clone. It gets a **scratch
     bucket** that reads through to the replicated bucket and writes to
     scratch, and its own edge port, so the real DR edge stays untouched.
  3. Runs the smoke checks above against it: a synthetic drill user
     logs in, a SIP test client places a call to a drill DID, and a
     test email is sent and routed.
  4. Tears the stack down and publishes `orbital_dr_drill_last_success_timestamp_seconds`,
     the measured RPO and the time to smoke-green. Add alert rules
     modelled on the backup alerts (`OrbitalBackupStale` and similar):
     `OrbitalDrDrillStale` (35 days), `OrbitalDrReplicationLagHigh`,
     `OrbitalDrObjectSyncLagHigh`, and `OrbitalDrSlotRetainingTooMuch`
     on the primary.
- **Twice-yearly cutover:** a planned, zero-data-loss switchover. Fence
  the primary cleanly (step 2 with the primary reachable), wait for
  replay lag 0 and object sync lag ~0, promote DR, turn the old primary
  into DR's standby, route real traffic through DR for the agreed
  window, then switch back the same way. The runbook has a "cutover"
  variant of the sequence that skips the "primary is lost" checklist.

### Runbook

`docs/admin/disaster-recovery.md`, rendered inside the admin panel
(the standby console shows it, and it is the most important page there),
covering: when to promote and who decides, the promote steps in order
with the CLI fallback for each, carrier and DNS steps for each trunk
mode, what operators see, what is lost (Valkey contents, recordings
still in the spool, up to RPO of DB writes), failback, and the drill
report format.

## Edge cases

- **Split brain.** Promotion never happens automatically. The fence
  step and the typed checklist exist because DR can't tell "primary
  dead" from "link dead". If the primary comes back after DR was
  promoted, its Patroni must not resume as leader. `orbital:dr:fence`
  on recovery puts it in pause and maintenance mode, and the runbook
  says to rebuild it as a standby.
- **APP_KEY.** Both sites must have the same APP_KEY, or encrypted
  columns (platform settings secrets, 2FA, trunk passwords) are noise
  on DR. The standby console compares its key fingerprint with the
  one the backup manifest records and refuses to show Promote on a
  mismatch.
- **Schema migrations** replicate as WAL, so DR follows. The DR site
  must run the **same image version** as the primary, so a deploy
  updates DR's images too, without running migrations there. The
  standby console shows an image version mismatch as red.
- **Slot bloat** while DR is down. `max_slot_wal_keep_size` prevents
  it, and when it trips the standby is re-cloned. Alert before that
  point.
- **DR site's own node rows** are created on the primary. If someone
  deletes them, promotion has no Asterisk to dispatch to. The
  readiness panel checks that at least one `site=dr` backend exists.
- **Rate limits and cron state in Valkey** reset on promotion. Every
  scheduled task is safe to run late, and `onOneServer` keeps double
  runs out.
- **LiveKit Cloud** (`telephony.livekit.mode=cloud`) is unaffected by
  site loss. Only `local` LiveKit needs DR's own nodes, which are
  site-scoped settings.
- **Drill side effects.** The drill stack must not send real email or
  SMS, or call real numbers. It runs with `mail.default=log` and the
  messaging carriers disabled through managed settings, and only dials
  drill DIDs.

## Migration and rollout

1. HA items 1–3 and 5: small independent PRs.
2. HA item 2 (filer store to Postgres) before any DR work.
3. Site identity: the `site` columns, `site_scoped` settings and the
   `ORBITAL_SITE` config. Ships with no visible change (everything is
   `primary`).
4. Postgres standby_cluster and the `dr_site` slot, then filer.sync
   on a staging pair of sites. Lag metrics and alerts.
5. Standby console, `orbital:dr:promote` / `fence` / `requeue`, the
   trunk `dr_mode` fields and the readiness panel.
6. Drill runner. Run the first drills by hand, then schedule them.
7. Runbook, then the first planned cutover on staging, then on
   production with the operator's agreement.
8. Helm: the `site.role` values and the managed-provider variant of
   the promote step. HA item 4 can come later, because managed services
   cover VKE.

## Tests

- `RuntimeConfigOverrideProvider` prefers `dr:<key>` for
  `site_scoped` keys only when `orbital.site=dr`, and managed settings
  still win.
- Dispatcher writer and peer resolver filter by site.
- Standby middleware: only the DR page and health routes respond.
  Login succeeds against a read-only connection (a test DB user
  with no write grants).
- `orbital:dr:promote` against fakes: refuses on a wrong secret, an
  APP_KEY mismatch or an image mismatch; runs steps in order; stops and
  reports on the first failure; copies the local log into the audit
  table once writable.
- `orbital:dr:requeue`: each non-terminal state dispatches the right
  job, and running it twice dispatches nothing new (idempotency).
- An idempotency test per job in `app/Jobs/`: run it twice, assert a
  single side effect.
- Trunk readiness: the panel is red until every active trunk has
  `dr_mode` and `dr_verified_at`.
- Staging, manual: a full drill, a planned cutover and a failback, with
  timings recorded in the runbook.

## Open questions

- Is a single DR site enough for the target customers, or will
  some need the DR site run by a different provider? That affects
  whether filer.sync over VPN is acceptable or CRR at a provider is
  required.
- Who decides to promote in practice: the platform operator's on-call,
  or Orbital support? That shapes the gate and the runbook's first page.
- Should DR also be a reporting replica (read-only queries for the
  reporting work in IS-parity plan 10), or is that a load risk on the
  standby?
- Is the standby console's read-only login worth the complexity, or
  should promotion be CLI-only, with the console coming up after
  step 4?
- Recording spool loss: is it acceptable, or should recording-daemon
  output stream straight to object storage? See the recording surfaces
  in [rtpengine-edge.md](../rtpengine-edge.md).

## Size

- HA gaps: Horizon/PDB 1 day; filer store to Postgres 3–4 days
  including a rehearsal; keepalived overlay 2–3 days; node maintenance
  tab with app/LiveKit drains and pause toggles ~1.5 weeks; Helm
  Patroni/Sentinel opt-in ~2 weeks.
- DR: site identity ~1 week; Postgres standby, slot and lag ~1 week;
  object sync and lag 3–4 days; standby console and promote/fence/requeue
  ~2 weeks; trunk readiness 3 days; drill runner ~1.5 weeks; runbook
  and first cutover ~1 week.
- Total ~9–11 weeks for DR on compose, plus ~1 week for the Helm
  variants (excluding Helm data-tier HA).
