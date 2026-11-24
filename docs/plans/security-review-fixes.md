# Plan: Fixes from the 2026-11-24 security review

**Status:** proposed — nothing fixed yet
**Branch:** none yet
**Written:** 2026-11-24

## Why

A whole-system security review on 2026-11-24 (five parallel reviewers, each
finding checked independently) found three issues that held at 8/10
confidence or higher, five real-but-narrower ones, and three latent
weaknesses. All of them are in the telephony and deployment layer or in
configuration defaults. The Laravel application code itself came up clean
for SQL injection, command injection, deserialization, template injection,
XSS and cross-tenant access.

Everything below was established by reading code and config. Nothing was
exploited against a running system, so each fix starts with the one-line
check that confirms the problem before changing anything.

## Order of work

| # | Item | Severity | Affects | Size |
|---|---|---|---|---|
| 1 | Kamailio JSON-RPC open on SIP ports | High | every edge, incl. production VMs | small |
| 2 | Default agent-worker token in Helm chart | High | Helm installs that follow the VKE guide | small |
| 3 | Committed AMI secret, port 5038 published | High | Docker Compose stacks | small |
| 4 | Committed LiveKit key/secret, 7880 published | Medium | Docker Compose stacks | small |
| 5 | `livekit` SIP endpoint claimable by From user | Medium | every install | small |
| 6 | Portal view permissions not enforced | Medium | every install (same-client only) | medium |
| 7 | Loki published without auth | Low–Medium | Docker Compose stacks | trivial |
| 8 | Dialplan injection through names | Low–Medium | every install (needs super-admin steps) | medium |
| 9 | Housekeeping and latent weaknesses | — | — | small each |

Items 1–3 should ship first and can go in one PR each. Items 3, 4 and 7
share a theme (compose publishes on `0.0.0.0` with committed or absent
credentials) and can share a PR.

## 1. Kamailio JSON-RPC reachable on the public SIP ports

**Where:** `docker/kamailio/kamailio.cfg:498-518` (`event_route[xhttp:request]`).
`local/edge/kamailio.cfg` is generated from it by `local/edge-up.sh`.

**Problem:** the route special-cases only port 7443, then hands any
`/jsonrpc` request to `jsonrpc_dispatch()` with no check on port, source or
credentials. Kamailio's xhttp hook fires on every TCP/TLS listener, so the
management RPC answers on 5060/tcp and 5061/tls. Binding 8090 to loopback in
`docker-compose.yml:411-422` does not help. `dispatcher.add` lets an outsider
join dispatcher set 1, pass every `ds_is_from_list("1")` check, and relay
calls from the edge's IP (toll fraud); `dlg.list` leaks live call metadata.

**Confirm first:**
`curl -X POST http://<edge>:5060/jsonrpc -d '{"jsonrpc":"2.0","id":1,"method":"core.version"}'`

**Fix:**
- After the 7443 block, reply 403 unless `$Rp == 8090` **and** the source is
  in the app/cluster network (a define, so each deployment sets its CIDR).
- Default `EDGE_JSONRPC_PUBLISH` in `local/edge/docker-compose.yml:61` to
  `127.0.0.1:8090`.
- Stop publishing the rtpengine NG control port (22222/udp) on `0.0.0.0` in
  `local/edge/docker-compose.yml:33`.
