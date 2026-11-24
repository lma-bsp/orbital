# Plan 26: Skills-based routing — operator skills, call requirements, priority and overflow

**Status:** proposed · part of [IS parity](README.md) · survey item U in
[07](07-additional-features.md) · depends on 06 (schedules, routing
rule editor), uses 01 (`enter_call_queue` step), 02 (AI → operator
handoff), the [live call board](../live-call-monitoring.md) (AMI
listener + `live_calls` projection) and feeds 10 (staff reporting)
**Written:** 2026-11-24

## Problem

### What IS does

Amtelco's IS/Infinity ACD routes each call to the best available
operator, not to "whoever is in the group":

- **Agent skills with levels.** Operators are trained on specific
  client *accounts* (or account groups), languages (Spanish), and
  specialties (medical, hospital paging). Each skill has a proficiency.
- **Call requirements** come from the account, the line (DID) the
  caller dialled, and the script (a script can re-queue a call needing
  a Spanish speaker or a supervisor).
- **Priority.** Urgent lines and VIP accounts jump the queue; priority
  ages so ordinary calls are not starved.
- **Overflow tiers.** If no fully-qualified operator answers within N
  seconds, the requirement widens (lower proficiency, backup group,
  then "anyone"), then the call goes to an overflow destination.
- **Agent states:** ready, on call, wrap-up, aux reasons, logged out;
  ring-no-answer auto-pauses the agent; supervisors can force states.
- **Reporting** per skill / account / tier: service level, answered at
  which tier, time in state.

For Orbital the final overflow tier is an **AI agent** (LiveKit
persona) before voicemail/message — that is the part IS cannot do.

### What Orbital has today (verified)

**Data model — present, mostly unused.**

- `Skill` (`app/Models/Skill.php:23-60`): platform-wide vocabulary,
  categories `language|availability|specialty|tier|compliance`
  (`:25-31`), `users()` pivot `skill_user` with `level` + `notes`
  (`:48-53`), `callQueues()` pivot `call_queue_required_skills` with
  `weight` (`:55-60`). Not tenant-scoped (correct — operators are
  platform staff). Tables: `database/migrations/2026_04_15_100002_create_operator_skills_tables.php:41-71`
  (`level` tinyint default 3, `weight` tinyint default 5).
- Seeded catalogue (`database/seeders/SkillCatalogSeeder.php:25-35`):
  spanish, french, after-hours, on-call, medical-intake, legal-intake,
  billing, tier-1/2-support, vip-tier, compliance. **No per-account
  skill** — the most important IS skill type has no representation.
- `User::skills()` (`app/Models/User.php:330-335`, level documented as
  1–5), `CallQueue::requiredSkills()` (`app/Models/CallQueue.php:88-93`).
- **No UI**: nothing in `app/Filament` references `skills` or
  `requiredSkills` (grep). `docs/roadmap.md:119-122` says so: "nothing
  assigns or edits them, so routing is by agent group today".
  `App\Filament\Pages\DispatchRules` is a stub page whose docblock
  lists "skill routing" as future work (`DispatchRules.php:11-20`).

**Agent groups (what actually routes today).**

- `AgentGroup` (`app/Models/AgentGroup.php:25-88`) is a platform pool;
  strategy lives on the group via `QueueStrategyTemplate`
  (`:69-72`, strategies `ringall|roundrobin|leastrecent|random|fewestcalls`
  in `QueueStrategyTemplate.php:30-36`). AI agents are explicitly not
  members (`AgentGroup.php:22-23`).
- `AgentGroupMember` (`app/Models/AgentGroupMember.php:17-23`) is a
  polymorphic User/Extension pivot with `priority` and `penalty`,
  edited in `AgentGroupResource/RelationManagers/MembersRelationManager.php:55-62`.

**Membership sync (`QueueMemberSyncer`).**

- `app/Services/Telephony/Realtime/QueueMemberSyncer.php` already has a
  skills path: `collectMembers()` (`:114-129`) uses skill matching when
  the queue has required skills, else the agent group.
- `collectSkillMatchedMembers()` (`:145-207`): every user with an active
  extension and ≥1 overlapping skill is a member; penalty =
  tier base (enterprise 0 / pro 5 / free 10, `:147-152`) minus
  `min(4, Σ weight×level / 12)` (`:188-189`). There is no notion of a
  *required* vs *preferred* skill, minimum level, or per-call need:
  holding **any one** required skill admits the operator.
- The tier base is the same for every member of a given queue, so it
  has **no effect on ring order** (Asterisk compares penalties within a
  queue only). The "enterprise rings first" intent is not achieved;
  `tests/Feature/Telephony/Realtime/OperatorSkillRoutingTest.php:92`
  asserts the number, not the behaviour.
- `syncForOperator()` (`:61-79`) only walks queues reached through
  `agent_group_members`, so availability changes never update
  skill-matched queues (see Bugs below).

**Asterisk side.**

