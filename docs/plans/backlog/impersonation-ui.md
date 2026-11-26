# Plan: Impersonation UI

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

A super-admin can sign in as another user. Support needs this: it is how
you see what a client contact sees when they report that something looks
wrong. But the only way in is a hand-made POST to a route. Nothing on screen
says the session is impersonated, and nothing records that it happened.
`docs/roadmap.md:114-117` and `docs/admin/security.md:93-96` both say to treat
it as a know-what-you-are-doing operation until a button and a banner exist.

Reading the code also turns up side effects that matter more than the missing
button. On an answering-service platform, impersonating an operator today
changes that operator's live telephony state.

## What exists today (checked in code)

- **Controller:** `app/Http/Controllers/ImpersonationController.php`.
  - `start()` (lines 22-37) checks `isSuperAdmin()`, refuses if
    `impersonator_id` is already in the session or if the target is the
    actor, then stores `impersonator_id` and calls `Auth::login($user)`. It
    redirects to `/`, and the root route
    (`routes/web.php:29-44`) picks the target's home panel from there.
  - `stop()` (39-47) pulls `impersonator_id`, calls `Auth::login($real)`, and
    always redirects to `/admin`.
- **Routes:** `routes/web.php:46-52`, both POST, under `auth` plus
  `jetstream.auth_session` (`AuthenticateSession`). `/impersonate/{user}` is
  declared **before** `/impersonate/stop`, so with uncached routes `stop` is
  bound as `{user}` and returns a 404 from model binding. This is already
  recorded in [security-review-fixes.md](../security-review-fixes.md)
  (lines 252-253) and is fixed here.
- **There is no guard against impersonating another super-admin.** A target
  that is a super-admin is accepted.
- **No audit.** Nothing is written anywhere. The only audit-style tables are
  domain-specific (`failover_audit_logs`, `user_logout_events`). Plans
  [17](../is-parity/17-secure-messaging.md) (lines 431, 522, 679-681) and
  [24](../is-parity/24-client-web-scripting.md) (lines 237, 528) already plan
  to stamp an `impersonator_user_id` / `impersonated_by_user_id` on their own
  rows. Today they have nowhere to read it from, except the raw
  `impersonator_id` session key.
- **No time limit.** The session lasts as long as any session
  (`SESSION_LIFETIME=120` idle, `.env.example:328`).
- **Permission catalog:** `platform.impersonate` is seeded
  (`database/seeders/PermissionCatalogSeeder.php:63`) and listed as
  platform-only (`app/Services/Clients/ClientPermissionGatekeeper.php:38`),
  but nothing checks it. The controller checks the super-admin role.
- **Side effects of `Auth::login()`:**
  - It fires `Illuminate\Auth\Events\Login`. `ResetAvailabilityOnLogin`
    (`app/Listeners/ResetAvailabilityOnLogin.php:35-59`, wired at
    `app/Providers/AppServiceProvider.php:114`) then sets any platform-role
    user to `unavailable` and resyncs their Asterisk queue membership.
    **Impersonating an operator on shift therefore pulls them out of
    rotation.** Stopping impersonation resets the super-admin the same way.
  - `AuthenticateSession` stores the password hash of whoever is logged in
    once the request finishes
    (`vendor/laravel/framework/src/Illuminate/Session/Middleware/AuthenticateSession.php:70-73`).
    So `Auth::login()` inside the start request leaves the target's hash in
    the session, and later requests do not log out. A replacement for
    `Auth::login()` has to keep this behaviour.
  - The session ID is not regenerated on either switch.
- **What the impersonated session renders:**
  - On the operator panel, the softphone is mounted on every page
    (`app/Providers/Filament/OperatorPanelProvider.php:282-286`), along with
    `AvailabilitySelector` (`:261-266`) and `AsteriskDrainNotice` (`:272-277`).
    An impersonated operator session would register the target's SIP
    extension from the super-admin's browser, alongside the target's real
    registration.
  - On every panel, `RequireTwoFactor`
    (`app/Http/Middleware/RequireTwoFactor.php:42-60`) evaluates the target.
    It starts *their* grace clock (`startGrace`, line 49) and can redirect
    the super-admin to the target's security page. The two-factor banner
    shows the target's countdown.
  - `App\Filament\Pages\Security` lets whoever is signed in enable or disable
    TOTP (`:71`, `:126`), regenerate recovery codes (`:108`), change the
    password (`:166`) and log out other sessions (`:197`). All of these
    would act on the target's account.
  - Operator panel logout shows a `LogoutReason` modal and writes a
    `UserLogoutEvent` for the user (`OperatorPanelProvider.php:124-170`).
    Logging out while impersonating would record a logout the target never
    made.
