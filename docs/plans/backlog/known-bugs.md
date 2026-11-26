# Plan: Fix the known bugs in TODO.md

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

The "Known bugs" section of `TODO.md` (lines 50–212) has two lists: twelve
bugs checked against the code on 2026-11-24, and about forty reported by
the IS-parity plan agents but never verified. Several are in the call path
and affect callers today: queued callers hung up after 30 s, client
extensions that never ring, recordings nobody can play, and an AI transfer
that drops the caller. Many are cheap to fix. They are filed under IS-parity
plans that are weeks away, so they would otherwise wait for those plans.

This plan checks every entry against the code, gives each one a verdict, and
groups the fixes into small PRs that can ship ahead of the IS-parity work.

## Scope

- **In:** every bullet under `## Known bugs` in `TODO.md`, plus five related
  defects found while verifying them (marked **new**).
- **Out, owned by [security-review-fixes.md](../security-review-fixes.md):**
  - "Portal permissions are never checked" (`TODO.md:86-90`), which is
    security plan §6.
  - "`BelongsToTeam` scope fails open" (`TODO.md:91-94`), which is security
    plan §9.
  This plan depends on both only where it says so. Bug W2 below is the
  opposite case: the scope applies when it should not.
- **Out, owned by IS-parity plans:** the feature work around each bug. Each
  entry below says which plan would otherwise absorb the fix, and only
  takes the part needed to stop the bug.

## How the verdicts were reached

Every claim was checked by reading the code at the cited lines on
2026-11-26, on branch `fix/sip-edge-desktop-phones` (HEAD `470edcc`).
Nothing was run against a live Asterisk. Where a verdict depends on
Asterisk or carrier behaviour rather than our code, the entry says so and
the PR's first step confirms it.

- **Confirmed:** the code does what the report says.
- **Partly:** the code has the defect, but the impact is narrower or
  different from the report.
- **Refuted:** the code does not have the defect.

## Summary

| ID | Bug | Verdict | PR |
|---|---|---|---|
| D1 | Queue `Queue()` total wait = per-member ring timeout | Confirmed | 2 |
| D2 | Client extensions dialled as `PJSIP/{number}` | Confirmed | 2 |
| D3 | Soft-deleted queues/rules/extensions still generated | Confirmed (wider) | 2 |
| D4 | Overflow AI picker lists every client's personas | Confirmed | 2 |
| D5 | Overflow to persona with no extension dials `0` | Confirmed | 2 |
| D6 | Queue/extension edits don't regenerate dialplan; agent-group edits don't resync members | Confirmed (wider) | 2 |
| D7 | Caller-side `T`/`K` Dial/Queue options | Confirmed | 11 |
| D8 | `queues` realtime table column types | Confirmed | 2 |
| D9 | `musiconhold.conf` never generated | Confirmed | 11 |
| D10 | `max_concurrent_calls` / `max_channels` never enforced | Confirmed | later (plan 09) |
| Q1 | Break/availability doesn't reach skill-matched queues | Partly | 3 |
| Q2 | Logout doesn't pause queues or reset availability | Confirmed | 3 |
| Q3 | Member recompute as an operator drops staff extensions | Confirmed (wider) | 3 |
| Q4 | Logout note collected but never stored | Confirmed | 3 |
| R1 | New recordings can't be played | Confirmed | 4 |
| R2 | Retention ignores `call_recordings`; client delete leaves files | Confirmed | 4 |
| R3 | `UploadCallRecordingJob` release vs `tries = 1`; watcher resends forever | Confirmed | 5 |
| R4 | Signed URL falls back to unsigned `Storage::url()` | Confirmed (low impact) | 5 |
| R5 | Abandoned queue calls logged `completed`, billable includes wait | Confirmed (by reading) | 6 |
| R6 | Queue-answered calls never get `extension_id` | Confirmed | 6 |
| R7 | Outbound never `outbound`; untagged calls dropped | Confirmed | 6 |
| R8 | `messages.call_log_id` never written | Confirmed | 6 |
| R9 | Deleting a client cascades call/message history | Confirmed | later (plan 09) |
| S1 | Softphone hold only mutes locally | Confirmed | 8 |
| S2 | `SipPhone.call()` overwrites a live session | Confirmed | 8 |
| S3 | Transfer keypad digits go to the caller as DTMF | Confirmed | 8 |
| S4 | `softphone.transfer/park/conference` never checked | Confirmed | 8 |
| M1 | Inbound SMS dedupe after thread resolve (reopens closed threads) | Confirmed | 9 |
| M2 | Delivery receipts can flip `delivered` ↔ `failed` | Confirmed | 9 |
| M3 | One rate limiter for inbound SMS and receipts | Confirmed (no impact yet) | 9 |
| M4 | `resolveByPool()` can match another client's pool | **Refuted** | — |
| M5 | Twilio status callbacks only if set per endpoint | Partly | 9 |
| M6 | `PhoneNumber::toE164()` makes bogus E.164 | Confirmed | 9 |
| W1 | Workspace shows another client's script | Confirmed | 7 |
| W2 | Workspace message list empty for non-super-admins | Confirmed (by reading) | 7 |
| W3 | Multi-call "Save again" duplicates messages | Confirmed | 7 |
| W4 | Workspace fields unvalidated; `captureField` takes any string | Confirmed | 7 |
| W5 | Portal dashboard "today" in UTC | Confirmed | 12 |
| P1 | `web_call` API-key auth sends an empty key | Confirmed (latent: no UI) | 10 |
| P2 | `web_call` sends credentials to any absolute URL; no SSRF block; `oauth2` throws | Confirmed | 10 |
| P3 | Post-call templates use `app.timezone`; errors leak into output | Confirmed | 10 |
| A1 | Agent worker `send_sms` tool is `None` | Confirmed | 1 |
| A2 | AI `transfer_call` lands in `[default]` and hangs up; no client check | Confirmed | 11 |
| A3 | `answer_question` with no stores never gets search | Confirmed | 11 |
| A4 | Worker discards Laravel's caller-context reply | Partly (nothing to discard yet) | — (plan 13) |
| A5 | Voicemail greetings / disclosures rendered at 16/24 kHz | Confirmed | 11 |
| A6 | Recording beep / disclosure silently dropped | Confirmed | 11 (interim) |
| F1 | `Filament\Forms\Get` / `Set` don't exist in Filament 5 | Confirmed | 1 |
| F2 | **new:** `Forms\Components\Grid` doesn't exist (Asterisk backend form) | Confirmed | 1 |
| X1 | AMI: failed login keeps socket; comma-joined variables | Partly | 12 |
| X2 | Telescope enabled by default | Confirmed | 12 |
| X3 | Voicemail email: transcript + WAV in clear, owner only | Confirmed | — (plan 17) |
| X4 | Public chat: `id OR name` lookup, no throttle/cap, raw exception | Confirmed | 12 |
| X5 | `METRICS_PER_CLIENT_LABELS=false` emits duplicate samples | Confirmed | 12 |
| X6 | Amtelco import drops "NOT O/C" / "AWAY TIL" prefixes | Confirmed (by code) | 12 |

## Workstream D: dialplan and queues

### D1. Queued callers hung up after ~30 s with no AI overflow

**Verdict: Confirmed.** `resources/views/asterisk/client-dialplan.blade.php:52`
emits `Queue(name,tTkK,,,{{ strategyTemplate->timeout ?? 30 }})`. The fifth
`Queue()` argument is the caller's total wait. The same template timeout is
also written as the per-member ring timeout (`QueueSyncer.php:40`). With no
overflow, line 58 is `Hangup()`. The same code is in the `[internal]` copy
(`extensions.blade.php:75`). `call_queues.timeout` (migration
`2026_04_11_100005…:20`) exists but nothing reads it.