- Queues are realtime: `docker/asterisk/extconfig.conf:26-29`
  (`queues`, `queue_members`, `queue_log` via ODBC). `QueueSyncer`
  (`QueueSyncer.php:35-48`) writes strategy/timeout/retry from the
  group template, `wrapuptime`, `musiconhold`, `joinempty=1`,
  `leavewhenempty=1`. `ringinuse`, `weight`, `defaultrule`,
  `penaltymemberslimit`, `autopause` are left null.
- The ARA `queues` table has `weight` (string) and `defaultrule`
  typed **integer** (`database/migrations/2026_04_15_100000_create_asterisk_realtime_tables.php:247,249`)
  — Asterisk expects a rule *name*.
- No queue rules: no `queuerules.conf`, no `queue_rules` realtime
  family, no `QUEUE_PRIO` / `QUEUE_MAX_PENALTY` / `QUEUE_RAISE_PENALTY`
  anywhere in `resources/views/asterisk` or `docker/` (grep).
- Dialplan: `client-dialplan.blade.php:49-58` (and the platform copy in
  `extensions.blade.php:75`) generates
  `Queue(<name>,tTkK,,,<template timeout>)` then, if set, `Dial()` to
  the queue's `overflow_agent_persona_id` AI over `@livekit`, else
  `Hangup()`. The 5th argument is the caller's **maximum wait**, but it
  is fed the template's per-member ring timeout (also written to
  `queues.timeout`), so callers leave after ~30 s total.
- `from-trunk.blade.php:33-41` sets `__ORBITAL_DID` and `Goto`s the
  client's `queue-<id>`; there is no per-DID priority or requirement.
- `docker/asterisk/queues.conf:7-13`: `persistentmembers`, `autofill`;
  its comment about `queues_generated.conf` is stale (queues are
  realtime now).
- Asterisk runs as several nodes behind Kamailio
  (`AsteriskBackendDiscovery`, dispatcher in `docker/kamailio/kamailio.cfg`).
  app_queue state is **per node**: the same queue name on two nodes is
  two independent queues with the same realtime member list.

**Availability.**

- `AvailabilityReason` (`app/Models/AvailabilityReason.php:36-95`):
  admin-defined states with `blocks_voice` / `blocks_non_voice`; slug
  `available` is protected. `User::isAvailableForVoice()`
  (`User.php:113-116`) maps the reason to queue `paused`.
- `AvailabilitySelector::updatedStatus()` (`app/Livewire/AvailabilitySelector.php:48-99`)
  saves the state and calls `syncForOperator()` (`:80`).
  `ResetAvailabilityOnLogin` (`app/Listeners/ResetAvailabilityOnLogin.php:47-63`)
  forces `unavailable` at login and resyncs.
- Logout (`app/Providers/Filament/OperatorPanelProvider.php:192-213`)
  records a `UserLogoutEvent` but does not pause or resync.
- No state history table (only `users.availability_changed_at`), no
  automatic states (wrap-up, ring-no-answer, desk-full).

**Multi-call desk (uncommitted, this branch).**

- `MultiCallDesk` (`app/Services/Operator/MultiCallDesk.php`) keeps
  `OperatorCall` rows per operator; `answer()` (`:98-121`) puts the
  live call on hold — "an operator talks to one caller at a time";
  `followSoftphone()` (`:271-300`) mirrors **one** SIP session.
- The softphone rejects a second INVITE while busy
  (`resources/js/sip-phone.js:76-83`). With `ringinuse` unset, Asterisk
  can still offer queue calls to a busy operator; each such offer is
  a rejected INVITE.

**Reporting.** `queue_log` is written by Asterisk to the database
(`docker/asterisk/logger.conf:17-20`) and rolled up nightly by
`RollUpQueueMetricsCommand` into `queue_metrics_daily` (offered,
answered, abandoned, wait, talk per queue/day). Nothing records which
requirement a call had, which tier answered it, or operator state time.

### Bugs found while surveying (fix in phase 0)

1. **Availability doesn't reach skill-matched queues.**
   `syncForOperator()` (`QueueMemberSyncer.php:61-79`) selects queues
   only via `agent_group_members`. An operator in a skills queue who
   goes on break stays `paused=0` there. Likewise login reset
   (`ResetAvailabilityOnLogin.php:63`). Granting/revoking a skill or
   editing `call_queue_required_skills` triggers no resync either (no
   observer on either pivot; `TelephonyObserver` only handles
   SipTrunk/Extension/RoutingRule/CallQueue, `AppServiceProvider.php:90-93`).
2. **Agent group edits don't resync.** Adding/removing a member in
   `MembersRelationManager`, or changing a group's strategy template,
   updates nothing in `queue_members` / `queues` until some CallQueue is
   saved or `orbital:resync-realtime` is run.
3. **Global team scope inside the syncer (likely).** The syncer runs in
   the acting operator's auth context (`AvailabilitySelector.php:80`).
   `Extension` uses `BelongsToTeam`, whose global scope filters by
   `currentTeam` for non-super-admins. Staff softphone extensions have
   `team_id = null`, so `resolveExtension()` (`:260-273`) and the
   `whereHas('extensions')` in `:154-160` return nothing when that
   operator has a `current_team_id` (e.g. staff also attached to a
   client via `EditAllUser.php:233`). The recompute then drops every
   User member from the queue. Syncer queries must use
   `withoutGlobalScopes()`.
