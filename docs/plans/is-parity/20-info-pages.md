# Plan 20: Info pages — rich, versioned, per-client reference pages

**Status:** proposed · part of [IS parity](README.md) · survey item N in
[07](07-additional-features.md) · depends on — for the core (model,
editor, operator view, import); pinning needs [01](01-script-runtime.md)
+ [02](02-operator-scripting.md); dialling external numbers needs an
outbound route ([08](08-call-patching-and-conferencing.md) /
[19](19-scheduled-outbound-calls.md))

## Problem

### What IS does

In Amtelco Intelligent Series each account has **Info Pages**: the
client's own reference sheet, kept next to the script. Operators read
it during the call ("Office hours", "Doctors and their specialties",
"Directions", "Emergency procedures", "Do NOT give out the cell").
The things that matter for parity:

- formatted content: headings, lists, tables, bold/colour for warnings,
  embedded pictures (a map, a building photo, a doctor's headshot), links;
- phone numbers the operator can **click to dial**;
- a script step can **open a given page or section** at the moment it
  matters ("show: Emergency procedures" on the emergency branch);
- supervisors edit them, with the change recorded; operators see
  the latest version on the next call;
- a client can maintain parts of them through the web portal.

### What Orbital has today (checked in the code)

- **Only a verbatim text dump.** The Amtelco importer maps the
  `Info Pages` tab to the legacy section `info_pages`
  (`app/Services/Import/Amtelco/AmtelcoImporter.php:58`). It is one of
  the `TEXT_SECTIONS` (`:67`), so the rows are also joined with `"\n"`
  into `payload['text']` (`:304-307`) and upserted per
  `(team, source, section)` (`:355-360`, unique key in
  `database/migrations/2026_11_24_100200_create_client_legacy_records_table.php:40`).
  `ClientLegacyRecord` is a plain `BelongsToTeam` model with a JSON
  `payload` (`app/Models/ClientLegacyRecord.php:15-35`). The rows are
  joined in sheet order; the `Line` column is not used to order them.
- **The operator sees it as `<pre>`.** `ClientCallBrief::for()` reads
  that record with the team scope removed and an explicit `team_id`
  filter (`app/Services/Operator/ClientCallBrief.php:27-32`) and returns
  it as a single `?string` (`:23`, `:44`). The single-account Workspace
  (`app/Filament/Operator/Pages/Workspace.php:141-150`) renders it in a
  collapsible monospace `<pre>` titled "Info pages (Amtelco)"
  (`resources/views/filament/operator/pages/workspace.blade.php:153-158`);
  the multi-call desk does the same in a `<details>`
  (`resources/views/livewire/multi-call-workspace.blade.php:354-359`,
  brief loaded in `app/Livewire/MultiCallWorkspace.php:288` on **every
  render**). There is no search, no structure, no links, and no
  click-to-dial.
- **Team call-handling fields (uncommitted on this branch).**
  `database/migrations/2026_11_24_100000_add_call_handling_fields_to_teams.php:31-35`
  adds `greeting`, `operator_note` (255) and `passcode` (encrypted cast,
  `app/Models/Team.php:80`; fillable `:36-38`). They are edited in the
  ClientResource Details tab (`app/Filament/Resources/ClientResource.php:170-188`).
  These are **not** info pages and stay as they are: short, always-on
  fields shown above everything else. Info pages are the long-form
  reference beneath them.
- **No editor, no versioning, no audit.** No info-page model, Filament
  page or portal page exists. The ClientResource sub-pages
  (`ClientResource.php:472-496` sub-nav, `:500-522` routes) have nothing
  for it. The only audit table in the app is `failover_audit_logs`;
  there is no generic revision/activity package in `composer.json`.
- **No portal access.** The client portal has `CallHistory`, `Dashboard`,
  `TextConversations` and roles (`app/Filament/Portal/`). The tenant
  permission allow-list is `portal.view_home|calls|messages|recordings`
  plus `portal.manage_users|roles`
  (`app/Services/Clients/ClientProvisioner.php:49-56`, user subset
  `:64-69`).
- **AI can't read them.** AI knowledge is `KnowledgeStore`
  (`BelongsToTeam`, soft-deletes, `app/Models/KnowledgeStore.php:23-55`)
  → `KnowledgeChunk` (pgvector, `source_type` / `source_ref` /
  `metadata`, `app/Models/KnowledgeChunk.php:28-45`). Ingest only
  **appends** (`IngestService::ingestText`,
  `app/Services/Knowledge/IngestService.php:69`, next `chunk_index`
  from `max()+1`); there is no "replace this source". The worker only
  gets the stores whose ids a goal names in `knowledge_store_ids`
  (`app/Services/Flows/AgentFlowCompiler.php:992-1015`); retrieval
  re-checks ownership (`RetrievalService::searchForTeam`, `:104`).
- **Softphone has no dial command for other components.** The
  multi-call desk drives the phone through the window event
  `orbital-softphone-command`, which only knows `answer`, `hangup`,
  `hold` and `resume` (`resources/js/softphone.js:52-63`). Dialling is
  only the softphone's own `call()` from its dial input (`:191-194`) →
  `SipPhone.call(extension, domain)` (`resources/js/sip-phone.js`,
  `async call`), which has **no guard against an existing call**: it
  replaces `this.session` (the incoming path has such a guard, `:85`).
- **No outbound PSTN route for operators.** The generated dialplan has
  AI-agent, internal-extension and queue extensions plus an optional
  "internal DID simulation" (`resources/views/asterisk/extensions.blade.php:33-48`)
  that is meant to be off in production. An operator clicking an
  external number has nowhere to go today.
- **Tools we can reuse.** Filament 5's `RichEditor` (TipTap) ships
  tables, H2/H3, links, file attachments, custom blocks and JSON storage
  (`vendor/filament/forms/src/Components/RichEditor.php:1051-1066`);
  `symfony/html-sanitizer` is already installed (via `filament/support`),
  but Filament's default config allows `style` on every element
  (`vendor/filament/support/src/SupportServiceProvider.php:111-125`), too
  loose for content clients can write. MMS media already uses a private
  object-store disk with temporary URLs (`config/messaging.php:136-141`,
  `app/Models/MessageEntry.php:139-164`).

### What the imported text looks like (measured on the NCI export)

From `data_files_inbound/NCI_Client_Data_by_Account.xlsx`, tab
`Info Pages` (columns `Account #`, `Client Name`, `Line`, `Text`):

- 37,767 lines for 1,862 accounts; median 20 lines per account, p90 32,
  max 58; widest line 78 characters.
- Every account uses the same layout: **two 40-column screens side by
  side**. 5,481 header lines carry `INFO PAGE n` at column 0 (odd n) and
  `INFO PAGE n+1` at column 40 (3 lines at column 41). Under each header
  are at most 12 lines. Accounts have 2–14 pages (6 is the most common,
  then 8 and 4).
- 96% of lines are all upper case. 5,202 lines contain something shaped
  like a phone number; 249 contain a URL or an email address.

So the importer can split each account's text back into real pages
reliably. Today's `<pre>` shows page 1 and page 2 as one interleaved
78-column block.

## Goal

Each client has **info pages** that are:

1. formatted (headings, lists, tables, images, links, callouts);
2. split into **sections with stable keys** that scripts can pin and AI
   can cite;
3. quick to use on a call: table of contents, search, click-to-dial,
   "changed since your last call";
4. **versioned**: every change is a revision with who, when, from where
   (admin, portal, import, AI draft), and can be compared and restored;
5. editable by platform staff, and by the client's own users in the
   portal when allowed (directly or with review);
6. readable by AI agents, with control per section;
7. created from the Amtelco text we already imported, without losing it.

Not in scope: a general wiki, cross-client shared pages (shared
directories already cover shared contacts), and WYSIWYG page layout
(columns, free placement).

## Design

### Data model (all `BelongsToTeam`, all `declare(strict_types=1)`)

```
info_pages                      one tab in the operator panel
  id, team_id (FK cascade), title, position,
  is_published bool, published_revision_id (FK nullable),
  draft_revision_id (FK nullable)   -- pending client/AI/import draft
  lock_version int                  -- optimistic concurrency
  created_by, updated_by (FK users, nullable), timestamps, soft deletes

info_page_sections              the unit for pinning, search, AI, permission
  id, team_id, info_page_id (FK cascade), revision_id (FK),
  key            slug, unique per (team_id, key) among live rows
  title, position,
  body           jsonb    TipTap document (source of truth)
  body_html      text     sanitised render, rebuilt from body
  body_text      text     plain text, for search and AI
  search         tsvector GENERATED (title 'A', body_text 'B'), GIN index
  audience       jsonb    {operators: true, ai: true, portal: true}
  client_editable bool    default false
  is_critical    bool     render as a red callout; always first in TOC

info_page_revisions             append-only history
  id, team_id, info_page_id, number (per page, 1..n),
  status         draft | published | rejected | superseded
  snapshot       jsonb    {title, sections: [{key,title,position,body,audience,…}]}
  source         admin | portal | import:amtelco | ai_draft | restore
  author_id (FK users, nullable), author_kind (staff|client|system),
  base_revision_id, summary (one-line "what changed"),
  reviewed_by, reviewed_at, review_note, created_at

info_page_assets                images and attachments
  id (uuid), team_id, info_page_id (nullable until saved),
  disk, path, mime, bytes, width, height, sha256, original_name,
  uploaded_by, created_at, deleted_at

info_page_reads                 "changed since your last call"
  user_id, team_id, last_seen_revision_at   (PK user_id+team_id)
```

Why sections are rows rather than headings in one document: a script
pin, a search hit, an AI chunk, an audience flag and a client-edit
permission all need a stable address. A heading inside a TipTap blob
changes id when someone retypes it; a row with a `key` does not. Pages
are just groups of sections (Amtelco "page 1…n" maps to pages; most
clients will have 1–4).

Live tables (`info_pages`, `info_page_sections`) hold the **published**
state for fast reads. Revisions hold full snapshots. Publishing a
revision rewrites that page's sections in one transaction. Snapshots
make restore and diff simple. They are small (whole account today is
under 3 KB of text), so storing full copies costs nothing.

`Team` gains `infoPages(): HasMany` next to `legacyRecords()`
(`app/Models/Team.php:209`) and a setting
`info_pages_client_edit` = `off | review | direct` (default `off`).

### Image and file storage

- Disk: the same private object store as MMS media
  (`config('messaging.media.disk')`, `s3` → SeaweedFS), path
  `info-pages/{team_id}/{uuid}.{ext}`. Never the `public` disk.
- Upload goes through `RichEditor::fileAttachmentsDisk()` +
  `fileAttachmentsVisibility('private')` with a custom save callback
  that creates the `info_page_assets` row. The stored TipTap node keeps
  `data-asset-id`, **not** a URL.
- Serving: `GET /info-assets/{asset}` (signed route, 10-minute links,
  generated at render) authorises `asset.team_id` against the viewer,
  then redirects to `temporaryUrl()` (as `MessageEntry` does). Signed
  links in cached HTML would go stale, so the asset-id → URL swap
  happens per render, after the HTML cache.
- Accepted: JPEG, PNG, WebP, GIF, ≤ 5 MB, ≤ 4000 px. **No SVG**
  (scripts inside). Re-encode on upload (strips EXIF/GPS and anything
  hidden in the file) and store the width and height so the layout
  doesn't jump. PDFs as attachment links only (no inline viewer), ≤ 10 MB.
- Quota: 50 MB per client by default, enforced on upload.
- Assets are deleted by a queued job when a revision older than the
  retention window (below) is pruned and nothing else references the
  asset. Team deletion cascades rows. A `TeamDeleted` listener queues
  deletion of the `info-pages/{team_id}/` prefix.

### Editor (admin and portal)

- `ManageClientInfoPages` — a new ClientResource sub-page (register in
  `getRecordSubNavigation` and `getPages`, `ClientResource.php:472-522`):
  - list of pages (drag to reorder), each opening an edit form:
    a `Repeater` of sections (key, title, audience toggles,
    client-editable, critical) with a `RichEditor` per section, `json()`
    storage, toolbar trimmed to: bold, italic, underline, link, H3,
    bullet/ordered list, table, blockquote, attachFiles, customBlocks,
    undo/redo. No text colour or alignment: operators need the same
    look on every client.
  - **Custom blocks**: `Phone` (number, label, ext, `private` flag),
    `Contact` (a reference to a `DirectoryEntry` id — shows its current
    name and numbers, so the info page never holds a stale copy of the
    directory; `Team::directoryEntries()`, `app/Models/Team.php:238`),
    `Callout` (info / warning / critical).
  - `key` is generated from the title on creation and then frozen;
    renaming the title does not change it. It can only be edited by
    staff, with a warning listing scripts that reference it.
  - Save = new revision (`source=admin`), published immediately for
    staff. `lock_version` check: if someone else saved since load, the
    save is refused with "updated by X at T — reload and re-apply"
    (no silent overwrite). Short drafts survive in browser
    `localStorage` across the reload.
  - "Preview as operator / as AI / as client portal" tabs render the
    same revision through each audience filter.
  - **History** slide-over: revisions with author, source, time
    (client timezone, `Team::displayTimezone()`), summary; side-by-side
    diff of `body_text` per section (with images shown as "[image]"),
    and **Restore** (creates a new revision `source=restore`; history is
    never rewritten).
  - **Review queue**: drafts from the portal or AI show as a banner on
    the page and in a platform-wide "Info page changes to review" list.
    Approve publishes, reject keeps it with a note, and the client sees
    the result.
- Keep the ClientResource Details fields (greeting, pinned note,
  passcode) where they are. The editor shows them read-only at the top
  with a link, so editors don't duplicate them into a section.
  A save-time check warns if a section contains the client's passcode
  string (compare against the decrypted `Team::passcode`), since
  sections can reach AI and the portal.

### Rendering and sanitisation

One service, `App\Services\InfoPages\InfoPageRenderer`:

1. TipTap JSON → HTML with Filament's `RichContentRenderer` (custom
   blocks rendered by our Blade partials).
