# Source offer and licensing

Orbital is AGPL-3.0. Section 13 asks anyone who runs a **modified** Orbital
for other people over a network to offer those people the source of what they
are running. Orbital makes that offer for you: every panel footer, the About
page, `/source` and `/api/version` link to the source URL, deep-linked to the
exact commit. This page is about keeping that link honest. The license itself
is explained in [`LICENSING.md`](../../LICENSING.md).

## The rule

**Whoever builds the image owns the source URL.** It names the public
repository the running commit was pushed to. The link comes from two values
fixed at image build time:

| Build arg | What it is |
|---|---|
| `ORBITAL_COMMIT` | `git rev-parse HEAD` of the build. Without it `/source` can only send people to a moving branch. |
| `ORBITAL_SOURCE_URL` | The public URL of the remote that commit is on. Empty falls back to upstream (`https://git.calltheory.com/calltheory/orbital`). |

The build scripts fill both in for you:

- `local/k3d-build.sh` and `scripts/build-and-push.sh` source
  `scripts/lib/build-identity.sh`. It passes `ORBITAL_COMMIT` and the URL of
  the remote that has a branch containing `HEAD` (it prefers `origin`, and
  strips credentials from the URL). If `HEAD` is on no remote branch it warns:
  push first, so the commit can be fetched.
- Set `ORBITAL_SOURCE_URL` in the environment to override the guess.
- The release workflow passes the canonical URL and the tagged commit.
- If you have your own build script (a `deploy/build.sh`, say), source the
  same file and add `"${ORBITAL_BUILD_ARGS[@]}"` to the laravel
  `docker build`.

At runtime the environment still wins. On Helm, `licensing.sourceUrl`
overrides the URL baked into the image. It is empty by default, so the image's
own URL applies. Every `values-*.yaml` overlay carries a commented-out
`licensing.sourceUrl` line as a reminder.

## By environment

| Environment | Builds from | Source URL |
|---|---|---|
| Upstream releases | tagged canonical repo | default |
| Orbital Cloud / Call Theory hosted | canonical repo | default (if unmodified) |
| Any fork's deployment | the fork | the fork's public URL |
| Local dev (`sail up`) | working tree | default is acceptable, since there are no external users |

## Checking it

`php artisan orbital:status` and the **Source offer** card on the system
status dashboard (category Platform) warn when either of these holds:

1. **`ORBITAL_COMMIT` is empty.** A source offer without a commit is a guess.
2. **The source URL is the upstream default, but the build is not a tagged
   upstream release.** That means the release channel is `dev`, or the
   version is not a semver tag such as `1.4.2`. Modified code pointing at
   unmodified source looks exactly like this.

The check is skipped when `APP_ENV=local`.

To fix a warning, rebuild with the build scripts above, or set
`licensing.sourceUrl` (Helm) / `ORBITAL_SOURCE_URL` (Compose) to the public
repository that holds the running commit. Then push that commit there.

## See also

- [System Maintenance](system-maintenance.md): the About page and version
- [Security](security.md): this deployment's security contact
