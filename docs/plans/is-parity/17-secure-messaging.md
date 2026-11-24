# Plan 17: Secure messaging (HIPAA-oriented delivery)

**Status:** proposed · part of [IS parity](README.md) · depends on 03 (templates/fields), 05 (dispatch, attempts, acks); uses 04 (recipients), 16 (two-way threads); phase 3 benefits from passkeys (roadmap)
**Written:** 2026-11-24 · expands survey item K in [07](07-additional-features.md)

## Problem

### What IS does

Amtelco sells secure delivery as a separate product (miSecure / Secure
Messaging) alongside IS dispatch. For a healthcare account the message
content never travels in clear SMS or email. The on-call clinician gets
a notice, opens the message in a secure app or web page after
authenticating, and that open counts as delivery confirmation and
acknowledgement, which feeds IS's escalation clock. Secure messages can
be answered (two-way, with read receipts), are encrypted in transit and
at rest, expire, and are audited. Medical answering clients ask about
this early, usually as "are you HIPAA compliant?" and "will you sign a
BAA?"

### What Orbital has today (verified)

**Messages are stored in clear and nobody is notified about them.**
- `Message` (`app/Models/Message.php:39-62`) has plain columns
  `caller_name`, `caller_phone`, `reason`, `notes`, `missing_fields`.
  None of them is encrypted. The only casts are boolean/array (`:56-62`).
  The table (`database/migrations/2026_04_17_060000_create_messages_table.php:13-27`)
  stores `reason`/`notes` as plain `text`.
- Status is portal-side only: `new/read/archived` (`Message.php:29-33`).
  Delivery and acknowledgement don't exist yet. Plan 05 adds them.
- Messages are written in five places with no shared builder:
  `SessionMessageWriter.php:164`, `Workspace.php:190,272`,
  `MultiCallDesk.php:199,244`, `ViewMessageThread.php:355`. No observer
  or event fires on save (`grep MessageObserver` finds nothing). Plan
  03 merges these into one builder, and this plan hooks into it.

**Encryption at rest exists only for credentials.** The `encrypted`
casts are on `Extension.sip_password`, `SipTrunk.password`,
`Team.passcode` (`app/Models/Team.php:80`),
`Team.voicemail_transcription_config` (`:76`),
`ClientWebEndpoint.credentials`, `ClientDbConnection.password` and
`MessagingEndpoint.provider_config`. Caller content is never encrypted.
Backups are encrypted with libsodium (`app/Services/Backup/BackupService.php`,
TODO.md:764-800, which also records the "APP_KEY trap" at :794). No
object-storage server-side encryption is configured anywhere (no
`ServerSideEncryption` key in `config/` or `app/`). The `s3` disk
(`config/filesystems.php:49-60`) is plain.

**Outbound channels exist, but neither is suitable for PHI.**
- SMS: `MessagingProvider` (`app/Services/Messaging/Contracts/MessagingProvider.php:30-140`)
  with `TwilioProvider` and `LogProvider`. `OutboundMessageService`
  (`app/Services/Messaging/OutboundMessageService.php:33-120`) is the
  single outbound chokepoint for thread replies, which makes it the
  right place for an opt-out check and for a PHI guard. It sends only
  on `MessageThread`s today, not for `Message` delivery.
- Email: `app/Services/Mail/OutboundReplyService.php:43-121` and
  `OutboundForwardService.php:26-140` send thread mail through
  `Mail::to()`. The forward service copies the full original body,
  including HTML, to any address (`:64-86`).
- **Voicemail email already sends PHI in clear.** `TranscribeVoicemailJob::sendEmail`
  (`app/Jobs/TranscribeVoicemailJob.php:146-166`) mails the team
  owner a `VoicemailReceived` mailable with the transcript inline and
  the WAV attached (`app/Mail/VoicemailReceived.php:17,60-72`). Today
  this is the only automatic delivery to clients, and it is exactly
  what a HIPAA client can't receive.

**The portal has no message list and doesn't enforce its own permissions.**
- Portal pages: `Dashboard`, `CallHistory`, `TextConversations`
  (`app/Filament/Portal/Pages/`). The message list is "being worked on"
  (`docs/roadmap.md:49-54`). Plan 03 builds it.
- The permission catalogue has `portal.view_home/calls/messages/recordings/manage_users/manage_roles`
  (`app/Services/Clients/ClientProvisioner.php:49-63`), with
  descriptions in `RoleResource.php:233-238`. Only `RoleResource`
  checks a permission (`:65-89,190`). `CallHistory` has no
  `canAccess()` and shows the recording **Play** action whenever a
  path exists (`CallHistory.php:96-110`), without checking
  `portal.view_recordings`. See Bugs.
- Portal queries filter by `current_team_id` explicitly
  (`CallHistory.php:48-51`, docblock `:20-25`) rather than relying on
  `BelongsToTeam`. That is the right habit for this plan's public
  endpoints, because `BelongsToTeam`'s scope does nothing when there is
  no authenticated user (`app/Models/Concerns/BelongsToTeam.php:17-20`).

**Authentication.** Fortify TOTP is required for everyone, with a
per-client grace window (`config/orbital.php:78-100`,
`RequireTwoFactor` in `PortalPanelProvider.php:109`,
`docs/admin/security.md:5-33`). WebAuthn/passkeys and email OTP are
still open (`TODO.md:251-293`, `docs/roadmap.md:69-75`). Passport
(`composer.json:15`) and Sanctum are installed, which helps a mobile
app. Session lifetime defaults to 120 minutes
(`config/session.php:35`), with no separate idle timeout for the portal.

**Signed links.** The app has no `temporarySignedRoute` anywhere.
Short-lived access uses S3 presigned URLs: recordings at 15 minutes
(`resources/views/filament/components/recording-player.blade.php:19-25`)
and MMS media through `messaging.media.link_ttl_minutes`
(`app/Models/MessageEntry.php:159-170`, `config/messaging.php:155`).
Both fall back to an unsigned `Storage::url()` if signing throws.

**Audit.** Nothing records who viewed what. The only audit model is
`FailoverAuditLog`. Telescope is enabled by default
(`config/telescope.php:19`) and in production it records failed
requests, failed jobs and exceptions
(`app/Providers/TelescopeServiceProvider.php` filter), and those
payloads can contain caller data. The error-reporting path is scrubbed
(`config/observability.php:40-57`, `App\Services\Observability\Scrubber`).

