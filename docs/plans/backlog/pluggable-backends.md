# Plan: Pluggable infrastructure backends (internal ↔ external swap)

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

TODO.md ("Pluggable infrastructure backends — internal ↔ external swap")
asks for the platform operator to be able to choose, per service,
between the container Orbital ships and an endpoint they supply.
The canonical case is the object store: flip a setting, enter an
endpoint, keys and a bucket, have Orbital provision the bucket with
the same policy the internal store gets, flip the disk config, keep
running, and be able to flip back.

The TODO's own implementation note sets the order: build that one swap
carefully, then extract a pattern once there are two or three real
shapes to compare. This plan follows that note. Most of it is the
object-store swap. The other candidates get a sketch each, and the
pattern comes last.

The TODO text is out of date on one point. It says "MinIO". Orbital no
longer ships MinIO. It moved to SeaweedFS after MinIO was archived
upstream (see the docblock at
`app/Services/Bootstrap/Bootstrappers/S3BucketBootstrapper.php:27-29`).
This plan is SeaweedFS ↔ external S3. The neighbouring TODO item
"MinIO Console OIDC login UI" (TODO.md:793) is stale for the same
reason and can be closed.

## What exists today

### The internal object store

- **docker-compose.** SeaweedFS runs only in the HA overlay
  `compose/ha-data.yml`: three masters (`:431-478`), two volume servers
  (`:480-520`) and two filers that also serve the S3 API (`:522-570`).
  The base `docker-compose.yml` defines no object store at all. Clients
  reach it through HAProxy `seaweed_s3_be` on `haproxy:8333`. Filer
  metadata is stored in Valkey database 2 (`docker/seaweed-ha/filer.toml`,
  and `docs/admin/high-availability.md` under "SeaweedFS metadata").
  There is one S3 identity, `orbital`, with Admin/Read/Write/List/Tagging
  (`docker/seaweed-ha/s3.json`).
- **Helm.** A single `weed server` pod by default
  (`helm/orbital/templates/seaweedfs-statefulset.yaml:27`), or
  `objectStorage.external.enabled: true` with endpoint, region and
  bucket in values (`helm/orbital/values.yaml:601-620`) and keys in the
  `object-storage-creds` Secret. The ConfigMap picks one or the other at
  render time (`helm/orbital/templates/configmap-app-env.yaml:61-72`).
  The bucket is `orbital-recordings` either way, and every app object
  lives in that one bucket.

So Helm can already point at external S3, but only when the chart is
installed or upgraded. Nothing moves the data, and nothing can change
it at runtime.

### How Laravel reaches it

- The `s3` disk reads `AWS_*` from the environment only
  (`config/filesystems.php:50-61`). It is not in `SettingsRegistry`, so
  Platform Settings cannot change it.
- `asterisk-config` and `asterisk-prompts` also use `AWS_*` when
  `ASTERISK_STORAGE_DRIVER=s3` (`config/filesystems.php:70-88`,
  `:130-148`). Helm sets that driver (`configmap-app-env.yaml:112`).
  Compose uses local bind mounts.
- The `backups` disk has its own `BACKUP_AWS_*` credentials on purpose
  (`config/filesystems.php:90-123`). It stays out of this swap.
- The only storage setting in the registry is
  `telephony.recording.storage_disk` (`SettingsRegistry.php:1048-1058`),
  which picks the disk name. It does not configure the disk.
- Paths are stored without a disk:
  `call_recordings.storage_path`
  (`database/migrations/2026_04_28_110100_create_call_recordings_table.php:59`)
  and `email_messages.raw_storage_path`
  (`2026_04_15_110000_create_email_messages_table.php:40`). Message media
  items carry a `storage_disk` (`app/Models/MessageEntry.php:139`). A
  migration that copies keys unchanged keeps every row valid.
- `Branding::url()` serves logos with `Storage::disk('s3')->url()`
  (`app/Support/Branding.php:39`), so the branding bucket has to be
  publicly readable.

### Consumers outside Laravel

These do not go through Laravel config, so a runtime override never
reaches them:

