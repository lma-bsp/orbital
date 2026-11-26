# First Login

What to do the first time you open a fresh Orbital installation.

## Creating the first administrator

**A new installation has no login.** Seeding creates roles, permissions, and
reference data, but it does not invent an administrator — a password shipped
in configuration is a password that ends up somewhere it should not.

Create the first account from the command line on the server:

```
php artisan orbital:make-admin
```

It asks for a name, email, and password. The password must be at least 8
characters.

On a Kubernetes install, run it inside the application pod with `-it`, so it
has a terminal to prompt on:

```
kubectl --namespace orbital exec -it deploy/orbital-orbital-laravel -- \
  php artisan orbital:make-admin --name="Your Name" --email=you@example.com
```

From a script or CI job, where there is no terminal, pipe the password on
stdin with `--password-stdin`:

```
printf '%s' "$ADMIN_PASSWORD" | kubectl --namespace orbital exec -i deploy/orbital-orbital-laravel -- \
  php artisan orbital:make-admin --name="Your Name" --email=you@example.com --password-stdin
```

Or pass `--generate-password` to have the command make up a 24-character
password and print it once.

Without a terminal the command never prompts: if the name, email, or password
is missing it stops at once and says what to pass. `--password=...` still
works, but it prints a warning, because the password then shows up in shell
history, process lists and the Kubernetes API server's audit log.

For GitOps installs where nobody execs into a pod, the Helm chart can create
the first admin from a Secret you create yourself: see `firstAdmin.existingSecret`
in `helm/orbital/values.yaml`. Delete that Secret after your first login.

That account is a super-admin: it can see and change everything, including
creating other administrators.

## The three panels

Orbital has three separate front doors. Everyone signs in at the same place
and lands in the one their role allows.

| Panel | Path | For |
|---|---|---|
| **Admin** | `/admin` | Platform staff configuring the system |
| **Operator** | `/operator` | Staff taking calls and messages |
| **Portal** | `/portal` | Your clients, reading what was taken for them |

Someone who reaches the wrong panel is redirected to theirs rather than
shown an error.

## Set up in this order

Each step depends on the ones before it.

**1. Branding.** System → Branding, and Preferences → Portal Branding. Your name and logo, on both
the staff panels and the client portal. Doing this first means every
screenshot you take afterwards is already right.

**2. AI provider credentials.** Conversational AI → Providers. Nothing
AI does anything until a model key is set. See
[Platform Settings](../admin/platform-settings.md).

**3. Check system status.** Monitor → System Status. Components you did not deploy
read "not deployed" rather than red. Anything genuinely red is worth
resolving before you build on it.

**4. Run setup.** System → Setup initializes the external services — object
storage buckets, the `vector` extension, Asterisk and LiveKit configuration.
Re-running a bootstrapper is safe. See
[System Maintenance](../admin/system-maintenance.md).

**5. Turn on backups.** Not glamorous and easy to postpone, but the platform
is about to start holding other people's callers. See
[Backups](../admin/backups.md).

**6. Create your first client.** See [Clients](../admin/clients.md). Use a
real one rather than a "test" account — test accounts have a way of becoming
permanent and confusing.

**7. Create operators and an agent group.** See
[Users & Roles](../admin/users-roles.md) and
[Agent Groups](../admin/agent-groups.md).

**8. Provision a channel.** A phone number, an email address, or a messaging
number, with a queue behind it. See [Channels](../admin/channels.md).

**9. Place a test call.** The only test that covers the whole path.

## Two-factor

Two-factor is required for everyone, with a grace window that starts on each
user's first login. Enrol your own account early — being locked out of a
platform you just installed is a poor first day. See
[Security](../admin/security.md).

## See also

- [How Orbital Works](../concepts.md) — the model behind all of the above
- [Clients](../admin/clients.md) — configuring your first customer