4. **Caller max wait = member ring timeout** (`client-dialplan.blade.php:52`):
   with no overflow persona, callers are hung up after ~30 s.
5. **Tier penalty is inert** (above), and the docblock at
   `QueueMemberSyncer.php:197-201` is wrong: Asterisk never rings paused
   members as a fallback.
6. `collectSkillMatchedMembers()` uses `extensions->first()` of *any*
   active extension (`:166`) and doesn't require a platform role —
   a client user with an extension and a skill would be admitted.
7. `queues.defaultrule` is an integer column (`…realtime_tables.php:249`).
8. Stale reference: `CallQueue.php:104` cites
   `resources/views/asterisk/queues.blade.php`, which does not exist.

## Goal

Every inbound call (and every AI → operator handoff) is offered to the
best-qualified available operator, considering:

1. **What the call needs** — derived from client, DID, routing rule and
   script: account training, language, specialty, minimum level.
2. **How urgent it is** — urgent lines and VIP clients first; aging so
   nobody starves.
3. **How long it has waited** — widen the requirement in configured
   tiers, then overflow to the client's AI persona, then voicemail /
   message.
4. **What the operator can take** — availability state, current call,
   wrap-up, multi-call desk capacity.

Supervisors manage skills and see routing outcomes; every routing
decision is reconstructable afterwards and testable without telephony.

Non-goals: outbound/predictive dialling; email/SMS routing (they keep
`AgentGroup` via `emailQueueIds()` / `messageQueueIds()` for now — the
same requirement model can be reused later); client-portal visibility
of operator skills.

## Design

### Approach: compute in Laravel, execute in Asterisk, in two stages

Two ways to do skills-based ACD on this stack:

| | **A. Asterisk-native** (penalties + queue rules) | **B. App-side dispatcher** (targeted offers) |
|---|---|---|
| How | Laravel computes static `queue_members` with skill-derived penalties; per-call `QUEUE_PRIO` and a queue rule widen `QUEUE_MAX_PENALTY` over wait time | Laravel holds the global picture (all nodes, all queues), picks operator × call, and makes the operator a member of exactly that call's queue on that node via AMI `QueueAdd`, removing on connect / RONA |
| Per-call requirements | One penalty axis per queue → per-call needs (language, urgent) need **variant queues** | Native: requirement is data on the call |
| Global priority / FIFO across clients | No. Each client queue is independent; queue `weight` only breaks ties for a shared member | Yes — one ordering across every client and node |
| Multi-node | Independent queues per node; same operator offered by two nodes at once | Router knows which node holds each caller |
| Multi-call desk capacity | Via pause only | Native (router checks desk load) |
| Failure mode | Asterisk alone; nothing to go down | Router outage must degrade safely |
| Effort | Small; reuses `QueueMemberSyncer` | Needs the AMI listener from the live-call plan |

**Decision:** ship **A as phase 1** (it fixes today's routing, and is
correct for a single node and moderate client counts), but build the
requirement/eligibility/scoring logic as a pure PHP service used by
both. **Phase 2 adds B** on top, reusing the same scorer, with A's
static membership as the automatic fallback when the router is
unhealthy. Asterisk keeps owning media, MOH, position, `queue_log` and
the final overflow `Dial()` in every phase — no ARI/Stasis rewrite (no
ARI client exists in `app/Services/Telephony/`).

### Data model

All new tenant-owned rows use `BelongsToTeam`; platform rows (skills,
operator qualifications) deliberately don't.

**1. Skills (extend `skills`).**

- Add category `account` to `Skill::CATEGORIES`, and nullable
  `skills.team_id` (FK, no global scope — the trait is *not* added;
  the column only links an account skill to its client). Slug
  convention `account:t{team_id}`; name = client name. Created by an
  action on the client ("Require trained operators"), never by clients.
  One vocabulary keeps one matcher; the `team_id` link lets us
  cascade-deactivate on client suspension and drive the "who is trained
  on this account" view.
- Add `skills.max_level` (default 5) and `skills.level_labels` (JSON,
  e.g. `{1:"Shadowing",3:"Trained",5:"Trainer"}`).
- Optional `skill_groups` (name) + `skill_group_skill` for "Medical
  accounts" style bundles — an operator trained on the group's skill
  counts as level N on every member account skill. Defer unless NCI
  confirms account groups (open question 2).

**2. Operator qualifications (extend `skill_user`).**

Add `is_active` (temporary suspension without losing level),
`expires_at` (annual compliance), `granted_by` (user id),
`granted_at`. Level stays 1–5. Expired/inactive rows are ignored by
the matcher and shown red in the UI.

**3. Call requirements: `routing_profiles` (BelongsToTeam, nullable team = platform default).**

One profile = what a class of call needs and how it overflows.