| Consumer | Where it is configured | What it reads |
|---|---|---|
| Asterisk `config-sync` init container and sidecar (Helm) | `docker/asterisk-config-sync/entrypoint.sh:14-69`, `helm/orbital/templates/asterisk-statefulset.yaml:64,154` | `AWS_*` env, rclone |
| Loki | `docker/loki/loki-config.yaml:33-40` | `haproxy:8333`, bucket `loki-chunks`, hard-coded |
| Tempo | `docker/tempo/tempo.yaml:53-54` | `haproxy:8333`, bucket `tempo-traces` |
| Barman WAL mirror | `compose/ha-data.yml:172-176` | `BARMAN_PRIMARY_S3_*`, defaults to the SeaweedFS filer |
| SeaweedFS filer | `docker/seaweed-ha/s3.json` | its own identity file |

### Bootstrapper, health and the Failover page

- `S3BucketBootstrapper` creates two hard-coded buckets, `orbital` and
  `orbital-recordings` (`:33-35`), and puts a public-read policy on
  `orbital` (`:117-132`). The app writes to `AWS_BUCKET` instead, which
  Helm sets to `orbital-recordings`. The bucket the app writes branding
  to is therefore not always the one that gets the public policy. This
  has to be fixed before the swap. See Phase 0.
- `SystemHealthService` probes whatever `filesystems.disks.s3.endpoint`
  is set to (`app/Services/Health/SystemHealthService.php:104-112`), so
  health follows a config flip on its own.
- `config/failover-tiers.php` already has an `object_storage` mode of
  `cluster`, `managed` or `none`. Helm derives it from
  `objectStorage.external.enabled`
  (`helm/orbital/templates/_helpers.tpl:145-156`). In `managed` mode,
  FailoverCentral hides the SeaweedFS cluster card.

### The settings machinery the swap builds on

- `RuntimeConfigOverrideProvider` applies `platform_settings` rows on top
  of config in a `booting()` callback, before any provider boots
  (`app/Providers/RuntimeConfigOverrideProvider.php:59-133`). Secrets are
  stored encrypted with `Crypt`. Each key maps to one `config_key` or
  fans out to several `config_keys` (`:117-131`). A key listed in
  `ORBITAL_MANAGED_SETTINGS` keeps the deployment's value and shows
  read-only (`SettingsRegistry::isManagedByDeployment`, `:1109`). The
  provider applies every key on its own. It has no notion of "only
  when another setting has value X".
- The provider's documented caveat (`:48-57`) is that long-running
  processes such as Horizon, Reverb and the agent worker only pick up
  overrides when they boot. `ServiceRestartCatalog` maps restart slugs
  to handlers, for example a queue restart
  (`app/Services/Settings/ServiceRestartCatalog.php`).
- One precedent for the "point at external" shape already exists:
  `telephony.livekit.mode` with `local.*` and `cloud.*` key groups
  (`SettingsRegistry.php:700-750`).

## Goal

A super-admin moves the application object store from the internal
SeaweedFS to an external S3-compatible bucket, or back, from the admin
panel. They can stop part-way, and nothing is lost.

- The external bucket is checked before anything changes: it is
  reachable, the key can read, write, list and delete, and the policy
  can be applied.
- Existing objects are copied to the new store, and objects written
  during the move are not lost.
- Reads keep working throughout. During the window, an object that
  hasn't been copied yet is read from the old store.
- After the flip, every Laravel process uses the new store, and
  so does every non-Laravel consumer of the app bucket.
- Flipping back uses the same mechanism in the other direction.
- On Helm, values stay the source of truth when the operator wants
  that, and the same migration runs as a Job.

Not goals for the first swap: moving Loki, Tempo or Barman off
SeaweedFS (Phase 3), switching off the SeaweedFS containers from the
app (the app has no control over containers), and multi-bucket layouts.

## Design

### Phase 0: one bucket, no hard-coded names

Fix the mismatch before adding a second store:

- `S3BucketBootstrapper` provisions `config('filesystems.disks.s3.bucket')`.
  It stops using the two constants.
- Branding stops depending on a public bucket. Serve logos and favicons
  through an app route, `GET /branding/{key}`, which streams from the
  disk with long cache headers and an ETag. Or have the route redirect
  to a long-lived `temporaryUrl()`. AWS buckets block public policies
  by default (Block Public Access), and Cloudflare R2 has no bucket
  policies at all. An app route keeps every provider in scope. The
  public-read policy then becomes optional.
- `S3BucketBootstrapper` takes an S3 client config argument instead of
  always reading `filesystems.disks.s3`. That lets it provision a target
  that isn't live yet.