**Retention.** Only recordings expire.
`PruneExpiredCallRecordingsJob` (`app/Jobs/PruneExpiredCallRecordingsJob.php:35-148`)
resolves a per-team window and caches it (`:56-70`), walks rows with
`chunkById` (`:91`), treats 0 as keep-forever (`:97-99`), is
idempotent (`:32`), and runs daily under `onOneServer`
(`routes/console.php:36-40`). Messages, transcripts, text threads and
email are never pruned.

**AI and other sub-processors that receive caller content.**
`config/orbital.php:150-171` (LLM default `anthropic`, STT/TTS default
`elevenlabs`, plus `openai`, `openrouter`), `config/services.php:70-110`
(`deepgram`, `cartesia`, OpenAI embeddings default
`openai:text-embedding-3-small`, self-hosted `ollama` and
`whisper_local`), `config/telephony.php:110` (AI fallback voice
provider), and per-client voicemail transcription providers
(`TranscribeVoicemailJob.php:84`). There is no per-client "BAA-covered
providers only" constraint.

**miSecure.** The Amtelco directory import keeps `.150S"<handle>".151S`
macros labelled "MISECURE TXT" as raw text (`TODO.md:483-484`).
Nothing else refers to miSecure.

## Goal

A healthcare client can be switched to **PHI mode**. From then on:

1. No message content leaves Orbital in clear SMS, email, pager or push.
   Recipients get a notice and a link. They open the message in Orbital
   after proving who they are, and that open counts as plan 05's
   acknowledgement.
2. PHI is encrypted at rest at the application layer, every access is in
   an append-only audit log, links expire, and content is deleted on a
   per-client retention schedule.
3. Recipients can reply securely in a portal-hosted thread (phase 2) and,
   if clients require it, use a mobile app with push and in-app ack
   (phase 3).
4. A PHI-mode client can only use transports and AI providers that the
   platform operator has marked as covered by a BAA. Orbital refuses the
   others instead of quietly sending.

Non-goals: certifying anyone as "HIPAA compliant" (compliance belongs
to the operator's organisation, not to the software), end-to-end
encryption where the server can't read content (operators must read
it), and a clone of miSecure.

## Design

### 0. Concepts

- **PHI mode** (`teams.phi_mode`, boolean, default false). It is a
  client-level switch and the only one most installs touch. It turns on
  secure delivery by default, provider restrictions, a stricter
  session, and audit of list views as well as detail views.
- **PHI class per template field.** This builds on plan 03's
  `message_templates` field list, which already has
  `redact-in-email`. We replace that boolean with `phi_class`:

  | `phi_class` | Meaning | Examples | Behaviour |
  |---|---|---|---|
  | `none` | Not PHI on its own | callback window, "is this urgent" | May appear in notices only if the template allows it **and** PHI mode is off |
  | `identifier` | Identifies a person | caller/patient name, DOB, MRN, phone, address | Encrypted; blind-indexed if searchable; never in a notice |
  | `clinical` | Health information | reason, symptoms, medication, provider seen | Encrypted; never in a notice; not indexed |
  | `restricted` | Extra-sensitive (behavioural health, SUD, HIV, minors) | flagged per template | As `clinical`, and also hidden from the operator list preview and sent only to recipients flagged `restricted_ok` |

  Promoted columns get a fixed class: `caller_name`, `caller_phone` =
  `identifier`; `reason`, `notes` = `clinical`. Template authors only
  classify custom fields. A new field defaults to `clinical`, so it
  fails closed.
- **Template delivery mode** (`message_templates.secure_delivery`):
  `off | auto | always`. `auto` means "secure when the team is in PHI
  mode or the message holds any field above `none`". PHI mode forces
  `always`.

### 1. Data model (all `BelongsToTeam`, all `team_id` NOT NULL + FK cascade)

**Changes to existing tables**

- `teams`: `phi_mode` bool; `message_retention_days` int (0 = forever,
  default null = platform default); `secure_link_ttl_hours` int
  (default 72; urgent messages use `min(ttl, 24)`);
  `secure_view_acknowledges` enum `view|button` (default `view`, as the
  survey specifies; `button` for clients who want an explicit "I have
  this"); `portal_idle_timeout_minutes` (PHI mode default 15);
  `legal_hold_until` nullable date.
- `messages`: encrypt `caller_name`, `reason`, `notes` and plan 03's
  `fields` (see §3). Add `caller_phone_bidx` (HMAC blind index, below),
  `contains_phi` bool (computed by the builder, which drives secure
  delivery and audit verbosity), `purge_after` timestamp (set at save
  from retention), and `purged_at`.
- Plan 03 `message_templates.fields[*]`: `phi_class`. Template:
  `secure_delivery`, `notice_text` (one line; only `{{service_name}}`,
  `{{client_display_name}}`, `{{urgency}}`, `{{link}}` are allowed, and
  the evaluator rejects any other variable at save time).
- Plan 04/05 directory contact methods: add `secure_link` (wraps
  `sms`/`email`/`push`, so the notice still needs a transport) and
  `secure_app` (phase 3 / miSecure).
- `directory_entries`: `restricted_ok` bool (see `restricted` above).

**New tables**

- `secure_recipients`: who can open secure content without a portal
  login. `team_id`, `directory_entry_id` nullable, `user_id` nullable
  (a portal user always authenticates through portal login; this row
  links the two), `display_name`, `pin_hash` (argon2id) nullable,
  `pin_set_at`, `failed_attempts`, `locked_until`, `enrolment_token_hash`
  and `enrolment_expires_at` (first-time PIN setup), `status`
  (`invited/active/locked/disabled`). Exactly one of
  `directory_entry_id`/`user_id` is set (check constraint).
- `secure_links`: `team_id`, `token_hash` (sha256 of a 128-bit random
  token, base64url, 22 chars; the clear token exists only in the
  notice), `subject_type`/`subject_id` (`message`, phase 2 `secure_thread`),
  `secure_recipient_id`, `delivery_attempt_id` (plan 05) nullable,
  `auth_mode` (`pin|portal_login|pin_or_portal`), `expires_at`,
  `max_opens` (default null = unlimited until expiry), `open_count`,
  `first_opened_at`, `last_opened_at`, `revoked_at`, `revoked_reason`.
  Unique index on `token_hash`. Index on `(team_id, subject_type, subject_id)`.
- `secure_link_sessions`: what you get after a successful PIN entry.
  `secure_link_id`, `session_token_hash`, `ip`, `ua_hash`,
  `expires_at` (10 minutes idle, 30 minutes absolute), and optional
  `trusted_device_id` for "don't ask for the PIN on this device for 12
  h" (an HttpOnly, SameSite=Strict, `__Host-` cookie bound to the
  recipient).