| column | meaning |
|---|---|
| `team_id` | client (null = platform template) |
| `name` | "Main line", "Spanish line", "Urgent / after hours" |
| `priority` | 0–100 base priority |
| `aging_per_minute` | priority points gained per minute waiting (phase 2) |
| `max_wait_seconds` | total time with humans before the final overflow |
| `final_overflow` | `ai` / `voicemail` / `queue:<id>` / `hangup_message` |
| `overflow_agent_persona_id` | defaults to the queue's |
| `language_skill_id` | nullable shortcut for the common case |

`routing_profile_requirements`: `routing_profile_id`, `skill_id`,
`min_level` (1–5), `mode` (`required` / `preferred`), `weight` (1–10).

`routing_profile_tiers` (ordered): `after_seconds`, `action`:
`lower_min_level` (by n), `drop_preferred`, `drop_requirement`
(skill_id — e.g. drop the account skill but keep Spanish),
`add_backup_pool` (skill_id of a general "backup" skill),
`any_operator`. The final overflow is not a tier row; it fires at
`max_wait_seconds`.

The existing `call_queue_required_skills` is migrated into a default
profile per queue (weight carried over, `mode = required`,
`min_level = 1`) and then dropped in a later release.

**4. Where requirements attach.**

- `call_queues.routing_profile_id` — the default for the queue.
- `client_dids.routing_profile_id` + `client_dids.priority_boost` —
  "this number is the Spanish line" / "this is the urgent line".
- `teams.routing_priority` (0–20, "VIP") replacing the meaningless use
  of `teams.tier` (`Team.php:41-42`) in routing. `tier` stays for
  billing/quotas.
- Routing rules (plan 06) may name a profile for a time window
  ("after hours → urgent profile").
- Script steps (plan 01): `enter_call_queue` gains optional
  `routing_profile_id` and `add_requirements` (e.g. AI detected
  Spanish → `{language: spanish}`), and AI → operator handoff (plan 02)
  carries the session's requirement.

**5. Resolution → `CallRequirement` (value object, not a table).**

`RoutingRequirementResolver::resolve(team, did, queue, rule, scriptOverrides): CallRequirement`

Precedence, each layer *adds or tightens* (never silently removes
a platform-set `required`):

1. queue profile → 2. client defaults (account skill if the client
requires trained operators; `routing_priority`) → 3. routing rule
profile → 4. DID profile + `priority_boost` → 5. script/AI overrides.

Output: `team_id`, list of `(skill, min_level, mode, weight)`, `priority`,
tiers, `max_wait`, final overflow, and a stable **profile key**
(hash of the hard requirements) used to name variant queues and to
report.

**6. Operator state (new).**

- `availability_reasons` gains `reporting_category`
  (`ready|aux|acw|training|offline`) and `is_system` (system-only rows,
  not selectable): seeded `wrap_up`, `ring_no_answer`, `desk_full`,
  `logged_out`.
- `operator_state_events` (append-only): user, from/to slug, cause
  (`self|supervisor|system:rona|system:acw|system:logout|login`),
  actor, at. Replaces "only `availability_changed_at`" and is the source
  for time-in-state reports.
- Desk capacity: `users.max_desk_calls` (default 1; platform setting
  for the default via `SettingsRegistry`).

**7. Routing audit (new).**