### Settings: an `object_storage` section with conditional keys

Add registry keys:

| Key | Type | Targets |
|---|---|---|
| `storage.backend` | select `internal` / `external` | none (read by the provider) |
| `storage.external.endpoint` | url | `filesystems.disks.s3.endpoint` + `asterisk-config` + `asterisk-prompts` |
| `storage.external.region` | text | `…region` (same fan-out) |
| `storage.external.bucket` | text | `…bucket` |
| `storage.external.key` | password, secret | `…key` |
| `storage.external.secret` | password, secret | `…secret` |
| `storage.external.path_style` | toggle | `…use_path_style_endpoint` |
| `storage.external.public_url` | url, optional | `filesystems.disks.s3.url` |

The provider needs one small addition, an `applies_when` field that
mirrors `visible_when`: skip a key unless the saved value of another key
matches. Then the external keys only take effect when
`storage.backend=external`. Flipping `storage.backend` back to
`internal` makes the environment values (SeaweedFS) win again, and the
external credentials stay saved for the next attempt. Only targets for
disks whose driver is `s3` get the `asterisk-*` fan-out. On compose
those disks are local, so leave them alone.

The settings get their own page, **System → Object Storage**, in the
same way Telephony and Providers were split off from
`PlatformSettings` (`app/Filament/Pages/PlatformSettings.php:28-33`). The
page saves through the same `PlatformSettingsRepository`, but writing
`storage.backend` is not a plain form field. Only the migration flow
below changes it.

On Helm installs that set `objectStorage.*` in values, the chart lists
these keys in `ORBITAL_MANAGED_SETTINGS`. The page then shows them
read-only and offers "run migration as a Job" instructions instead of
the in-app flip. See "Helm flow" below.

### Validation before anything changes

A **Test connection** action builds a client from the unsaved form
values and checks, in order:

1. `HeadBucket`. If the bucket is missing, offer to create it (needs
   `CreateBucket`).
2. Put a probe object, then get, list and delete it.
3. Multipart upload of a probe larger than 8 MiB. Recordings and mail
   with attachments can be large, and some S3-compatibles behave
   differently there.
4. Presigned GET of the probe works from the server.
5. If `public_url` is set, a GET on it returns the probe.

A step-by-step result is shown in the same format as `BootstrapReport`.
Also set `request_checksum_calculation` / `response_checksum_validation`
to `when_required` on the client. Recent AWS SDK releases send CRC
checksums by default, and several S3-compatible providers reject them.
That is a common cause of "works on AWS, fails on X". Verify the
installed `aws/aws-sdk-php` version supports the options before relying
on them.

### The migration, as a state machine

New table `object_storage_migrations`: `id`, `direction`
(`to_external` / `to_internal`), `source` and `target` config snapshots
(encrypted JSON), `status`, `phase`, `started_by`, counters (`objects`,
`bytes`, `copied`, `skipped`, `failed`), `checkpoint` (the
ListObjectsV2 continuation token for each prefix), `bulk_started_at`,
`flipped_at`, `finished_at`, `last_error`. Each transition also writes a
`failover_audit_logs` row (the HA pages already use that table, see
`app/Models/FailoverAuditLog.php`).

Phases:

1. **Validate.** The checks above run against the target. Abort on
   any failure.
2. **Provision.** The refactored `S3BucketBootstrapper` runs against
   the target, so the bucket gets the same policy (if one is still used
   after Phase 0) and the same lifecycle and CORS (none today).
3. **Bulk copy.** A Horizon batch, one job per top-level prefix
   (`recordings/`, `mail/`, `voicemail/`, `media/`, `branding/`,
   `asterisk/`, and so on; enumerate them from the listing rather than
   hard-coding). Each job pages ListObjectsV2 on the source and streams
   each object with `readStream` → `writeStream`. Cross-provider
   server-side copy does not exist. Content-Type and user metadata are
   preserved. A sha256 is computed while streaming and stored as
   `x-amz-meta-orbital-sha256`. An object is skipped when the target
   already has the same size and the same sha256 metadata, which makes
   the job safe to resume. Concurrency and rate are configurable, and
   the batch checkpoints after every page. The app keeps reading and
   writing the source the whole time.