2. **Strict** `HtmlSanitizer` (our own config, not Filament's scoped
   one): elements `p br strong em u a h3 h4 ul ol li table thead tbody
   tr th td blockquote img figure figcaption span div`; attributes
   `href` (schemes `https http mailto tel`), `data-asset-id`,
   `data-dial`, `data-section`, `class` from an allow-list; **no**
   `style`, no `on*`, no iframes. External links get
   `rel="noopener noreferrer nofollow" target="_blank"`.
3. **Phone linker**, a DOM pass over text nodes outside `a`/`code`:
   NANP numbers with separators (`(845) 555-0123`, `845-555-0123`,
   `845.555.0123`, `1-845-…`) plus an optional `x123` / `ext 123` →
   `<a href="tel:+18455550123" data-dial="+18455550123" data-ext="123">`.
   Bare 10-digit runs are linked only if they pass NANP checks (to
   avoid account numbers and dates). Numbers inside a `Phone` block
   with `private` get a "do not give out" lock badge.
4. Result → `body_html` (cached per revision); `body_text` from the
   same tree (tables as `cell | cell` lines, images as their alt text).
5. Per request: swap `data-asset-id` for signed URLs and apply the
   audience filter (drop sections the viewer may not see; for AI also
   drop `private` phone blocks).