`routing_decisions` (append-only, retained like `queue_log`):
linkedid, node, team, queue, profile key, priority, event
(`entered|tier_widened|offered|rona|answered|overflow_ai|overflow_vm|abandoned`),
tier index, operator user id, candidate count, reason (JSON: why the
top candidates were/weren't chosen), at. In phase 1 a subset is written
from dialplan `QueueLog()` custom events (see below) and ingested from
`queue_log`; in phase 2 the router writes it directly.

### Routing algorithm (shared scorer)

`App\Services\Routing\SkillMatcher` — pure, no I/O:

```
eligible(op, req, tier):
  op has platform role, active softphone extension, voice not blocked
  for each requirement r still in force at tier (after tier actions):
     if r.mode == required: level(op, r.skill) >= r.min_level(tier) else reject
score(op, req, tier):
  Σ over matched requirements: r.weight × level(op, r.skill)
  + preferred bonus
penalty(op, req) = 10 × (first tier index at which op becomes eligible)
                 + (4 − min(4, score / 12))        # 0..4 within a tier
```

So penalty bands are 0–4 (full match), 10–14 (tier 1), 20–24
(tier 2)… Operators never eligible are not members.

**Phase 1 (Asterisk executes):**

- `QueueMemberSyncer::collectSkillMatchedMembers()` uses the matcher:
  one row per eligible operator per queue (and per variant queue),
  `penalty` from the band, `paused` from state. AgentGroup fallback
  stays for queues with no profile.
- **Widening** = one Asterisk queue rule per profile, realtime:
  `queue_rules(rule_name, time, min_penalty, max_penalty)` with rows
  `(<profile key>, 0, 0, 4)`, `(…, tier1.after, 0, 14)`,
  `(…, tier2.after, 0, 24)` … Generated by a new `QueueRuleSyncer`.
- **Priority within a queue** = `Set(QUEUE_PRIO=…)` in the dialplan from
  `profile.priority + did.priority_boost + team.routing_priority`.
- **Priority across clients** (shared operator in many queues) = queue
  `weight` = client `routing_priority` (Asterisk uses weight when one
  member is wanted by several queues at once). This is coarse; true
  cross-client ordering is phase 2.
- **Variant queues** for per-call hard requirements that differ from
  the queue default (Spanish line on a mixed queue, urgent line):
  `t42_support__<profilekey>` (6-char key), created by `QueueSyncer` only
  for profiles actually referenced by a DID, rule or script step of that
  client — bounded by configuration, not traffic. Variant membership is
  computed with the variant's requirement.
- **Overflow:** caller max wait = `profile.max_wait_seconds` (not the
  template ring timeout — fixes bug 4), then the existing AI `Dial()`
  path, then voicemail/message as configured.
- **Desk/ringing:** `ringinuse=no` on every queue so a busy operator is
  never offered a call; `autopause=yes` + `autopausedelay` so
  ring-no-answer pauses the member (reason `ring_no_answer`), mirrored
  into `users.availability_status` by the AMI listener (or, before it
  exists, by `queue_log` `RINGNOANSWER` ingestion every few seconds).
- `leavewhenempty` / `joinempty` must not include `penalty`, or a caller
  whose current window excludes everyone logged in would leave
  immediately instead of waiting for the next tier. Proposed:
  `joinempty = invalid,unavailable` / `leavewhenempty = invalid,unavailable`
  (i.e. leave to overflow only when nobody could ever take the call).
  Verify exact semantics on the Asterisk 22 image.

**Phase 2 (router executes):**

- `orbital:acd` (supervised, one active instance via Valkey lock,
  standby takes over) consumes the live-call plan's AMI stream
  (`QueueCallerJoin/Leave`, `AgentCalled`, `AgentConnect`,
  `AgentRingNoAnswer`, `QueueMemberStatus`) and keeps in Valkey:
  waiting calls (node, queue, linkedid, `CallRequirement`, entered at),
  operator states (ready/ringing/on call/acw/aux, desk load).
- Router-managed queues have **no static members**. Loop (on every
  event + 1 s tick):
  1. rank waiting calls by `priority + aging × minutes waited`
     (then entered-at);
  2. for the top call, current tier from its wait; candidates =
     `eligible()` among ready operators with desk load < capacity;
  3. pick lowest penalty, then longest idle (IS "longest available"),
     then fewest calls today;
  4. AMI `QueueAdd(Queue, Interface, Penalty=0, StateInterface,
     MemberName)` on **that call's node**; reserve the operator in Valkey
     (TTL = ring timeout + 2 s) so no other call is offered to them;
  5. on `AgentConnect` → `QueueRemove`, write `answered`; on
     `AgentRingNoAnswer` → `QueueRemove`, set `ring_no_answer` state,
     next candidate.
  Because ringinuse=no and the operator is the only member, Asterisk
  rings exactly them for the head caller of that queue; the router
  only targets queue heads (it knows positions from `QueueCallerJoin`).
- **Fallback:** the router writes a heartbeat; if stale > 10 s, a
  scheduled watchdog (and the router's own startup) flips queues back to
  phase-1 static membership via `QueueMemberSyncer`, and the dialplan
  timeout/AI overflow still protects callers throughout. Recovery
  removes static members again.
- Per-client concurrency: `teams.max_concurrent_calls` (exists,
  `Team.php:30`) can be enforced by the router (calls beyond the limit
  are held for AI/voicemail) — open question 5.

### Telephony integration (concrete changes)

- `QueueSyncer`: write `ringinuse='no'`, `weight`, `autopause`,
  `autopausedelay`, `timeout` = ring timeout, `joinempty`/`leavewhenempty`
  as above; create/delete variant queue rows.
- Migration: `queues.defaultrule` → string(128); add realtime
  `queue_rules` table; `extconfig.conf` line
  `queue_rules => odbc,orbital,queue_rules`; new
  `docker/asterisk/queuerules.conf` with `[general] realtime_rules = yes`.
- `client-dialplan.blade.php` queue block becomes a call into a shared
  `orbital-queue` Gosub (in `extensions.blade.php`) with args
  (queue, variant, prio, rule, maxwait, overflow target):
  ```
  same => n,Set(QUEUE_PRIO=${ARG3})
  same => n,QueueLog(${ARG1},${UNIQUEID},NONE,ORBITAL_ROUTE,${ARG2}|${ARG3})
  same => n,Queue(${ARG1},tTkK,,,${ARG5},,,${ARG4})
  ```
  then overflow by `QUEUESTATUS` (`TIMEOUT` / `LEAVEEMPTY` / `JOINEMPTY`
  → AI `Dial()` as today; `orbital-ai-unavailable` → voicemail).
  Check `Queue()`'s argument order on the 22 image
  (`core show application Queue`) — macro was removed in 21, rule is
  the 8th argument.
- `from-trunk.blade.php` sets `__ORBITAL_ROUTE_PROFILE` from the DID's
  profile so the client context picks the variant; routing rules
  (plan 06) do the same per time window.
- AI → operator handoff and script `enter_call_queue` (plan 01) go
  through the same Gosub with the session's requirement (variant key in
  phase 1, requirement id in phase 2 via `__ORBITAL_REQ` channel var
  that the router reads from `QueueCallerJoin` / `VarSet`).
- Multi-node: phase 1 accepts duplicate offers across nodes (the second
  INVITE is rejected by the softphone, `sip-phone.js:76-83`, Asterisk
  moves on); phase 2 removes them.

### Multi-call desk interplay

- **Capacity:** desk load = open `OperatorCall` rows for the operator
  that are `live`, `held` or `ringing` (`MultiCallDesk::openCalls`),
  plus ended-but-unclosed calls if the client requires the message to
  be finished before the next call (wrap-up).
- Phase 1: when load reaches `max_desk_calls`, the desk sets the system
  state `desk_full` (pauses the operator everywhere); closing a call
  restores the previous self-chosen state. With today's one-SIP-session
  softphone, `max_desk_calls` is effectively 1 for live calls;
  >1 becomes real when plan 08 lets held calls be parked in Asterisk
  so the softphone is free.
- **Wrap-up (ACW):** per queue `wrapup_time` stays as Asterisk's
  timer; additionally, a client flag "message must be saved before next
  call" holds `wrap_up` state until `MultiCallDesk::saveMessage()` /
  `close()` or a platform max (e.g. 120 s), then auto-returns to ready.
- Phase 2: the router reads desk load directly; no pause churn.
- `OperatorCall` gets `routing_profile_key`, `routing_tier` and
  `linkedid` so the desk can show "Spanish · urgent · answered at tier 1"
  and reports can join desk work to routing decisions.
- Trial desks (`is_trial`) never affect state or capacity.

### Availability states

| State | Source | Routes voice? | Reporting category |
|---|---|---|---|
| Available | operator | yes | ready |
| On call | Asterisk device state / desk | no (ringinuse=no) | on_call (derived) |
| Wrap-up | system (ACW) | no | acw |
| Ring no answer | system (autopause / router) | no, until operator acknowledges | aux |
| Desk full | system | no | on_call |
| Break / Lunch / Training / Non-voice only… | operator (existing rows) | per `blocks_voice` | aux / training |
| Logged out | login reset, logout action, session expiry | no | offline |

Changes: logout action (`OperatorPanelProvider.php:192-213`) sets
`logged_out` and pauses; supervisors can force a state (audited);
every change goes through one `OperatorStateService::set(user, slug,
cause, actor)` that writes `operator_state_events`, updates
`queue_members.paused` with a single targeted UPDATE by interface (not
a full per-queue recompute — an operator may be in hundreds of client
queues), and in phase 2 notifies the router. `AvailabilitySelector` and
`ResetAvailabilityOnLogin` call that service.

### Admin / supervisor UI (Filament, platform panel)

- **Skills** resource (Workflow group): CRUD, category, level labels,
  "operators with this skill" relation manager with level/expiry.
  Account skills appear under the client instead.
- **Operator → Skills** relation manager on `UsersResource` /
  `AllUsersResource`: attach skill + level + expiry; bulk "grant
  account training to selected operators".
- **Client → Routing** sub-page (beside `ManageClientCallQueues`):
  "Require trained operators" toggle (creates the account skill),
  VIP priority, routing profiles with requirements and tier editor,
  DID → profile/boost mapping, preview: "Right now: 3 operators match at
  tier 0, 7 at tier 1, AI after 90 s".
- **Skills matrix** page: operators × skills grid with levels, filter by
  client/category, highlights accounts with < N trained operators
  logged in (coverage risk).
- **Routing simulator** on the client page: pick DID + time + script
  override → resolved `CallRequirement`, tiers, and the ranked
  candidate list with reasons. Same code path as production (matcher).
- Live call board (live-call plan) gains columns: profile, priority,
  current tier, candidates available; supervisor "force state" and
  "offer to operator X" (phase 2 only) actions.
- `DispatchRules` stub page is replaced by (or links to) the routing
  profile templates (platform-level profiles with null team).

### Permissions & tenant isolation

New catalogue entries (`PermissionCatalogSeeder`, alongside
`call_queue.*` at line 29): `skill.view_any|create|update|delete`,
`operator_skill.assign`, `routing_profile.view_any|create|update|delete`,
`operator_state.force`, `routing.simulate`. Defaults: super_admin all;
supervisor `operator_skill.assign`, `operator_state.force`,
`routing.simulate`, view of profiles; operator none. Client portal:
none — clients never see operator skills, levels or routing internals.

Isolation rules:
- `routing_profiles` use `BelongsToTeam`; platform templates (null
  team) are read-only to non-super-admins and *copied* into a client,
  never referenced live across clients.
- An account skill (`skills.team_id = A`) can only be required by
  profiles of team A (validated on save and in the resolver).
- The syncer, matcher, router and resolver never run under an
  operator's auth scope: queries use `withoutGlobalScopes()` explicitly
  and are covered by a test that runs the sync while authenticated as a
  non-super-admin with a `current_team_id` (bug 3).
- Variant queue names include the team prefix (`CallQueue::asteriskName()`,
  `CallQueue.php:112-125`) so two clients' variants never collide.

### Audit

- `skill_user` changes (grant/level/expiry/revoke) → `skill_audit_events`
  (who, operator, skill, old → new). Account-training grants are what a
  client will ask about after a bad call.
- `operator_state_events` (above) for every state change incl. forced.
- `routing_decisions` (above) for every call: enough to answer "why did
  this Spanish urgent call wait 70 s and go to AI?".
- Routing profile edits: versioned (`version` + JSON snapshot on save)
  and `routing_decisions` stores the version used.

### Reporting hooks (consumed by plan 10)

- Phase 1: `QueueLog(...,ORBITAL_ROUTE,<profile>|<prio>)` at entry; the
  nightly roll-up gains columns per (queue, profile key, day):
  answered at tier 0/1/2, overflowed to AI, overflowed to voicemail,
  service level (answered ≤ X s), abandoned per tier.
- `queue_log` `CONNECT` agent + `routing_decisions` give "answered by a
  primary vs backup operator" per client.
- `operator_state_events` → time in ready/aux/acw/on-call per operator
  per shift, RONA counts, forced-state counts.
- Skills coverage: per account, trained operators logged in by hour
  (sampled every 5 min into `skill_coverage_samples`) — the staffing
  input supervisors actually use.
- Live: the live-call board shows current tier and per-profile waiting
  counts from the same Valkey projection.

## Edge cases and failure modes

- **Nobody holds the account skill** (new client): profile save warns;
  tiers must end in `any_operator` or the final overflow; simulator
  shows "no eligible operators".
- **All operators paused / logged out:** `joinempty=unavailable` sends
  the call straight to overflow (AI) rather than holding the caller.
- **Caller already waiting when an operator gains a skill:** phase 1
  depends on app_queue re-reading realtime members for an active queue
  — verify on 22 (spike task); if it doesn't, also send AMI
  `QueueAdd`/`QueuePause` to each node for immediacy.
- **Mid-call requirement change** (script/AI detects Spanish): re-queue
  through the Gosub with the new variant/requirement; wait time
  continues counting from first entry for tiering (pass original entry
  time in a channel var) so the caller isn't penalised twice.
- **RONA loops:** an operator who keeps not answering is paused with
  `ring_no_answer` and must click back to Available; the router never
  re-offers the same call to the same operator within 60 s.
- **Priority starvation:** aging guarantees an ordinary call eventually
  outranks a fresh urgent one; cap max boost so VIP can't starve
  everyone (phase 2). Phase 1 has no aging (Asterisk QUEUE_PRIO is
  static) — document it.
- **Router split-brain** (two instances offering): single-writer Valkey
  lock with fencing token stored on each reservation; offers carry the
  token and stale ones are ignored.
- **AMI disconnect** in phase 2: the router stops offering on that node,
  the watchdog restores static members for that node's queues; callers
  keep waiting with MOH and still hit the timeout overflow.
- **Node drain** (`AsteriskDrainService`): no new offers to calls on a
  draining node beyond those waiting; waiting callers are still served.
- **Skill expiry at shift start:** nightly job + check at match time;
  expiry never kicks an operator off a live call.
- **Client suspended** (`teams.suspended_at`): its account skill and
  profiles deactivated; queues follow existing suspension routing.
- **Membership explosion:** with ~1,600 clients and an "any trained
  operator" profile, an operator can be in hundreds of queues. Keep
  pause updates targeted by interface; measure `queue_members` size
  and Queue() join latency in the spike; this is the main scaling reason
  for phase 2.
- **Desk phones (Extension members)** have no human state: they keep
  the AgentGroup path (or are linked to a user to inherit skills).

## Migration / rollout

0. **Phase 0 — fixes** (independent, ship first): bugs 1–8 above
   (observers on `skill_user`, `call_queue_required_skills`,
   `AgentGroupMember`, `AgentGroup`; scope-free syncer queries; max wait
   vs ring timeout; `ringinuse=no`; `defaultrule` column; doc fixes),
   plus logout pausing.
1. **Phase 1a — data + UI:** skills UI, operator skill assignment,
   account skills, routing profiles; migrate `call_queue_required_skills`
   into per-queue default profiles; queues with no profile stay on
   AgentGroup. Feature flag per client `routing_mode = group | skills`
   (default `group`).
2. **Phase 1b — Asterisk-native skills:** matcher-driven members,
   queue rules, QUEUE_PRIO, variants, overflow by profile, state
   service + system states, `routing_decisions` via queue_log. Enable
   per client; pilot on 2–3 clients with Spanish/urgent lines.
3. **Phase 2 — router:** after the live-call board's AMI listener is
   live. Per-client `routing_mode = router`, shadow mode first (router
   computes and logs what it *would* offer while Asterisk phase 1 still
   routes; compare in a report), then cut over client by client.
4. Remove `call_queue_required_skills` and the tier penalty math once
   all clients are on profiles.

Backfill: seed account skills for clients flagged "trained only" from
NCI's existing IS agent-account assignments if an export exists (open
question 1); otherwise supervisors assign via the bulk action.