- **Panel access:** `User::canAccessPanel()` (`app/Models/User.php:251-259`)
  uses the target's roles. Impersonating a client contact therefore loses
  `/admin`, which is the point, and the way back has to work from the portal.
- **Panel BODY_START hooks:** each panel already renders the two-factor
  banner there: admin `AdminPanelProvider.php:309-312`, operator
  `OperatorPanelProvider.php:288-291`, portal `PortalPanelProvider.php:123-126`.
  The admin and operator panels also render `SystemStatusBar` there, gated on
  `hasAnyPlatformRole()`.
- **Where users are managed:** Staff via `UsersResource` (edit page header
  actions at `UsersResource/Pages/EditUser.php:24`), all client users via
  `AllUsersResource` (header `ActionGroup` at
  `AllUsersResource/Pages/EditAllUser.php:56`; the list has no row actions by
  design, see `AllUsersResource.php:291-295`), and a client's contacts via
  `ClientResource/Pages/ManageClientUsers.php` (table at :65, with header and
  bulk actions but no row actions).
- `isSuperAdmin()` (`app/Models/User.php:184-196`) can return a stale answer
  from a cached `roles` relation. This is also in
  [security-review-fixes.md](../security-review-fixes.md), and it matters
  for the "target is a super-admin" check.

## Goal

- An Impersonate action on the user screens a super-admin already uses. It
  needs a reason and a fresh confirmation.
- A banner on every page of every panel while impersonating, showing who you
  are, who you really are, the time left, and a Stop button that works from
  any panel.
- An audit row for every impersonation, and one helper that other features
  use to stamp `impersonator_user_id`.
- No side effects on the target: no availability reset, no SIP registration,
  no grace clock, no changes to factors or password, no fake logout events.
- Hard guards: never another super-admin, never nested, never longer than a
  set limit.

## Design

### Service

Move the logic into `App\Services\Auth\ImpersonationService` (thin
controller, per CLAUDE.md):

- `start(User $actor, User $target, string $reason): ImpersonationSession`
- `stop(string $endReason = 'stopped'): ?User`
- `current(): ?ImpersonationSession`, `isImpersonating(): bool`,
  `impersonatorId(): ?int`

Plans 17 and 24, and any later audited write, read
`ImpersonationService::impersonatorId()`. Nothing else reads the raw session
key.

**Quiet switch.** Do not call `Auth::login()`. Switch the guard without
firing `Login`:

```php
$guard = Auth::guard('web');
$session->put($guard->getName(), $target->getAuthIdentifier());
$session->put('password_hash_web', $target->getAuthPassword());
$guard->setUser($target);
$session->migrate(true);   // new session id, keep data
```

This avoids `ResetAvailabilityOnLogin` and any other `Login` listener in both
directions. It sets the `AuthenticateSession` hash explicitly rather than
relying on the end-of-request store. Fire the app's own `ImpersonationStarted`
and `ImpersonationStopped` events instead. Wrap the session-key handling in
one private method, and cover it with a test that fails if Laravel renames
the guard's session key.

**Session payload:** `impersonation.id` (the audit row),
`impersonation.impersonator_id`, `impersonation.started_at`,
`impersonation.expires_at`, `impersonation.return_url` (the page the button
was clicked on).

### Guards (checked in `start()`, not only in the UI)

- The actor is a super-admin, checked against fresh roles
  (`$actor->unsetRelation('roles')` first). Keep it at super-admin only, as the
  roadmap says. Wiring the unused `platform.impersonate` permission so a
  support role can impersonate *client* users only is listed under Open
  questions, not built.