- `phi_access_events` (append-only, see §5).
- Phase 2: `secure_threads`, `secure_thread_participants`,
  `secure_posts`, `secure_post_reads`.
- Phase 3: `push_devices`.

**Blind index.** `caller_phone_bidx = HMAC-SHA256(PHI_INDEX_KEY, E.164(phone))`.
It keeps exact-match lookup by caller phone working for plan 13 caller
history and for portal search, without storing the number in clear.
Names are not blind-indexed: a fuzzy name search over PHI is a feature
we give up in PHI mode (portal search becomes phone, date, status,
urgency).

### 2. Services

All new services live in `app/Services/SecureMessaging/` and carry
`declare(strict_types=1)`.

- **`PhiClassifier`**: given a template plus field values, returns the
  per-field class, `contains_phi`, and the effective delivery mode.
  Plan 03's builder calls it at save. It is pure and table-tested.
- **`SecureLinkIssuer`**: `issue(Message|SecureThread $subject,
  SecureRecipient $r, ?DeliveryAttempt $a): IssuedLink`. It creates the
  row, returns the clear URL `https://{portal_host}/s/{token}` once,
  and never logs the token (the URL goes into the Scrubber's deny-list).
  Each plan 05 delivery attempt gets its **own** link, so we can tell
  which attempt was opened.
- **`SecureNoticeRenderer`**: renders the one-line notice per channel
  from `notice_text`. Example SMS: `NCI Answering: new urgent message
  for Dr. Smith's office. Open securely: https://p.example/s/Ab3…`
  (fits in 160 GSM-7 characters with a 22-character token and a short
  host). The email subject is fixed ("New secure message"), with no
  caller, reason or patient in the subject, preheader or `Message-ID`.
  The email body carries the link only. The email has no attachments,
  ever.
- **`SecureDeliveryTransport`**: a plan 05 transport, so
  `send(Recipient, RenderedMessage)` holds no special cases. For a
  secure contact method it issues a link, renders the notice, and hands
  the notice to the underlying `MessagingProvider` / mailer / push. The
  attempt's status means "notice sent". The **open** produces
  `delivered`, and with `secure_view_acknowledges=view` it also
  produces the plan 05 `acknowledgements` row (`method=secure_link_view`).
- **`PhiEgressGuard`**: a last-line check that runs inside
  `OutboundMessageService::send()` (`OutboundMessageService.php:71`),
  the two mail services, and every plan 05 transport. If the team is in
  PHI mode and the payload isn't a rendered secure notice (each notice
  carries a marker type, `SecureNotice`, not a string), it refuses. The
  failure is recorded the same way the opt-out block is recorded
  (`:83-101`), so the operator sees why nothing went. This is the
  "chokepoint" pattern the service already documents (`:27-31`).
  Operator free-text SMS replies on a PHI client's thread are blocked
  with a "use secure reply" prompt.
- **`SecureLinkGate`** (controller + middleware). It handles
  `GET /s/{token}`, `POST /s/{token}/pin` and `GET /s/{token}/view`:
  1. Hash the token and look it up with
     `SecureLink::withoutGlobalScope('team')`, which is explicit because
     no user is authenticated. Bind the team from the row, and from
     then on make **every** query `where('team_id', $link->team_id)`.
     Don't trust the global scope on this route.
  2. If the link is unknown, expired, revoked, the recipient is
     disabled, the team is suspended, or the message is purged, show the
     same generic "This link is no longer valid. Contact {service}."
     page. The page includes no team or message information and returns
     HTTP 410/404 with identical timing.
  3. `auth_mode=pin`: a PIN form, rate limited per link (5 tries, then
     the link is locked) and per recipient (10 tries per hour across
     links, then `locked_until` +30 min). Every failure is audited.
     `auth_mode=portal_login`: redirect to portal login, then back. The
     user must belong to the team **and** match `secure_recipients.user_id`.
     Belonging to the team is not enough, because a receptionist with a
     login is not the on-call doctor.
  4. On success, render the message read-only with
     `Cache-Control: no-store`, `Referrer-Policy: no-referrer`,
     `X-Robots-Tag: noindex`, a strict CSP, and no third-party assets.
     Then emit the audit event and the delivery/ack side-effects
     idempotently (a unique ack per dispatch, as plan 05 tests require).
- **First-time PIN enrolment.** A directory recipient with no PIN gets
  an enrolment link through a **different** channel from the notice
  when we have one (email if notices go by SMS). Otherwise the operator
  or client admin sets an initial PIN and reads it out by phone, and the
  recipient must change it on first use. The enrolment link expires in
  7 days. A recipient with neither gets the plain "call the service"
  fallback, and plan 05 escalates to a voice callout. Avoid the trap of
  "the SMS link lets you set a PIN": anyone who holds the phone would
  then own the account.
- **`MessagePurger`** (job `PruneExpiredMessagesJob`, see §6).
- **`ProviderCompliance`**: a registry of every sub-processor key
  (`twilio`, `smtp:<host>`, `anthropic`, `openai`, `openrouter`,
  `elevenlabs`, `deepgram`, `cartesia`, `ollama`, `whisper_local`,
  `s3:<endpoint>`, `livekit_cloud`, `error_reporting`), each with
  `phi_allowed` (platform setting, set by the operator after signing a
  BAA, plus a `baa_reference` note and date). Self-hosted providers
  (`ollama`, `whisper_local`, `livekit` self-hosted, SeaweedFS) are
  allowed by default. PHI-mode teams fail validation when they pick a
  persona LLM/STT/TTS, a voicemail transcription provider, an embedding
  model for a knowledge store, or a messaging endpoint whose provider
  isn't `phi_allowed`. `orbital:status` lists violations, and
  `php artisan orbital:generate-config` / persona sync refuse to push
  one.

