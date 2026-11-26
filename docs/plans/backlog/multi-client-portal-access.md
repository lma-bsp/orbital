# Plan: Portal access to more than one client

**Status:** proposed · part of [Backlog plans](README.md) · should land after, or together with, [security-review-fixes §6](../security-review-fixes.md#6-portal-view-permissions-are-never-enforced)
**Written:** 2026-11-26

Covers `TODO.md:749-763` "Multi-tenant portal access for contacts +
shared directories".

## Problem

Some people legitimately need the portal for more than one client:
a practice manager running two clinics, a law firm that is a client
and also sits on another client's escalation list, a property company
with one account per building. The portal can only show one client at
a time and gives the user no way to change which.

The TODO describes the fix in terms of a `Contact` model, a "Grant
portal access" action that sets `contacts.user_id`, and a new
`contact_portal_access` pivot. **That model no longer exists**, so
the plan has to start from what replaced it.

## What exists today (checked in code)

### The TODO's premise is out of date

- `app/Models/Contact.php`, `SharedContactList`, the tenant contacts
  pages and the shared contact list resource were deleted in
  `e864f9d` (2026-04-22, "WIP"). The grant-portal-access action went
  with them.
- They were replaced by the **directory**: `DirectoryEntry`
  (`app/Models/DirectoryEntry.php`) for a client's own phone book and
  `SharedDirectory` for platform-curated lists attached to many
  clients through `team_shared_directory`
  (`app/Models/SharedDirectory.php:43-45`). Directory entries are
  explicitly not users: "Never logs in to Orbital"
  (`DirectoryEntry.php:13-15`; the migration says the same,
  `database/migrations/2026_04_14_100001_create_directory_tables.php:10-14`).
- Portal identity is now a plain `User` invited to a client:
  `ClientInvitation` (`app/Models/ClientInvitation.php`), accepted by
  `ClientInvitationController::grantRoleAndMembership()`
  (`app/Http/Controllers/ClientInvitationController.php:121-140`), or
  attached directly by staff from the client's **Users** page
  (`app/Filament/Resources/ClientResource/Pages/ManageClientUsers.php:100-142`,
  `attachExistingUser()` `:277-311`).

### Membership is already many-to-many

- Membership is Jetstream's `team_user` pivot (`User` uses `HasTeams`,
  `app/Models/User.php:16,30`), with a `role` column of `admin` or
  `member`.
- Permissions are Spatie roles **per team**: `config/permission.php:138`
  has `'teams' => true`. `ClientProvisioner` creates `client_admin`
  and `client_user` roles with that client's `team_id`
  (`app/Services/Clients/ClientProvisioner.php:135-187`), and
  assignment always happens inside a `setPermissionsTeamId($team->id)`
  swap (`:270-278`, `ManageClientUsers.php:288-297`,
  `ClientInvitationController.php:126-139`).