- The target is not a super-admin, also checked against fresh roles.
  Impersonating a super-admin is privilege-neutral today, but it hides who
  did what, and a future support role would turn it into escalation.
- The target is not the actor, the actor is not already impersonating (no
  nesting), and the target account exists and is not disabled.
- **2FA:** the actor must be enrolled
  (`TwoFactorPolicy::isEnrolled($actor)`). This holds even if
  `TWO_FACTOR_REQUIRED=false`, because impersonation is the most powerful
  thing a single session can do. The action also requires a fresh
  confirmation. Ask for the actor's current password in the action modal
  (`current_password` rule). Once passkeys land
  ([2fa-passkeys-and-email-otp.md](2fa-passkeys-and-email-otp.md)), offer a
  passkey confirmation instead. Do not rely on `password.confirm`'s 3-hour
  window: the confirmation is per impersonation.
- **Reason:** a required free-text field (min 10 characters), for example
  "Ticket 4411, contact says messages missing". It is stored on the audit
  row.

### Time limit and expiry

- `orbital.security.impersonation_minutes` (`IMPERSONATION_MINUTES`), default
  30 and capped at 120.
- New middleware `EnforceImpersonation`, added to all three panels'
  `->middleware([...])` right after `SetPermissionsTeamContext`, before
  `RequireTwoFactor`, and to the `auth` route groups in `routes/web.php`. On
  each request while impersonating it does three things:
  1. If `now() > expires_at`, it calls `stop('expired')`, flashes "Your
     impersonation of X ended after 30 minutes", and redirects to the return
     URL.
  2. If the impersonator no longer exists or is no longer a super-admin, it
     calls `stop('actor_revoked')` and logs out entirely.
  3. Otherwise it passes the request through.
- Livewire and XHR requests get the same treatment. On an expired XHR, return
  a 419-style response so Livewire reloads the page rather than acting as the
  target after expiry.

### What changes while impersonating

Add `ImpersonationService::isImpersonating()` checks in these places:

- `RequireTwoFactor::handle` returns `$next` right away. No `startGrace`, no
  redirect. The two-factor banner partial renders nothing.
- The Security page shows a read-only notice ("You are impersonating X; their
  security settings can't be changed"). Every mutating method, which today
  means `enableTwoFactor`, `confirmTwoFactor`, `regenerateRecoveryCodes`,
  `disableTwoFactor`, `updatePassword` and `logoutOtherSessions`, and later
  passkey and email-OTP management, aborts with 403 at its top. Hiding the
  buttons is not enough.
- The operator panel's softphone, `AvailabilitySelector` and
  `AsteriskDrainNotice` render hooks return `''`, so there is no SIP
  registration and no availability change. Show a static placeholder where
  the softphone would sit ("Softphone disabled while impersonating"). The
  multi-call workspace and any other page that originates or answers calls
  checks the same flag and refuses. Impersonation is for seeing, not for
  taking calls as someone else.
- **Logout while impersonating** means stop. Override the logout route and
  the operator panel's logout action: if impersonating, call
  `stop('logout')`, skip the `LogoutReason` modal, and write no
  `UserLogoutEvent`.
- **Broadcasts.** Private Reverb channels authorise as the target, so the
  impersonator receives the target's private events (drain notices, call
  updates). That's fine for viewing. `routes/channels.php` has no presence
  channels today. If one is added, it must skip the join while
  impersonating, or the target would show as online twice.

### Stop flow

- Fix the route order: declare `POST /impersonate/stop` first, and constrain
  `{user}` with `->whereNumber('user')`. Either change alone fixes the
  shadowing, and doing both stops it coming back.
- `stop()` quietly switches back to the impersonator, using the same method
  in reverse, and closes the audit row with `ended_at` and `end_reason`. It
  redirects to `impersonation.return_url`, falling back to `/admin`, rather
  than always to `/admin`, so a super-admin who started from a client's
  Users tab lands back there.
- The stop route keeps `auth` but must not be behind any panel's
  `canAccessPanel`. It already isn't, because it is a plain web route.
- If the session somehow holds `impersonation.*` but the impersonator row is
  gone, log out completely. Never fall back to staying as the target.

### UI: the button