### 3. Encryption at rest

- **A dedicated key, not `APP_KEY`.** Add an `EncryptedPhi` cast backed
  by its own `Illuminate\Encryption\Encrypter` (AES-256-GCM) keyed by
  `PHI_ENCRYPTION_KEY`, with `PHI_PREVIOUS_KEYS` for rotation (the same
  semantics as Laravel's `APP_PREVIOUS_KEYS`). Why not reuse
  `encrypted`: rotating `APP_KEY` for a session-cookie incident would
  otherwise force a PHI re-encrypt, and the backup manifest already has
  to warn about APP_KEY (TODO.md:794). Add a second line to that
  manifest for the PHI key. The ciphertext carries a key id, so
  `orbital:phi:rekey --chunk=1000` can re-encrypt in the background
  after a rotation.
- **What gets encrypted, and for every client, not only PHI mode.** One
  code path keeps it simple. A client switched into PHI mode later then
  needs no data migration, and the cost is small (decrypting 25 rows
  per page).
  - Phase 1: `messages.caller_name`, `reason`, `notes`, `fields` (plan
    03), and `message_revisions.diff` (plan 03).
  - Phase 1b, as a separate PR because each has its own readers:
    `call_logs.transcript`, `voicemails.transcript`,
    `message_entries.body`, `email_messages.body_text/body_html`,
    `call_session_states.fields`, `message_threads.fields`. Each gets an
    inventory row in `docs/admin/security.md` ("where caller data
    lives").
  - `caller_phone` stays clear in phase 1, because routing, opt-out and
    thread matching all use it. It gets a blind index now, and becomes
    encrypted plus index-only in a later phase once plan 13 reads the
    index.
- **Search consequences.** Plan 03 proposes a GIN index on
  `messages.fields`. With encryption, that index can only cover a
  separate `fields_searchable` JSONB that holds `phi_class=none` fields.
  Plan 03 should adopt this split when it lands, and this plan records
  it as a dependency.
- **Objects.** Recordings, voicemail WAVs, MMS media and secure-thread
  attachments: require SSE on the recordings/media buckets (`SSE-S3`
  where the backend supports it, including SeaweedFS; for AWS add
  `ServerSideEncryption` to the disk's `options`). `orbital:status`
  checks the bucket's default encryption and that PHI disks are never
  the public `orbital` asset bucket, which `S3BucketBootstrapper`
  deliberately makes public-read
  (`app/Services/Bootstrap/Bootstrappers/S3BucketBootstrapper.php:114-130`).
  Secure-thread attachments are additionally app-encrypted
  (secretstream, as in `ArchiveCipher`) because they come from outside
  and may land on any disk.
- **In transit.** HTTPS only. HSTS on the portal host. The mailer must
  use SMTP with STARTTLS or TLS (`mail.mailers.smtp.scheme`).
  `orbital:status` warns when a PHI-mode team exists and the mailer is
  `log`/unencrypted. The inbound mail path (Haraka) must offer STARTTLS
  before phase 2 accepts any replies by email.

### 4. UI

**Recipient (secure link page).** A minimal Blade page under the portal
host and outside the Filament panel. It has no navigation, so a link
recipient never sees other messages. It shows: service/client branding
(`Branding::portal*`), the message rendered with the plan 03 portal
template, urgency, time taken, callback number as a `tel:` link, the
recording (if the template allows it and the recipient has
`can_hear_recording`) through a 5-minute presigned URL, and the
delivery/ack timeline for this message only. Buttons:
**Acknowledge** (when the client uses `button` mode), **Reply securely**
(phase 2), **Call the service**. A recipient who also has a portal
login sees "Open in portal" instead.

**Portal (plan 03 list plus this plan).**
- The message detail shows a "Delivered securely: opened by Dr. Smith
  14:02 via link (SMS)" timeline.
- **Access log** page (`portal.view_access_log`): who viewed which
  message, filterable, CSV export (the export is itself audited).
- **Secure recipients** page (`portal.manage_secure_recipients`): list
  of directory people, PIN status, reset PIN (sends enrolment), disable,
  revoke all open links.
- With PHI mode on: idle timeout (`teams.portal_idle_timeout_minutes`)
  through a small Alpine timer plus server-side check middleware, list
  views show the caller's initials and "reason hidden" until a row is
  opened (configurable), and print/CSV export of message content
  requires `portal.export_messages`.

**Operator / admin.**
- Client record → **Compliance** tab (super-admin): PHI mode,
  retention, link TTL, view-vs-button ack, idle timeout, legal hold,
  and a read-only "providers in use and their BAA status" panel from
  `ProviderCompliance`.
- Platform Settings → **Sub-processors**: per provider `phi_allowed`,
  BAA reference and date (`SettingsRegistry`, which already supports
  secret/encrypted settings).
- Message view: a lock badge on PHI messages. Plan 05's dispatcher
  queue shows "notice sent / not yet opened" separately from "opened".
  "Resend secure link" and "revoke links" actions.
- Message template editor (plan 03): a `phi_class` select per field,
  `secure_delivery` mode, notice text with the restricted variable set
  and a live 160-character counter.

### 5. Access audit log

`phi_access_events`, append-only:

| column | notes |
|---|---|
| `id` (bigint), `occurred_at` (timestamptz, indexed) | |
| `team_id` | the **subject's** team, NOT NULL |
| `actor_type` | `user`, `secure_recipient`, `system`, `api_token`, `agent_worker` |
| `actor_id`, `impersonator_user_id` | impersonated sessions record both (impersonation has no banner yet: `docs/admin/security.md:88-92`) |
| `panel` | `portal`, `operator`, `admin`, `secure_link`, `api`, `app` |
| `action` | `list`, `view`, `play_recording`, `download`, `export`, `print`, `edit`, `deliver_notice`, `link_open`, `pin_fail`, `pin_lockout`, `ack`, `reply`, `revoke`, `purge`, `rekey` |
| `subject_type`, `subject_id`, `subject_ids` (int[] for list/export) | |
| `secure_link_id`, `delivery_attempt_id` | when relevant |
| `ip`, `ua_hash`, `request_id` (OTel trace id) | |
| `prev_hash`, `hash` | per-team hash chain: `sha256(prev_hash ‖ canonical row)` makes tampering detectable |

- **Write path.** An explicit `PhiAudit::record(...)` call goes in the
  controller, Livewire action or Filament action that shows content. We
  don't use the Eloquent `retrieved` event, because it fires in jobs,
  counts and exports and would drown the log. A Pest architecture test
  enforces that every portal/operator page rendering
  `Message`/`CallLog` content calls it (a list of known render points,
  plus a test that fails when a new Filament page queries `Message`
  without being on that list).
- **Append-only in the database.** A `BEFORE UPDATE OR DELETE` trigger
  raises an exception. Purges use a separate `SECURITY DEFINER`
  function, callable only by the retention job's role, and only for
  rows past audit retention. The migration documents granting the app
  role `INSERT, SELECT` only, for installs that run separate roles.
- **Retention**: 6 years by default
  (`config/orbital.php security.audit_retention_days = 2190`), which is
  independent of message retention. A purged message keeps its audit
  rows (subject id, no content).
- **Telescope and logs.** In PHI-enabled installs, Telescope's
  production filter should exclude job payloads and request bodies, or
  `TELESCOPE_ENABLED=false` should be the documented default.
  `Log::*` calls in new code take ids only. The Scrubber gets the
  secure-link URL pattern and PIN fields.

### 6. Retention and deletion

A new `PruneExpiredMessagesJob`, modelled on
`PruneExpiredCallRecordingsJob` (per-team resolve with a cache,
`chunkById(500)`, idempotent, daily `onOneServer`):

- Eligible when `purge_after < now()`, the team has no
  `legal_hold_until > now()`, and the message is not in an open plan 05
  dispatch.
- **Purge, not delete.** Null the encrypted content columns, set
  `purged_at`, and keep the row (id, team, timestamps, urgency, delivery
  and ack metadata) so billing (plan 09), reports (plan 10) and the
  audit log still join. Hard delete is optional
  (`teams.message_retention_mode=hard`) for clients whose contracts
  require it. In that mode the audit rows keep the id only.
- Cascades: revoke and delete `secure_links` / sessions for the message,
  purge secure-thread posts and attachments (phase 2), and delete
  voicemail WAV/transcript on the same schedule when the team is in PHI
  mode.
- Link rows are deleted 30 days after expiry. Audit rows follow §5.
- Backups: purged content survives in encrypted backups until those
  backups age out. The backup retention setting is documented as part
  of the effective retention (`config/backup.php`).
- Defaults: platform `MESSAGE_RETENTION_DAYS=0` (keep, today's
  behaviour). PHI mode forces an explicit choice when it is enabled; the
  form won't save without one.

### 7. Permissions and tenant isolation

New permissions, added to the catalogue with descriptions in
`RoleResource.php:233-238`:

| Permission | Namespace | Default |
|---|---|---|
| `portal.view_access_log` | client | allow-list + `client_admin` |
| `portal.manage_secure_recipients` | client | allow-list + `client_admin` |
| `portal.export_messages` | client | allow-list, not on `client_user` |
| `portal.reply_secure` (phase 2) | client | allow-list + `client_user` |
| `messages.view_phi_audit` | platform | supervisor, super_admin |
| `compliance.manage` (PHI mode, sub-processors) | platform | super_admin |

They are added to `ClientProvisioner::DEFAULT_TENANT_ALLOW_LIST`
(`:49-56`), with a migration that back-fills existing clients'
`client_permission_grants` through `ClientPermissionGatekeeper`.

Isolation rules:
- Every new model uses `BelongsToTeam`, **and** every query in the
  public secure-link path filters `team_id` explicitly, because the
  global scope is a no-op without an authenticated user
  (`BelongsToTeam.php:17-20`).
- A secure link resolves a recipient only from the **same** team. A
  directory person who is on-call for two clients has two
  `secure_recipients` rows, each with its own PIN, so a PIN leak at
  client A never opens client B's messages.
- Portal users authenticate to a link only if
  `secure_recipients.user_id = auth()->id()` **and** the link's team is
  one of their teams. Switching `current_team_id` happens automatically
  and is audited.
- Super-admin access to PHI is allowed (it is the job; `SECURITY.md`
  scope excludes malicious super-admins), but it is **always audited**
  with the impersonator recorded.

### 8. Phases

**Phase 1: secure link (the P2 deliverable).** PHI classification in
templates, `phi_mode`, encryption of the message columns,
`SecureLinkIssuer`/`Gate`/`NoticeRenderer`, PIN recipients and
enrolment, the `SecureDeliveryTransport` in plan 05, view = ack,
`PhiEgressGuard`, the audit log plus the portal access-log page,
`PruneExpiredMessagesJob`, `ProviderCompliance` checks, and switching
voicemail email to a notice plus link for PHI clients (the
`VoicemailReceived` content moves behind the gate).

**Phase 2: secure two-way thread in the portal.** It reuses plan 16's
thread concepts but is a separate store, so PHI never mixes with
`MessageThread`/`EmailThread` rows that carriers and mailers touch.
- `secure_threads` (team, optional `message_id`, subject = message
  summary *without* PHI, status open/closed), with participants
  (portal user, secure recipient, operator pool for the client, or an
  external guest invited by email with OTP plus PIN). `secure_posts`
  have an encrypted body, attachments (app-encrypted, size and MIME
  limits, AV scan hook), `reply_to_post_id`, and `edited_at` (no
  silent edits; edits create a new post). `secure_post_reads` give
  per-participant read receipts, which also feed plan 16's read-receipt
  timeline.
- New posts notify the other participants through the same notice and
  link machinery (a link to the thread, plus a deep link to the post).
- Operators see secure threads in the operator workspace next to text
  conversations. A reply from an operator is a post, never an SMS or
  email.
- **Email replies to a notice are not accepted as content.** The notice
  `Reply-To` is a dedicated `secure-noreply+{link}@` local part, and
  `InboundRouter` (`app/Services/Mail/InboundRouter.php`) routes that
  local part to an auto-response ("Replies by email aren't secure.
  Reply here: {link}"). The inbound body is discarded and audited as
  `reply_rejected`, and never stored in `email_messages`.
- `OutboundForwardService` is blocked by `PhiEgressGuard` for PHI teams.
  It forwards whole bodies to arbitrary addresses (`:26-86`). A secure
  forward becomes "invite a guest participant".

**Phase 3: optional mobile app.** We build it only if clients demand it
(survey item K).
- **Step 3a: PWA plus Web Push first.** The portal is installable,
  Web Push uses VAPID (no third-party SDK), and the push payload is
  PHI-free ("New secure message", plus an opaque id). Opening the app
  needs the portal session, and after phase 2 of 2FA, a passkey. This
  gives push and in-app ack with no app-store work. iOS supports Web
  Push for home-screen web apps.
- **Step 3b: native wrapper (Capacitor) only for features a PWA can't
  do.** These are iOS Critical Alerts or Android full-screen intents to
  break through Do Not Disturb (Apple's Critical Alerts entitlement
  needs approval), an escalating ringtone, and reliable background ack.
  Auth uses OAuth 2.0 + PKCE through the installed Passport
  (`composer.json:15`) with refresh tokens bound to a `push_devices` row
  (`team_id`, `user_id`/`secure_recipient_id`, platform, push token,
  app version, `last_seen_at`, `revoked_at`). Biometric unlock is
  required after 5 minutes in the background. Content is never cached
  offline (the app fetches on open, and the WebView is `no-store`).
  Revoking a device kills its tokens on the next request.
- Acks from the app are plan 05 `acknowledgements` with
  `method=app_ack`, which escalation already understands. An unread
  push followed by no open escalates the same way an unopened SMS
  notice does.

### 9. BAA and sub-processor implications

The platform operator (the answering service) is a **business
associate** of its covered-entity clients. Every vendor that creates,
receives, stores or transmits PHI on the operator's behalf is a
**subcontractor** that needs a BAA. Orbital's job is to make that
inventory visible and enforceable. It does not decide whether a vendor
is covered. Vendor terms change, so the operator must verify each one;
the table records what to check, not legal advice.

| Flow | Provider(s) in the code | PHI exposure today | With this plan | Action for the operator |
|---|---|---|---|---|
| SMS notices | Twilio (`TwilioProvider`) | Full content in thread replies | Notice only; no patient data | Twilio offers a BAA for HIPAA-eligible products; SMS itself isn't secure, so content never goes by SMS. A BAA is still advisable (the recipient's number and the client's name are metadata) |
| Email | Whatever `MAIL_*` points at (any SMTP relay, e.g. a Google Workspace relay or self-hosted Postal) | Voicemail transcript plus WAV, thread replies and forwards | Notice only | A BAA with the relay provider (a Workspace BAA must explicitly cover the relay service), or a self-hosted MTA; TLS required |
| Inbound email | Self-hosted Haraka | Caller emails | Unchanged; PHI reply-by-email rejected (phase 2) | Enable STARTTLS |
| LLM (live calls, summaries) | Anthropic, OpenAI, OpenRouter (`config/orbital.php:150-171`) | Full conversation | Unchanged, but gated by `phi_allowed` | Anthropic and OpenAI offer BAAs under specific (enterprise / zero-retention) terms; **OpenRouter brokers to many upstreams, so treat it as not coverable**; Ollama is self-hosted |
| STT/TTS | ElevenLabs, Deepgram, Cartesia, OpenAI; `whisper_local` | Caller audio and text | Gated | ElevenLabs and Deepgram offer BAAs at certain tiers; confirm Cartesia; whisper-local is self-hosted |
| Embeddings / knowledge stores | OpenAI default (`config/services.php:109`) | Client documents (could hold PHI) | Gated | Use Ollama for PHI clients |
| Voicemail transcription | Per-client provider (`TranscribeVoicemailJob.php:84`) | Caller audio | Gated | as above |
| Media / object storage | `s3` disk (self-hosted SeaweedFS or a cloud S3) | Recordings, MMS | SSE required | BAA if cloud |
| LiveKit | Self-hosted or LiveKit Cloud | Live media | Gated (`livekit_cloud`) | BAA if Cloud |
| SIP trunks | Carrier | Live voice | Unchanged | Generally treated as a conduit; confirm with counsel |
| Error reporting / tracing | GlitchTip/Sentry/OTel endpoint (`config/observability.php`) | Scrubbed, but stack traces | Off for PHI unless self-hosted or BAA'd | Already documented as sub-processor-sensitive |
| Backups | `backups` disk (`config/filesystems.php:89-122`) | Encrypted DB | Unchanged (client-side encrypted) | BAA still advisable if cloud |
| Push (phase 3) | VAPID push services / APNs / FCM | Payload PHI-free | n/a | No PHI in the payload, so it stays out of BAA scope; document it |
| Orbital vendor support | Support bundles / remote assistance | Possible | Bundles must exclude message tables | A BAA with the software vendor only if they access production data (hosted / support) |

`docs/admin/security.md` gains a "HIPAA deployments" section and a
generated **sub-processor list** (from `ProviderCompliance`) that the
operator can attach to their own BAAs with clients.

### 10. miSecure interop (open, not built)

The import shows clients' clinicians reachable by miSecure handles
(`TODO.md:483-484`). The options, in order of preference:
1. **Coexist.** Map the `.150S` macro to a `secure_app:misecure`
   contact method that, until there is an integration, falls back to
   this plan's secure link to the same person's mobile number (with a
   note to the operator). That needs the macro decoded first (TODO).
2. **Integrate**, if Amtelco exposes an API or gateway that accepts
   third-party messages for miSecure users (unknown; it needs a
   conversation with Amtelco and probably a commercial agreement, plus a
   BAA chain). It would plug in as a plan 05 transport with ack
   callbacks.
3. **Migrate** recipients to Orbital's secure link/app at cut-over. This
   is the cheapest, but it means clinicians change app.

## Edge cases

- **Link forwarded or shoulder-surfed.** The PIN stops it. With
  `trusted_device` the PIN is skipped only on the same browser. An open
  by someone with a PIN that isn't the intended recipient's can't happen
  because PINs belong to recipients, not links.
- **Notice SMS to a shared or recycled number.** It carries no PHI. The
  PIN is required. Plan 05's escalation still fires if nobody opens it.
- **Preview bots** (email security scanners, iMessage/Slack link
  unfurlers) "open" links. `GET /s/{token}` only shows the PIN or login
  page and never counts as an open or ack. Only a successful
  authentication counts. HEAD and GET without cookies don't change
  state. This matters because unfurlers would otherwise acknowledge
  urgent messages nobody read.
- **View = ack but the recipient can't act.** The client can choose
  `button` mode. Even in `view` mode, the recipient page shows "Not
  for me / can't take this" which reopens escalation (a plan 05
  `decline` that moves to the next tier).
- **Multiple recipients, one ack.** The first ack stops escalation.
  Other recipients' links still open (until expiry) and show "already
  acknowledged by Dr. X at 14:02". Their opens are audited but don't
  ack again.
- **Message edited after delivery** (plan 03 revisions). The link
  always shows the latest version with a "changed since sent" banner.
  An edit to a PHI field on an already-acknowledged message may
  re-dispatch, depending on the template (plan 05 policy).
- **Message purged while a link is live.** The link shows the generic
  invalid page, and the purge revokes links first.
- **Client switches PHI mode off.** Existing links keep working.
  Encrypted data stays encrypted (encryption is universal). Notices
  revert to template behaviour. The switch is audited and
  super-admin-only.
- **Client switches PHI mode on with messages in flight.** Dispatches
  already in a tier finish with their rendered bodies. New attempts use
  secure links. It shows on the dispatcher board.
- **Recipient locked out at 3am.** The page shows the service's phone
  number, plan 05 escalates to voice callout or the operator, and a
  client admin or supervisor can unlock from the portal or the operator
  UI (audited).
- **Daylight saving / clock skew.** Expiry is absolute UTC.
- **Very long tokens in SMS.** A 22-character token plus a short host
  keeps the notice to one segment. A host longer than 25 characters
  triggers a warning in the template editor.
- **Restricted-class fields.** A recipient without `restricted_ok`
  sees "Additional details withheld. Call the service", and the
  operator gets a dispatcher task.
- **Super-admin impersonating a portal user opens a link.** It is
  allowed and audited with `impersonator_user_id`, and it does **not**
  ack (an impersonated session is never the recipient).
- **Key loss.** Losing `PHI_ENCRYPTION_KEY` means messages can't be
  read. The backup manifest records the key fingerprint, and
  `orbital:restore` refuses when the fingerprint doesn't match.
- **The AI agent reads PHI back to a caller** (e.g. a caller asks
  "what did I leave?"). Out of scope, and it stays as today.
  `phi_allowed` gating covers the provider side.

## Migration / rollout

1. **Migration A (additive):** new tables, new team columns, and the
   `caller_phone_bidx` column. Nothing is switched on.
2. **Migration B (encrypt in place):** a chunked command
   `orbital:phi:encrypt-messages` (not a schema migration, because it is
   long-running and must be resumable) writes ciphertext into new
   `*_enc` columns, verifies by decrypting, then swaps the column names
   in a short migration. During the window, readers go through an
   accessor that prefers `*_enc`. Text columns grow by about 1.4x plus
   overhead.
3. Ship `PhiAudit` calls on existing surfaces (operator message views,
   portal call history, recordings) **before** the secure link, so the
   log is already populated and tested.
4. Add the `ProviderCompliance` settings, default `phi_allowed=false`
   for every external provider. Only PHI-mode teams are affected, and
   there are none yet.
5. Phase 1 behind `phi_mode` per client. Pilot with one medical client
   and a handful of on-call recipients. Enrol PINs by phone with the
   client admin.
6. Switch voicemail email to notice plus link for PHI clients.
7. Phase 2 after plan 16 lands. Phase 3a after passkeys (roadmap).
   Phase 3b only on demand.
8. Docs: `docs/admin/security.md` (HIPAA section, sub-processors, data
   inventory), `docs/portal/` (secure messages, access log), and the
   client-facing "how to open a secure message" page.

## Tests

Pest feature tests, using plan 05's fake transports, `travel()`, and
the shared answering-service fixtures (README "Testing strategy").

**Classification and rendering**
- A template with name/DOB/reason fields classifies them as
  `identifier`/`clinical`, `contains_phi=true`, and selects secure
  delivery. A new field with no class is treated as `clinical`.
- `notice_text` containing `{{ fields.dob }}` is rejected at save. A
  rendered SMS notice is ≤160 GSM-7 characters and contains none of the
  message's field values (property test: random field values never
  appear in the notice).
- The email notice has no PHI in subject, preheader, headers or
  `Message-ID`, and no attachments.

**Egress guard**
- PHI-mode team: an operator SMS reply on a thread is blocked and
  recorded as a failed `MessageEntry` with a reason, and the provider
  fake records no send. `OutboundForwardService` refuses. The
  voicemail job sends a notice, not `VoicemailReceived` with a WAV.
- Non-PHI team: behaviour unchanged (regression).

**Secure link lifecycle**
- Issue → open with the right PIN → message shown → plan 05 dispatch
  `acknowledged` with `method=secure_link_view`, escalation timers
  cancelled (`travel()` past `wait_minutes` and no tier 2 attempt).
- `button` mode: the open makes the attempt `delivered` but does not
  ack. Clicking acknowledges.
- Unauthenticated GET (unfurler simulation) changes nothing: no open,
  no ack, no audit `link_open`.
- Wrong PIN ×5 locks the link. ×10 across links locks the recipient.
  Every failure is audited, and the lockout releases after 30 minutes.
- Expired, revoked and unknown tokens, a purged message and a suspended
  team all give byte-identical responses with the same status.
- Two recipients: A acks, B opens and sees "acknowledged by A", and
  there is no second ack row.
- A replayed POST of the PIN form after success is idempotent.
- The token never appears in `storage/logs`, Telescope entries, or a
  scrubbed error event (assert on captured log output).

**Tenant isolation**
- A link for client A's message, opened with the PIN of the same doctor's
  client-B recipient row, fails.
- A portal user of client A with `secure_recipients.user_id` set for
  client B can't open client A's link even when `current_team_id` is A.
- A portal user in the right team but not the recipient is refused.
- The access-log page shows only the viewer's team rows. CSV export is
  team-filtered and audited.
- The public controller works with **no** authenticated user and never
  returns another team's row (seed two teams with colliding ids in
  related tables).

**Encryption**
- After save, `DB::table('messages')` raw values for name/reason/notes/
  fields are not plaintext and decrypt through the model.
- Key rotation: data written under the old key reads after rotation,
  `orbital:phi:rekey` re-encrypts, and the old key can then be removed.
- `caller_phone_bidx` lookup finds the message, and a different
  normalisation of the same number (`(555) 123-4567` vs `+15551234567`)
  matches.
- Backup/restore round-trip with the PHI key fingerprint check.

**Audit log**
- Viewing a message in the operator panel, portal, secure link and
  recording play each writes exactly one event with the correct actor
  and panel. An impersonated view records the impersonator.
- `UPDATE`/`DELETE` on `phi_access_events` throws. The hash chain
  verifies, and detects a modified row inserted by superuser SQL.
- Architecture test: every Filament page/resource that queries
  `Message` is in the audited render-points list.

**Retention**
- Team retention 30 days: a message at 31 days is purged (content null,
  row kept, links revoked, audit kept). At 29 days it is kept.
  Retention 0 keeps the message. Legal hold keeps it. A message in an
  open dispatch is kept until the dispatch closes.
- The job is idempotent across two runs and per-team cached (query
  count assertion, as the recording job does).
- Audit rows older than 6 years are purged only through the definer
  function.

**Provider compliance**
- PHI-mode team: saving a persona with an OpenRouter LLM fails
  validation. Choosing ElevenLabs TTS with `phi_allowed=false` fails.
  Setting it true passes. `orbital:status` lists the violation.
  `orbital:generate-config` refuses to push a non-compliant persona.

**Phase 2**
- A post is encrypted at rest. A notice goes to the other participants.
  Read receipts per participant. An email reply to a notice is rejected
  with an auto-response and nothing stored. An attachment over the size
  limit or with a disallowed MIME type is refused. A guest participant
  can't see other threads.

**Phase 3**
- The push payload contains no field values (snapshot). An in-app ack
  produces `method=app_ack` and stops escalation. A revoked device's
  refresh token fails. A device for team A can't fetch team B's thread.

## Open questions

1. **Which NCI clients are covered entities**, and do they already have
   BAAs with NCI? That tells us whether PHI mode is needed at launch or
   at first healthcare onboarding (survey priority P2).
2. **View = ack or explicit button** as the platform default. The
   survey says view; some medical directors want an explicit button
   for liability. This plan defaults to view, configurable per client.
3. **Recipient identity.** Is a 4–6 digit PIN acceptable to NCI's
   clients, or do they want portal login (plus passkey) for all
   clinicians? Who resets PINs at night (NCI supervisors or client
   admins)?
4. **miSecure.** Does Amtelco offer third-party injection or an API for
   miSecure users, and on what terms? How many NCI recipients use
   miSecure today (count `.150S` macros)? Can they be migrated?
5. **Retention defaults.** What do NCI's contracts say (often 30–90
   days for messages, longer for recordings)? Should purge be soft
   (default here) or hard?