**Fix:** add `call_queues.max_wait_seconds` (nullable int), using the name and
meaning plan 14 already chose (`14-queue-announcements-and-virtual-hold.md:198`).
Emit it as the `Queue()` timeout only when set. Null means wait until a
member answers or the caller hangs up. Backfill null for queues without
overflow. For queues with overflow, backfill 300 (plan 14's default) so
they still overflow. Expose it on the queue form with plan 14's
validation (≥ 15 or empty).

**Test:** dialplan snapshot: a queue with no overflow and null max wait emits
`Queue(q,tTkK)` with no timeout; one with `max_wait_seconds = 120` emits
`,,,120`; the template timeout never appears in the `Queue()` line.

**Risk:** low. A queue with no members and no overflow now holds callers
indefinitely. `joinempty`/`leavewhenempty` are both hard-coded to `1`
(`QueueSyncer.php:45-46`), so Asterisk never evicts them. D8 fixes those
columns, and this PR should map `call_queues.leave_when_empty` through.

**Subsumed by:** plan 14 (which adds the same column), plan 26.

### D2. Client extensions dialled by bare number

**Verdict: Confirmed.** `client-dialplan.blade.php:39` dials
`PJSIP/{{ $ext->number }}`. Client endpoints are named
`t{team}_{number}` (`Extension::realtimeEndpointId()`, `Extension.php:83-90`).
A client extension fails with "endpoint not found", or rings a platform
extension that has the same number. `[internal]` has the same line at
`extensions.blade.php:63`, and it lists every client's extensions in one
context, so two clients with extension 101 produce duplicate `exten` lines.
The `VoiceMail({{ number }}@@default)` lines (client-dialplan `:41,:43`)
also key mailboxes by bare number. Mailboxes are only generated per
voicemail routing rule (`voicemail.blade.php:54-61`), so these usually hit
a mailbox that doesn't exist.

**Fix:** dial `PJSIP/{{ $ext->realtimeEndpointId() }}` in both templates. In
`[internal]`, emit client extensions as `t{team}_{number}` (dialable by
operators) and keep bare numbers only for platform (`team_id` null)
extensions. Leave voicemail-per-extension as is for now, but stop emitting
the `VoiceMail()` branch for an extension with no mailbox: fall to
`Hangup()` with a `NoOp` saying why.

**Test:** snapshot with two clients sharing number 101: each client file
dials its own `t{id}_101`, and `[internal]` has no duplicate `exten`.

**Risk:** medium. Any staff speed-dial that relied on dialling a client
extension by bare number from `[internal]` changes. Check with ops.

**Subsumed by:** plan 15 (IVR), which rebuilds this template.

### D3. Soft-deleted rows still generated into the dialplan

**Verdict: Confirmed, and wider than reported.** `CallQueue`, `RoutingRule`
and `Extension` all use `SoftDeletes`. `AsteriskConfigService` loads them
with `withoutGlobalScopes()`, which also removes `SoftDeletingScope`:
queues at `:54,:160`, rules at `:113,:164`, extensions at `:48,:150`. A
deleted routing rule that is still `is_active` keeps routing its DID. A
deleted queue keeps a `queue-{id}` exten pointing at a realtime row that
`QueueSyncer::delete()` already removed.

**Fix:** use `withoutGlobalScope('team')` in all six places. Add a guard test
that fails when `withoutGlobalScopes()` appears in
`app/Services/Telephony/AsteriskConfigService.php`.

**Test:** soft-delete a rule, a queue and an extension, then regenerate:
none appear in `from-trunk` or the client file.

**Risk:** low. Routing changes only for rows that were already deleted.

**Subsumed by:** plans 14 and 15.

### D4. Queue overflow picker lists every client's personas

**Verdict: Confirmed.** `ManageClientCallQueues.php:103-108` builds the options
from `AgentPersona::withoutGlobalScope('team')->whereNotNull('team_id')`,
which returns every client's personas.

**Fix:** filter by `team_id = $owner->id` (and `is_active`). Add an
`exists`-style rule so a forged id for another team is rejected on save.

**Test:** Livewire form test: client A's queue form lists only A's personas.
Saving B's persona id fails validation.

**Risk:** low. Run a one-off query for existing cross-client
`overflow_agent_persona_id` values and report them before the PR merges.

**Subsumed by:** plan 14.

### D5. Overflow to a persona with no extension dials `0`

**Verdict: Confirmed.** `client-dialplan.blade.php:55` (and
`extensions.blade.php:78`) emit
`Dial(PJSIP/{{ …extensions->first()?->number ?? '0' }}@livekit…)`.

**Fix:** dial an AI target that doesn't need an extension, as the
orchestration route already does with `AiCallTarget::forRule()`
(`from-trunk.blade.php:49`). Add an `AiCallTarget::forPersona($persona)`
and use it. If that's not possible, skip the overflow lines and emit
`Goto(orbital-ai-unavailable,s,1)`. Also block saving an overflow persona
that has no reachable target.

**Test:** snapshot: a persona with no extension never produces `PJSIP/0@`.

**Risk:** low.

**Subsumed by:** plan 14 / 26 (overflow redesign).

### D6. Edits that don't regenerate the dialplan

**Verdict: Confirmed, and wider than reported.** `TelephonyObserver`
(`app/Observers/TelephonyObserver.php:67-106`) regenerates the dialplan only
for `RoutingRule`. `CallQueue` (`:86-96`) syncs the realtime `queues` /
`queue_members` rows but never rewrites `queue-{id}` exten lines. A new
queue has no dialplan entry, and an overflow change is not applied.
`Extension` (`:70-72`) has the same gap: a new client extension has an
endpoint and no `exten` line. Nothing observes `AgentGroup` or
`agent_group_members`, so adding a member to a group doesn't change
`queue_members` until something saves the queue. Nothing on the schedule
regenerates either (`routes/console.php`).

**Fix:**
- In `onQueueChange()` and the Extension branch, also
  `RegenerateTelephonyConfig::dispatch($model->team_id)`. The job is
  deduped per client, so the reload churn the class doc worries about stays
  bounded. Platform extensions (`team_id` null) regenerate `[internal]`.
- Add an observer on the agent-group membership pivot model (or the
  Filament relation manager's after hooks) that calls `syncForQueue()` for
  every queue on that group.

**Test:** creating a queue dispatches `RegenerateTelephonyConfig` for its
team. Adding a user to an agent group inserts their `queue_members` row.

**Risk:** low. More `dialplan reload`s, but per-client and deduped.

**Subsumed by:** plans 14 and 26.

### D7. Caller-side `T`/`K` options on Dial/Queue

**Verdict: Confirmed (options present). Impact depends on featuremap.**
Every `Dial()`/`Queue()` passes `tTkK` (`client-dialplan.blade.php:31,39,52,55`,
`from-trunk.blade.php:51`, `extensions.blade.php:54,63,75,78`). `T` and `K`
give the calling party (the outside caller) blind transfer and parking.
The repo ships no `features.conf`; the image runs `make samples`
(`docker/asterisk/Dockerfile:116`), and Asterisk's built-in blind-transfer
code is `#`. A caller who presses `#` (common when entering account numbers
for the AI) starts a transfer prompt instead of sending the digit.

**Fix:** drop `T` and `K` everywhere the caller is the calling party. Keep `t`
and `k` (called party = operator). For the AI legs, drop all four: LiveKit
can't use DTMF features, and the REFER path does transfers.

**Test:** dialplan snapshot: no `T`/`K` in any generated `Dial`/`Queue`
option string.

**Risk:** low. Confirm with ops that no workflow has callers park or
transfer themselves.

**Subsumed by:** plan 08 (call patching).

### D8. `queues` realtime table column types

**Verdict: Confirmed.** `2026_04_15_100000_create_asterisk_realtime_tables.php`
types `queue_youarenext` … `queue_reporthold` as `integer` (`:208-219`).
These are sound-file names in app_queue. It also sets
`periodic_announce` to `string(50)` (`:227`), `defaultrule` to `integer`
(`:249`, a rule name), and `membergosub` to `integer` (`:207`).
`joinempty`/`leavewhenempty` (`:243-244`) take option lists such as
`paused,unavailable`, not integers. No later migration alters `queues`.

**Fix:** one migration changing these to `string` (sound columns 128,
`periodic_announce` 4096 since it is a comma list, `defaultrule` 128,
`membergosub` 128, `joinempty`/`leavewhenempty` 128). `QueueSyncer` writes
`joinempty`/`leavewhenempty` from `call_queues.join_empty` /
`leave_when_empty` (`yes`/`no`) instead of the hard-coded `1`.

**Test:** migration test inserts a sound path into `queue_youarenext`.
`QueueSyncer` test maps `join_empty=false` to `'no'`.

**Risk:** low. Postgres `integer → varchar` with `USING col::text`. All
current values are null.

**Subsumed by:** plans 14 and 26 (both need these columns).

### D9. `musiconhold.conf` never generated

**Verdict: Confirmed.** `HoldMusicResource` says each `HoldMusicClass` row
"becomes the literal class name in musiconhold.conf"
(`HoldMusicResource.php:24`, `HoldMusicClass.php:13`). Nothing writes that
file: no Blade template, and no reference in `AsteriskConfigService`. Only
the stock `default` class from `make samples` exists. `QueueSyncer.php:44`
writes `call_queues.music_on_hold`, but no queue form sets it.

**Fix:** generate `generated/musiconhold.conf` from `HoldMusicClass`
(`mode=files`, `directory=` the class's spool dir) through the same
`asterisk-config` disk, `#include` it from the baked file, and reload
`res_musiconhold` on change. Until the upload part of HoldMusicResource
exists (`:113` "files coming soon"), at least validate that a chosen class
exists, and fall back to `default`.

**Test:** generator snapshot lists every active class. The queue form only
offers existing classes.

**Risk:** low.

**Subsumed by:** plan 14 (per-client hold experience). Do the generator now
and leave the per-client UI to plan 14.

### D10. Concurrency limits never enforced

**Verdict: Confirmed.** `teams.max_concurrent_calls` (`ClientResource.php:199`)
and `SipTrunk.max_channels` (`SipTrunkResource.php:86`) are editable, but
nothing in `app/`, `resources/views/asterisk/` or `docker/kamailio/` reads
them (no `GROUP_COUNT`, no dialog limit).

**Fix (deferred):** this needs a decision on where to count (Asterisk
`GROUP()` per client context vs Kamailio dialog counting at the edge) and
what callers hear at the cap. That is plan 09's billing and limits design.
**Now:** add helper text "not enforced yet" to both fields so nobody relies
on them. That one-line change goes in PR 2.

**Subsumed by:** plans 09 and 19.

## Workstream Q: queue membership and availability

### Q1. Availability changes don't reach skill-matched queues

**Verdict: Partly.** `QueueMemberSyncer::syncForOperator()`
(`app/Services/Telephony/Realtime/QueueMemberSyncer.php:27-45`) finds queues
only through `agent_group_members`, and returns early if the operator is in
no group. A queue whose members come from required skills
(`collectSkillMatchedMembers`, `:80-142`) computes `paused` correctly
whenever it *is* recomputed (`:137`). It just isn't recomputed when an
operator outside the queue's agent group goes on break. Callers to
`AvailabilitySelector.php:80` and `ResetAvailabilityOnLogin.php:63`.

**Fix:** `syncForOperator()` also collects queues with
`call_queue_required_skills` rows that overlap the operator's skills. A
cheaper and more correct alternative is to update `paused` directly on the
operator's `queue_members` rows (`WHERE interface = PJSIP/{endpoint}`) instead
of recomputing whole queues. Do the direct update. Keep the full recompute for
membership changes.

**Test:** an operator matched only by skill goes on break → their
`queue_members.paused` becomes 1.

**Risk:** low.

**Subsumed by:** plan 26.

### Q2. Logout doesn't pause queues or reset availability

**Verdict: Confirmed.** The operator logout action
(`app/Providers/Filament/OperatorPanelProvider.php:192-216`) writes a
`UserLogoutEvent` and logs out. It never calls `removeForOperator()` or
pauses, and it leaves availability as it was. Session expiry runs no code at
all. A logged-out operator's extension stays a live queue member.
`app/Listeners/` has no Logout listener.

**Fix:** a `Logout` event listener (covers both the action and other logout
paths) that sets availability to offline and pauses the operator's
`queue_members` rows (the direct update from Q1). For session expiry, an
every-minute job pauses members whose endpoint has no live registration
(`AsteriskAmiService::endpointIsRegistered()` exists). Do that job in PR 3
only if it's cheap; otherwise list it as a follow-up.

**Test:** log out → `queue_members.paused = 1` for the user's interface.

**Risk:** low.

**Subsumed by:** plans 18 and 26.

### Q3. Member recompute run as a scoped user drops staff extensions

**Verdict: Confirmed, and wider than reported.** `QueueMemberSyncer` uses
team-scoped queries: `CallQueue::query()` (`:38`), `Extension::query()` (`:54`,
`:191`), `Extension::find()` (`:199`). Jetstream's `currentTeam` falls back to
the personal team (`vendor/laravel/jetstream/src/HasTeams.php:26-33`), so
any non-super-admin has a current team, and `BelongsToTeam.php:30-35` then
adds `team_id = that team`:
- **Operator toggles availability:** `CallQueue::query()` hides every
  client's queues, so `syncForOperator()` syncs nothing. Breaks never pause
  anyone.
- **Client admin edits a queue:** the observer calls `syncForQueue()`, and
  `Extension::query()` hides staff extensions (`team_id` null). Every
  staff member is deleted from that queue's `queue_members`.

**Fix:** use `withoutGlobalScope('team')` on every query in
`QueueMemberSyncer` (and `QueueSyncer`). It is infrastructure code and must
not depend on who is logged in.

**Test:** run `syncForQueue()` while acting as a non-super-admin operator and
as a client user. Staff members survive both. Run `syncForOperator()` as an
operator and the client queue rows update.

**Risk:** low. This is the most damaging bug in the queue area, so ship it
first in PR 3.

**Subsumed by:** plan 26.

### Q4. Logout note collected but never stored

**Verdict: Confirmed.** Both form variants collect `note`
(`OperatorPanelProvider.php:174,187`). The action writes only
`user_id/logout_reason_id/reason_label_snapshot/logged_out_at` (`:196-207`),
and `UserLogoutEvent::$fillable` has no `note` (`app/Models/UserLogoutEvent.php:23-28`).
The column doesn't exist.

**Fix:** migration adding `user_logout_events.note` (text, nullable). Write it
from the action and show it wherever logout events are listed.

**Test:** submit the logout form with a note → row has the note.

**Risk:** none.

**Subsumed by:** plans 10 and 18.

## Workstream R: recordings and Call History

### R1. New recordings can't be played anywhere

**Verdict: Confirmed.** The Play actions are only visible when a legacy column
is set (`CallLogResource.php:125-127`, portal `CallHistory.php:100-102`), and
`recording-player.blade.php:13` reads only
`recording_path/_rx_path/_tx_path`. New recordings go to `call_recordings`
(`UploadCallRecordingJob.php:138`, `IngestLivekitEgressJob.php:126`,
`UploadRtpengineRecordings.php:180`). The `CallLog::recordings()` relation
exists (`CallLog.php:125`) but nothing in Filament uses it. Nothing in `app/`
writes the legacy columns any more, so for new calls the button never
appears.

**Fix:**
- `CallLog::playableRecordings()` returns the legacy legs (if any) plus
  `call_recordings` rows, each labelled by source/direction ("Full call"
  for rtpengine `mixed`, participant identity for LiveKit tracks).
- `visible()` on both Play actions uses it (eager-load
  `withCount('recordings')` on the table query to avoid N+1).
- `recording-player.blade.php` takes that list instead of the hard-coded
  three columns. It keeps the leg tabs, built from the list.
- The portal Play action also gets `portal.view_recordings` once security
  §6 lands. Don't block on it.

**Test:** a call with only `call_recordings` rows shows Play in admin and
portal. The player renders one tab per row. A legacy-only call still works.

**Risk:** low. Signed-URL generation is unchanged (R4 tightens it).

**Subsumed by:** plans 10 and 22 (QA replay). Fix now, because recordings are
a selling point and nobody can currently play them.

### R2. Retention ignores `call_recordings`; client deletion leaves files

**Verdict: Confirmed.** `PruneExpiredCallRecordingsJob.php:79-136` selects and
clears only the legacy columns. `call_recordings.team_id` is
`cascadeOnDelete` (migration `…call_recordings…:28`), and client delete is a
plain `DeleteAction` (`EditClient.php:29`, bulk at `ClientResource.php:462`)
→ `Team::purge()`. The rows go and the objects stay on the disk.

**Fix:**
- Second pass in the prune job over `call_recordings` joined to
  `call_logs` for the anchor time, using the same per-team retention
  resolver, deleting `storage_path` then the row. Chunk by id. Rows with
  `call_log_id` null use `created_at`.
- A `deleting` hook on `Team` (or in `DeleteTeam::delete()`) that queues a
  job to delete that team's recording objects before the cascade. It can't
  be done after, because the rows are gone. Use a `PurgeTeamRecordingsJob`
  that takes the path list captured in the hook.

**Test:** an expired `call_recordings` row loses its file and row. A fresh
one is kept. Deleting a team queues the purge with every path.

**Risk:** medium. This deletes customer data. Ship it behind a dry-run flag
(`--dry-run` on a command wrapper) and run the dry run on production first.

**Subsumed by:** plan 17 (retention) and plan 09.

### R3. Upload job deferral never retries; watcher resends forever

**Verdict: Confirmed.** `UploadCallRecordingJob` has no `$tries` and calls
`$this->release(now()->addMinutes(2))` when no CallLog matches
(`UploadCallRecordingJob.php:78-90`). It runs on `default`, whose Horizon
supervisor has `tries => 1` (`config/horizon.php:213`), so the released
attempt fails with MaxAttemptsExceeded. Meanwhile `orbital:upload-recordings`
runs every minute (`routes/console.php:104-108`) and dispatches a job for
every settled file still in the spool (`UploadRtpengineRecordings.php:123-130`).
A file whose call never gets a CallLog is dispatched every minute
forever. R7 makes that common: test calls, outbound calls and handoffs
never get a CallLog.

**Fix:**
- Give the job `$tries = 10` and `backoff()` of 2 min, so the deferral works.
- In the watcher, skip files with a pending job: use a cache lock keyed by
  path, held for the job's lifetime.
- After N hours unmatched, move the file to `spool/unmatched/` and log once.
  A later reconcile command can attach it if a CallLog appears.

**Test:** a job with no CallLog releases and succeeds on a later attempt once
the row exists. The watcher dispatches once per file while a job is
pending. Old unmatched files move aside.

**Risk:** low.

**Subsumed by:** none directly (rtpengine-edge.md follow-up).

### R4. Signed URLs fall back to unsigned `Storage::url()`

**Verdict: Confirmed, low impact.** `recording-player.blade.php:18-25` and
`MessageEntry::temporaryUrl()` (`app/Models/MessageEntry.php:159-174`) catch
any `temporaryUrl()` failure and return `url($path)`. The comment says this is
for the local driver, but Laravel's local disk with `serve => true`
(`config/filesystems.php:36`) signs URLs itself. So the fallback mostly fires
on S3 misconfiguration. It then hands out a plain object URL that works only
if the bucket is public.

**Fix:** remove the fallback. On failure, log, and render "Recording
temporarily unavailable" or skip the attachment link.

**Test:** a disk mock whose `temporaryUrl()` throws produces no URL and no
`url()` call.

**Risk:** low. A dev setup on a disk without signing loses playback. Document
`serve => true`.

**Subsumed by:** plan 17.

### R5. Abandoned queue calls logged as `completed`; billable includes queue wait

**Verdict: Confirmed by reading. Confirm with one live CDR before fixing.**
`queue-{id}` runs `Answer()` before `Queue()` (`client-dialplan.blade.php:51`).
Asterisk then marks the caller's CDR answered at the `Answer()`. In
`CdrIngester::record()` (`app/Services/Telephony/CdrIngester.php:114-138`),
any `ANSWERED` row makes `$wasAnswered` true, so the status is `completed`
(`:241-243`), `answered_at` is the Asterisk answer, and `billable_seconds`
runs from it, including the whole wait.

**Fix:** treat a call as answered only when a row with a non-empty
`dstchannel` (a bridged party: member, AI or extension) is `ANSWERED`. Take
`answered_at` and billable seconds from that row's `answer`. A queue call
with no bridged answer becomes `abandoned` (new status) when the caller
hung up, or `missed` when it fell through. Keep `duration_seconds` as is.

**Test:** fixtures from a real `asterisk_cdr` capture: queue abandon →
`abandoned`, `billable 0`. Queue answered after 40 s → `answered_at` = member
answer, billable excludes the wait.

**Risk:** medium. Changes reporting numbers. Re-ingest is idempotent
(`record()` rebuilds), so a backfill command can recompute history from the
raw rows kept for 30 days (`KEEP_RAW_DAYS`).

**Subsumed by:** plans 09 and 10.

### R6. Queue-answered calls never get `extension_id`

**Verdict: Confirmed.** `CdrIngester::route()` (`:195-230`) sets `extension_id`
only from `Dial` rows (`:209-219`). A `Queue` row's answering member is in
`dstchannel` (`PJSIP/t5_101-0000001a`), which is never read. The `Dial`
branch also looks up `number` against the client team only (`:217-218`).
Staff extensions (`team_id` null) never match, so operator answers are
never attributed.

**Fix:** parse `dstchannel` for every answered row
(`^PJSIP/([^-]+)-[0-9a-f]+$`). Resolve the endpoint id through a reverse of
`realtimeEndpointId()`: `t{team}_{number}` → client extension, bare → platform
extension. Set `extension_id` (and `answered_by_user_id` if plan 10 adds it).

**Test:** fixture queue call answered by `PJSIP/1001-…` (staff) → that
extension's id.

**Risk:** low.

**Subsumed by:** plan 10.

### R7. Outbound calls never `outbound`; untagged calls dropped

**Verdict: Confirmed.** `direction` is `inbound` when the CDR has an
`orbital_did` tag, else `internal` (`CdrIngester.php:131`). Nothing ever
writes `outbound`. Calls with no `orbital_team_id` tag and no `tenant_N`
context return null (`:105-108`, `:172-187`). That covers operator calls
from `[internal]`, test calls (`TestCallPlacer`), and `from-livekit`
handoffs out of a trunk (`from-trunk.blade.php:103-111`), which only set
`orbital_team_id` via `orbital-cdr-inbound` on the inbound leg. A handoff
shares the inbound linkedid, so it usually *is* logged, but as inbound with
its outbound leg's duration folded in.

**Fix:**
- Tag outbound legs: a `Gosub(orbital-cdr-outbound,s,1(team))` in the
  `from-livekit` handoff entries, and in any staff outbound dial pattern
  when one is added, setting `CDR(orbital_direction)=outbound`. The
  ingester then uses `orbital_direction` when present.
- For staff calls with no client, log them under a platform pseudo-client or
  a `call_logs.team_id` null row (that column is non-null today, so it needs
  a migration). Choose one and record it in open question 2.
- Handoff legs get `metadata.legs[].direction` so billing can split them.

**Test:** fixture handoff call → inbound row with an outbound leg flagged.
Fixture staff `[internal]` call → logged per the chosen rule.

**Risk:** medium (schema decision).

**Subsumed by:** plans 09 and 19 (outbound billing). Recommendation: do only
the handoff tagging now and leave platform-call logging to plan 09.

### R8. `messages.call_log_id` never written

**Verdict: Confirmed.** The column exists (`2026_04_17_060000_create_messages_table.php:18`)
and is fillable (`Message.php:43`). No `Message::create` sets it:
`Workspace.php:191`, `MultiCallDesk.php:199,244`, and the AI path through
`CallSessionController::maybePersistMessage()`.

**Fix:** AI path: `call_session_states.linked_id` → `CallLog` by `linked_id`. If
the CallLog isn't ingested yet, set the link when `CdrIngester::record()` runs
(update messages whose session has this linked id). Operator path: carry the
SIP Call-ID from the softphone's `orbital-call` event (`sip-phone.js:117-126`
already has `callId`), and link by `call_logs.sip_call_id` in the same way at
ingest.

**Test:** AI session with linked_id → message linked after ingest. Operator
message with call id → linked after ingest.

**Risk:** low.

**Subsumed by:** plan 13 (caller history needs it).

### R9. Deleting a client cascades call and message history

**Verdict: Confirmed.** `call_logs.team_id` and `messages.team_id` are
`cascadeOnDelete` (`2026_04_11_100007…:15`, `2026_04_17_060000…:15`). `Team`
has no `SoftDeletes`, and client delete is a plain `DeleteAction`
(`EditClient.php:29`, `ClientResource.php:462`).

**Fix (deferred to plan 09):** archive instead of delete (`teams.archived_at`,
hidden from pickers, calls refused). Real deletion becomes a separate
retention-driven purge. **Now:** in PR 4, replace the bulk delete action with
nothing, and make the single delete require typing the client name, with a
warning that history and recordings are destroyed. That is cheap and stops
accidents.

**Subsumed by:** plan 09.

## Workstream S: softphone

These need a manual test against a real Asterisk. Each PR description lists
the clicks.

### S1. Hold only mutes locally

**Verdict: Confirmed.** `SipPhone.toggleHold()` (`resources/js/sip-phone.js:249-277`)
only toggles `sender.track.enabled`. No re-INVITE is sent, so Asterisk never
starts MOH and the caller hears silence. Unhold also re-enables a track the
user had muted.

**Fix:** use SIP.js's `session.invite({ sessionDescriptionHandlerOptions: { hold: true } })`
(sendonly), and `hold: false` to resume, keeping the PCMU modifier. Asterisk
then plays the endpoint's `moh_suggest` class. Track `held` and `muted`
separately.

**Test:** manual: hold → caller hears MOH. Resume → two-way audio. Mute →
hold → resume keeps mute. Unit: a mocked session receives `invite` with
`hold: true`.

**Risk:** medium (re-INVITE through the Kamailio/rtpengine edge). Test through the local SIP edge (`local/edge-up.sh`).

**Subsumed by:** plan 08.

### S2. `call()` overwrites a live session

**Verdict: Confirmed.** `SipPhone.call()` (`sip-phone.js:156-201`) has no busy
check, unlike `_handleIncoming()` (`:84-87`), and assigns `this.session =
inviter` (`:197`). The live call keeps running with no handle. Its
`Terminated` handler (`:186-191`) also doesn't check
`this.session === inviter`, so the old call ending clears the new call.

**Fix:** refuse `call()` while `state` is `in-call/connecting/ringing/incoming`
(return an error state the UI shows: "Hang up or hold the current call
first"). Guard every `Terminated` handler with `this.session === x`. Click-to-dial
callers (plan 20) check the state first.

**Test:** unit with a mocked UA: `call()` during `in-call` creates no Inviter.
An old session terminating leaves the current one intact.

**Risk:** low.

**Subsumed by:** plans 08 and 20.

### S3. Transfer keypad digits sent as DTMF to the caller

**Verdict: Confirmed.** `onPadKey()` (`resources/js/softphone.js:288-299`) checks
`state === 'in-call'` before `transferMode`. Transfer mode only exists during a
call, so the transfer branch is unreachable, and every digit goes to the
caller through `sendDtmf()`.

**Fix:** test `transferMode` first.

**Test:** JS unit: in-call + transferMode → `transferInput` grows and
`sendDTMF` is not called.

**Risk:** none.

**Subsumed by:** plan 08.

### S4. `softphone.transfer/park/conference` never checked

**Verdict: Confirmed.** The permissions are seeded
(`PermissionCatalogSeeder.php:39`, `SuperAdminRoleSeeder.php:50-52,72-74`) and
referenced nowhere else.

**Fix:** pass `can.transfer/park/conference` into the softphone's Alpine config
from the Blade component, and hide the buttons without them. Park and
conference have no UI yet, so wire just transfer. Server side, transfers
are SIP REFERs that Laravel never sees. For real enforcement, the endpoint's
realtime row needs `allow_transfer=no` (`ps_endpoints.allow_transfer`) for
users without the permission. Have `EndpointSyncer` set it.

**Test:** `EndpointSyncer` writes `allow_transfer = no` for a user lacking
`softphone.transfer`. The Blade view omits the button.

**Risk:** low. Re-sync endpoints when roles change (a role observer).

**Subsumed by:** plan 08.

## Workstream M: messaging and SMS

### M1. Inbound SMS dedupe runs after the thread resolve

**Verdict: Confirmed.** `ProcessInboundMessageJob::handle()` resolves the
thread (`app/Jobs/ProcessInboundMessageJob.php:108`) before the
provider-message-id check (`:123-133`). `MessageThreadResolver::resolve()`
reopens a closed thread and saves it (`MessageThreadResolver.php:52-77`). A
carrier retry of a message the operator already handled reopens the closed
thread.

**Fix:** look up the existing entry first. If found, run only
`applyConsentKeywords()` against *that entry's* thread (preserving the
STOP-retry guarantee the comment at `:110-117` explains), then return. Only
resolve a thread for new messages.

**Test:** process a message, close the thread, process the same payload again
→ thread stays closed and one entry. Repeat with body "STOP" → opt-out
recorded once.

**Risk:** low.

**Subsumed by:** plan 16.

### M2. Delivery receipts can flip `delivered` ↔ `failed`

**Verdict: Confirmed.** `finality()` ranks delivered, undelivered and failed
all as 3 (`InboundMessageController.php:141-151`), and the guard is a
strict `<` (`:128`), so a terminal state can be overwritten by another.

**Fix:** once terminal, ignore further terminal receipts, except `failed` or
`undelivered` → `delivered`, which carriers do send when a retry succeeds.
Record every receipt in `message_entries.metadata.receipts[]` for audit.

**Test:** delivered then failed → stays delivered. Failed then delivered →
delivered.

**Risk:** low.

**Subsumed by:** plan 16.

### M3. One rate limiter for inbound SMS and receipts

**Verdict: Confirmed, no impact at current volume.** One route
(`routes/api.php:89-90`) carries both, under `messaging-inbound`, 300/min per
IP (`AppServiceProvider.php:72-74`). One outbound blast (plan 23) produces a
receipt per recipient, and the overflow is rejected with 429.

**Fix:** key the limiter by provider + IP and raise the default to 1200/min.
Better: count receipts-only requests (payload has receipts but no messages)
against a separate, higher limit. The controller already separates them
(`:61-67`). Make it configurable per provider.

**Test:** limiter unit: 301 receipt posts from one IP aren't throttled
under the receipt limit.

**Risk:** low.

**Subsumed by:** plan 23.

### M4. `resolveByPool()` returns another client's endpoint

**Verdict: Refuted.** `MessagingEndpoint::resolveByPool()` does use `first()`
(`app/Models/MessagingEndpoint.php:109-122`), but
`2026_08_23_100000_add_sender_pool_to_messaging_endpoints.php:59` adds
`unique(['provider', 'sender_pool_id'])`. Two rows can't share a pool, so
`first()` is the only match. No later migration drops the index.
Nothing to fix. Remove the bullet from `TODO.md`.

### M5. Twilio status callbacks only when set per endpoint

**Verdict: Partly.** `TwilioProvider::send()` adds `StatusCallback` only from
`$options['status_callback']` (`TwilioProvider.php:219-221`), which only comes
from the endpoint's optional config JSON (`ManageClientMessagingEndpoints.php:155`).
Twilio also posts status to the Messaging Service's own callback URL when one
is set in the Twilio console, so receipts can still arrive. Orbital just
never asks for them by default.

**Fix:** default `StatusCallback` to
`route('messaging.inbound', ['provider' => 'twilio'])` (`routes/api.php:89-91`, absolute from `APP_URL`) when
the endpoint doesn't override it.

**Test:** a send with no option posts `StatusCallback` = the inbound URL.

**Risk:** low. Check `APP_URL` is the public one on each install.

**Subsumed by:** plan 16.

### M6. `PhoneNumber::toE164()` produces bogus E.164

**Verdict: Confirmed.** The fallthrough returns `'+'.$digits`
(`app/Support/PhoneNumber.php:38`), so `1234` → `+1234`, a 7-digit local
number → `+5551234`, and `011441234…` → `+011441234…`. The `395-403` range in
`TODO.md` doesn't exist (the file is 40 lines). Callers:
`CallSessionController.php` (context), `HandoffController`,
`HandoffDestinations`, `ApplyOrchestrationTemplate`.

**Fix:** return null for anything that isn't 10 digits, 11 starting with 1, or
`+`/`011`-prefixed international (strip `011`). Callers already handle null
(`?? $state->caller_number`). Plan 25 moves this onto libphonenumber later.
That swap stays in plan 25.

**Test:** data provider: `1234` → null, `5551234` → null, `01144…` → `+44…`,
`(555) 123-4567` → `+15551234567`.

**Risk:** low. Handoff lookups by a short internal caller id stop matching
anything, which is correct.

**Subsumed by:** plans 13 and 25.

## Workstream W: operator workspace and portal

### W1. Operator Workspace shows another client's script

**Verdict: Confirmed.** `workspace.blade.php:150` mounts
`compiled-flow-viewer` with `['teamId' => …]`. `CompiledFlowViewer` has no
`teamId` property, and `mount()` takes only `extensionNumber/personaId/sessionKey`
(`app/Livewire/CompiledFlowViewer.php:50`), so the value is dropped. With no
persona, `loadCompiled()` falls back to the first active persona of any
client (`:104-111`). There is also no `wire:key`, so switching clients
wouldn't remount it anyway. The extension lookup (`:93-96`) matches `number`
across all teams. `personaId` is a public, unlocked property, so a browser
can set it to any persona. The handoff promise in `docs/operator/workspace.md:65-68`
("questions already answered are already filled in") has nothing feeding
it: nothing passes `sessionKey`, and no SIP header carries one.

**Fix:**
- Add `#[Locked] public ?int $teamId` to the viewer. Resolve the client's
  entry flow as: the routing rule for the active call's DID, if known →
  `AgentFlowCompiler::entryFlow()`. Otherwise the client's first active
  persona (`team_id = teamId`). Otherwise an empty state ("No script for this
  client"). Delete the cross-client fallback. Filter the extension lookup by
  `team_id`. Lock `personaId` and `extensionNumber`.
- Mount with `key('flow-'.$activeTeamId)` so a client switch remounts.
- `sessionKey`: leave out of this PR. It needs the AI → operator transfer
  (A2) to carry the room name (e.g. an `X-Orbital-Session` header the
  workspace reads from the `orbital-call` event). List it in A2's follow-up
  and fix the doc sentence now to "will be filled in" → mark as planned.

**Test:** with clients A and B each having personas, a viewer mounted for A
renders A's flow and never B's. With no persona for A it renders the empty
state. Setting `personaId` from the client throws `CannotUpdateLockedPropertyException`.

**Risk:** low.

**Subsumed by:** plan 02 (operator scripting).

### W2. Workspace message list empty for non-super-admin operators

**Verdict: Confirmed by reading. Write the test first.** `Workspace::table()`
uses `Message::query()` with the team scope on
(`app/Filament/Operator/Pages/Workspace.php:359-361`). For a non-super-admin,
`currentTeam` is at least their personal team (Jetstream fallback, see Q3),
so the query is `team_id = personal AND team_id = active` → empty. The
multi-call desk removes the scope (`app/Livewire/MultiCallWorkspace.php:290`).
`saveMessage()` (`Workspace.php:183-205`) has a related gap. It doesn't
check that `activeTeamId` is set, and when it is null, `BelongsToTeam`'s
`creating` hook (`BelongsToTeam.php:42-46`) files the message under the
operator's personal team.

**Fix:** `Message::withoutGlobalScope('team')->where('team_id', $this->activeTeamId)`,
matching the desk. Add `if (! $this->activeTeamId) return;` to
`saveMessage()`.

**Test:** act as an operator with a personal team, load client A with messages
→ the table shows them. `saveMessage()` with no client creates nothing.

**Risk:** low. Operator access to client A is still gated by the panel and
the per-client allow-list (unchanged).

**Subsumed by:** plan 02/03.

### W3. Multi-call "Save again" creates duplicate messages

**Verdict: Confirmed.** `MultiCallDesk::saveMessage()` always calls
`Message::create` (`app/Services/Operator/MultiCallDesk.php:197-207`), then
overwrites `operator_calls.message_id` (`:210`). The button text says "Save
again" when `message_saved_at` is set
(`resources/views/livewire/multi-call-workspace.blade.php:302`). A partial
save followed by a full save (`:244`) also leaves two messages.

**Fix:** if `$call->message_id` points to an existing message, update it
(fields + `is_partial = false`). Otherwise create it. Wrap it in a
transaction with `lockForUpdate()` on the call to stop a double click.

**Test:** save twice → one message with the second draft. Partial then full →
one message, `is_partial` false.

**Risk:** low. Updating a message the client may have already seen. Record
an "edited" timestamp (plan 03 has message edit history; until then
`updated_at` is enough).

**Subsumed by:** plan 03.

### W4. Workspace phone/reason unvalidated; `captureField` accepts any string

**Verdict: Confirmed.** `Workspace::saveMessage()` validates only
`msgCallerName` and `msgReason` (`Workspace.php:185-188`). `msgCallerPhone`
and `msgUrgency` (any string, written straight to `urgency`) are not
checked, and `msgReason` has no max. `CallSessionController::captureField()`
validates `key` as any 64-char string and `value` as any string
(`CallSessionController.php:67-71`). `CallSessionState::captureField()`
stores it without checking that the key is a slot of the compiled flow or that
the value fits the slot type (`CallSessionState.php:94-103`).

**Fix now (cheap):** `msgUrgency` `in:` the `Message::URGENCY_*` constants,
`msgCallerPhone` `nullable|string|max:32` plus a loose phone regex,
`msgReason` `max:5000`. `captureField`: reject keys that aren't a slot in
the session's compiled flow (422, which the worker already logs).
**Leave to plan 25:** slot-type validation, read-back and normalisation.

**Test:** urgency `"x"` rejected. Unknown slot key → 422.

**Risk:** low. Check that the worker's `set_field` only uses compiled slot
names. It reads them from `function_schemas`, so it should.

**Subsumed by:** plan 25.

### W5. Portal dashboard counts "today" in UTC

**Verdict: Confirmed.** `whereDate('started_at', today())`
(`app/Filament/Portal/Pages/Dashboard.php:66-69`) with `app.timezone = UTC`
(`config/app.php:68`). The recent-calls list formats `started_at` in UTC too
(`:62`).

**Fix:** compute the client-day bounds in `Team::displayTimezone()` and use
`whereBetween('started_at', [startUtc, endUtc])`. Format the list in the
viewer's `displayTimezone()` as the operator side does
(`Workspace.php:355`).

**Test:** frozen clock 2026-11-26 03:00 UTC, client in America/New_York: a
call at 2026-11-25 23:00 local counts as today.

**Risk:** none.

**Subsumed by:** plan 10.

## Workstream P: post-call actions (`web_call`)

### P1. API-key auth sends an empty key

**Verdict: Confirmed, latent.** The credentials shape is documented as
`{header_name, key_value}` (`2026_04_23_230100_create_client_web_endpoints_table.php:34`).
`PostCallActions::authenticate()` reads `header`/`key`
(`app/Services/Flows/PostCallActions.php:372-374`). No Filament page creates
`ClientWebEndpoint` rows (only the model, the migration and
`PostCallActions` reference it), so endpoints exist only where someone
inserted them by hand.

**Fix:** read `header_name`/`key_value`, with `header`/`key` as a fallback for
any rows already inserted by hand. Add a cast-level shape check on the model.

**Test:** endpoint with `{header_name: X-Key, key_value: abc}` sends `X-Key: abc`.

**Risk:** none.

**Subsumed by:** plan 21 (which builds the endpoint UI).

### P2. Credentials sent to any absolute URL; no SSRF block; `oauth2` throws

**Verdict: Confirmed.** In `webCall()` (`PostCallActions.php:308-363`), a
rendered `path` matching `^https?://` becomes the URL (`:317-321`), but the
named endpoint is still loaded, and its auth and default headers are still
attached (`:331-335`). `path` is a template, so a captured caller value can
become the host. Nothing blocks private, loopback or link-local
destinations, so Valkey, the metadata service and internal ports are
reachable. `oauth2` is a valid `auth_type` (`ClientWebEndpoint::AUTH_TYPES`)
that always throws (`:376`).

**Fix:**
- If the URL came from an absolute path, attach endpoint credentials and
  default headers only when its scheme+host+port equals the endpoint's
  `base_url`. Otherwise send with no endpoint auth.
- Resolve the host and refuse RFC 1918, loopback, link-local (incl.
  169.254.169.254), CGNAT and IPv6 ULA unless a platform setting allow-lists
  the CIDR. Pin the resolved IP for the request (Guzzle `CURLOPT_RESOLVE`) to
  stop DNS rebinding. Disable redirects or re-check each hop.
- `oauth2`: implement client-credentials (token cached until expiry), or
  remove it from `AUTH_TYPES` until plan 21. Removing it is cheaper. Do that.

**Test:** absolute URL to another host → no `Authorization` header.
`http://127.0.0.1/` and `http://169.254.169.254/` → refused. A redirect to a
private IP → refused.

**Risk:** medium. A client legitimately calling an on-prem system on a
private IP needs the allow-list. Add the setting in the same PR.

**Subsumed by:** plan 21 (and the security review, which didn't cover this
path). Fix now.

### P3. Templates use `app.timezone`; failed expressions leak into sent output

**Verdict: Confirmed.** `context.now` is built in `config('app.timezone')`
(`PostCallActions.php:100,228`), and `fnNow()` falls back to the same
(`ExpressionEvaluator.php:427`). On error, `TemplateEvaluator::render()`
returns `{{ expr ⚠ message }}` inline (`TemplateEvaluator.php:41-45`). That
text goes into emails and web-call bodies sent to clients.

**Fix:** build `now` in `Team::displayTimezone()` for the session's team.
Give `render()` a `strict` mode. Post-call actions use it, and it throws a
`TemplateRenderException` listing the broken expressions. The action is
marked failed with that message, and nothing is sent. The editor preview
keeps the inline marker.

**Test:** a client in America/Los_Angeles gets local `now`. An email body with
a broken expression isn't sent and the action log shows the error.

**Risk:** low. Actions that sent partially-broken output before now fail
loudly. That is the point, but tell clients in release notes.

**Subsumed by:** plan 21.

## Workstream A: agent worker and AI call paths

### A1. `send_sms` tool is `None`

**Verdict: Confirmed.** `_make_send_sms_tool()` ends at the inner function with
no `return` (`agent-worker/tools/compiled_flow.py:224-230`). Its
`return send_sms` is unreachable, after `return transition_to_flow`
(`:263-265`). `tools.append(None)` (`:69-70`) puts `None` in the tools passed
to `Agent(...)` (`agent.py:198-205`).

**Fix:** move the return. The tool stays a stub. Real sending is plan 05.

**Test:** pytest: `build_tools([{"name": "send_sms"}], …)` returns a list with
no `None` and a tool named `send_sms`.

**Risk:** none.

### A2. AI `transfer_call` lands in `[default]`; AI can name any extension

**Verdict: Confirmed.** `transfer_call` REFERs to
`sip:{extension}@asterisk` with an LLM-chosen `extension`
(`agent-worker/tools/transfer.py:25-55`). The REFER arrives on the LiveKit
endpoint, whose context is `from-livekit` (`docker/asterisk/pjsip_livekit.conf:15`).
Only the configured handoff numbers and `ai-unavailable` are defined there.
Anything else matches `_X.` → `Goto(default,${EXTEN},1)`
(`from-trunk.blade.php:117-118`), and `[default]` hangs up
(`docker/asterisk/extensions.conf:25-27`). The caller is dropped, and the
worker has already shut the session down (`transfer.py:56`).

**Fix:** follow the pattern `transfer_to_operator` already uses
(`agent-worker/tools/handoff.py`): Laravel decides the target.
- New `POST /call-sessions/{key}/transfer {extension}`: checks that the
  extension belongs to the session's client, or is a queue or platform
  extension the client's flow allows, and returns a SIP URI of the form
  `sip:xfer-t{team}-{number}@asterisk`.
- Generate matching `from-livekit` entries per client extension and queue
  (`exten => xfer-t5-101,1,Goto(tenant_5,101,1)`), next to the handoff
  entries in `handoffDialplanEntries()`.
- The worker tool calls the endpoint, REFERs only to what it returns, and
  doesn't shut down the session until the REFER is accepted.
- Follow-up (W1): add `X-Orbital-Session` on that leg so the operator's
  viewer can load captured fields.

**Test:** API: an extension of another client → 422. Dialplan snapshot has an
`xfer-` entry per client extension. pytest: tool refuses when the API refuses.

**Risk:** medium (call path). Manual test: AI → transfer to operator
extension → rings, answers, two-way audio.

**Subsumed by:** plans 08 and 15.

### A3. `answer_question` with no stores selected never gets search

**Verdict: Confirmed.** The goal's param hint says "None = search all stores
attached to this client" (`database/seeders/IntakeGoalLibrarySeeder.php:762`).
`collectAvailableStores()` returns `[]` when no ids are selected
(`app/Services/Flows/AgentFlowCompiler.php:1001-1003`), and the worker then
skips `search_knowledge` (`compiled_flow.py:61-65`).

**Fix:** when any goal in the flow is `answer_question` with an empty
selection, include all active stores of the team.

**Test:** compiler test: flow with `answer_question` and no ids →
`available_stores` lists the client's active stores.

**Risk:** low. The AI may now search stores the author didn't intend, but
that is what the UI says it does.

**Subsumed by:** plan 20.

### A4. Worker discards Laravel's caller-context reply

**Verdict: Partly. Nothing to fix yet.** `_report_call_context()` ignores the
response (`agent-worker/agent.py:234-265`). The response is only the
serialized session state (`CallSessionController.php:109`). No caller
history is in it, and the report runs after `session.start()` (`agent.py:207-211`),
so it couldn't change the prompt anyway. This is plan 13's design work.
Drop it from Known bugs and leave it in plan 13.

### A5. Voicemail greetings and disclosures rendered as 16/24 kHz WAV

**Verdict: Confirmed.** `VoicemailGreetingRenderer` wraps ElevenLabs
`pcm_16000` as a 16 kHz `.wav` (`VoicemailGreetingRenderer.php:184,200`)
and saves OpenAI's `wav` response (24 kHz) as-is (`:143-159`). `DisclosureRenderer` asks
OpenAI for `wav` (`DisclosureRenderer.php:172`, `RENDER_FORMAT` `:49`).
Asterisk's `format_wav` reads 8 kHz only. 16 kHz needs the `.wav16`
extension. The repo already learned this: `AiFallbackPrompt.php:24-25`
explains it and stores 8 kHz `.sln` via `downsample()`.

**Fix:** reuse `AiFallbackPrompt::downsample()`. Request `pcm` from OpenAI
(24 kHz → factor 3) and `pcm_16000` from ElevenLabs (factor 2), and store
`.sln`. Update `asteriskPromptPath()` and the `STAT(e,…)` checks to match.
Re-render existing greetings with a one-off command.

**Test:** renderer test with a faked HTTP response: output length is
input/factor, and the path ends `.sln`.

**Risk:** low. The custom greeting has been silently failing, so this
turns it on for clients who picked it. Have ops listen to one per voice.

**Subsumed by:** plans 14 and 15.

### A6. Recording beep and disclosure silently dropped

**Verdict: Confirmed.** `partials/mix-monitor.blade.php` is inert by design
(`:17-20`: "beep + disclosure are silently dropped"). It emits only a `NoOp`
(`:31-34`). The `[hooks-beep]` subroutine (`extensions.conf:36-41`) is never
called. Nothing in `docker/kamailio`, `local/edge` or the worker plays a
disclosure. Recording still happens at the edge, so clients that configured
a disclosure for two-party-consent states are recording without one.

**Fix (interim, PR 11):** play the disclosure in the dialplan before the
caller is connected. After the `orbital-cdr-inbound` Gosub in `from-trunk`,
when the client's policy has a disclosure (already resolved by
`CallRecordingService`, `disclosurePromptPath`), emit `Answer()` +
`Playback(<path>)`. It depends on A5 for the file format. The periodic
beep stays dropped until the rtpengine `play-media` work in
[rtpengine-edge.md](../rtpengine-edge.md). Say so on the client recording
settings page.

**Test:** snapshot: a client with a disclosure gets `Playback` before
`Goto`/`Dial`. A client without one gets nothing.

**Risk:** medium. This is a compliance gap: escalate to the platform owner
now, and don't wait for the PR. `Answer()` before routing changes CDR answer
times. R5's fix (answered = bridged party) makes that harmless, so ship R5
first or together.

**Subsumed by:** plans 19 and 22, and the rtpengine-edge follow-up.

## Workstream F: Filament 5 breakage

### F1. `Filament\Forms\Get` / `Set` don't exist

**Verdict: Confirmed.** Filament 5.10 (`vendor/composer/installed.json`) has
only `Filament\Schemas\Components\Utilities\Get`/`Set`, and no alias
exists. Closures type-hinted `Forms\Get`/`Forms\Set` are injected by
parameter name and then fail PHP's type check (TypeError). Locations (scan
of `app/`): `ManageClientDirectory.php:179-180`,
`ManageClientDirectoryFields.php:54` (`Set`) and `:90` (`Get`),
`KnowledgeStoreResource.php:116` (`Set`) and `:189-203` (`Get`). So the
directory import mapping, the directory field label slugging and option
visibility, and the knowledge store ingest form all break when evaluated.

**Fix:** import `Filament\Schemas\Components\Utilities\Get`/`Set` and drop the
`Forms\` prefix.

**Test:** Livewire smoke tests that mount each form and set the triggering
field (`file`, `type`, `source_type`, `label`, and the embedding-model select).

**Risk:** none.

### F2. **New:** `Forms\Components\Grid` doesn't exist

**Verdict: Confirmed.** `AsteriskBackendResource.php:68` uses
`Forms\Components\Grid`. In Filament 5 that class is
`Filament\Schemas\Components\Grid`, so the Asterisk backend create/edit form
fatals. The same scan found `use Filament\Forms\Components\Component` in
four queue pages (`ManageClientCallQueues.php`, `…ChatQueues`,
`…EmailQueues`, `…MessageQueues`). It is only used in docblocks, so it is
harmless at runtime but wrong for static analysis. Fix it in the same PR.

**Fix:** switch to `Filament\Schemas\Components\Grid` and
`Filament\Schemas\Components\Component`. Add a CI check: a test that
reflects every `use Filament\…` import and `Forms\…` alias reference in
`app/` and asserts that the class exists (the scan used for this plan, as a
test).

**Test:** the class-existence test, plus a smoke render of the
AsteriskBackend form.

**Risk:** none.

## Workstream X: misc

### X1. AMI: failed login keeps the socket; comma-joined variables

**Verdict: Partly.** `connect()` stores the socket before `Login` and returns
`false` on failure without closing it (`AsteriskAmiService.php:43-65`).
The next `connect()` on the same instance returns `true` (`:32-34`), so
later actions go out on an unauthenticated socket. Asterisk rejects them,
so this causes misleading failures, not unauthenticated actions. The service
isn't a singleton, so the window is one instance. Variables are joined with
commas into one `Variable:` header (`:306-313`). Asterisk splits on commas
outside parentheses, so the only caller (`TestCallPlacer.php:97-104`, key
`PJSIP_HEADER(add,…)`, hex token value) works. A value with a comma would
break, so the bug is latent.

**Fix:** on login failure, `fclose` and null the socket. Let `sendAction()`
take a list of `[key, value]` pairs and emit one `Variable:` line per
variable. Strip `\r\n` from every value (header injection).

**Test:** fake socket server: failed login → second call reconnects. Two
variables → two `Variable:` lines.

**Risk:** none.

**Subsumed by:** plans 19 and 23 (originate-heavy).

### X2. Telescope enabled by default

**Verdict: Confirmed.** `config/telescope.php:19` defaults
`TELESCOPE_ENABLED` to true. `laravel/telescope` is in `require` (not
`require-dev`, `composer.json:19`), and neither the Helm chart nor
`.env.example` sets it. In production it records exceptions, failed
requests, failed jobs and scheduled tasks
(`TelescopeServiceProvider.php:24-31`). Failed job payloads carry message
and SMS bodies, and the hidden headers omit `authorization` and API-key
headers (`:45-49`). Access is gated (`:57-68`).

**Fix:** default to `env('TELESCOPE_ENABLED', env('APP_ENV') === 'local')`.
Add `authorization`, `x-api-key`, `php-auth-pw` to hidden headers and
`password`, `secret`, `token` to hidden parameters. Add a 48 h prune to the
schedule.

**Test:** config test under `APP_ENV=production` → disabled.

**Risk:** none.

**Subsumed by:** plan 17.

### X3. Voicemail email: transcript and WAV in clear, owner only

**Verdict: Confirmed.** `VoicemailReceived` puts the transcript in the body
and attaches the WAV (`app/Mail/VoicemailReceived.php:44-73`), and it is sent
only to `team->owner->email` (`TranscribeVoicemailJob.php:148-159`). This is
a product decision, not a code bug: secure delivery and recipient lists are
plan 17 (secure messaging) and plan 05 (delivery). **No PR here.** If a
client is HIPAA-covered before plan 17 lands, the stop-gap is a per-client
toggle "email a link, not the audio/transcript". That is about half a day
and can join PR 12 if someone asks.

### X4. Public chat: `id OR name` lookup, no throttle or length cap, raw exception shown

**Verdict: Confirmed.** `ChatController::show()` runs
`Team::where('id', $client)->orWhere('name', $client)`
(`app/Http/Controllers/ChatController.php:27-29`). On Postgres, a
non-numeric `$client` compared to a bigint raises 22P02, which is a 500.
Names are guessable, and personal teams match too. The route has no
throttle (`routes/web.php:103-104`). `ChatConsole::send()` has no length
cap on `draft` and puts `$e->getMessage()` in the UI
(`app/Livewire/ChatConsole.php:40-60`), which can leak provider and SQL
errors.

**Fix:** look up by a public slug (add `teams.chat_slug`, unique, random by
default) or by numeric id only (`ctype_digit`), and always require
`personal_team = false`. `throttle:30,1` on the route. A per-session limiter
in `send()` (e.g. 20 turns/min). `max:2000` on `draft`. Log the exception
and show "Something went wrong, please try again".

**Test:** `/chat/abc/1` → 404 not 500. A personal team id → 404. A 2001-char
draft rejected. A thrown exception shows the generic text.

**Risk:** low. Existing shared links that use the client *name* break if
the slug option is chosen. Check whether any are in use.

**Subsumed by:** plan 24.

### X5. `METRICS_PER_CLIENT_LABELS=false` emits duplicate samples

**Verdict: Confirmed.** With the flag off, `clientLabel()` returns `[]`
(`app/Services/Metrics/PlatformMetricsCollector.php:576-583`), but callers
still loop per team (`:97-104` and five more, `:142,157,171,225,242`). Each
loop emits one sample per client with identical (empty) labels, and the
Pushgateway rejects a push with duplicate series.

**Fix:** when the flag is off, sum per family before emitting. Simplest is a
helper `perClientGauge($exposition, $name, array $countsByTeam, $help)` that
emits per-client or one summed sample.

**Test:** flag off, two clients → one `orbital_calls_recent` sample equal to
the sum.

**Risk:** none.

**Subsumed by:** plan 10.

### X6. Amtelco directory import drops status prefixes

**Verdict: Confirmed by code.** `placeNumber()` takes `number` before `raw`
(`app/Services/Import/Amtelco/DirectoryListingMapper.php:253`) and never
looks at `raw` when `number` is set. The prefixes plan 11 counted in the
export ("NOT O/C!!!!1917…", "AWAY TIL 11/13 2124…", `11-directory-status.md:110-114`)
are dropped. I didn't re-check the export itself (customer data).

**Fix:** if `raw` has non-digit text outside the number (after removing the
number's digits and punctuation), add it to the listing notes as
`"{label}: {text}"`. Plan 11 asks for exactly this as a standalone
prerequisite (`11-directory-status.md:675-678`). Re-import follows the
production-safety rules in `TODO.md` (the Amtelco import section).

**Test:** mapper unit with `raw: "AWAY TIL 11/13 2124399843"`,
`number: "2124399843"` → note "Home: AWAY TIL 11/13".

**Risk:** low. The re-import touches production directory data. Use the
importer's dry-run first.

**Subsumed by:** plan 11.

## PR sequence

Each PR is one branch, independently revertable, and ordered by
caller impact ÷ effort. Sizes are for one developer including tests.

| PR | Branch | Contents | Size |
|---|---|---|---|
| 1 | `fix/filament5-and-worker-stubs` | F1, F2 (+ class-existence test), A1 | 0.5 d |
| 2 | `fix/dialplan-queues` | D1, D2, D3, D4, D5, D6, D8, D10 helper text | 3 d |
| 3 | `fix/queue-membership` | Q3 first, then Q1, Q2, Q4 | 1.5 d |
| 4 | `fix/recording-playback-retention` | R1, R2 (dry-run first), R9 interim delete guard | 2.5 d |
| 5 | `fix/recording-ingest` | R3, R4 | 1 d |
| 6 | `fix/cdr-accuracy` | R5, R6, R7 (handoff tagging only), R8 + re-ingest command | 3 d |
| 7 | `fix/operator-workspace` | W1, W2, W3, W4 | 2 d |
| 8 | `fix/softphone-call-control` | S1, S2, S3, S4 | 2 d (+ manual test) |
| 9 | `fix/sms-inbound` | M1, M2, M3, M5, M6; remove M4 from TODO | 1.5 d |
| 10 | `fix/web-call-hardening` | P1, P2, P3 | 2 d |
| 11 | `fix/ai-call-paths` | A5, A6 interim, A2, A3, D7, D9 | 3.5 d |
| 12 | `fix/platform-hygiene` | X1, X2, X4, X5, X6, W5 | 2 d |

**Total:** about 24 developer-days. PRs 1–3 (5 days) fix the bugs callers
and operators hit today. PR 11 carries the compliance item (A6). If the
platform owner judges two-party consent urgent, pull A5 + A6 forward to
right after PR 2, and ship R5 with them.

Dependencies:
- PR 2 before PR 11 (both touch `client-dialplan.blade.php` and
  `from-trunk.blade.php`; A2 adds entries next to D-series changes).
- PR 6 (R5) before or with A6 (the disclosure `Answer()` would otherwise
  inflate "answered").
- PR 7's W1 `sessionKey` part waits on A2.
- PR 4's portal Play gating waits on security plan §6, but doesn't block on it.

Not scheduled here (owned by IS-parity plans, interim noted above): D10
enforcement (plan 09), R7 platform-call logging (plan 09), R9 archive
(plan 09), X3 (plan 17), A4 (plan 13), slot-type validation in W4 (plan 25).

## What the IS-parity plans would otherwise absorb

Fixing these now costs little compared with waiting. Each line names the
plan that lists it as a prerequisite or rewrites the code around it:

- **Plan 14** (queues/hold): D1 (same `max_wait_seconds` column), D3, D4,
  D5, D6, D8, D9, A5. The plan should drop these from its prerequisites
  once PR 2 and PR 11 land.
- **Plan 26** (skills routing): Q1, Q2, Q3, D1, D6, D8.
- **Plan 08** (patching): S1–S4, D7, A2.
- **Plan 10** (reporting): R5, R6, W5, X5, Q4.
- **Plan 09** (billing): R5, R7, R9, D10.
- **Plan 13** (caller history): R8, M6, A4.
- **Plan 16** (two-way messaging): M1, M2, M5.
- **Plan 17** (secure messaging): R2, R4, X2, X3.
- **Plan 21** (appointments / web calls): P1–P3.
- **Plan 25** (message aids): W4, M6.
- **Plan 11** (directory status): X6.
- **Plans 02/03** (operator scripting / messages): W1, W2, W3.
- **Plans 15, 19, 20, 22, 23, 24:** D2, A2 (15); A6, X1 (19); A3, S2 (20);
  R1, A6 (22); M3, X1 (23); X4 (24).

After each PR merges, tick the matching `TODO.md` bullets and remove the
item from the IS-parity plan's "prerequisites" or "what exists today"
section, so the two don't drift.

## Migration and rollout

- **Schema changes:** `call_queues.max_wait_seconds` (PR 2),
  `queues` column retypes (PR 2), `user_logout_events.note` (PR 3),
  optional `teams.chat_slug` (PR 12), `call_logs.status` value `abandoned`
  (PR 6; `call_logs.status` is a plain string, `2026_04_11_100007…:28`, so no migration).
  All additive or widening.
- **Dialplan:** PRs 2 and 11 change generated files. Run
  `php artisan orbital:generate-config` and diff the output on staging before
  deploying. Every PR touching templates includes snapshot tests.
- **Data:** PR 2 reports existing cross-client overflow personas. PR 4 runs
  the retention dry run on production and reviews the count before enabling
  deletes. PR 6 ships a `orbital:reingest-cdrs --since=` command to rebuild
  30 days of history with the corrected rules. PR 11 re-renders greetings.
  PR 12 re-imports the directory only after a dry run.
- **Manual call tests:** PRs 2, 8 and 11 each list the call scenarios in the
  PR body: queue wait > 30 s, client extension rings, hold MOH, AI transfer,
  disclosure heard. Run them through a local SIP edge (`local/edge-up.sh`).

## Tests

Each entry above names its test. Across all PRs:

- Dialplan snapshot tests (`tests/Feature/Telephony/…`) for every template
  change, with two clients sharing extension numbers in the fixture.
- CDR ingester tests use fixture rows captured from a real Asterisk for:
  queue abandon, queue answered by staff, AI call, AI → handoff, voicemail.
  Capture them once from a dev stack and commit them.
- A guard test that fails on `withoutGlobalScopes()` in
  `AsteriskConfigService` and on any team-scoped query in
  `app/Services/Telephony/Realtime/`.
- The Filament class-existence test (F2).
- pytest for the worker tool factories (A1, A2).
- JS unit tests for `SipPhone`/softphone state (S1–S3), if the repo has no JS
  test runner yet, add Vitest with pnpm (small).

## Open questions

1. **D2:** Do staff ever dial a client extension by bare number from
   `[internal]`? If yes, what number scheme should they dial instead
   (`t5_101` is awkward on a keypad; maybe `*5*101`)?
2. **R7:** Should platform-only calls (operator ↔ operator, test calls) appear
   in Call History at all, and under which client? This decides whether
   `call_logs.team_id` becomes nullable.
3. **R5:** Is `abandoned` a new status or `missed` with an outcome flag?
   Plan 10's reporting should decide. Pick one before PR 6.
4. **A6:** Who confirms which clients need a disclosure today, and do we
   notify them that it hasn't been playing since rtpengine took over?
5. **X4:** Are public chat links in use with client names in the URL?
6. **R2:** Should client deletion purge recordings immediately or after a
   grace period (ties into R9's archive)?

## Size

About 24 developer-days over 12 PRs (table above). PRs 1–3 are the urgent
slice at about 5 days.