Legacy text that has not been converted is rendered escaped in `<pre>`,
as today.

### Operator UI (Workspace, multi-call desk, plan 02 runner)

Replace the two `<pre>` blocks with one Livewire component,
`InfoPagesPanel` (team id, `pinnedSectionKey`):

- **TOC first**: critical sections in red at the top, then pages as
  tabs and section titles as a list. Bodies load lazily when opened, so
  `MultiCallWorkspace::render()` (which rebuilds the brief on every
  poll) stays cheap. `ClientCallBrief` returns only the TOC
  (`info_pages: array{pages: list, has_legacy: bool, updated_at, changed_since_seen: bool}`)
  instead of the full string. The passcode stays out of public
  Livewire state, as its docblock requires (`ClientCallBrief.php:17-19`).
- **Search box** (`/` to focus): Postgres full-text over this client's
  sections only (`websearch_to_tsquery`, `ts_headline` snippets), with
  `ILIKE` fallback for short or numeric input ("x204", "Suffern").
  Ranks title hits first. The results list jumps to the section.
- **Pinned section**: when the script runner (plan 02) enters a step
  that pins a section, the panel scrolls to it, expands it and
  highlights it, with a "Pinned by script" tag. The operator can unpin
  it. On the multi-call desk each call keeps its own pin; the panel is
  keyed by call id (`wire:key`), so switching calls never shows client
  A's page on client B's call.