6. **Key management.** Is an env key acceptable, or do installs need
   KMS/Vault envelope encryption (per-tenant data keys)? That is a later
   phase that doesn't change the cast interface.
7. **AI on PHI calls.** Will NCI sign BAAs with its LLM/STT/TTS
   providers, or run PHI clients on self-hosted models (Ollama,
   whisper-local) only? This decides whether AI answering is offered
   to healthcare clients at all.
8. **Mobile.** Is a PWA with Web Push enough, or do clients need
   DND-breaking critical alerts (native, an App Store entitlement)?
9. **Should encryption extend to `caller_phone`** (index-only) in phase
   1, given the plan 13 caller-history dependency?
10. **Orbital (the software vendor)'s own position.** Do support bundles
    or remote assistance ever touch production data, which would make
    the vendor a BA too?

## Size estimate

| Piece | Estimate |
|---|---|
| PHI classes in templates, `phi_mode`, `PhiClassifier`, notice renderer | 3–4 d |
| `EncryptedPhi` cast, blind index, encrypt-in-place command, rekey | 4–5 d |
| Secure links: issuer, gate, PIN recipients, enrolment, lockout, recipient page | 6–8 d |
| Plan 05 transport integration, view/button ack, `PhiEgressGuard`, voicemail switch | 3–4 d |
| Audit log (table, trigger, hash chain, calls on existing surfaces, portal page, arch test) | 4–5 d |
| Retention job, legal hold, cascades | 2–3 d |
| `ProviderCompliance`, settings UI, validation, `orbital:status` checks, docs | 3–4 d |
| Phase 1b encryption of transcripts/threads/email bodies | 3–5 d |
| **Phase 1 total** | **~6–8 weeks** (after 03 and 05) |
| Phase 2: secure threads, participants, guests, attachments, inbound-email rejection | 3–4 weeks |
| Phase 3a: PWA plus Web Push, in-app ack | 1.5–2 weeks (needs passkeys, ~2 d per TODO) |
| Phase 3b: native wrapper, critical alerts, OAuth PKCE devices, store submission | 4–6 weeks plus store review |