4. **Flip with read fallback.** Set `storage.backend` to the target
   and register a `FallbackFilesystemAdapter` over the `s3` disk for
   the length of the window. Writes go to the target. `read`,
   `readStream`, `fileExists`, `fileSize` and `mimeType` try the target
   first and then the source. `delete` deletes in both. `temporaryUrl`
   and `url` use the target when the object is there, otherwise the
   source. The adapter is active only while a migration row is
   in `flipped` state, and it is a dozen methods over two Flysystem
   adapters. Then restart every long-running process: queue restart
   via `ServiceRestartCatalog`, plus Reverb and the scheduler, which
   don't touch S3 but are listed for completeness. A worker that
   booted before the flip writes to the source until it restarts. Step 5
   exists for those writes.
5. **Catch-up copy.** After the restart has gone through (wait for
   Horizon's supervisors to report a boot time after `flipped_at`), copy
   every source object whose `LastModified` ≥ `bulk_started_at` and
   which is missing or different in the target. Repeat until a
   pass copies nothing.
6. **Verify.** Compare object count and total bytes per prefix. Do a
   sha256 spot-check of a sample (default 1%, minimum 200 objects),
   plus every object referenced by the newest 500 `call_recordings` and
   `email_messages` rows. A DB-referenced key that is missing from the
   target fails verification.
7. **Finish.** Drop the fallback adapter. Set the Failover tier mode:
   `object_storage` → `managed` when external, `cluster` when internal.
   Today that mode comes from env, so it becomes a registry-backed key
   with `config/failover-tiers.php` as the fallback. The source data is
   **not deleted**. The page shows how much it holds, with a separate,
   typed-confirmation "purge old store" action that is only available
   after a configurable soak (default 14 days).

**Abort** is available in every phase before Finish. Before the flip it
just stops the batch, and the target keeps a partial copy, which is
harmless. After the flip it runs the reverse direction from step 4. For
that it needs the copies of new objects that exist only in the target,
so abort after the flip is "flip back with catch-up", never "drop the
target".

### Flipping back

Flipping back is the same state machine with `direction=to_internal`.
Before step 1 the page checks that SeaweedFS is reachable at the
environment's `AWS_ENDPOINT`. The TODO asks for flipping back to
"restart the container and re-bootstrap it". The app cannot start
containers. The compose app has no Docker socket, and only promtail
mounts it (`docker-compose.yml:848`). So the page tells the operator
which compose services or Helm values to bring up, then re-runs the
bootstrapper against the internal store once it answers.

The internal store usually still holds the pre-migration data. The bulk
copy's size+sha256 skip makes the return trip cheap, because only new
or changed objects move. Objects deleted on the external side since
the first flip (retention pruning by
`app/Jobs/PruneExpiredCallRecordingsJob.php`, unrouted-mail deletes) are
still present internally. Verify lists them as orphans, with no DB row
referencing the key, and offers to delete them. It never deletes them
automatically.

### Non-Laravel consumers of the app bucket

- **Compose.** None in Phase 1. Loki, Tempo and Barman use their own
  buckets on SeaweedFS, and the Asterisk config/prompt disks are local.
  SeaweedFS keeps running for them, which is why Phase 1 does not try
  to turn it off.
- **Helm.** The Asterisk `config-sync` sidecar reads `AWS_*` from env.
  Two options:
  1. Values-driven (recommended for the first release). The
     operator updates `objectStorage.external.*` and runs `helm
     upgrade`. The StatefulSet already rolls on app-env change
     (`asterisk-statefulset.yaml:37`).
  2. The sidecar pulls its storage config from an authenticated app
     endpoint, in the pattern of `EdgeDispatcherController`
     (`app/Http/Controllers/Api/EdgeDispatcherController.php`).
     That makes the in-app flip reach it too. Leave this for later.

### Helm flow

When the chart manages the keys, the same code runs as artisan commands:

```
orbital:storage:migrate --target-secret=object-storage-migration-target --phase=copy
helm upgrade … --set objectStorage.external.enabled=true \
               --set objectStorage.migration.fallbackFrom=in-cluster
orbital:storage:migrate --phase=catchup,verify
helm upgrade … --set objectStorage.migration.fallbackFrom=""   # drops fallback
```

`objectStorage.migration.fallbackFrom` keeps the in-cluster SeaweedFS
pod running and passes its endpoint to Laravel as `AWS_FALLBACK_*`. The
fallback adapter is enabled when those are set. The Filament page
renders the commands with the right values filled in. Shipping both an
in-app flip and a Helm runbook costs little, because the state machine
and jobs are shared and only the trigger differs.

## Edge cases

- **`config:cache`.** With a cached config, the provider's overrides
  never apply (`RuntimeConfigOverrideProvider.php:53-57`). The flip runs
  `config:clear` as part of the restart step, and the page warns if
  `app()->configurationIsCached()` stays true.
- **Presigned URLs issued before the flip** point at the source. They
  expire on their own. MessageEntry's lifetime is minutes. Don't purge
  the source before the soak ends.
- **Uploads in flight at the flip**, for example an
  `UploadCallRecordingJob` in an old worker, land in the source. Catch-up
  copies them. The job is already idempotent on
  `(sip_call_id, direction)` (`app/Jobs/UploadCallRecordingJob.php:29-33`).
- **Large objects.** The stream copy uses multipart above 64 MiB.
  Timeouts are per part, not per object.
- **Keys with odd characters** (mail attachment filenames). The copy
  uses the exact key from the listing and never rebuilds it from the DB.
- **Provider differences.** R2 has no bucket policies, B2 and some
  others need path-style, and AWS needs the correct region or it returns
  301. Each of these surfaces in the validation step, not at flip time.
- **APP_KEY.** External credentials are stored encrypted with APP_KEY.
  A restore onto an install with a different key turns them into
  noise. The backup manifest already records the key fingerprint
  (TODO.md "APP_KEY trap"). The page shows "credentials unreadable"
  instead of a raw decrypt error.
- **Two migrations at once.** Not allowed. A unique partial index on
  `status in (running, flipped)` prevents it.
- **Valkey loss during the window.** Batch state lives in Valkey, but the
  checkpoint lives in Postgres, so the migration resumes from the last
  page.
- **Settings managed by the deployment.** If `AWS_*` keys are managed,
  the in-app flip is hidden, not just disabled. Two sources of truth
  for where recordings live is not acceptable.

## Migration and rollout

1. Phase 0 (bucket name and branding route) ships alone. It changes
   behaviour for anyone whose `AWS_BUCKET` isn't `orbital`, so release-
   note it.
2. Phase 1: the settings section, conditional keys, validation, the
   state machine, the fallback adapter, and the in-app flip for compose.
   Behind a feature flag until a full round trip has been done on a
   staging install with real data.
3. Phase 2: the Helm commands and the `fallbackFrom` values. Optionally,
   config-sync pulling from the app.
4. Phase 3: move Loki, Tempo and the Barman primary to settings-driven
   endpoints, so that "external" can mean SeaweedFS is switched off
   entirely. Put the SeaweedFS services behind a compose profile. Today
   HAProxy `depends_on` `seaweed-filer-1` healthy
   (`compose/ha-data.yml:583-586`), so that dependency has to become
   optional first.

## Later candidates (sketch only)

Most of these already have the config half. What's missing is the
validate / provision / move / flip-back half.

| Service | What exists | What the swap still needs | Data to move |
|---|---|---|---|
| **SMTP** (Mailpit ↔ relay) | `mail.default`, `mail.smtp.*`, SendGrid, SES in the registry (`SettingsRegistry.php:314-393`) | A "send test" action in the validation format above. Mailpit is dev only. Nothing to provision. | None |
| **Reverb** (↔ Pusher / Ably / other) | `broadcasting.default` offers pusher and ably (`:532-545`), and the browser config is runtime (`resources/views/partials/echo-config.blade.php`) | The browser client hard-codes `broadcaster: 'reverb'` (`resources/js/bootstrap.js:28`). Pusher needs `cluster` and Ably needs its Pusher-protocol endpoint. The echo-config partial must emit the broadcaster and options per driver. Plus Pusher/Ably keys in the registry and a test-publish check. | None (clients reconnect) |
| **Ollama** (↔ external inference) | `services.ollama.url` (`:1012-1019`) | Validation (model list, and an embed call whose vector dimension matches the store). Changing the model is a reindex, not a flip. | Reindex if the model changes |
| **Observability** (Prometheus / Loki / Grafana ↔ external) | Tracing endpoint and auth in the registry (`:795-851`) | Prometheus `remote_write`, Loki push target and Alertmanager receivers are container config files, not Laravel config. The swap means generating those files from settings, the way Asterisk config is generated. Same work as Phase 3 above. | Optional history backfill. Usually skipped. |
| **Valkey** (↔ external Redis) | Helm `valkey.external` (`values.yaml:579-600`) | Install-time only, and should stay that way. The setting that would point the app at a new Valkey is itself read during boot from a DB the app reaches through config, and Horizon, sessions, cache, Reverb pub/sub and the SeaweedFS filer store all live there. A flip is a maintenance window: drain Horizon, switch, log everyone out. Move the filer metadata off Valkey first (see [DR site](dr-site.md)). | Sessions are dropped. Queued jobs must be drained first. |

Postgres is in the same position as Valkey. Helm supports
`postgres.external` (`values.yaml:550-578`), but switching it is a
dump/restore or replication cut-over with downtime, not a settings flip.

## What can't be swapped, and why

- **Asterisk.** It reads endpoints, AORs, contacts and queues live from
  our Postgres through ARA realtime. Its dialplan is generated from
  Blade templates (`orbital:generate-config`). Orbital drives it over
  AMI/ARI, and Kamailio's dispatcher list is generated from
  `AsteriskBackend` rows (`app/Services/Telephony/AsteriskDispatcherWriter.php`).
  An "external Asterisk" would have to accept all of that, which makes
  it ours in everything but hosting.
- **LiveKit SIP bridge.** LiveKit media can already be external
  (`telephony.livekit.mode` = `cloud`). The SIP side still terminates
  on infrastructure the operator owns, because trunks, Kamailio
  dispatch and the rtpengine edge
  ([rtpengine-edge.md](../rtpengine-edge.md)) all sit on the operator's
  network.
- **Kamailio and rtpengine edge.** These hold the public SIP and RTP
  addresses that carriers are configured against, so they are the
  fixed point the rest is placed around.

## The pattern to extract (after two or three swaps)

Once S3 plus one of SMTP or Reverb are done, compare them and extract
only what they actually share. The likely shape:

- A `SwappableBackend` contract with: the settings key group, `probe(config)
  → BootstrapReport`, `provision(config)`, an optional
  `migrate(from, to)`, `consumers()` (which restart slugs to run, and
  which non-Laravel consumers need a values change or a config push),
  and `failoverTier()`.
- `applies_when` in the registry (added here) becomes the general way
  to switch a key group on.
- A generic "Backends" admin page lists each backend's current mode,
  health and last migration.

If the second swap doesn't fit this shape, don't force it.

## Tests

- Provider: `applies_when` skips external keys while
  `storage.backend=internal`, applies them when `external`, and fans out
  to the `asterisk-*` disks only when their driver is `s3`.
- Validation against a fake S3 (Flysystem in-memory plus a mocked
  `S3Client`): each failing step reports the right label, and no state
  changes on failure.
- Fallback adapter: read from the secondary when the primary misses,
  write only to the primary, delete from both, `temporaryUrl` source
  selection.
- Copy job: resumes from a checkpoint, skips on matching sha256,
  preserves metadata, streams without loading the whole object into
  memory (memory assertion with a 50 MiB fixture).
- State machine: abort in every phase, abort after the flip reverses
  correctly, a second migration is refused, verify fails on a missing
  DB-referenced key.
- Bootstrapper: provisions `AWS_BUCKET`, not the old constants.
- Branding route: serves from a private bucket with cache headers.
- Manual: a full round trip on staging (SeaweedFS → AWS S3 → SeaweedFS)
  with real recordings and mail, with calls placed during the window.

## Open questions

- Is the public-read policy needed anywhere besides branding? If not,
  drop it entirely after Phase 0.
- Should Loki, Tempo and Barman get their own external destinations, or
  share the app's credentials under prefixes? Separate is safer, for
  the same reason backups use their own key.
- Source purge after the soak: manual only, or a scheduled reminder?
- Does any customer want something other than "one bucket", such as
  recordings in a separate bucket with its own retention and lock?
  If so, the registry keys become per-disk.

## Size

- Phase 0: 2–3 days.
- Phase 1 (settings, validation, state machine, fallback adapter,
  copy/verify jobs, page): ~2 weeks.
- Phase 2 (Helm commands and values): 3–4 days.
- Phase 3 (observability and Barman endpoints, SeaweedFS profile): ~1 week.
- Each later candidate: 2–5 days, except Valkey, which is a
  documented maintenance procedure.