- **Click-to-dial**: an Alpine handler on `[data-dial]` sends
  `orbital-softphone-command` with `{action: 'dial', target, ext}`;
  `softphone.js` gains a `dial` case (next to `answer`/`hangup`,
  `:57-62`) that calls `phone.call(target, domain)` **only when the
  phone is idle**.
  - While in a call, the click instead opens a small menu: *Copy*,
    *Consult/transfer to this number* (enabled only once plan 08's
    attended transfer exists). We must not start a second call,
    because `SipPhone.call()` would orphan the caller (see Bugs).
  - External numbers need an outbound route with the client's caller
    ID (`Team::primaryDid()`, `app/Models/Team.php:225`). Until plan
    08/19 add it, external numbers are copy-only and internal
    extensions dial. The `softphone.use` permission (already in
    `PermissionCatalogSeeder`) gates the dial action.
  - Each dial is logged as a script event (`info_page_dial`, number,
    section key) when a script session exists.
- **"Updated since your last call"** badge: compares
  `info_pages.updated_at` with `info_page_reads.last_seen_revision_at`
  for (operator, client). Opening the panel marks it seen. Changed
  sections show a dot, and a "what changed" link opens the diff.
- The Amtelco verbatim view stays available as a "Original (Amtelco)"
  toggle for 90 days after conversion.