## Tests (answering-service suite)

Pure unit (no DB telephony):
- Matcher table: operator skill vectors × requirement → eligible tier
  and penalty band; required vs preferred; min_level; expired/inactive
  qualification ignored; operator without platform role excluded.
- Resolver precedence: queue → client → rule → DID → script; DID
  "Spanish line" adds language; script override can tighten but not
  drop a platform `required`; account skill of client B rejected on
  client A's profile.
- Tier timeline: frozen clock, call waiting 0/20/45/90 s → active tier
  and final overflow; re-queue keeps original entry time.
- Phase 2 ranking: urgent vs normal with aging (normal at 6 min beats
  fresh urgent when aging=…); VIP cap; ties by entered-at; longest-idle
  operator chosen; RONA operator skipped for 60 s.

Feature (DB, fake AMI):
- Sync: Spanish level-4 + account-trained operator → penalty 0–4 in
  `t{A}_main__<es>`; untrained operator only in tier-2 band; non-Spanish
  operator absent from the Spanish variant.
- Availability: switching to Break sets `paused=1` in **every** queue
  row for that interface, including skills queues (regression for bug 1);
  run while authenticated as a non-super-admin with `current_team_id`
  (regression for bug 3).
- Granting/revoking a skill, editing an agent group member, changing a
  profile → queue_members updated without saving a CallQueue (bug 2).
