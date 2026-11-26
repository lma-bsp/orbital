# Plan: Backups follow-ons

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

`orbital:backup` / `orbital:restore` shipped an encrypted nightly
PostgreSQL dump to one off-box S3-compatible destination
(`TODO.md:951-1006`, `docs/admin/backups.md`). The "Remaining" list:

- Object storage content is not backed up: recording audio, MMS media,
  mail attachments.
- No admin UI: destinations, schedule, history, "Run now".
- No `backup_runs` history table. Last-success state lives in two
  `platform_settings` rows.
- No SFTP or multi-destination support.
- No per-tenant configuration export.
- The pg_dump / pg_restore path has no automated test, because the suite
  runs on SQLite.

Checking the code turned up one more problem. **`orbital:restore` never
reads the manifest**, so the APP_KEY fingerprint check the comments and
docs describe doesn't happen. There's also no restore drill, so "test a
restore before you need one" (`docs/admin/backups.md:151-154`) is advice
with no tool behind it.

## What exists today (checked in code)

### Backup service

- `BackupService::run()` (`app/Services/Backup/BackupService.php:52-91`)
  runs in this order:
  1. dump (`--format=custom --no-owner --no-privileges`, `:244-271`)
  2. encrypt (`ArchiveCipher`, libsodium secretstream, Argon2id per
     archive via `sodium_crypto_pwhash` at INTERACTIVE limits,
     `ArchiveCipher.php:60,107,193-198`)
  3. shred the plain dump
  4. build the manifest (`:293-320`)
  5. upload the `.dump.enc` plus a cleartext `.manifest.json` (`:330-357`)
  6. `recordSuccess()` (`:359-363`)
  7. prune (`:131-160`)
- Prune is `keep_days` by filename timestamp only. Unparseable names are
  never deleted. There's no minimum-count floor.
- The manifest has `sha256`, `orbital_version`/`commit`, sizes,
  `duration_seconds` and `app_key_fingerprint` (first 16 hex of
  sha256(APP_KEY)). The comment at `:317` says "orbital:restore compares
  this and warns before writing".
- `connection()` refuses anything but `pgsql` (`:368-387`) and always
  uses `config('database.default')`.
- `restore()` runs `pg_restore --no-owner --no-privileges
  --exit-on-error [--clean --if-exists]` (`:100-126`).
  - The comment says "keep going past benign noise", but
    `--exit-on-error` does the opposite: it stops at the first error.
  - The first error a non-superuser restore is likely to hit is
    `CREATE EXTENSION vector`. The schema needs pgvector
    (`database/migrations/2026_04_11_100021_create_knowledge_chunks_table.php:35`),
    and it isn't a trusted extension.
- Failure is a thrown `RuntimeException`. `BackupCommand` logs it
  (`BackupCommand.php:62-71`) and **records nothing**. A failed run
  leaves no trace in the database.

### Restore command

- `RestoreCommand` (`app/Console/Commands/RestoreCommand.php`) has
  `--list`, `--file`, `--decrypt-only`, `--output`, `--clean` and
  `--force`.
- It downloads the archive to `sys_get_temp_dir()`, decrypts, confirms,
  and restores.
- It contains no reference to `manifest` or `fingerprint`. It never
  fetches the manifest, never compares APP_KEY, and never checks the
  manifest's `sha256`.
- It doesn't put the app in maintenance mode or pause Horizon. The
  post-restore instructions are printed only.

### Configuration and scheduling

- `config/backup.php` covers `enabled`, `destination` (disk name,
  default `backups`), `prefix`, `encryption_key`, `keep_days`,
  `pg_dump_path`/`pg_restore_path` and `timeout` (3600). There's no
  settings-registry entry, so it's env only.
- The `backups` disk (`config/filesystems.php:103-123`) is S3 when
  `BACKUP_AWS_BUCKET` is set, otherwise local
  `storage/app/backups`, with `throw => true`.
- Scheduling is gated at file load: `if (config('backup.enabled'))
  Schedule::command('orbital:backup')->dailyAt('03:00')`
  (`routes/console.php:66-72`), with `onOneServer()`.
- Helm `backups.*` values (`helm/orbital/values.yaml:416-444`) give the
  bucket, endpoint, region and `keepDays`. The scheduler pod runs it.

### Metrics and alerts

- `PlatformMetricsCollector::backups()` (`:478-518`) reads the two
  `platform_settings` rows directly, bypassing the repository's Valkey
  cache on purpose (`BackupService::LAST_SUCCESS_KEY`, `LAST_BYTES_KEY`,
  `:39-41`).
- Three rules: `OrbitalBackupStale`, `OrbitalBackupNeverSucceeded` and
  `OrbitalBackupShrankSharply` (`docker/prometheus/rules/orbital.yml:226-280`).

### Tests