- Nothing stops a user from belonging to two clients. "Add existing
  user" lists users from other clients on purpose
  (`ManageClientUsers.php:104`, "or a user attached to another
  client"), and invitation acceptance uses `syncWithoutDetaching`
  (`ClientInvitationController.php:135`).

So the `contact_portal_access (contact_id, team_id, role)` pivot the
TODO proposes already exists: it is `team_user (user_id, team_id,
role)` plus `model_has_roles.team_id`. Building another pivot would
give two sources of truth for the same fact.

### What actually breaks for a two-client user

- **One client at a time, chosen silently.** Every portal page reads
  `users.current_team_id`: `Dashboard.php:47-48`,
  `CallHistory.php:50`, `TextConversations.php:56-68`,
  `RoleResource.php:98-101`, `CreateRole.php:24`. So does
  `SetPermissionsTeamContext` (`app/Http/Middleware/SetPermissionsTeamContext.php:22-24`),
  which decides which client's roles `can()` sees, and the record
  policies (`app/Policies/CallLogPolicy.php:31`,
  `app/Policies/Concerns/ClientResourcePolicy.php:90`).
- **Whoever touched the user last decides.** Both attach paths
  overwrite `current_team_id` (`ManageClientUsers.php:299-304`,
  `ClientInvitationController.php:136`). Accepting an invite to
  client B silently moves the user off client A.
- **No switcher.** The portal panel
  (`app/Providers/Filament/PortalPanelProvider.php`) has no team menu.
  Jetstream's `PUT /current-team` route is registered
  (`vendor/laravel/jetstream/routes/livewire.php:41`) and the
  `switchable-team` component exists, but they are only used in the
  Jetstream navigation menu (`resources/views/navigation-menu.blade.php:65,212`),
  which no Filament panel renders.
- **Removal strands the user.** `removeUser()` sets
  `current_team_id = null` when it removes the user from their
  current client (`ManageClientUsers.php:376-378`), even when they
  still belong to another one. Jetstream's `currentTeam()` then tries
  `switchTeam($this->personalTeam())`
  (`vendor/laravel/jetstream/src/HasTeams.php:26-33`). Client users
  have no personal team, so it stays null. The user still passes
  `belongsToAnyTenant()` (`User.php:228-233`) and lands on a portal
  that shows nothing.
- **Removal leaves the admin role behind.** `removeUser()` removes
  only `client_user` (`ManageClientUsers.php:362-369`). A removed
  admin keeps `client_admin` for that team. It does nothing while they
  have no membership, but re-adding them as a member makes them an
  admin again.
- **Two-factor grace follows the current client.**
  `TwoFactorPolicy` reads `currentTeam?->two_factor_grace_days`
  (`app/Services/Auth/TwoFactorPolicy.php:61`). A user in two clients
  gets whichever grace window belongs to the one they last switched
  to.
- **Portal permissions aren't enforced anyway.** `portal.view_*` are
  seeded and offered on the Roles page but never checked
  ([security-review-fixes §6](../security-review-fixes.md#6-portal-view-permissions-are-never-enforced)).
  Per-client permissions only mean something once §6 lands.
  `portal.manage_users` has no portal page at all. Only
  `portal.manage_roles` is checked
  (`RoleResource::viewerCanManageRoles()`, `RoleResource.php:179-191`).

### The TODO's other names

- "Portal RequestContext": there is no such class in `app/`. The
  context is `current_team_id` plus `SetPermissionsTeamContext`.
- "The shared-list entries editor skips the grant-access action": the
  editor was deleted with `SharedContactList`. Shared directory
  entries are managed in `SharedDirectoryResource` and, like every
  directory entry, can't log in.

## Goal

1. A user who belongs to several clients sees a client switcher in
   the portal and can move between them without logging out.
2. Each client's portal permissions apply only inside that client.
   Being an admin at client A gives nothing at client B.
3. Adding a user to another client never changes what they're looking
   at without them asking.
4. Removing a user from one client leaves them working in the others.
5. No new identity model. `team_user` and team-scoped Spatie roles
   stay the single source of membership.

Out of scope: giving directory entries a login. Plan 11 gives
directory contacts a limited self-service identity for status
updates (`directory_status_credentials`, see
[11](../is-parity/11-directory-status.md)), which is a different
thing from portal access. A convenience "invite this directory
contact to the portal" is listed under phase 3.

## Design

### Decision: keep `current_team_id`, add a switcher

Two ways to give the portal a per-client context:

| | Jetstream `current_team_id` + switcher | Filament tenancy (`/portal/{team}/…`) |
|---|---|---|
| Context lives in | the `users` row | the URL |
| Changes needed | a switcher, fixes to attach/remove | `->tenant(Team::class)` on the panel, `HasTenants` on `User`, every portal page and policy reads `Filament::getTenant()`, `SetPermissionsTeamContext` reads the tenant on portal routes, `BelongsToTeam` learns about it |
| Two tabs on two clients | the second switch wins in both tabs | works |
| Fits the other panels | yes; admin and operator also read `current_team_id` | portal-only, so two context mechanisms |

**Use the switcher.** Every existing check already reads
`current_team_id`, and the security review found portal query scoping
sound. Rewriting all of it to Filament tenancy for a two-tab edge case
is not worth the risk. The two-tab case is handled below as a stale
context guard. If multi-client portal users turn out to be common,
moving to Filament tenancy is the follow-up, and this design doesn't
block it.

### `PortalContext` service

One small class owns "which client is this portal user in", so
the logic isn't spread across controllers.

`app/Services/Clients/PortalContext.php`:

- `clientsFor(User): Collection<Team>`: the user's teams where
  `personal_team = false`, ordered by name.
- `switchTo(User, Team): void`: checks `belongsToTeam`, calls
  Jetstream `switchTeam()`, `unsetRelation('roles')` and
  `unsetRelation('permissions')`, calls
  `PermissionRegistrar::setPermissionsTeamId($team->id)`, and clears
  that user's cached permissions so the same request sees the right
  roles.
- `ensureValid(User): ?Team`: if `current_team_id` is null or points
  to a team the user no longer belongs to, move them to their first
  remaining client, or null if there's none. Called from a new
  `EnsurePortalClient` middleware on the portal panel, placed after
  `SetPermissionsTeamContext` and re-running it when it changes the
  team.

### Switcher UI

- Portal user menu: a "Switch client" section listing
  `clientsFor($user)` (name and account number), the current one
  ticked. Shown only when the user has two or more clients.
- The selected client's name is also shown in the topbar via
  `PanelsRenderHook::GLOBAL_SEARCH_BEFORE`, so it's always clear
  which account is on screen. The dashboard already shows
  `tenantName` (`Dashboard.php:48`).
- Choosing a client posts to a portal route
  `POST /portal/switch-client/{team}` that calls `switchTo()` and
  redirects to the portal dashboard, not back to the current URL. A
  deep link to a client-A record is meaningless in client B.
- The switch is written to the audit log (below).

### Stale context guard (two tabs)

Each portal page render stamps the team id into the page
(`<meta name="orbital-client" content="…">`). A tiny script compares
it with a `X-Orbital-Client` response header on each Livewire
request. On mismatch it shows a banner, "You switched to Client B in
another tab", with a reload button, and stops polling. This is a
display fix only. The server already filters every query by the
current team, so a stale tab can't read the other client's data. Its
actions just fail to find their records.

### Membership changes stop moving people

- `attachExistingUser()` and `grantRoleAndMembership()` set
  `current_team_id` only when it is null or invalid
  (`PortalContext::ensureValid`). The "Their current client context
  has been switched" notification (`ManageClientUsers.php:306-310`)
  goes.
- Invitation acceptance redirects to the portal with the new client
  selected for that request **once**, by calling `switchTo()` only
  when the user has no other clients. Otherwise it flashes "You now
  have access to Client B. Switch clients from the user menu."
- `removeUser()`:
  - removes **every** role the user holds for that team, not just
    `client_user`;
  - detaches the membership;
  - calls `ensureValid()` so the user lands on another client if
    they have one.
- The bulk "Remove from client" action already promises this. Its
  modal says it "revokes the tenant-scoped roles (client_admin /
  client_user / any custom)" (`ManageClientUsers.php:166`), but it
  calls `removeUser()` (`:186-188`), which removes only `client_user`.
  The fix makes the code match the description.

### Permission model

Nothing new is needed in the schema. Each permission check must run
with the team context set to the client being viewed, which
`SetPermissionsTeamContext` already does from `current_team_id`.
Rules to keep it that way:

- Portal permission checks go through `$user->can('portal.…')` after
  the middleware. No portal code reads roles across teams.
- [security-review-fixes §6](../security-review-fixes.md#6-portal-view-permissions-are-never-enforced)
  adds `canAccess()` per page. This plan adds a test that the same
  user with `client_admin` at A and `client_user` minus
  `portal.view_recordings` at B can play recordings at A and not at B.
- The team-owner fallback (`RoleResource.php:185-188`) stays
  per-team: owner of A is not owner of B.
- **Platform staff with a client membership.** `isSuperAdmin()` and
  `hasAnyPlatformRole()` switch the team context to null for their
  own checks (`User.php:184-216`) because `current_team_id` would
  otherwise hide team-less roles. A staff member who switches to a
  client in the portal keeps that `current_team_id` in the admin and
  operator panels. Any `can()` call there that doesn't do the same
  swap evaluates against the client's roles. Audit those call sites
  (`CallLogPolicy`, `CheckToolPermission`,
  `AdminPanelProvider.php:335`) as part of this work. The safe fix is
  for `SetPermissionsTeamContext` to set `null` on the admin and
  operator panels, where client roles never apply.
- **Two-factor grace.** `TwoFactorPolicy` uses the **shortest** grace
  window across all of the user's client memberships, not the current
  one. A user can't relax their own enforcement by switching.

### Audit

`portal_context_events` would be overkill. Write switches and
membership changes to the operator-activity log from
[operator-activity-events](operator-activity-events.md) when it
exists, or to `Log::info` on the `audit` channel until then:
`portal.client_switched` (user, from, to, ip),
`client.member_added`, `client.member_removed`, `client.role_changed`
(actor, user, team, roles before/after).

### Phase 3: invite from the directory

A directory entry can't log in, but staff often want "give Dr Lee
portal access" while looking at Dr Lee's entry.

- Action "Invite to portal" on a client directory entry
  (`ManageClientDirectory`): pre-fills the email from the entry's
  `email`-role field and calls the existing `sendInvitation()` for
  that client.
- On a shared directory entry (`SharedDirectoryResource`): the action
  asks which of the subscribed clients (`SharedDirectory::teams()`)
  to invite them to, one invitation per client chosen. This is the
  "which tenant?" question the TODO said blocked the shared-list
  editor. The answer is that the staff member picks.
- No link column between the entry and the resulting `User`. The
  email is the join, and keeping it loose avoids a directory import
  `--refresh` (`TODO.md:682-693`) breaking portal identities.

## Edge cases

- **User with one client.** No switcher, no behaviour change.
- **Membership removed while signed in.** The next request runs
  `ensureValid()` and lands them on another client or, with none
  left, `PanelRedirect` sends them home and the portal denies access
  (`belongsToAnyTenant()` false).
- **Suspended client.** Listed in the switcher with "suspended" and
  not selectable, if suspended clients are meant to lose portal access
  (open question 2).
- **Impersonation.** A super-admin impersonating a portal user can
  switch clients as that user. Record the impersonator in the audit
  event (`Session::get('impersonator_id')`,
  `app/Http/Controllers/ImpersonationController.php:30`).
- **Client admin at two clients.** Roles pages show each client's
  roles only (`RoleResource.php:98-101` filters by current team).
  Custom role names may repeat across clients; that's fine, they are
  separate rows.
- **Deleted client.** `team_user` cascades. `ensureValid()` picks
  another client on next request.
- **Livewire request after switching in another tab.** Covered by the
  stale context guard. The server answer is already scoped to the new
  client.

## Migration and rollout

No schema change.

1. `PortalContext`, `EnsurePortalClient` middleware, membership fixes
   (`attachExistingUser`, `grantRoleAndMembership`, `removeUser`, bulk
   delete).
2. Data fix: a one-off command `orbital:repair-portal-context
   --dry-run` that (a) sets `current_team_id` for users whose value is
   null or invalid but who have a client membership, and (b) lists
   users holding `client_admin` for a team they no longer belong to,
   then removes those roles when run without `--dry-run`.
3. Switcher UI, topbar label, stale tab guard.
4. Permission audit of admin/operator `can()` calls and the
   `SetPermissionsTeamContext` change. `TwoFactorPolicy` change.
5. Phase 3 directory invite actions.
6. Docs: `docs/admin/users-roles.md` (a user can belong to several
   clients; how removal works), `docs/portal/index.md` (switching
   clients). Remove the TODO item.

Back-out: steps 1–4 are independent. The switcher can be hidden with
no data effect.

## Tests

- Attaching a user to client B leaves `current_team_id` on A.
  Accepting an invite to B when the user has no other client selects
  B.
- `removeUser` from the current client with another membership: the
  user's next portal request lands on the other client and shows its
  data. Both `client_admin` and `client_user` for the removed team
  are gone.
- Switcher: only the user's own non-personal teams are listed;
  posting another team's id returns 403 and doesn't change
  `current_team_id`.
- After switching, in the same request, `can('portal.manage_roles')`
  reflects the new client's roles (cache cleared).
- Admin at A, member at B: Roles page editable at A, hidden at B.
  With §6 in place, recording playback allowed at A and denied at B.
- Two-factor grace: user in a 0-day and a 14-day client gets 0 days
  whichever is current.
- Platform staff with a client membership and the client selected:
  operator-panel permission checks still pass (after the
  `SetPermissionsTeamContext` change).
- Stale tab: a Livewire request after a switch carries the new
  `X-Orbital-Client` header.
- `orbital:repair-portal-context --dry-run` reports and changes
  nothing; without the flag it fixes the seeded broken fixtures.
- Shared directory invite sends one invitation per selected client
  and refuses clients not subscribed to that directory.

## Open questions

1. Should a client admin be able to see that one of their users also
   belongs to another client? Today the admin side shows nothing, and
   the portal has no Users page. Probably not; it leaks the other
   client's name.
2. Do suspended clients keep portal access (read-only history), or
   lose it? Today nothing in the portal checks `suspended_at`.
3. Is a personal "all my clients" dashboard (messages across every
   client the user belongs to) wanted? It's the natural next request
   and would need cross-team queries that check membership per row.
   Not in this plan.
4. Do multi-client users ever need different notification settings
   per client (voicemail email, plan 05 deliveries)? That belongs to
   the recipient side of plan 05, but it'll come up here first.

## Size

~1.5–2 days, not the half-day the TODO guessed, mainly because the
membership fixes and the permission audit come with it:

- `PortalContext`, middleware, membership fixes, repair command:
  ~0.5 day.
- Switcher, topbar label, stale tab guard: ~0.5 day.
- Permission audit, `SetPermissionsTeamContext` change, two-factor
  change, tests: ~0.5 day.
- Directory invite actions: ~2–3 hours.