- `QueueRuleSyncer` writes rows matching profile tiers; changing a tier
  updates them; deleting a profile removes them.
- Dialplan snapshot: client context Gosub args (variant, prio, rule,
  maxwait, overflow) for queue / Spanish DID / urgent DID / no-overflow
  queue (voicemail, never a bare Hangup after 30 s — bug 4).
- Desk: third open OperatorCall with capacity 2 → `desk_full`; closing
  one restores prior state; trial desk calls never change state.
- Wrap-up: client requiring saved messages holds `wrap_up` until
  `saveMessage()`; max ACW auto-returns to Available.
- Logout action sets `logged_out`, pauses, writes state event.
- Router (fake AMI stream fixtures): queue-join → QueueAdd to the right
  node/queue/interface; AgentConnect → QueueRemove + `answered`
  decision; RingNoAnswer → state change + next candidate; heartbeat
  stale → static members restored.
- Permissions: supervisor can assign skills, operator cannot; portal
  users get 403 on every routing page; client admin can't see skills.
- Isolation: two clients both named "Support" with variants never share
  rows; account skill A never admits an operator to B's queue.

Integration (manual / nightly with the local SIP edge, see memory
`local-sip-edge-on-this-host`):
- Two softphones (one Spanish), call the Spanish DID → Spanish operator
  rings first; mark them busy → after tier-1 delay the other rings;
  both unavailable → AI persona answers at max wait.