- `tests/Feature/Backup/BackupTest.php` covers:
  - cipher round-trip, wrong passphrase, truncation, bit flip and a
    foreign file
  - the missing-key refusal and key generation
  - retention (age, undatable names, zero)
  - listing order
  - the two metric cases
- Nothing runs `pg_dump` or `pg_restore`.
- `phpunit.xml:26-27` pins SQLite `:memory:`. CI runs `composer test`
  with no service containers (`.forgejo/workflows/application-tests.yml:122-126`).
- Locally there's no host PHP. Tests run in the Laravel image (memory
  note).
- PHPUnit's `<env>` entries don't override variables already set in the
  environment (no `force="true"`), so a CI job can point one test class
  at a real Postgres without changing the rest of the suite.

### Object storage

- Buckets are `orbital` (assets, public-read) and `orbital-recordings`
  (`app/Services/Bootstrap/Bootstrappers/S3BucketBootstrapper.php:33-35`).
  In-cluster they're SeaweedFS. MinIO was removed
  (`SsoSecretsBootstrapper.php:85-91`).
- Object keys the database knows about:
  - `call_logs.recording_path` / `recording_rx_path` / `recording_tx_path`
    (`CallLog.php:38-40`)
  - `call_recordings.storage_path` (`CallRecording.php:64`)
  - `voicemails.s3_path` (`Voicemail.php:38`)
  - `email_attachments.storage_path` (`EmailAttachment.php:33`)
  - message media items `{storage_disk, storage_path}` in
    `message_entries` (`MessageEntry.php:134-139`)
- Disks:
  - recordings: `telephony.recording.storage_disk`, default `s3`
    (`config/telephony.php:146-147`)
  - MMS media: `messaging.media.disk`, default `s3`
    (`config/messaging.php:141`)
- Recordings are deleted on purpose by `PruneExpiredCallRecordingsJob`
  daily at 03:15 (`routes/console.php:36-40`). It applies per-client
  retention (`telephony.recording.retention_days`, default 90), deletes
  the object, and nulls the column. A copy elsewhere that outlives this
  breaks the retention promise.
- The docs tell operators to "turn on versioning at the provider"
  (`docs/admin/backups.md:31-35`, `docs/admin/recording.md:67`). On the
  default install the provider is the in-cluster SeaweedFS. It's in the
  same failure domain as the database it serves, and Orbital doesn't
  configure versioning or object lock on it.

### Other durable state outside the dump

- `.env` / `APP_KEY` / `BACKUP_ENCRYPTION_KEY`: deliberately excluded.
- Passport OIDC keys.
- The separate `grafana` database on the same cluster
  (`docker-compose.yml:954-959`).
- Barman WAL archive and base backups in HA topologies (`docker/barman/`,
  `docs/admin/high-availability.md:376-400`). Barman is physical PITR on
  site. It complements this plan and isn't replaced by it.

## Goal

1. **Object content survives** loss of the storage tier, by a decided
   mechanism that respects per-client retention.
2. **One history table** (`backup_runs`) records every database, object,
   export, restore and drill run, including failures. Metrics read it.
3. **Multiple destinations** (S3, SFTP, local) with per-destination
   retention. The env-defined destination stays the break-glass path.
4. **Admin UI**: status, destinations with a connection test, schedule,
   history, "Run now". Restore stays CLI-only.
5. **Restore checks** the manifest: APP_KEY fingerprint, sha256, version.
6. **Automated pg_dump → pg_restore test** against real Postgres 16 and
   17 with pgvector, in CI.
7. **`orbital:backup:drill`**: a scheduled, non-destructive restore into
   a scratch database that verifies the result and records it, with an
   alert when drills stop succeeding.
8. **Per-tenant configuration export**: one client's setup as a
   versioned, secret-free file, for snapshots, migration between
   installs and offboarding.

Non-goals:

- Restoring from the UI.
- Replacing Barman PITR.
- Exporting a tenant's operational data (calls, messages). Plan 10's
  exports cover that.
- Backing up Grafana's database. Its dashboards are provisioned from
  git, and users are recreated by auth-proxy sign-up.

## Design

### 1. Object storage: decision

**Decision: Orbital-driven replication, encrypted, driven by the
database's own object index, with retention-aware deletes.** Provider
versioning stays recommended *in addition* for external S3.

Why not provider versioning alone:

- On the default install the "provider" is SeaweedFS on the same hosts.
  Versioning there doesn't survive the failure (losing the storage tier
  or the site) that an off-box backup exists for.
- Provider replication (S3 CRR, B2 replication) doesn't know Orbital's
  per-client retention. Either the replica keeps recordings past the
  client's window, which breaks the retention promise, or someone hand
  maintains lifecycle rules that drift from `retention_days`.
- It leaves restore as "go to your provider console". Orbital can
  restore objects the database still points at, by key, with one
  command.