### Script pinning (with plans 01/02)

- New catalogue goal **`show_info_section`** (non-interactive, plan 01
  handler): params `section_key` (picker of this client's section keys)
  and `mode` = `pin | flash`. The engine emits `info_section_shown
  {key, revision_id}` to `script_events`. This is also how QA proves
  the operator had "Emergency procedures" in front of them.
- Every interactive step also gets an optional `info_section` param
  ("show alongside this step"). This is common in IS: the step about
  directions shows the directions page.
- Editor validation (plan 01 "Editor changes"): a key that doesn't
  exist for the bound client is an error. For platform-shared
  orchestrations (`team_id` null) it is a warning, resolved per client
  at runtime.
- At runtime, a missing key gives an `info_section_missing` event and a
  visible "Script asked for section 'x' — not found" line in the panel.
  It never fails the call.
- Keys are always resolved **within the session's team**. A shared
  orchestration that says `emergency` gets each client's own
  `emergency` section.

### AI path

- One **system knowledge store per client**, `kind = 'info_pages'`
  (new nullable column on `knowledge_stores`; regular stores are
  `null`), created lazily on first publish and hidden from the generic
  KnowledgeStoreResource edit actions.
- On publish, the queued `SyncInfoPagesToKnowledge` job deletes chunks
  `where store_id = X and source_type = 'info_page_section' and
  source_ref = key` for changed sections. It then ingests `title + "\n" +
  body_text` with `metadata {page, title, revision_id}`. This needs a
  new `IngestService::replaceSource($store, $type, $ref, $text)`,
  because `ingestText` only appends. Sections with `audience.ai = false`
  are deleted, not ingested. Sync failures mark the store
  `ingest_status = failed` and show on the admin page. The AI keeps the
  previous chunks rather than nothing.
- **Inclusion**: `AgentFlowCompiler::collectAvailableStores()`
  includes the client's `info_pages` store whenever the persona has
  `use_info_pages` (default on). It no longer depends on a goal listing
  the store id. This also fixes the `answer_question` mismatch (see
  Bugs).
- **Pinned sections are deterministic**: when the engine (plan 01)
  renders a step with `show_info_section`/`info_section`, it injects
  that section's `body_text` into the step instructions verbatim, so
  the AI has it without a retrieval hop. Retrieval is for open
  questions ("where do I park?").
- Small clients: if all AI-visible text is under ~2,000 tokens, the
  compiler also inlines it into the prompt as "Client reference" (no
  RAG miss possible). The threshold is an open question.
- The prompt keeps the existing citation instruction
  (`AgentFlowCompiler.php:117-124`). `private` phone numbers never
  reach the AI, so it cannot read them to a caller.

### Client portal

- `app/Filament/Portal/Pages/InfoPages.php`: read view (same renderer,
  `portal` audience). Edit is per section where
  `client_editable = true` and the team setting is not `off`.
- New permissions `portal.view_info_pages` and
  `portal.manage_info_pages`, added to `PermissionCatalogSeeder` and
  `ClientProvisioner::DEFAULT_TENANT_ALLOW_LIST`. `view` also goes in
  `DEFAULT_TENANT_USER_PERMISSIONS`. A one-off command backfills the
  allow-list and roles for existing clients (1,630 in production). It
  runs in batches, with one Spatie cache reset at the end, because of
  the cache cost described in `app/Support/PermissionRegistrar.php`.
- Mode `review`: a client save creates a `draft` revision; staff
  approve. Mode `direct`: it publishes immediately, `author_kind =
  client`, and staff get a notification. Either way operators see who
  changed it ("edited by client — J. Smith").
- Clients can't change keys, audience flags, critical flags, or
  sections not marked editable. They can upload images (counted
  against the quota).

### Permissions and tenant isolation

| Who | View | Edit | Approve | History/restore |
|---|---|---|---|---|
| Operator | ✓ (operators audience) | — | — | view only |
| Supervisor | ✓ | ✓ | ✓ | ✓ |
| Platform admin | ✓ | ✓ | ✓ | ✓ |
| Client user | `portal.view_info_pages` (portal audience) | — | — | own changes |
| Client admin / delegated | ✓ | `portal.manage_info_pages`, editable sections only | — | ✓ (view) |
| AI worker | `ai` audience, own team via store | — | — | — |

New platform permissions: `info_page.view`, `info_page.update`,
`info_page.approve` (policy `InfoPagePolicy`).

Isolation rules, since `BelongsToTeam`'s scope does nothing for
super-admins or without an authenticated user
(`app/Models/Concerns/BelongsToTeam.php:16-36`), and operators are
platform staff who move between clients:

- Every operator- and worker-side query names the team explicitly
  (`where team_id = $call->team_id`), as `ClientCallBrief` already does.
  Panel loads take the team from the call/`activeTeamId` on the server,
  never from a client-supplied section id alone. Section ids received
  from the browser are checked against that team.
- Asset route: the asset's team must match the panel's team (staff) or
  the user's `currentTeam` (portal). The signed URL is not enough on
  its own.
- Knowledge: the info-pages store is owned by the team, and retrieval
  re-validates ownership (`RetrievalService::searchForTeam`).

### Audit

- `info_page_revisions` is the edit audit (who, when, source,
  before/after, reviewer).
- Views are **not** logged per open (too noisy), except: pinned-section
  display and click-to-dial become `script_events` when a session
  exists (plan 01). Portal edits also write a row in the client's
  activity (plan 10 reporting can count them).
- Retention: keep all published revisions for 2 years, drafts/rejected
  for 90 days (pruned by a scheduled command). Configurable per
  platform.

### Amtelco import path

`App\Services\Import\Amtelco\InfoPagesConverter` plus the command
`orbital:convert-amtelco-info-pages {--dry-run} {--account=} {--refresh}`.
It reads `ClientLegacyRecord` (`section = info_pages`, `payload.rows`),
not the workbook, so it can be re-run offline.

1. Sort rows by `Line`. Find header lines by searching for
   `INFO PAGE (\d+)` and record each marker's column (handles the 3
   lines at col 41). Split every following line at that column into
   left (odd page) and right (even page) halves, until the next header.