- Correct the comment at `kamailio.cfg:495` ("Routes HTTP requests on port
  8090" describes intent, not behaviour).
- Roll the fixed config to the production edge VMs (orbital-setup repo).

**Done when:** the curl above returns 403 on 5060, 5061 and 7443, and the
app's dispatcher sync against 8090 still works.

## 2. Default agent-worker token in the Helm chart

**Where:** `helm/orbital/values.yaml:239`, rendered at
`templates/configmap-app-env.yaml:167` and
`templates/agent-worker-deployment.yaml:54-55`.

**Problem:** `agentWorker.token` defaults to a public dev string with no
`required`/`fail` guard. No shipped overlay sets it, and the install and
upgrade commands in `docs/getting-started/vultr-vke.md` (376-380, 492-496,
510-514) omit it. With that token the public ingress serves
`/api/agent-personas/by-extension/{ext}`, which returns the platform's AI
provider keys plus every tenant's prompt and flow, and allows forged call
logs, transcripts and session fields.

**Confirm first:** on each Helm install,
`kubectl -n <ns> get configmap -o yaml | grep AGENT_WORKER_TOKEN`.

**Fix:**
- Default the value to `""`; make both templates `required` and `fail` on
  the dev string. Better: read it from `app-secrets` via `secretKeyRef`
  instead of a ConfigMap.
- Add the token to the secrets recipe and commands in `vultr-vke.md`.
- Add the dev string to `App\Support\DefaultCredentials::shipped()` so
  `orbital:status` flags existing installs.
- Check whether the orbital-setup installer injects a token (not reviewed).
- Any install found running the default: rotate the token **and** every AI
  provider key (Anthropic, OpenAI, OpenRouter, ElevenLabs, Deepgram).

**Done when:** `helm template` without a token fails, and a test asserts the
middleware rejects the dev string.

## 3. Committed AMI secret with port 5038 published

**Where:** `docker/asterisk/manager.conf:12-23`,
`docker/asterisk/entrypoint.sh:66-73`, `docker-compose.yml:536`,
`compose/ha-telephony.yml:35-53`.

**Problem:** `manager.conf` is committed with a literal secret,
`permit = 0.0.0.0/0.0.0.0` and the `system`, `command`, `originate`,
`config`, `dialplan` write classes. The entrypoint replaces the secret only
when `ASTERISK_AMI_SECRET` is set, and compose never passes it to the
Asterisk containers, so the committed value is always live. 5038 is
published on all interfaces. Anyone who reaches it gets command execution in
the Asterisk container (`Originate` + `Application: System`).

**Fix:**
- Pass `ASTERISK_AMI_SECRET` to `asterisk-1` and `asterisk-2`.
- Make the entrypoint exit when it is unset; replace the committed value
  with a non-working placeholder and treat the old value as burned.
- Publish as `127.0.0.1:5038:5038` or drop the publish.
- Default `permit` to private ranges; drop `system` and `command` from the
  write classes if the app does not need them (check `AsteriskAmiService`).
- In Helm, fail when the Secret lacks the key rather than falling back.

**Done when:** a compose stack with no secret set refuses to start Asterisk,
and `orbital:status` still reports AMI healthy with one set.

## 4. Committed LiveKit key/secret with the API published

**Where:** `docker/livekit/livekit.yaml:11-12,39,47`,
`docker/livekit/sip-config.yaml:2-3,13`, `docker-compose.yml:607-613,624-629`.

**Problem:** the key pair and the Valkey password are committed and mounted
read-only with no env override, so every compose install shares them. With
7880 reachable, an attacker can mint tokens, list rooms, join any live call,
and forge webhooks (`room_finished` abandons a session and fires post-call
actions).

**Fix:**
- Pass `LIVEKIT_KEYS` from `${LIVEKIT_API_KEY:?}` / `${LIVEKIT_API_SECRET:?}`
  and remove the literals (including the Valkey password) from both YAML files.
- Generate the pair at install time, as `key:generate` does for `APP_KEY`.
- Bind 7880/7881/5069 to loopback unless overridden.
- Add LiveKit and the Valkey password to `DefaultCredentials::shipped()`.
- Constrain `storage_path` in `IngestLivekitEgressJob` to the egress prefix,
  and check `exp`/`iss` in `LivekitWebhookController::verify`.

## 5. `livekit` SIP endpoint has no auth and no IP match

**Where:** `app/Services/Telephony/Realtime/InfrastructureEndpointSyncer.php:33-46`;
dialplan at `resources/views/asterisk/from-trunk.blade.php:96-118`.

**Problem:** the endpoint has no `auth`, no `ps_endpoint_id_ips` row and
default `identify_by`, so an INVITE with `From: livekit` is accepted into
`[from-livekit]` unchallenged. That context holds `handoff-{team}-{digits}`,
which dials a client's configured handoff number through the carrier trunk.
The destination is not attacker-chosen, but the call is billed and bridged.

**Fix:**
- Set `identify_by => 'ip'` and upsert a `ps_endpoint_id_ips` row matching
  the LiveKit SIP bridge address.
- Do the same for trunks with no username (`TrunkSyncer.php:49,59`).
- At the edge, reject outside INVITEs whose From user is `livekit` or
  `trunk_*`, or whose R-URI user starts with `handoff-`.
- Verify the identifier behaviour on a live Asterisk 22 before and after
  (the review reasoned from documentation, not a running system).

**Done when:** a `SyncerTest` case asserts the identify row, and a spoofed
INVITE gets 401/403 in the lab.

## 6. Portal view permissions are never enforced

**Where:** `app/Filament/Portal/Pages/CallHistory.php:83-110`, `Dashboard.php`,
`TextConversations.php`; `resources/views/filament/components/recording-player.blade.php:12-26`.

**Problem:** `portal.view_home`, `portal.view_calls`, `portal.view_messages`
and `portal.view_recordings` are seeded and offered as checkboxes on the
portal Roles page ("Listen to recorded call audio"), but nothing checks
them. Unticking one has no effect. Exposure stays within one client. The
operator-side per-client allow-list is equally ineffective for these.

**Fix:**
- Add `canAccess()` to each page for its permission, with the team-owner
  fallback used by `RoleResource::viewerCanManageRoles()`.
- Gate the `play` action (`visible` and `authorize`) on
  `portal.view_recordings`; decide which permission gates `transcript`.
- Redirect a user lacking `view_home` to a page they can open.
- Feature tests: a `client_user` with each permission removed gets 403 or a
  hidden action.
- Reconcile `docs/portal/index.md:35-42` and `docs/admin/security.md:57-66`
  with the code (they say the Roles page is hidden by default; it is not).

## 7. Loki published without auth

**Where:** `docker-compose.yml:827-828`, `docker/loki/loki-config.yaml:18`.

**Problem:** every other observability port is pinned to loopback; Loki is
not. Promtail ships every container's logs, and the agent worker logs caller
fields and SMS bodies verbatim.

**Fix:**
- Change the publish to `127.0.0.1:3100:3100` or drop it.
- Redact `value`, `to` and `body` in the `agent-worker/tools/compiled_flow.py`
  log lines (106, 217, 229): log the key and length only.
- While here, pin Ollama (11434) and pgAdmin to loopback too.

## 8. Dialplan injection through names

**Where:** `resources/views/asterisk/extensions.blade.php:61`, and the same
pattern in `client-dialplan.blade.php`, `from-trunk.blade.php`,
`voicemail.blade.php`.

**Problem:** Blade `{{ }}` HTML-escapes but leaves newlines, so a name with
`\n` becomes extra dialplan lines (`exten = …,System(…)`). The only
non-admin source is a staff user's own name, copied into `Extension.label`
when a super-admin allocates them a softphone; everything else is
super-admin input or imported client names.

**Fix:**
- One helper that strips control characters and `; ( ) [ ]`, applied to every
  DB string in `resources/views/asterisk/*` (or pre-sanitised in
  `AsteriskConfigService`). Prefer emitting IDs rather than free text in
  `NoOp` and comments.
- Reject control characters in user, client, queue, rule and persona names
  (`EditProfile.php`, `UpdateUserProfileInformation.php`, the Filament forms,
  and the Amtelco import mappers).
- Test: a label containing `\n` renders as a single dialplan line.

## 9. Housekeeping and latent weaknesses

- **Customer data not git-ignored.** `data_files_inbound/` and `core.9` are
  in `.dockerignore` but not `.gitignore`. Add both; delete `core.9`.
- **Secret sweep was wrong.** The "Before making the repository public"
  sweep in `TODO.md` says nothing real was found. Items 3 and 4 are live
  committed credentials; redo the sweep over `docker/` and rewrite history
  or rotate before the repo goes public.
- **Tenant scope fails open.** `BelongsToTeam` / `BelongsToTeamOrSharedPool`
  apply no filter when the user has no current team. Make it `1=0` for
  non-platform users.
- **Agent-worker routes accept any Sanctum token.** Unreachable today
  (nothing issues tokens). Remove the fallback or team-scope the controllers
  before API tokens are ever enabled.
- **`isSuperAdmin()` reuses a cached `roles` relation.** Add
  `unsetRelation('roles')` and reserve platform role names in the portal form.
- **pgAdmin SSO approves any logged-in user.** Restrict `skipsAuthorization`
  to super-admin or `tooling.pgadmin`.
- **Shell quoting in `SystemHealthService::runParallelProbes`** (990-1013):
  the host is re-parsed inside a nested `bash -c`. Admin-only input; validate
  hostname characters or pass positionally.
- **Kamailio in-dialog relay** (`kamailio.cfg:229-286`) relays any request
  with a To-tag and a Route naming the edge; add `is_known_dlg()`.
- **Timing-safe compares.** Heartbeat, inbound-mail and TLS-webhook tokens
  use `!==`; switch to `hash_equals`.
- **Bug, not security:** `POST /impersonate/stop` is shadowed by
  `/impersonate/{user}` (`routes/web.php:47-52`) with uncached routes.

## What was reviewed and found sound

Recorded so the next review does not repeat it: panel access rules, portal
query scoping, the portal Roles resource, impersonation, mail attachment
access, all webhook verification (fail closed on empty secrets), the client
API v1 key scoping, broadcast channels, mass assignment, SQL raw fragments,
AMI command construction, Blade and JS output escaping, inbound email HTML
sandboxing, recording path handling, the xlsx importer (no XXE, no
extraction), the dialplan's lack of outbound-dial patterns from untrusted
contexts, the Helm data tier, and the Python agent worker.