Add one reusable Filament action, `App\Filament\Actions\ImpersonateAction`:
label "Impersonate", icon `heroicon-o-identification`, colour warning. Its
modal has the reason textarea and the current-password field and shows
"You'll see Orbital exactly as {name} does for up to 30 minutes. This is
logged." It is visible only when the viewer is a super-admin, the record is
not a super-admin and not the viewer, and no impersonation is in progress. On
submit it calls the service and redirects to `/`. Place it in three spots:

- **Staff:** a header action on `UsersResource/Pages/EditUser.php`.
- **Client users (global list):** inside the existing header `ActionGroup` on
  `AllUsersResource/Pages/EditAllUser.php`. The list keeps its no-row-actions
  design.
- **A client's contacts:** a row action on
  `ClientResource/Pages/ManageClientUsers.php`. Here the service also sets
  the target's `current_team_id` to this client *for the session only*. That
  way "impersonate this contact" shows this client even when the contact
  belongs to several. Do it in session state rather than writing
  `users.current_team_id`, so the target's own next login is unaffected.
  If that turns out not to fit Jetstream's `currentTeam` resolution, fall back
  to writing it and restoring it on stop, and record that on the audit row.

### UI: the banner

Add a partial, `resources/views/filament/partials/impersonation-banner.blade.php`,
rendered through `PanelsRenderHook::BODY_START` on all three panels and
registered **before** the status bar and the two-factor banner, so it sits on
top:

- A solid, high-contrast strip. Use a colour no other banner uses (fuchsia or
  violet), with inline styles in the same style as `two-factor-banner.blade.php`
  so it survives theme changes.
- Text: "You are signed in as **Jane Doe** (Acme Dental) · impersonated by
  **Admin Name** · 23 min left". Then a **Stop impersonating** button, a
  plain `<form method="POST" action="{{ route('impersonate.stop') }}">` with
  `@csrf`, which works without Livewire.
- `position: sticky; top: 0; z-index` above the Filament topbar, so it can't
  scroll away.
- A `HEAD_END` hook that prefixes `<title>` with "[Impersonating] " and
  swaps the favicon for a tinted one, so a background tab is recognisable
  too.
- Also include the partial in the non-panel layouts an impersonated session
  can reach: `resources/views/layouts/app.blade.php` and
  `resources/views/components/layouts/filament-simple.blade.php`.
- The minutes left come from `expires_at`. A small Alpine timer counts down
  and reloads the page at zero, so expiry happens visibly.

### Audit

Add an `impersonation_sessions` table:

| column | notes |
|---|---|
| `id` | |
| `impersonator_id` | FK users, `restrictOnDelete`. Deleting a super-admin must not erase their history. |
| `impersonated_id` | FK users, `nullOnDelete` |
| `impersonated_name_snapshot`, `impersonated_email_snapshot` | survive user deletion |
| `team_id` | the client context chosen, nullable |
| `reason` | text |
| `started_at`, `expires_at`, `ended_at` | |
| `end_reason` | `stopped`, `expired`, `logout`, `actor_revoked`, `session_lost` (closed by a sweeper) |
| `ip`, `user_agent` | |

- A nightly scheduled sweep closes rows with no `ended_at` that are past
  `expires_at`, marking them `session_lost`. This covers browsers closed
  mid-session.
- Admin UI: a read-only "Impersonation log" resource under System, with
  filters by actor, target and client. On user edit pages, add a "Recent
  impersonations" section listing sessions where the user was the target.
- Not on the `BelongsToTeam` path: this is platform data, and client
  portal users never see it (see Open questions).
- Also write each start and stop to the `auth` log channel, so it reaches
  log shipping.

## Edge cases

- **Target is deleted mid-session.** The next request's auth fails. The
  middleware sees `impersonation.*` with no user, closes the row as
  `session_lost` and logs the impersonator back in. If the impersonator is
  also gone, it logs out completely.
- **Target's password changes mid-session.** `AuthenticateSession` sees the
  hash mismatch and logs out the whole session. That's acceptable: the
  impersonator signs in again. The sweeper closes the row.
- **Two tabs.** One session, one impersonation. Stopping in one tab affects
  the other on its next request, and the banner disappears on reload.