2. Right-trim each half, drop trailing blank lines, drop pages that are
   empty.
3. Section per Amtelco page: key `amtelco-page-{n}`, title = the first
   line if it looks like a heading (short, all caps, followed by
   content), otherwise "Info page n". Body = paragraphs split on blank
   lines, with each source line kept as a hard break (the 40-column
   layout carries meaning). Case is kept as-is (96% upper case; don't
   guess).
4. Phone numbers become links at render time, so the importer doesn't
   have to rewrite text.
5. One page "Info pages" per client, revision `source=import:amtelco`,
   published, with `audience.ai = false` by default. Imported text has
   not been reviewed and may contain things not meant for callers.
   Staff switch AI on per client after a look.
6. Re-runs: a section still on an `import:amtelco` revision is
   refreshed; one edited since is left alone and listed as a conflict
   in the report (same rule as the importer's `refresh` semantics,
   `AmtelcoImporter.php:32-37`).
7. Optional, later: the planned AI structuring pass (Haiku batch,
   already decided for hours/directory) writes an **`ai_draft`
   revision** per client: headings, real tables for "Doctor | Days |
   Phone", sentence case, `Contact` blocks matched to directory
   entries. It always goes through review and is never auto-published.

Production safety, as for the original import: DB backup, dry-run
report (accounts, pages, sections, conflicts, lines that failed to
split), sign-off, then the real run.

## Edge cases

- **Two editors at once**: `lock_version` refuses the second save. A
  client draft based on revision 7 when 8 is published shows the diff
  against 8 before approval.
- **Page changes mid-call**: the operator keeps reading the version
  they opened; a "Updated — reload section" chip appears (polled with
  the panel). Pinned-section events record the `revision_id` shown.
- **Pinned key missing / renamed / deleted section**: keys are frozen,
  deleted sections keep their key reserved for 90 days (soft delete),
  and runtime fallback per "Script pinning".
- **Shared orchestration** pinning a key only some clients have:
  warning in the editor, runtime miss event, no failure.
- **Click-to-dial while on a call**: never starts a second call (menu
  instead). Dial while the phone is unregistered: disabled, with a
  tooltip. Number with extension: dial the main number and show the
  extension to key in (DTMF after answer is a later nicety).