## Bugs and risks noticed while writing this

- **Portal permissions aren't enforced on pages.** `CallHistory` has no
  `canAccess()` and the Play action checks only that a path exists
  (`app/Filament/Portal/Pages/CallHistory.php:96-110`). A custom role
  without `portal.view_recordings` or `portal.view_calls` still sees
  calls and plays recordings. `Dashboard` and `TextConversations` don't
  check either. Only `RoleResource` does.
- **Signed-URL fallbacks fail open.** `recording-player.blade.php:19-25`
  and `MessageEntry.php:163-170` fall back to an unsigned
  `Storage::url()` when `temporaryUrl()` throws, so a transient S3
  error hands the browser a non-expiring URL.
- **`BelongsToTeam` fails open** for a non-super-admin user with no
  `currentTeam` (`BelongsToTeam.php:30`: no filter is applied) and for
  unauthenticated contexts (`:17-20`). The portal pages filter by hand
  and `RoleResource` defends with `whereRaw('1 = 0')`, but any surface
  that trusts the trait would leak across tenants.
- **Voicemail email contains PHI in clear** (transcript plus WAV) and
  goes only to the team owner (`TranscribeVoicemailJob.php:148`), with
  no per-client recipient list.
- **Telescope defaults to enabled** (`config/telescope.php:19`) and in
  production keeps failed requests and jobs, whose payloads can include
  caller data.