Why not a nightly bucket mirror (`rclone sync`):

- A buggy delete or compromised key at the source propagates on the
  next run. That's the failure the database backup's separate
  credentials were designed against.
- It copies orphans (objects no row points at) and can't tell intended
  retention deletes from accidents.

**Mechanism** (`App\Services\Backup\ObjectReplicator`,
`orbital:backup:objects`):

- **Index table** `backup_objects`. It's platform-level, not
  `BelongsToTeam`, but carries `team_id` for retention:
  - `source_disk`, `source_key`, `kind`
    (`recording`/`voicemail`/`mms`/`attachment`/`asset`), `team_id`
  - `bytes`, `source_etag`, `replica_key` (opaque:
    `objects/<yyyy>/<mm>/<ulid>.enc`)
  - `replicated_at`, `source_gone_at`, `purged_at`
  - Unique (`source_disk`, `source_key`).
- **Discovery** walks the known reference columns listed above,
  incrementally by `id > watermark` per source table, plus a full
  reconciliation weekly. It never LISTs the source bucket to decide what
  to copy. The database is the index, so orphans are skipped by
  construction.
- **Copy**: stream from the source disk, encrypt, stream to each object
  destination (§3), and record the result.
  - Encryption reuses `ArchiveCipher` with a **pre-derived key**: one
    Argon2id per run, salt stored in the run's manifest and in each
    object's header. Per-object Argon2id at INTERACTIVE limits would
    cost ~0.1 s of CPU and 64 MiB of memory × thousands of recordings
    a day.
  - This needs a small `ArchiveCipher` addition,
    `encryptWithKey()`/`decryptWithKey()` sharing the stream code.
- **Deletes follow retention, with a grace period.**
  - The weekly reconciliation marks rows whose source reference is gone
    (pruned, column nulled, row deleted) with `source_gone_at`.
  - After `backup.objects.delete_grace_days` (default 14), the replica
    object is deleted and `purged_at` is set.
  - An accidental mass delete at the source can therefore be recovered
    for two weeks, and a retention deletion is honoured within two
    weeks. The docs state that window as part of the retention promise.
  - `PruneExpiredCallRecordingsJob` also writes `source_gone_at`
    directly when it deletes, so the common path doesn't wait for
    reconciliation.
- **Schedule**: hourly incremental at :20 (`onOneServer`,
  `withoutOverlapping`), so a recording is off-box within about an hour.
  The weekly reconciliation runs Sunday 04:00.
- **Restore**: `orbital:restore:objects [--kind=] [--team=] [--since=]
  [--dry-run]`.
  - Finds `backup_objects` rows whose `source_key` is missing at the
    source but whose database reference still exists, then decrypts the
    replica back to the original key.
  - After a database restore it's the natural second step, and the
    command prints it.
- **Sizing**: recordings dominate. WAV at 8 kHz/16-bit mono is about
  1 MB/min. 90-day retention at 2,000 recorded minutes a day is about
  180 GB of replica. The UI shows replica bytes, and the admin docs
  give the formula.
- **Metrics**: `orbital_backup_objects_pending` (rows discovered, not
  yet replicated) and `orbital_backup_objects_oldest_pending_age_seconds`.
  Alert `OrbitalObjectBackupLagging` when the oldest pending is over 6 h.

### 2. `backup_runs` history table

```
backup_runs
  id, kind enum(database, objects, tenant_export, restore, drill)
  trigger enum(schedule, manual, cli), triggered_by_user_id nullable (nullOnDelete)
  status enum(requested, running, succeeded, partial, failed)
  started_at, finished_at, duration_seconds
  archive_name nullable, plain_bytes, encrypted_bytes, sha256
  objects_copied, objects_bytes, objects_purged   (kind=objects)
  team_id nullable                                (kind=tenant_export)
  manifest jsonb, error text nullable, host string
backup_run_destinations
  backup_run_id, backup_destination_id nullable, destination_label snapshot,
  status, remote_path, bytes, error, finished_at
```

- Platform-level, not `BelongsToTeam`.
- `BackupService::run()` opens a `running` row first and closes it as
  `succeeded`/`partial`/`failed` in a `finally`. Failures finally leave
  a record, with the error text.
- `recordSuccess()` goes away. The metrics collector queries the latest
  `succeeded` database run directly, with no cache, which keeps the
  reason in the current comment.
- New series:
  - `orbital_backup_last_failure_timestamp_seconds`
  - `orbital_backup_last_run_status{kind}` (1 succeeded, 0.5 partial,
    0 failed)
  - `orbital_backup_last_drill_success_timestamp_seconds`
- New rules:
  - `OrbitalBackupPartial`: a destination failed while another
    succeeded. Warning.
  - `OrbitalBackupDrillStale`: drills enabled and no successful drill in
    35 days. Warning.