- **False-positive numbers**: account numbers, dates, ZIP+4, ICD codes.
  Mitigation: separators or NANP validation required, never inside
  tables marked "codes", and an editor "not a phone number" escape
  (wrap in code).
- **Private numbers**: lock badge for operators, removed for AI,
  shown in the portal (it's the client's own data).
- **PHI** (medical clients): info pages may name patients or staff
  cells. Private disk, signed URLs, no public assets, revisions under
  the same retention as messages. Search indexes stay in Postgres (no
  external search service).
- **Pasted Word/Google Docs content**: the sanitiser strips styles and
  spans; tables survive. Images pasted as data URIs are converted to
  assets or rejected.
- **Huge content**: limit 50 sections per page, 20 pages per client,
  100 KB of text per section. Wide tables scroll horizontally inside the
  sidebar.
- **Client suspended / deleted**: pages read-only / cascaded. Assets
  are purged by job.
- **Embedding provider down or dimension mismatch** (`IngestService`
  throws on mismatch): the store is marked failed and the AI keeps the
  old chunks. Pinned sections still work because they don't use
  retrieval.
- **Operator without `softphone.use`** (e.g. supervisor reading
  pages): numbers render as text with copy.
- **Multi-call desk with two calls for the same client**: each call has
  its own pin state. "Seen" is per operator and client.

## Migration / rollout

1. **Model + renderer + admin editor + operator panel** (with legacy
   `<pre>` fallback when a client has no converted pages). Feature flag
   `orbital.info_pages.enabled`. Update `ClientCallBrief`'s return shape
   and `AmtelcoImportTest` (`tests/Feature/Import/AmtelcoImportTest.php:237`
   asserts the string).
2. **Convert the Amtelco text** for the 1,862 accounts (dry run →
   sign-off → run). Verbatim toggle stays.
3. **Click-to-dial (internal)** and search. External dialling switches
   on when an outbound route exists (08/19).
4. **AI**: system store, sync job, compiler inclusion. Enable per client
   (`audience.ai` defaults off for imported sections).
5. **Script pinning**, after plan 01 engine and plan 02 runner ship.
   Until then, an operator-facing "pin" only exists via manual click.
6. **Portal** view, then edit (`review` mode first for NCI).
7. Remove the legacy fallback and the `<pre>` blocks once every active
   client has converted pages. Keep the `ClientLegacyRecord` rows
   forever (they are the source of truth for re-conversion).

## Tests

Factories: `InfoPage`, `InfoPageSection`, `InfoPageRevision`,
`InfoPageAsset`. Use the existing `tests/Concerns/BuildsXlsxFiles.php`
for import fixtures.

**Import / conversion**

- Two-pane fixture (`INFO PAGE 1` @0, `INFO PAGE 2` @40) → two sections
  with the correct halves. Header at column 41 is handled. Lines out of
  `Line` order are sorted first.
- Empty right pane → only odd page created. An account with 14 pages →
  14 sections in order.
- Re-run with no edits refreshes. Re-run after a staff edit leaves the
  section alone and reports a conflict. `--dry-run` writes nothing.
- Imported sections have `audience.ai = false`.

**Rendering / security**

- `<script>`, `onerror=`, `javascript:` links, `style=`, `<iframe>`,
  SVG upload → all stripped/rejected.
- Phone linker: `(845) 555-0123 x12` → `tel:+18455550123` with ext.
  Account number `6200001`, date `11/24/2026`, ZIP `10901-1234` → not
  linked. Already-linked numbers are not double-wrapped.
- `private` phone block present for operator, absent from AI text.
- Passcode-in-section warning fires.

**Operator flows (Livewire)**

- Workspace shows TOC for the active client. Critical section first.
  Search "suffern" finds the 2nd-office section and only in this
  client.
- Multi-call desk: call A (client 1) and call B (client 2); select B →
  panel shows client 2 only. Pins don't cross calls.
- Click-to-dial dispatches `orbital-softphone-command` `dial` when idle.
  While "in-call" the menu shows instead and no dial event is
  dispatched.
- "Updated since last call" appears after an edit, clears on open.
- Section changed mid-call shows the reload chip. The pinned event
  records the original revision id.

**Script / AI**

- Engine fixture: emergency branch with `show_info_section emergency`
  → event `info_section_shown {key: emergency}`. Runner pins it.
- Shared orchestration pins `emergency` for client A and B → each sees
  its own. Client C without the key → `info_section_missing`, call
  continues.
- Publish → sync job replaces only changed sections' chunks. Section
  with `ai=false` has no chunks. Compiler includes the info-pages store
  with no explicit `knowledge_store_ids`.