- **Impersonating a staff user with the operator panel.** Allowed, for
  example to see an operator's queue view, with telephony suppressed as
  above. Impersonating a supervisor whose pages trigger actions, such as
  barge from [live-call-monitoring.md](../live-call-monitoring.md), must be
  refused while impersonating. Add `isImpersonating()` to that plan's
  permission check.
- **API keys, client API, OIDC.** Impersonation is web-session only. The
  OIDC provider (`app/Http/Controllers/Oidc/`) must refuse to issue tokens
  for an impersonated session. Otherwise a downstream app (pgAdmin, Grafana)
  would receive the target's identity with no impersonation marker.
- **Session lifetime shorter than the impersonation limit.** The session
  idles out first, and the sweeper closes the row.
- **Client user with no team.** The portal redirect handles it as for the
  real user. This is a useful thing to be able to see.

## Migration and rollout

1. Migration: `impersonation_sessions`. Additive.
2. Fix the route order first, as its own small commit. It is a live bug.
3. Service, middleware and the side-effect guards (2FA middleware, Security
   page, telephony hooks, logout) go in one PR. The button and banner can't
   ship without them: a visible button with today's side effects would make
   them common.
4. Button, banner and audit UI in the same or the next PR.
5. Update `docs/admin/security.md` §Impersonation: remove the "no button
   and no banner" warning, and document the reason, the time limit, the
   audit log and what is suppressed. Remove the roadmap item.

## Tests

- Guards: a non-super-admin gets 403; a super-admin target is refused, even
  when the actor's cached `roles` relation is stale; self is refused; nested
  is refused; an actor without 2FA is refused; a wrong password is refused;
  an empty reason is refused.
- The route fix: `POST /impersonate/stop` reaches `stop()` with uncached
  routes. This is a regression test for the security-review finding.
- No `Login` event: impersonating an `available` operator leaves them
  `available` and does not call `QueueMemberSyncer`
  (`Event::fake([Login::class])` plus assertNotDispatched, and a
  mock syncer). The same goes for stopping.
- Session: the ID changes on start and stop, and the session survives the
  next request (the `AuthenticateSession` hash is set correctly).
- `RequireTwoFactor` while impersonating an unenrolled, out-of-grace user:
  no redirect, and their `two_factor_grace_started_at` stays null if it was
  null.
- Security page: every mutating method returns 403 while impersonating.
- Operator panel while impersonating: the softphone, availability and drain
  hooks render empty.
- Expiry: `travel(31)->minutes()` ends impersonation, writes
  `end_reason=expired`, and redirects to the return URL. A Livewire request
  after expiry does not act as the target.
- Actor demoted mid-session: the next request ends it with `actor_revoked`.
- Logout while impersonating returns to the actor and writes no
  `UserLogoutEvent`.
- Audit: a row is written on start, with reason, IP and team, and closed on
  each end path. The sweeper closes stale rows.
- Banner: rendered on admin, operator and portal pages while impersonating
  and absent otherwise. The stop form posts to the right route.
- `ImpersonationService::impersonatorId()` returns the actor's id while
  impersonating and null otherwise. Plans 17 and 24 depend on it.

## Open questions

1. Should the client be told? Some clients, such as medical practices,
   may expect "Orbital support viewed your account on <date>" in their
   portal. The audit table supports it. Whether to show it is a product and
   compliance decision.
2. Should `platform.impersonate` be wired up, so a non-super-admin support
   role can impersonate client users (never staff)? This plan keeps it at
   super-admin only.
3. Read-only impersonation? Blocking all writes as the target (a middleware
   that 403s non-GET outside the stop route and Livewire reads) would be
   safer, but it makes it hard to reproduce "I click Save and it errors".
   Recommendation: full access with an audit log in v1, and revisit if
   clients ask for it.
4. Default limit: 30 minutes, or shorter?

## Size

About 2.5 days. That is half a day for the service, quiet switch and route
fix, half a day for the middleware and suppressions (2FA, Security page,
telephony, logout, OIDC), half a day for the action in three places and the
banner, half a day for the audit table, sweeper and log UI, and half a day
for tests and docs.
