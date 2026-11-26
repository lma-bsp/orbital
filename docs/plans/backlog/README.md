# Backlog plans — everything in TODO.md that had no plan

**Status:** proposed
**Written:** 2026-11-26

Each open item in [`TODO.md`](../../../TODO.md) now has a plan. Items
already covered elsewhere point there:

- [IS parity](../is-parity/README.md) (01–26) — scripting, messages,
  on-call, delivery, routing editors, billing, reporting, hold music,
  skills, and the portal message list.
- [Security review fixes](../security-review-fixes.md) — the
  2026-11-24 review, including the committed `docker/` secrets.
- [Live call monitoring](../live-call-monitoring.md) and the
  [rtpengine edge](../rtpengine-edge.md).

The plans below cover the rest. Every plan was checked against the code
on 2026-11-26, and several found that a TODO entry is out of date. Those
corrections are listed under each plan.

## The plans

| Plan | TODO.md / roadmap source | Size |
|---|---|---|
| [Known bugs](known-bugs.md) | "Known bugs" (all 56 entries) | ~24 dev-days, 12 PRs; PRs 1–3 urgent (~5 d) |
| [Release and install readiness](release-and-install-readiness.md) | "Before making the repository public", "First-run gaps", "Runtime config" | ~7–8 d; urgent steps 1–4 ~2 d |
| [Two-factor: passkeys and email OTP](2fa-passkeys-and-email-otp.md) | 2FA phases 2 and 3 | ~4 d |
| [Impersonation UI](impersonation-ui.md) | Roadmap: impersonation UI | ~2.5 d |
| [Inbound email follow-ons](inbound-email-follow-ons.md) | Inbound email follow-ons (9 items) + email routing editor | 7–10 wk; public-inbound gate ~2.5–3 wk |
| [Web chat](web-chat.md) | Roadmap: web chat + public chat bug | 5½–6½ wk; phase 0 hardening 2–3 d |
| [Messaging follow-ons](messaging-follow-ons.md) | Messaging channel follow-ons → Remaining | ~3 wk without SMPP/WCTP; 5.5–6 wk all |
| [Amtelco import follow-ons](amtelco-import-follow-ons.md) | Amtelco import follow-ons + status-notes bug | 1.5–2 wk plus NCI's answers |
| [Observability follow-ons](observability-follow-ons.md) | Horizon metrics, portal metrics, MinIO OIDC, "Remaining" under error reporting / tracing / alerting, per-client-labels bug | 2.5–3 wk; bug fixes + CI first (~3 d) |
| [Backups follow-ons](backups-follow-ons.md) | Backups → Remaining | ~4–4.5 wk; restore safety + Postgres test first (~3 d) |
| [Pluggable backends](pluggable-backends.md) | Pluggable infrastructure backends | ~4–4.5 wk for the object-store swap |
| [HA gaps and DR site](dr-site.md) | HA / DR / BC | HA gaps ~4–5 wk; DR ~9–11 wk + ~1 wk Helm |
| [Template clients and phone book](template-clients.md) | Template tenants, roadmap template clients, softphone phone book | ~1.5–2 wk, more after plans 08/11 |
| [Portal access to more than one client](multi-client-portal-access.md) | Multi-tenant portal access | ~1.5–2 d |
| [Operator activity events](operator-activity-events.md) | Operator activity tracking | ~1–1.5 wk |
| [i18n](i18n.md) | Full i18n pass | ~2 wk engineering + translator cost |

## Act on now

Some findings shouldn't wait for planning:

1. **The repository is already public.** Upstream and its GitHub mirror
   report `private: false`, and the mirror has a public fork. The
   `docker/` AMI, LiveKit and Valkey secrets have been in public history
   since the first commit. Rotate wherever compose stacks use them
   ([security review](../security-review-fixes.md) §3, §4;
   [release plan](release-and-install-readiness.md) steps 1–4).
2. **Recording disclosures and beeps are dropped** since rtpengine took
   over recording. Clients that set a two-party-consent disclosure are
   being recorded without it ([known bugs](known-bugs.md)). Someone has
   to decide how to tell those clients.
3. **AGPL source offer.** A deployment running unpushed commits points
   `ORBITAL_SOURCE_URL` at upstream ([release plan](release-and-install-readiness.md)).
4. **Alerts that can't fire.** Queue depth always reads 0, and
   per-client labels off makes Pushgateway reject the whole push
   ([observability](observability-follow-ons.md)).
5. **`orbital:restore` never checks the manifest**, so the APP_KEY
   mismatch guard the docs describe doesn't run
   ([backups](backups-follow-ons.md)).

## TODO.md entries found out of date

- MinIO was replaced by SeaweedFS. The MinIO Console OIDC item is moot,
  and "MinIO ↔ S3" means SeaweedFS ↔ S3.
- The `Contact` model and its grant-portal-access action were removed in
  `e864f9d`. Portal users are plain Users with per-client membership.
- `MessagingEndpoint::resolveByPool` cross-client leak: **refuted**. A
  unique index on (provider, sender_pool_id) prevents it.
- Directory `--refresh` "skip when `updated_at > created_at`" no longer
  works. The 2026-11-24 refresh rewrote every entry.
- i18n is ~2,100–2,300 strings (~2 weeks of wrapping), not 2–3 days.
- The app runs Laravel 13, not 12 as CLAUDE.md says.

## Suggested order

1. Act-on-now items above, plus known-bugs PRs 1–3.
2. Cheap hardening slices: web chat phase 0, observability bug fixes +
   CI, backups restore safety, impersonation UI.
3. IS parity 03 → 01 → 02, then 06 → 04 → 05 (see that README). The
   messaging, Amtelco and template plans slot in alongside, as each
   states its IS dependencies.
4. 2FA phases 2–3, multi-client portal access, operator events.
5. Inbound email public-inbound gate, then the rest of email and web chat.
6. Late on purpose: pluggable backends, then HA gaps and the DR site,
   then i18n once English copy is signed off.