- Worker search with client A's store ids can't return client B's
  info-page chunks (existing 403 path).

**Versioning / audit / permissions**

- Every save creates revision n+1 with author and source. Restore
  creates a new revision equal to the old snapshot.
- Stale `lock_version` save is refused.
- Portal: `client_user` sees pages but can't edit. A user with
  `portal.manage_info_pages` in `review` mode creates a draft; the
  operator still sees the old text until approval; after approval the
  revision shows `author_kind=client`. Non-editable section → 403.
  Client of team A can't load team B's page or asset URL (also with a
  valid signature for B's asset).
- Operator can't open the editor (`info_page.update` missing).
  Supervisor can.
- Team deletion removes pages/sections/revisions and queues asset
  purge.

**Answering-service scenarios (end to end, fake telephony)**

- After-hours medical call: script hits "doctor on call?" branch → the
  "On-call doctors" section pins → operator clicks the internal
  extension → softphone dial event with that extension.
- Property management: caller asks for directions → operator searches
  "parking" → finds the section with a photo whose signed URL renders.
- AI call: caller asks "what are your Saturday hours?" → AI retrieves
  the hours section, cites it, and does not read the private cell from
  the same section.

## Open questions

1. Should imported sections default to `ai = false` (proposed), or does
   NCI want AI to read them from day one?
2. Client self-edit: `review` for everyone at launch, or `direct` for
   trusted clients? Who reviews at NCI (supervisors on shift, or an
   account manager)?
3. Inline-all-text threshold for small clients (~2,000 tokens) versus
   always RAG.
4. Do NCI operators need click-to-dial to **external** numbers outside
   a transfer (callbacks)? This decides whether we need plan 19's
   outbound route before plan 08's.
5. Should the IS "Forms" tab (`forms` legacy section, also plain text)
   go into info pages, or into plan 03 message templates? It looks like
   message forms (`FULL NAME:|   |`), so probably plan 03.
6. Revision retention: is 2 years right for NCI's compliance needs
   (HIPAA clients)?
7. Per-operator "favourite sections" or IS-style function-key jumps to
   page n: wanted?

## Size estimate

| Piece | Effort |
|---|---|
| Models, migrations, policies, revisions/restore/diff | 3–4 d |
| Renderer, sanitiser, phone linker, assets + signed route | 3 d |
| Admin editor (sections repeater, custom blocks, history, review queue) | 4 d |
| Operator panel (TOC, lazy sections, search, seen-badge) in both workspaces | 3 d |
| Click-to-dial (softphone `dial` command, in-call menu) | 1 d (+ outbound route in 08/19) |
| Amtelco converter + command + dry-run report | 2 d |
| AI: system store, `replaceSource`, sync job, compiler inclusion | 2 d |
| Portal view/edit + permission backfill | 2–3 d |
| Script pinning (`show_info_section`, step param, validation) | 1.5 d after 01/02 |
| Tests | 3 d |

**≈ 4.5–5 weeks** for one developer. Phases 1–3 (≈ 2.5 weeks) already
give operators real info pages and replace the `<pre>`.

## Bugs and inconsistencies found while writing this plan

- `SipPhone.call()` (`resources/js/sip-phone.js`, `async call`) doesn't
  check for an existing session and overwrites `this.session`. Its
  `Terminated` handler clears `this.session` without checking which
  session ended, unlike the incoming path (`:85`, `:104`; line numbers as of 2026-11-24 — the file is being edited on this branch). Any
  feature that dials while a call is up would orphan the caller.
- `answer_question`'s param hint says "None = search all stores
  attached to this client" (`database/seeders/IntakeGoalLibrarySeeder.php:762`),
  but `collectAvailableStores()` returns `[]` when no ids are given
  (`app/Services/Flows/AgentFlowCompiler.php:1001`), so
  `search_knowledge` is not registered and the step can never answer.
- `KnowledgeController`'s session-auth path uses the user's
  `current_team_id` (`app/Http/Controllers/Api/KnowledgeController.php`,
  `resolveTeamId`). Platform operators handle many clients, so the
  "operator clicks check knowledge" use its docblock describes would 403
  for client stores. No UI calls it yet.
- The importer joins info-page rows in sheet order without sorting by
  `Line` (`AmtelcoImporter.php:304-307`). That is fine if the export is
  ordered, but nothing checks it.
- CLAUDE.md says Laravel 12; `composer.json` requires
  `laravel/framework ^13.0`.