- Urgent DID call placed after a normal one in the same queue is
  answered first.

## Open questions

1. Can NCI export IS agent ↔ account/skill assignments and skill levels
   (IS Supervisor), so we can import them instead of re-entering?
2. Does NCI use account *groups* ("all medical accounts") for training,
   or per-account only? Decides whether `skill_groups` is phase 1.
3. Default tier timings and max wait before AI per client class
   (medical vs property vs general)? IS sites typically use 15–30 s
   steps; need NCI's current settings.
4. Should urgent priority come only from DID/rule, or also from caller
   ID (known VIP callers, plan 13 caller history)?
5. Enforce `teams.max_concurrent_calls` in routing (hold extra callers
   for AI) or leave it as a billing/plan limit?
6. Is RONA auto-pause wanted (IS default) or does NCI prefer to keep
   ringing the next operator without pausing?
7. Desk capacity >1 for live calls depends on plan 08's park/hold —
   confirm the multi-call desk is meant to hold several *live* callers
   at once, not just unfinished messages.
8. Should AI personas ever be "members" with skills (e.g. Spanish AI as
   tier 0 at night) — i.e. AI as a first-class candidate in the router
   instead of only final overflow? (Today `AgentGroup.php:22-23` rules
   it out; the router could support it later.)

## Size estimate

- Phase 0 fixes: 2–3 days.
- Phase 1a (skills/qualifications/profiles model, migrations, Filament
  resources, skills matrix, simulator): ~1.5 weeks.
- Phase 1b (matcher, syncer rewrite, queue rules + realtime family,
  dialplan Gosub + variants, state service + system states, desk
  capacity, queue_log hooks, roll-up columns, tests): ~2 weeks, incl. a
  2-day spike on app_queue realtime behaviour (member refresh, queue
  rules, `leavewhenempty` values) on the 22 image.
- Phase 2 (router, reservations, fencing, fallback watchdog, shadow
  mode, live-board integration, tests): ~2–3 weeks after the live-call
  board's AMI listener exists.

Total ~6–8 weeks of focused work; phase 0 + 1 (~4 weeks) delivers
IS-equivalent behaviour for NCI's pilot clients.
