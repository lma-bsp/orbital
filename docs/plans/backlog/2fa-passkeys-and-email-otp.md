# Plan: Two-factor phases 2 and 3 (passkeys and email OTP)

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

Two-factor is required for everyone, but TOTP is the only factor a user can
enrol. TODO.md ("2FA — Phase 2 (WebAuthn) and Phase 3 (email OTP) still
open") records why the other two were left: the WebAuthn ceremonies need a
real browser and an authenticator to verify, and email OTP cannot be bolted on
as a new challenge action, because Fortify only challenges a user who already
has a TOTP secret.

That leaves two groups badly served:

- Users who would happily use a passkey (phone, laptop, YubiKey) and find
  six-digit codes tedious. Passkeys are also the only phishing-resistant
  option, and platform staff, who can read every client's messages, are the
  people who most need that.
- Client contacts who will not install an authenticator app. Today their
  options are to comply or to be locked out at the end of the grace window.
  The second outcome becomes a support call to the call centre.

Plan 17 (secure messaging, [17-secure-messaging.md](../is-parity/17-secure-messaging.md))
also waits on passkeys for its phase 3a.

## What exists today (checked in code)

**Enforcement (Phase 4, shipped).**

- `App\Services\Auth\TwoFactorPolicy` owns the decision. `isEnrolled()`
  (`app/Services/Auth/TwoFactorPolicy.php:50-54`) is TOTP-only: it requires
  `two_factor_secret` and `two_factor_confirmed_at`. `isBlocked()`,
  `shouldWarn()` and `daysRemaining()` all go through it, so this is the one
  method that has to learn about new factors.
- Grace is per client via `teams.two_factor_grace_days`
  (`database/migrations/2026_08_17_120000_add_two_factor_enforcement.php:40`),
  read from `currentTeam` in `graceDaysFor()` (`TwoFactorPolicy.php:59-68`),
  with the platform default for staff.
- `RequireTwoFactor` (`app/Http/Middleware/RequireTwoFactor.php:34-61`) is
  registered on all three panels: admin
  (`app/Providers/Filament/AdminPanelProvider.php:260-276`), operator
  (`OperatorPanelProvider.php:219-236`), portal
  (`PortalPanelProvider.php:96-112`). Its exemptions (`isExempt()`, lines
  66-86) let through non-GET, XHR/JSON, `livewire/*`, `login`, `logout` and any
  path ending `/security`.
- The countdown banner (`resources/views/filament/partials/two-factor-banner.blade.php`)
  is a `BODY_START` render hook on each panel and calls only the policy.

**Login.**

- The panels have no `->login()`. Everyone signs in at the app-level `/login`
  (`resources/views/auth/login.blade.php`, posting to Fortify's `login` route,
  line 25).
- `app/Providers/FortifyServiceProvider.php:41` binds Fortify's
  `RedirectsIfTwoFactorAuthenticatable` contract to Fortify's own
  `RedirectIfTwoFactorAuthenticatable`. Fortify's default login pipeline
  (`vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:85-91`)
  resolves that contract, then `AttemptToAuthenticate`, then
  `PrepareAuthenticatedSession`.
- The redirect only fires when `two_factor_secret` is set and confirmed
  (`vendor/laravel/fortify/src/Actions/RedirectIfTwoFactorAuthenticatable.php:55-62`).
  It puts `login.id` and `login.remember` in the session and redirects to
  `two-factor.login` (lines 147-158). A user with no TOTP secret skips the
  challenge and goes straight to `AttemptToAuthenticate`. This is the reason
  Phase 3 needs pipeline work.
- Rate limiters: `login` is 5/min by email and IP, and `two-factor` is 5/min
  by `login.id` (`FortifyServiceProvider.php:43-51`, named in
  `config/fortify.php:120-123`).
- The challenge view is `resources/views/auth/two-factor-challenge.blade.php`:
  a code field, with an Alpine toggle to a recovery-code field.

**Enrolment UI.** `App\Filament\Pages\Security` (`app/Filament/Pages/Security.php`)
is one Livewire page, registered on all three panels. It calls Fortify's
actions directly: `enableTwoFactor()` at :71, `confirmTwoFactor()` at :84,
`regenerateRecoveryCodes()` at :108, `disableTwoFactor()` at :126, plus
password change (:166) and logout of other sessions (:197). It does not use
Fortify's HTTP routes, and it does not use `password.confirm`.

**Admin reset.** Staff "Reset 2FA" lives on the bulk action at
`app/Filament/Resources/UsersResource.php:291-314` and the header action at
`UsersResource/Pages/EditUser.php:93`. Client users have `disableTwoFactor`
at `AllUsersResource/Pages/EditAllUser.php:106-120`. All three clear only
the TOTP columns.

**Passkey support is already installed.** The TODO assumes
`laragear/webauthn`, but `composer.lock` tells a different story:

- `laravel/fortify` v1.40.0 requires `laravel/passkeys` ^0.2.0
  (`composer.lock:3191-3198`). v0.2.1 is installed, on top of
  `web-auth/webauthn-lib` 5.3.9.
- Fortify ships a `Features::passkeys()` feature
  (`vendor/laravel/fortify/src/Features.php:167-174`), a
  `Laravel\Fortify\PasskeyAuthenticatable` trait and a `PasskeyUser`
  contract. It registers routes for login options/verify, confirm
  options/verify (which sets `passwordConfirmed()`), and registration
  options/store/delete
  (`vendor/laravel/fortify/routes/routes.php:179-215`). It also reads
  `fortify.passkeys.*` config and a `fortify.limiters.passkeys` limiter
  (`vendor/laravel/fortify/src/FortifyServiceProvider.php:121-170`).
- The package migration creates one `passkeys` table: `user_id`, `name`,
  `credential_id` (unique), `credential` (JSON), `last_used_at`
  (`vendor/laravel/passkeys/database/migrations/2024_01_01_000000_create_passkeys_table.php`).
- Registration requires a resident key and user verification, with no
  attestation (`vendor/laravel/passkeys/src/Actions/GenerateRegistrationOptions.php:31-88`).
  Assertion requires user verification
  (`src/Actions/GenerateVerificationOptions.php:21-30`). When given a user it
  limits `allowCredentials` to that user's keys, and when given none it asks
  for a discoverable credential.
- `Passkeys::authorizeLoginUsing()` (`src/Passkeys.php:149`) is a hook to
  refuse a passkey login, for example for a disabled account.
- The browser half is the `@laravel/passkeys` npm package (named in
  `vendor/laravel/passkeys/README.md:52-61`). It is not in `package.json`.
- The feature is not enabled. `config/fortify.php:149-158` lists no
  `Features::passkeys()`, there is no `passkeys` config block, and
  `User` (`app/Models/User.php:21-32`) uses `TwoFactorAuthenticatable` but
  not `PasskeyAuthenticatable`.

The app runs `laravel/framework` v13.42.0 (`composer.json` requires `^13.0`;
CLAUDE.md's "Laravel 12" is out of date). This plan does not check whether
`laragear/webauthn` supports Laravel 13: it is not installed, and it is not
needed. Adding a second WebAuthn stack beside the one Fortify already pulls in
would leave two credential tables and two sets of ceremonies.

**Browser tests.** There is no Dusk, Pest browser plugin or Playwright in
`composer.json` or `package.json`. Tests are PHPUnit feature tests, for example
`tests/Feature/Auth/TwoFactorEnforcementTest.php`.

## Goal

- A user can register one or more passkeys and sign in with a passkey alone.
  That login counts as two-factor, because user verification is required.
- A user who signs in with a password can use a passkey as the second step,
  in place of or alongside TOTP.
- A user can enrol email OTP as a fallback second factor, where the client's
  policy allows it. Platform staff cannot by default.
- `TwoFactorPolicy::isEnrolled()` counts any confirmed factor the user is
  allowed to use. Enforcement, the banner and the grace window then behave
  as they do today without changes of their own.
- One challenge page for every second factor, and recovery codes that work
  whichever factors are enrolled.

## Design

### Phase 2: passkeys

**Library.** Use the Fortify passkeys feature that is already installed
(`laravel/passkeys` 0.2.x). Do not add `laragear/webauthn`. Version 0.2 is
pre-1.0, so pin it with `"laravel/passkeys": "~0.2.1"` in `composer.json`.
That way a Fortify bump can't quietly pull in a breaking 0.3.

**Wiring.**

- `config/fortify.php`: add `Features::passkeys(['confirmPassword' => true])`,
  a `passkeys` block (`relying_party_id`, `allowed_origins`, `timeout`), and a
  `'passkeys' => 'passkeys'` limiter. Define that limiter in
  `FortifyServiceProvider` at 10/min by IP for the guest routes and by user
  id for the authenticated ones.
- `relying_party_id` defaults to the `APP_URL` host. Expose it as
  `PASSKEYS_RP_ID` in `.env.example`, with a comment: **changing the domain
  invalidates every registered passkey.** An install that serves the portal on
  a second hostname would need that hostname listed in `allowed_origins` and
  would also have to be a registrable suffix of the RP ID. Document this in
  `docs/admin/security.md` rather than trying to support several RP IDs.
- `PASSKEYS_USER_HANDLE_SECRET`: set it explicitly. The fallback is
  `APP_KEY`, and rotating `APP_KEY` would otherwise change every user handle.
- `User implements PasskeyUser` and uses `Laravel\Fortify\PasskeyAuthenticatable`.
- Publish the package migration, and add a `passkeys.purpose` column
  (`login` default). The table is shared, and the column leaves room for
  "second factor only" keys later without a second table. Nothing reads it
  in v1.
- `pnpm add @laravel/passkeys`. Pin the exact version that matches the
  server package's JSON shapes, and check it before starting. That pairing is
  the main compatibility risk.

**First-factor login.** Add a "Sign in with a passkey" button to
`resources/views/auth/login.blade.php`. Alpine calls the JS client against
`passkey.login-options` and `passkey.login`. Also set
`autocomplete="username webauthn"` on the email field, so browsers offer
conditional-UI autofill.

- `PasskeyLoginController::store` logs the user in directly and never enters
  the Fortify pipeline. That is correct: a UV-required assertion is already
  two factors. Bind `Laravel\Passkeys\Contracts\PasskeyLoginResponse` to a
  response that reuses `App\Http\Responses\LoginResponse::defaultFor()` and
  honours `intended()`. Otherwise a passkey login always lands on `/`, while
  a password login returns to the deep link.
- Use `Passkeys::authorizeLoginUsing()` to apply whatever rules password
  login applies, such as disabled or soft-deleted users. Today that is only
  "user exists", so the callback starts as a no-op with a comment saying it
  must track the password path.
- Fire the same `Login` event. `ResetAvailabilityOnLogin` hangs off it
  (`app/Listeners/ResetAvailabilityOnLogin.php:35`), and the guard's `login()`
  already dispatches it, so no change is needed there. A test should
  confirm it.

**Second factor after a password.** This needs the same pipeline change as
Phase 3, so both share one class (see "Unified challenge" below). A user with
any passkey, after a correct password, gets the challenge page with "Use a
passkey" as an option. The assertion runs against
`GenerateVerificationOptions($user)`, which restricts `allowCredentials` to
that user's keys, and against `VerifyPasskey(..., $user)`, which rejects a
key that belongs to someone else.

**Enrolment UI.** Add a "Passkeys" section to the Security page
(`resources/views/filament/pages/security.blade.php`), above TOTP:

- A list showing name, created date, last used and authenticator type, each
  with Rename and Remove. The AAGUID-to-name map is in
  `vendor/laravel/passkeys/resources/aaguids.php`.
- "Add a passkey": prompt for a name, then run the ceremony against
  `passkey.registration-options` and `passkey.store`. Those routes sit
  behind `password.confirm`. The Security page has never used it, so the
  section confirms first: a small modal posts the password to Fortify's
  confirm-password route, or uses `passkey.confirm` once the user already
  has a passkey. After that the ceremony runs. Refresh the Livewire list on
  success.
- Removing the last factor must not leave an enforced user unenrolled
  without warning. If removing it would make `isEnrolled()` false, the modal
  says so, and the grace clock is not restarted (see Edge cases).
- `Security::getTwoFactorState()` (:137) becomes a factor summary: which
  factors are confirmed and how many recovery codes are left.

**Recovery codes become factor-independent.** Today the codes come with TOTP.
`EnableTwoFactorAuthentication` creates them, and
`DisableTwoFactorAuthentication` nulls `two_factor_recovery_codes` along with
the secret (`vendor/laravel/fortify/src/Actions/DisableTwoFactorAuthentication.php:18-25`).

- Generate codes on the first confirmed factor of any kind, if none exist.
- Wrap TOTP disable in an app action that keeps `two_factor_recovery_codes`
  while another factor remains. Use it from `Security::disableTwoFactor()`
  and the three admin reset actions.
- The challenge page accepts a recovery code whichever factors are
  enrolled.

**Admin reset.** Rename "Reset 2FA" to "Reset all second factors". It
removes TOTP, every passkey and email OTP, and clears recovery codes. A
user who has lost every device is recovered this way, as with TOTP today. It
also resets `two_factor_grace_started_at` to now, so the user gets a fresh
window instead of an immediate block. The current TOTP reset doesn't do this,
which is a small existing gap that this closes.

### Phase 3: email OTP

**Enrolment.** On the Security page, "Email me a code instead" sends a code to
the user's current address. Entering it stamps
`users.two_factor_email_confirmed_at`. Confirming proves the mailbox
receives mail, so an address that bounces cannot count as enrolment. Changing
the email address clears the stamp in `UpdateUserProfileInformation` and
the admin edit forms, and the user then re-confirms.

**Policy.**

- New `teams.allow_email_two_factor` boolean, default `true`, on the client
  Details tab next to `two_factor_grace_days`.
- New `orbital.security.staff_email_two_factor` (`STAFF_EMAIL_TWO_FACTOR`),
  default `false`. Staff can reach every client's data, so the weakest factor
  is off for them unless an operator opts in.
- `TwoFactorPolicy::emailOtpAllowedFor(User)` returns the staff setting for
  any user with a platform role. Otherwise it returns true only if **every**
  team the user belongs to allows it. Using only `currentTeam` would let a
  contact who belongs to two clients pick the laxer one by switching team.
- The Security page hides the option when it is not allowed.

**`isEnrolled()` becomes:**

```
TOTP secret + confirmed_at
  || user has ≥1 passkey
  || (two_factor_email_confirmed_at && emailOtpAllowedFor(user))
```

The query cost is a single `exists()` on `passkeys`. The banner and the
middleware call this on every panel request, so memoise it per request on the
policy instance, which the container resolves per request.

**Login pipeline.** Replace the binding at `FortifyServiceProvider.php:41`
with `App\Actions\Auth\RedirectIfSecondFactorRequired`, which implements
Fortify's `RedirectsIfTwoFactorAuthenticatable` contract. Fortify's default
pipeline resolves that contract (`AuthenticatedSessionController.php:88`), so
this is a drop-in replacement, not a full `Fortify::authenticateThrough()`
override:

1. Validate credentials, as Fortify's class does (extend it for
   `validateCredentials()` and the failed-login rate-limiter bookkeeping).
2. Work out the user's usable factors: TOTP if confirmed, passkey if any,
   and email if confirmed and allowed.
3. If there are none, call `$next` and log in as today. This keeps the grace
   window: an unenrolled user still signs in, sees the banner, and is held
   at the security page once out of time.
4. Otherwise put `login.id`, `login.remember` and `login.factors` in the
   session and redirect to `two-factor.login`. If email is the only factor,
   send the code now, so the user doesn't have to click "send".

Keep Fortify's `two-factor.login` route name and URL, and replace the view
and the POST handler. The handler is an app controller registered at the
same path before Fortify's routes, or Fortify's routes with
`Fortify::ignoreRoutes()` for that pair only if that proves cleaner. It
accepts exactly one of `code` (TOTP), `recovery_code`, `email_code`, or a
passkey assertion. It reuses Fortify's `TwoFactorLoginRequest` for the
`login.id` lookup and the "challenged user" check, then logs in, regenerates
the session and fires `TwoFactorAuthenticated` / `ValidTwoFactorAuthenticationCodeProvided`,
so existing listeners keep working.

**Unified challenge page.** `two-factor-challenge.blade.php` becomes tabs
showing only the factors in `login.factors`. The order is passkey,
authenticator code, email code, then recovery code. "Email me a code" appears
only when email is a usable factor. A TOTP user whose client allows email OTP
and who enrolled it can fall back to it here. A TOTP user who never enrolled
email cannot.

**Codes.**

- Six digits from `random_int`, valid for 10 minutes and single-use. Store
  them in the cache (Valkey) under `2fa:email:{user_id}` as
  `{hash, expires_at, attempts}`, with the hash an HMAC-SHA256 keyed by
  `APP_KEY`. Only one live code exists at a time: sending a new one replaces
  the old. A table would add retention and cleanup for something that lives
  for minutes. The cache is shared across app nodes already, so it is enough.
- Verify with `hash_equals`. After 5 wrong attempts the code is burned and
  a new one has to be requested. This is in addition to the existing
  `two-factor` limiter (5/min by `login.id`).
- Send limit: a 60-second resend cooldown, and at most 5 sends per user per
  hour and 20 per IP per hour (`RateLimiter::for('two-factor-email')`). The
  send endpoint returns the same response whether or not it actually sent,
  so it can't be used to probe the limiter.
- Mail is sent **synchronously**, not queued. A login code that waits behind
  a Horizon backlog is a login that fails. Use a dedicated
  `LoginCodeMail` mailable with no tracking pixels or links: code, expiry,
  IP and approximate time, and "if this wasn't you, change your password".
  The subject leads with the code, so it shows in notification previews.
- Log each send and verify outcome to the `auth` log channel, with user id,
  IP and result but never the code.

**The mailbox weakness, stated plainly.** Email OTP adds nothing against an
attacker who controls the mailbox. Password reset also goes to that mailbox,
so mailbox access alone is enough to reset the password and receive the code.
What email OTP does stop is password reuse and credential stuffing, the most
common attack on a client portal. The plan accepts that trade for client
contacts, refuses it for staff by default, and leaves each client free to
turn it off. Say this in `docs/admin/security.md` and on the client policy
toggle's helper text. Do not imply that it is equivalent to TOTP.

The Security page shows email-only users a soft nudge ("Add a passkey for
stronger protection"). It is not a block.

## Edge cases

- **Policy turned off after users enrolled by email.** `isEnrolled()` flips
  to false for those users, and because their grace started long ago, they
  would be blocked on the next request. When `allow_email_two_factor` is
  saved as false, reset `two_factor_grace_started_at` to now for that team's
  users whose only factor is email, so they get a full window. Do the same
  when the staff setting changes. Being an env setting, it has no save hook,
  so handle that with an `orbital:two-factor:regrace` artisan command
  mentioned in the setting's comment.
- **Removing the last passkey.** If it was the last factor, the user is
  unenrolled. Their old `two_factor_grace_started_at` is still set, so they
  are blocked at once. They are on the Security page anyway, which is exempt,
  so they can enrol something else. The removal modal warns them about this.
- **Passkey on a shared or kiosk machine.** Call-centre workstations are
  shared. Registration needs password confirmation, and the passkey lives in
  that OS profile, so the risk is another operator signing in as the user on
  that profile. Add a line to the operator onboarding doc: register passkeys
  on a personal device or a security key, not a shared Windows profile.
  Disallowing platform authenticators for staff is an open question.
- **Security keys without a PIN.** Verification is UV-required, so a YubiKey
  with no PIN set will prompt the user to set one. That is the intended
  behaviour, and it needs a line in the user help.
- **`two-factor.login` reached with no `login.id`** (an expired session or a
  direct hit). Redirect to `/login`, as Fortify does.
- **Email change while a code is outstanding.** The code is tied to the user
  id, not the address. Clearing `two_factor_email_confirmed_at` on change
  removes email from `login.factors` on the next login.
- **Impersonation.** A super-admin impersonating a user must not be able to
  add or remove that user's factors. See [impersonation-ui.md](impersonation-ui.md).
  The Security page's factor actions refuse while impersonating.
- **Passkey login and the grace banner.** A passkey user is enrolled by
  definition, so no banner appears.
- **API tokens and OIDC.** Sanctum tokens and the OIDC provider
  (`app/Http/Controllers/Oidc/`) don't go through this pipeline. OIDC
  sign-in starts from an existing web session, so it inherits whatever
  factor that session used. Nothing changes there.

## Migration and rollout

1. Migration: the `passkeys` table (published from the package, plus
   `purpose`), `users.two_factor_email_confirmed_at`, and
   `teams.allow_email_two_factor` (default true). All additive.
2. Ship Phase 2 behind `PASSKEYS_ENABLED` (default `true` in `.env.example`,
   with the Fortify feature added conditionally). An install with a broken RP
   ID can then switch passkeys off without a deploy, matching
   `TWO_FACTOR_REQUIRED`.
3. Ship Phase 3 after Phase 2, because it depends on the pipeline class and
   the unified challenge page that Phase 2's password-plus-passkey path
   introduces. Each phase is one PR.
4. `isEnrolled()` only widens, so no existing user becomes unenrolled on
   deploy. Existing TOTP users see no change except the new challenge
   layout.
5. Docs: update `docs/admin/security.md` (factors, RP ID warning, email OTP
   weakness, client toggle) and the portal user help. Tick the TODO.md item
   off.

## Tests

**Unit and feature (PHPUnit, no browser).**

- `TwoFactorPolicyTest`: `isEnrolled` for each factor alone, for email
  allowed vs not, and for multi-team "all teams must allow". Check that
  memoisation doesn't survive a factor change within a request where that
  matters (the Security page after adding a key).
- Pipeline: a user with no factors logs in directly. TOTP-only, passkey-only,
  email-only and mixed users are redirected to `two-factor.login` with the
  right `login.factors`. An email-only user gets a code sent on redirect
  (`Mail::fake`).
- Email codes: correct code logs in; wrong code ×5 burns it; expiry at
  10 minutes (`travel`); reuse refused; resend cooldown; per-user and per-IP
  send caps; the response doesn't reveal the limiter.
- Recovery codes: generated on first passkey, kept on TOTP disable while a
  passkey remains, accepted on the challenge for a passkey-only user.
- Policy flip regrace: turning `allow_email_two_factor` off resets the clock
  for email-only users only.
- Admin "Reset all second factors" clears every factor and restarts grace.
- `ResetAvailabilityOnLogin` fires on passkey login.

**WebAuthn ceremonies at unit level.** `web-auth/webauthn-lib` verifies real
bytes, so tests need a software authenticator. Add
`tests/Support/FakeAuthenticator.php`:

- It generates an EC P-256 key pair with `openssl_pkey_new` and builds a
  `none`-attestation registration response: clientDataJSON with
  `type=webauthn.create`, the challenge from the session's options and the
  origin from `allowed_origins`, plus authenticatorData with rpIdHash, the
  UP and UV flags, the AT flag and a COSE public key.
- For assertions it builds `webauthn.get` clientDataJSON and authenticatorData
  with an incrementing sign count, and signs
  `authData || sha256(clientDataJSON)` with `openssl_sign` (SHA-256, then
  DER, which is what WebAuthn ES256 expects).
- Check the tests against a recorded fixture: capture one registration and
  one assertion from a real browser with Chrome's virtual authenticator,
  together with its options. Commit them as JSON under
  `tests/Fixtures/webauthn/`, and assert that `VerifyPasskey` accepts the
  recorded pair with a frozen challenge. This catches a mismatch between the
  fake authenticator and real browser output.
- The cases cover registration stored with the right `user_id`; login with a
  discoverable credential; second-factor assertion rejected when the key
  belongs to another user; wrong origin rejected; wrong RP ID rejected;
  missing UV flag rejected; replayed challenge rejected (options are consumed
  from the session); and a sign-count regression, whose behaviour should be
  documented whichever way the library goes.

**Browser (one end-to-end smoke test, not a suite).** Add a Playwright
script under `tests/e2e/` (`pnpm add -D @playwright/test`) that runs against
`composer dev` or Sail. It uses CDP to attach a virtual authenticator:
`WebAuthn.enable`, then `WebAuthn.addVirtualAuthenticator` with
`{protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true}`.
It then signs in with a password, confirms the password, registers a passkey,
logs out, and signs in with the passkey alone, landing on the right panel.
Run it as a separate CI job or manually before release. It is the
"exercised end to end" bar that TODO.md asked for, and it is also how the
`tests/Fixtures/webauthn/` recordings are made.

## Open questions

1. Should platform staff be limited to roaming security keys
   (`authenticatorAttachment: cross-platform`) because workstations are
   shared? That would need a small override of `GenerateRegistrationOptions`.
2. Should a passkey-only first-factor login be allowed for super-admins, or
   should they always be asked for a password as well? Recommendation: allow
   it, since UV passkeys are stronger than password plus TOTP.
3. Is `laravel/passkeys` 0.2.x stable enough to ship? If a 1.0 lands before
   this work starts, take it.
4. Should email OTP be offered to clients by default (`true`), or should it
   be opt-in per client? Recommendation: `true`, since the people it helps
   are the ones who would otherwise call support.

## Size

- Phase 2 (passkeys): about 2.5 days. That covers half a day of wiring and
  config, one day for the Security page section and the login button, half a
  day for the fake authenticator and fixtures, and half a day for the
  Playwright smoke test and docs. The pipeline class and unified challenge
  page are counted here.
- Phase 3 (email OTP): about 1.5 days. That covers codes, limits and mail,
  the policy toggle and regrace, the enrolment UI, and tests.
- Total: about 4 days. That is more than the TODO's 3, mostly for the
  factor-independent recovery codes and the test harness.