- **The restore trap.** Restoring a dump rolls `backup_runs` back to the
  dump's moment, which loses the run that produced it and everything
  after.
  - `orbital:restore` inserts a `kind=restore` row *after* the restore,
    carrying the restored archive's manifest.
  - It then re-imports run history from the destination's manifests
    (`.manifest.json` files are cleartext, see `:348-354`) for runs
    newer than the restored archive.
  - This keeps `OrbitalBackupStale` from firing on a fresh restore, and
    keeps the history honest.
- **Migration**: create the tables, then seed one `succeeded` database
  run from the two `platform_settings` rows if they're present
  (`archive_name` unknown, `manifest` `{"migrated_from":
  "platform_settings"}`). The collector reads the table from the same
  release. Delete the two settings rows one release later.

### 3. Destinations: multiple, SFTP, and the break-glass rule

```
backup_destinations
  id, name, driver enum(s3, sftp, local), config encrypted:array,
  enabled, holds enum(database, objects, both), keep_days nullable,
  object_lock_days nullable, last_tested_at, last_test_error, sort
```

- **The env destination stays, and can't be deleted.** `BACKUP_AWS_*` /
  `BACKUP_LOCAL_PATH` appears in the list as a read-only "Environment"
  row whenever it's configured.
  - UI-defined destinations store their credentials in the database,
    encrypted with APP_KEY. They live inside the dump they're meant to
    help restore.
  - When the platform is gone, only the env destination's credentials
    (in the operator's secret store) can fetch an archive.
  - The UI says this next to "Add destination". The docs tell operators
    to keep every destination's credentials out of band.
- **SFTP** needs `composer require league/flysystem-sftp-v3`. It isn't
  installed today: `composer.lock` lists it only as a suggestion.
  - Config: host, port, username, private key or password, root path,
    host-key fingerprint. The fingerprint is required, because an SFTP
    backup that accepts any host key hands the archive to whoever
    answers.
- **Upload once per destination from one encrypted file.**
  - The archive is produced once, then streamed to each enabled
    destination whose `holds` covers the kind.
  - Per-destination results go to `backup_run_destinations`.
  - The run is `succeeded` if all destinations succeed, `partial` if at
    least one succeeds, and `failed` if none do.
  - Only destinations that received *this* run's archive are pruned. The
    existing prune-after-store rule applies per destination.
- **Retention floor**: prune never deletes the newest
  `backup.min_keep` (default 3) archives on a destination, whatever
  `keep_days` says. Today a destination whose clock or naming went wrong
  could prune down to zero. With the floor, the newest three always
  survive.
- **Object lock** (S3 only): when `object_lock_days` is set, uploads
  pass `ObjectLockMode=COMPLIANCE` with a retain-until date. Prune skips
  objects that are still locked. This is the ransomware answer: a stolen
  backup credential can't delete locked archives.
  - **Test connection** checks that the bucket has object lock enabled
    before allowing the setting.
- `BackupService` gets the destination list from a
  `BackupDestinationRegistry`, which builds Flysystem disks at runtime
  (`Storage::build()`). The configured `backups` disk becomes the
  registry's env entry.

### 4. Admin UI

Add a "Backups" page under System (`app/Filament/Pages/Backups.php`).
It needs a new `backup.manage` permission, defaulting to super-admin
only. Viewing is `backup.view`, for supervisors who should see that
backups are healthy.

- **Status header**:
  - Last successful database backup (age, size, destinations).
  - Last object replication, with pending count and lag.
  - Last drill.
  - Encryption key status: "set" plus a fingerprint, the first 8 hex of
    sha256(key). The operator can compare it against their password
    manager without the key ever being displayed.
  - Next scheduled run, in the platform timezone.
- **Destinations**: a table plus a form for each driver.
  - **Test connection** writes, reads back and deletes
    `orbital-backups/.probe-<ulid>`, checks list permission, and for S3
    reports versioning and object-lock state. It stores
    `last_tested_at` / `last_test_error`.
  - Disable instead of delete when a destination has archives. Deleting
    asks for the name to be typed.
- **Schedule**: these become settings-registry entries. Because of
  `RuntimeConfigOverrideProvider` they override env, the same way
  tracing does.
  - enabled, `backup.time` (default 03:00), `keep_days`, `min_keep`
  - object replication on/off
  - drill cadence (off / weekly / monthly) and the drill target
- **History**: a table of `backup_runs` with kind, status, trigger,
  user, size, duration and destinations. Filter by kind and status.
  Clicking a row shows the manifest and error text.
- **Run now** (database or objects):
  - It inserts a `backup_runs` row with `status=requested` and returns.
  - It doesn't dispatch a Horizon job. The Redis queue's
    `retry_after` is 90 s (`config/queue.php:71`) and the largest
    supervisor timeout is 120 s (`config/horizon.php:262`). A backup
    that runs for 20 minutes would be handed to a second worker
    mid-dump.
  - Instead, the scheduler runs `orbital:backup --pending` every minute
    (`onOneServer`, `withoutOverlapping`, `runInBackground`). It claims
    requested rows with `UPDATE … WHERE status='requested'` and runs
    them in the scheduler container, which already has `pg_dump` and
    owns the timetable.
  - A run already in progress blocks a new request. The UI shows "a
    backup is running (started 4 min ago)".
- **Restore**: an information panel showing the exact CLI commands for
  the selected archive (`orbital:restore <name> --clean`, then
  `orbital:restore:objects`). No button. The existing doc's reasoning
  stands: when you need a restore, the admin UI is likely to be the
  broken thing.
- **Schedule wiring**: replace the load-time `if
  (config('backup.enabled'))` with `->when(fn () =>
  config('backup.enabled'))->dailyAt(config('backup.time'))`, so a UI
  change takes effect without restarting the scheduler.
  - The comment at `routes/console.php:63-65` (don't fail nightly with
    no destination) still holds: `when()` also checks that at least one
    destination resolves.
- Health: a "Backups" card in `SystemHealthService`, fed by the latest
  run, so the existing health page shows it too.

### 5. Restore checks the manifest

`orbital:restore` (and the drill) first fetch `<name>.manifest.json`
when it exists:

- **APP_KEY fingerprint** mismatch: a red warning naming the
  consequence ("encrypted columns — platform settings, 2FA secrets,
  tokens — will be unreadable"). It requires `--accept-app-key-mismatch`
  in addition to the usual confirmation. `--force` doesn't imply it.
- **sha256** of the downloaded file is compared before decryption. The
  cipher already authenticates the content. This catches "wrong file"
  or "manifest and archive from different runs" before the Argon2id and
  decrypt cost, and gives a clearer message.
- **Version**: if the manifest's `orbital_version` is newer than the
  running code, warn that migrations in the dump may be unknown to this
  build. Restoring an older dump is normal: run `migrate` afterwards.
  The command prints this and offers `--migrate`.
- A missing manifest (hand-copied archive, `--file`) warns and
  continues.
- Also new: `--maintenance` (default on for non-`--decrypt-only`)
  calls `down` and `horizon:pause` before the restore and reverses both
  after. Restoring under live writes produces a database that matches
  no point in time.
- Fix the misleading comment at `BackupService.php:113-115`.
- Restore runs with `--exit-on-error`, and a non-superuser can't create
  `vector`. So before restoring, the command checks for that extension
  with `pg_available_extensions` / `pg_extension`. If it's missing, the
  command stops and prints the one statement a superuser must run
  (`CREATE EXTENSION vector;` in the target database). Without this
  check, the restore fails partway through the schema.

### 6. Automated pg_dump / pg_restore test

- New test class `tests/Feature/Backup/PostgresRoundTripTest.php`,
  `#[Group('pgsql')]`.
  - It skips unless `BACKUP_TEST_PGSQL_HOST` is set, so the default
    SQLite run is unaffected.
  - It defines two connections at runtime, `backup_src` and
    `backup_dst`, on that server with throwaway database names, and
    creates and drops them in setUp/tearDown.
- To make this possible, `BackupService` takes the connection name from
  `config('backup.connection')`, default `database.default`, instead of
  hard-coding the default connection. It's a small refactor that the
  drill (§7) needs too.
- The test:
  1. Runs `migrate` against `backup_src`. This exercises pgvector, JSONB,
     enum checks and partial indexes as they really exist.
  2. Seeds representative rows:
     - a team
     - a user with 2FA secrets (encrypted column)
     - a platform setting (encrypted)
     - a call log with JSONB metadata
     - a knowledge chunk with a vector
     - a sequence advanced past 1,000
  3. Runs `BackupService::run(localPath: tmp)` against `backup_src`.
  4. Runs `orbital:restore --file=tmp --force --clean` against
     `backup_dst`, as a role **without** superuser that owns the
     database, with `vector` pre-created by the fixture superuser. This
     is the documented production shape. A second case leaves the
     extension out and asserts that the §5 pre-check stops with the
     right message.
  5. Asserts, per table:
     - the row count matches
     - `md5(string_agg(t::text, ',' ORDER BY id))` matches for the
       seeded tables
     - sequence `last_value` matches
     - the encrypted columns decrypt with the same APP_KEY
     - the `migrations` table matches
  6. Asserts `--clean` against a populated `backup_dst` replaces the
     data and doesn't duplicate it.
- **CI**: add a `php-pgsql` job to `.forgejo/workflows/application-tests.yml`.
  - Matrix: `pgvector/pgvector:pg16` (what the Helm chart deploys,
    `helm/orbital/values.yaml:570-572`) and `pg17` (what compose
    deploys, `docker/patroni/Dockerfile:13`).
  - Run as a `services:` container, with
    `vendor/bin/phpunit --group pgsql` and `BACKUP_TEST_PGSQL_*` set.
  - The job uses the runner's `postgresql-client`, which is checked to
    be at least the server major. It's the same rule as the image
    comment (`Dockerfile:108-115`).
- **Locally**, the memory-file recipe runs it: the Laravel image with
  `--network` onto a `docker run pgvector/pgvector:pg17` and the
  `BACKUP_TEST_PGSQL_*` env. The command goes in
  `docs/admin/backups.md` under a "Testing" heading.

### 7. Restore drill: `orbital:backup:drill`

```
orbital:backup:drill [archive] [--destination=] [--target=] [--keep] [--allow-same-server]
```

1. **Pick** the newest archive on the destination (default: env
   destination, which proves the break-glass credentials work), or the
   named one.
2. **Download**, verify the manifest sha256 and decrypt. A drill that
   only gets this far already proves the passphrase on file matches.
   Today that's the whole `--decrypt-only` advice.
3. **Restore** into a scratch database `orbital_drill_<yyyymmddhhmm>` on
   the **drill target**.
   - The target is `backup.drill.connection`: host, port, credentials
     and an admin database to `CREATE DATABASE` from.
   - It refuses when the target resolves to the same host and port as
     the live connection, unless `--allow-same-server`. A 40 GB restore
     onto the Patroni primary doubles its disk use and replicates to
     every replica.
   - It refuses outright if the scratch name equals the live database
     name.
4. **Verify**:
   - pg_restore exit status.
   - Table count and per-table row counts within tolerance of the
     manifest. This means adding `table_rows` (exact counts for ~15 key
     tables: teams, users, call_logs, messages, routing_rules, …) to the
     manifest at backup time. Counting is cheap next to the dump.
   - The APP_KEY fingerprint matches.
   - One encrypted `platform_settings` value decrypts.
   - `migrations` holds no migration unknown to this build.
   - A smoke query per core model through Eloquent on the drill
     connection, with no global scopes.
5. **Drop** the scratch database, unless `--keep`. It's always dropped
   on failure after the evidence is captured, so a failed drill doesn't
   leave a copy of every caller record lying around.
6. **Record** a `kind=drill` run with each check's result. Feed
   `orbital_backup_last_drill_success_timestamp_seconds`.
7. **Optionally restore objects**: `--objects=N` decrypts N random
   replicated objects to a temp path, compares them against the source,
   and deletes them. This proves the object replica is readable without
   restoring the bucket.

Scheduling: off by default. The UI offers weekly or monthly at 05:00,
which requires a drill target to be configured. The Helm values gain
`backups.drill.*`.

### 8. Per-tenant configuration export

`orbital:client:export {team} [--output=] [--encrypt]`, plus an
"Export configuration" action on `ClientResource`, gated by
`backup.manage` or a narrower `client.export_config`.

- **Format**: a zip containing `manifest.json` and one JSON file per
  model type.
  - `manifest.json` holds the format version, Orbital version, team id
    and name, exported-at, exporter, per-type counts and a
    `secrets_omitted` list.
  - Each model file keeps ids as exported. References use those ids, so
    an importer can remap them.
- **Contents**: configuration, not operational data.
  - Team profile and settings, without `passcode`.
  - OperatingHour, RoutingRule, ClientDid, CallQueue.
  - Extension, without `sip_password`.
  - AgentPersona, IntakeFlow and its steps, rules, transitions and
    goals.
  - Orchestration and OrchestrationBinding.
  - KnowledgeStore metadata. Chunk text is optional; vectors are always
    dropped, because they're re-embeddable.
  - DirectoryEntry, DirectoryTag, DirectoryFieldDefinition.
  - EmailRoutingRule, MessagingEndpoint (no carrier credentials),
    ClientSlot, ClientWebEndpoint, ClientAccountAlias.
  - ClientDbConnection, without `password`.
  - Queue membership by user email, not user id.
  - Every encrypted cast (`Extension.php:40`, `ClientDbConnection.php:62`,
    `Team.php:80`, `SipTrunk.php:39`) is listed in `secrets_omitted`
    with its record id.
- **Isolation**: every query is built as `withoutGlobalScopes()->where('team_id',
  $team->id)`, never from `BelongsToTeam`. That scope is absent in
  console runs and bypassed for super-admins, which is the same rule
  plan 10 states. An architecture test asserts the exporter's model list
  equals the `BelongsToTeam` config models, minus an explicit
  operational-data exclusion list, so a new model can't be silently left
  out.
- **PII**: directories hold on-call staff names and cell numbers.
  - `--encrypt` (default on in the UI) uses `ArchiveCipher` with a
    one-time passphrase shown once in the modal.
  - Downloads from the UI go through a signed URL that expires in
    24 hours and is re-checked against the permission.
  - Each export writes a `kind=tenant_export` run with `team_id`.
- **Import**: v1 ships **`orbital:client:diff {team} {export.zip}`**
  only. It's a readable diff of current configuration against an
  export, which answers "what changed since the snapshot" safely.
  `orbital:client:import` (new team, ids remapped, secrets prompted) is
  phase 2, once there's a real move between installs to test against.
- **Scheduled snapshots** (optional): include each client's export in
  the nightly run as `tenant-configs/<team>/<date>.zip.enc` on the
  database destinations, keeping the last N (default 14). It's cheap,
  and it makes "restore just this client's routing rules from Tuesday"
  possible without restoring the database.

## Edge cases

- **Two nightly runs racing the "Run now" request**: both claim through
  the same conditional `UPDATE`, and `withoutOverlapping` plus
  `onOneServer` on the scheduler entries make only one run at a time.
  A request made during a scheduled run is satisfied by that run and
  marked `succeeded` with a "covered by run #n" note.
- **Destination partially fails**: the run is `partial`, prune happens
  only where upload succeeded, and the alert fires. The failed
  destination's last good archive is untouched.
- **All destinations fail**: `failed`, no prune anywhere, the error is
  stored, and `OrbitalBackupStale` follows within 48 h. Add
  `OrbitalBackupFailed` for immediate notice: last run status 0 and
  last failure newer than last success.
- **Clock jumps / mis-dated archive names**: the `min_keep` floor
  prevents pruning to zero.
- **APP_KEY rotated after a backup**: older archives warn on restore, as
  §5 describes. The drill's encrypted-column check catches it on
  schedule instead of on the bad day.
- **Encryption key rotated**: archives carry no key id today. Add the
  key fingerprint to the manifest, so a drill against an archive taken
  under the old key says "taken with key ab12…, current key cd34…"
  instead of a bare decrypt failure. Keep the old key while those
  archives exist, as `--generate-key` already warns.
- **Recording deleted at source, then the database restored to before
  the deletion**: the row points at a key that is gone at the source but
  present in the replica (inside the grace window). `restore:objects`
  brings it back.
  - That is correct for an accident. For a retention deletion, restoring
    re-surfaces data past its window. The next 03:15 prune deletes it
    again, so the exposure is under a day. The restore output says so.
- **Object replica outlives a client's deletion**: deleting a client
  cascades rows (TODO bug "Deleting a client cascades call_logs").
  Reconciliation sees the references gone and purges after the grace
  period. That's consistent with retention.
- **Large first object run** on an install with existing recordings: the
  incremental job caps each run (`backup.objects.max_per_run`, default
  5,000 objects or 20 GB) and resumes next hour. The UI shows backfill
  progress.
- **SeaweedFS as both source and a destination**: Test connection
  refuses a destination whose endpoint and bucket equal an app disk's
  bucket, which is the "same credential, same failure" rule the env
  destination already documents. A different bucket on the same
  SeaweedFS is allowed with a warning that it's not off-box.
- **SFTP server without free space**: the upload fails mid-stream, the
  partial remote file is deleted on a best-effort basis, and prune skips
  unparseable or partial names as today.
- **Drill target unreachable**: the drill fails at step 3 with
  `kind=drill, status=failed`. Steps 1–2 are still recorded as passed,
  so "the passphrase works" is known even when the database part
  couldn't run.
- **Tenant export of a client with shared directories**
  (`SharedDirectory`, not team-owned): export references by name only.
  Shared directory contents aren't the client's to export.

## Migration and rollout

1. **Restore safety**, shipped alone, with no schema change:
   - manifest checks (APP_KEY, sha256, version)
   - the pgvector pre-check
   - `--maintenance`
   - the comment fix
2. **Real-Postgres test** + CI job (pg16 and pg17), plus the
   `backup.connection` refactor it needs. Land this before anything
   else touches `BackupService`, so later changes are tested against a
   real dump.
3. **`backup_runs`**: migration, seed from `platform_settings`, the
   collector switch, failure recording, the post-restore history
   re-import, and new series and rules. Delete the settings rows in the
   following release.
4. **Destinations**: the table, the registry (env destination
   read-only), SFTP dependency, the `min_keep` floor, object lock,
   multi-destination upload with `partial`.
5. **Admin UI**: status, destinations with Test connection, schedule
   settings, history, Run now via the scheduler, the restore
   instructions panel, the health card. The settings override env, so
   existing env-only installs keep working untouched.
6. **Drill command**: `table_rows` in the manifest, the drill target
   config, scheduling, alert, Helm values.
7. **Object replication**: index table, discovery, hourly job, prune-job
   hook, weekly reconciliation, `restore:objects`, metrics and alert.
   Backfill runs in capped batches. Update `docs/admin/backups.md` and
   `docs/admin/recording.md`, because the "NOT BACKED UP" box changes.
8. **Tenant export + diff**, then optional nightly snapshots. Import
   comes later.

## Tests

- **Restore manifest checks**:
  - Mismatched fingerprint → refused without
    `--accept-app-key-mismatch`, even with `--force`.
  - sha256 mismatch → refused before decryption.
  - Missing manifest → warning and continue.
  - Newer `orbital_version` → warning.
- **pgvector pre-check**: a fake connection reporting the extension
  missing → stops with the `CREATE EXTENSION` instruction, and
  pg_restore is never invoked (Process fake).
- **PostgresRoundTripTest** (`pgsql` group, CI): as described in §6,
  across pg16 and pg17.
- **backup_runs**:
  - A thrown dump error leaves a `failed` row with the message.
  - Success leaves `succeeded` with the manifest.
  - The collector emits last-success, last-failure and status from the
    table.
  - The migration seeds from both settings rows, and from none.
- **Post-restore history**: after a simulated restore, a `restore` row
  exists and newer manifests on a faked destination are re-imported as
  runs, with no duplicates on a second restore.
- **Multi-destination**:
  - Two faked disks, one throwing → `partial`, prune only on the good
    one.
  - Both throwing → `failed`, no prune.
  - `min_keep` holds three archives when all are past `keep_days`.
  - Locked objects (faked S3 `getObjectRetention`) are skipped by prune.
- **SFTP**: a destination built from config with a host-key
  fingerprint. Missing fingerprint fails validation.
- **Run now**:
  - The action creates a `requested` row and dispatches nothing to
    Horizon.
  - `--pending` claims it once across two concurrent invocations.
  - A request during a running backup is reported, not queued twice.
- **UI permissions**: `backup.view` sees status and history, while
  `backup.manage` is required for destinations, schedule and Run now.
  Credentials are never rendered back into the form.
- **Drill**:
  - Refuses the same server without the flag, and refuses a scratch
    name equal to the live database.
  - Drops the scratch database on success and on failure.
  - Records per-check results.
  - Under `pgsql`, a full drill against the round-trip fixture passes,
    and one against an archive taken with a different APP_KEY fails the
    encrypted-column check.
- **Object replication**:
  - Discovery picks up each reference type and skips orphans.
  - Incremental watermark.
  - Copy encrypts with the run key and records the replica.
  - Pruned recording → `source_gone_at` → purged after the grace period,
    with frozen time.
  - `restore:objects --dry-run` lists exactly the missing keys.
  - The per-run cap resumes correctly.
- **Tenant export**:
  - Contains only team A's rows when team B has identical data.
  - Runs with no auth user (console) and as a super-admin, with the same
    output.
  - No encrypted-cast value appears in any file.
  - `secrets_omitted` lists them.
  - The architecture test fails when a new `BelongsToTeam` model is
    neither exported nor excluded.
  - The diff reports an edited routing rule and a deleted queue.
  - Encrypted export round-trips with the shown passphrase.

## Open questions

1. Object replication default: on by default when the source is the
   in-cluster SeaweedFS, and off for external S3 (where provider
   replication may already exist)? Or always opt-in?
2. Delete grace period for replicas: is 14 days compatible with NCI's
   client contracts and any HIPAA BAA wording on recording retention?
3. Is there a real near-term need to move a client *between installs*?
   This decides whether tenant import is phase 2 or never.
4. Drill target in production: a small dedicated Postgres VM, the
   Barman host, or a scratch database on a Patroni replica? A replica
   is read-only, so it's not usable without a separate instance.
5. Should Passport's OIDC keys and other non-database secrets get an
   encrypted "config bundle" in the backup? They're regenerable, but
   regenerating breaks pgAdmin and Grafana SSO until clients are
   re-registered.
6. Should UI-defined destinations be allowed at all, given the
   credentials-inside-the-dump problem? Or should they be env/Helm only,
   with the UI read-only?

## Size

| Piece | Estimate |
|---|---|
| Restore manifest checks, pgvector pre-check, maintenance mode | 1–1.5 days |
| Real-Postgres round-trip test + CI matrix + `backup.connection` refactor | 2 days |
| `backup_runs` + collector/rules + post-restore history | 2 days |
| Destinations: registry, SFTP, multi-upload, min_keep, object lock | 3 days |
| Admin UI (status, destinations + test, schedule, history, Run now via scheduler, health card) | 3–4 days |
| Drill command + manifest row counts + scheduling + alert | 2 days |
| Object replication (index, discovery, copy, purge, reconcile, restore:objects, metrics) | 4–5 days |
| Tenant config export + diff (+ nightly snapshots) | 2–3 days |

**Total ≈ 4–4.5 weeks** for one developer. The first two rows, about
3 days, are the highest value for the cost: they turn an untested
restore path into a tested one.
