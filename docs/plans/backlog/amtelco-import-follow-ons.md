# Plan: Amtelco import follow-ons

**Status:** proposed · part of [Backlog plans](README.md)
**Written:** 2026-11-26

## Problem

`orbital:import-amtelco` and `orbital:import-amtelco-directories` have
run in production (1,630 clients; 7,764 client directory entries plus
the provider index and 517 unattached shared directories, 15,360
entries in all). TODO.md "Amtelco import follow-ons" and one bug in the
"Bugs" list leave three problems:

1. **Most dial macros are still raw.** Only the SMS macro is decoded.
   Every other macro is stored as "(code …)" next to its number, and
   nobody at our end knows for certain what those macros do. TODO.md
   says not to guess, and the 2026-11-24 import decisions say the same.
2. **`--refresh` overwrites operator edits.** It replaces every field
   the import owns. That was safe the one time it ran, because nobody
   had edited anything yet. It won't be safe again, and the two
   changes below both need a refresh to reach existing entries.
3. **Status notes around numbers are dropped** (TODO bug, ref [11]).
   Amtelco operators wrote "NOT O/C", "AWAY TIL …", "DO NOT USE" and
   similar into the raw number text. The mapper keeps the number and
   loses the words.

## What exists today (checked in code)

### The mapper

- `DirectoryListingMapper::placeNumber()`
  (`app/Services/Import/Amtelco/DirectoryListingMapper.php:249-305`)
  picks the value with
  `number ?? number_local ?? handle ?? gateway_address ?? pager_id ?? paging_code ?? raw`
  (`:253`). `raw` is used only when every other key is missing. So any
  text in `raw` around a parsed number is lost. This is the status-note
  bug.
- The SMS macro is recognised by `isSmsMacro()` (`:156-159`), a regex
  on the closing `"….210S` (or `.210SX`). That value goes into
  `text_to` as a bare number (`:269`, `:281`). Any other `paging_code`
  becomes `code <macro>` in the field's parenthesised extras or in
  notes (`:271-273`, `:294`, `:304`).
- `spellOutSmsMacros()` (`:165-172`) rewrites SMS macros inside contact
  procedure text. Other macros in procedure text pass through
  unchanged.
- Fields the import writes: `BASE_FIELDS`
  (`DirectoryImporter.php:55-63`) plus `EXTRA_FIELDS`
  (`DirectoryListingMapper.php:25-34`). `amtelco_ref` is the match key
  (`:143`).
- People with no office line get their cell or home number promoted to
  `primary_phone` (`:84-93`). No check is made on what the raw text
  said about that number.

### The importer and the command

- `DirectoryImporter::importListings()` (`DirectoryImporter.php:197-251`):
  - In a **dry run it loads no existing entries** (`:201-213`, guarded
    by `! $dryRun`). So every listing is counted as "created". A dry
    run therefore can't show what a refresh would overwrite, which is
    the one thing the TODO asks the dry run to show.
  - Existing entries are loaded with `DirectoryEntry::query()`
    (`:203`). `DirectoryEntry` uses `SoftDeletes`
    (`app/Models/DirectoryEntry.php:27`), so **an entry an operator
    deleted is not found and gets re-created on the next run**, with
    or without `--refresh`.
  - On refresh, the import replaces every owned field as a set. Keys
    it doesn't produce this time are removed (`:231-237`). Keys it
    never owns are kept.
  - On every create or refresh it re-attaches the tags with
    `syncWithoutDetaching` (`:243-247`), so a kind tag an operator
    removed comes back on refresh.
  - For shared directories in a dry run, `$owner` is `[]`
    (`:140-142`, `:159-160`), so the dry run has no owner to look
    entries up under even when it does load them.
- `ImportAmtelcoDirectoriesCommand`
  (`app/Console/Commands/ImportAmtelcoDirectoriesCommand.php:20-24`)
  has `--dry-run`, `--refresh` and `--report`. The report
  (`DirectoryImportReport`, `app/Services/Import/Amtelco/DirectoryImportReport.php`)
  has per-directory counts of created, updated and unchanged.
  "Updated" means "rewritten", not "changed".
- **A refresh has already run in production.** That was the
  2026-11-24 run whose report is `data/import-reports/nci-directories-refresh.csv`,
  covering all 15,360 entries. Every entry whose mapped values changed
  in that run now has `updated_at > created_at`, with no operator
  involved. So the test the TODO suggests (`updated_at > created_at`
  means edited) is no longer reliable on production data. It would
  flag importer writes as operator edits.
- There's no per-field edit history for directory entries. `values` is
  one JSON column (`DirectoryEntry.php:33-46`), and no audit trait is
  attached.
- The raw directories are archived per client in
  `client_legacy_records` (section `directories`,
  `DirectoryImporter.php:326-340`). Shared directories (the provider
  index and unmatched directories) aren't archived. Their only source
  is the export file.
- Tests: `tests/Unit/Import/DirectoryListingMapperTest.php` and
  `tests/Feature/Import/AmtelcoDirectoryImportTest.php`, both with
  synthetic fixtures. The export itself
  (`data_files_inbound/nci_directories.json`) is git-ignored
  (`.gitignore:38`) and must stay out of the repo and out of fixtures.

### What the export contains (aggregate counts only)

These counts come from a read-only scan of the export on this host. No
names, numbers or addresses are reproduced here. The report command
below (D1) is how they should be regenerated.

- 17,741 contact methods, and 3,236 of them carry a `paging_code`.
  The quoted value is shown as `<v>`. Clipped openings
  (`SX."<v>"…`, `C."<v>"…`, `X."<v>"…`, `."<v>"…`) are folded into
  their family.

| Family (opening … closing) | Methods | Value shape | Typical labels | State |
|---|---|---|---|---|
| `.200S[X]"<v>".210S` / `.210SX` / `.210` | ~2,238 | 10–11 digits | TEXT CELL, TXT CELL, TEXT | **decoded** (SMS, confirmed 2026-11-24) |
| `.200SX"<v>".211S` | 7 | 10 digits | ALPHA, TEXT CELL | raw. **Not** matched by `isSmsMacro()`, and differs from SMS only in the closing code |
| `.150S"<v>".151S` | ~155 | alphanumeric handle | MISECURE TXT, MI SECURE, TXT CELL | raw |
| `.60R[X]"<v>".13S` / `.14S` | ~197 | email-shaped (`user@domain`). The export sometimes writes `.` as `+` | NONURGENT, NONURG EMAIL, WILL NOTDROP, SEND AS WELL, EMAIL | raw |
| `.60R[X]"<v>".138S`–`.145S`, `.135S`, `.16S` | ~182 | 10 digits | CONTACT PROC, TEXT CELL, HOME, NAME, PAGER # | raw. **Not in TODO.md's list** |
| `.NNS"<v>".0.1.1M` / `.1.1.1M` with NN ∈ {19, 21, 46, 50, 55, 71, 81, 88, 92, 97, 98} | ~437 | 10 digits (~324) or 7 digits (~110) | PAGER #, BEEPER #, ALPHA, BKUP PAGER, TXT CELL, SPECIALTY | raw |
| unparsed | ~20 | mixed | mixed | raw |

- Macros also appear inside contact procedure text: about 31 `.210S`
  (spelled out today), 22 `.145S`, 7 `.14S`, 2 `.144S` and 1 `.13S`
  (left raw).
- Status text in `raw` around a number, on methods without a macro:
  about 247. The commonest are:
  - "NOT O/C" and its variants (NOT OC, NEVER O/C, NOT O.C, NEVER ON
    CALL): ~120.
  - DO NOT USE / DO NOT CALL / DO NOT CONTACT: ~30.
  - N/A: ~15.
  - AWAY / AWAY TIL / ON LEAVE / OUT ON MEDICAL: ~12.
  - NOT IN USE / DOES NOT WORK: ~7.
  - NO LONGER CLIENT: ~6.
  - Non-status prefixes such as GIVE #, REFER TO, OFC, FX and ACCT#.

  [Plan 11](../is-parity/11-directory-status.md) counted "NOT O/C" at
  about 200 and AWAY/ON LEAVE at about 25 across all text. The figures
  differ because plan 11 also counted procedures and names.

## Related plans: what this plan owns and what it defers

- **[Plan 11, directory status](../is-parity/11-directory-status.md)**,
  "Migration and rollout" step 3, names the mapper fix ("keep any
  non-numeric prefix of `raw` … in the notes") as a prerequisite that
  "can ship on its own", followed by "re-run with the TODO.md
  safeguards". **This plan owns that prerequisite and the safeguards.**
  Plan 11 keeps the status importer
  (`orbital:import-amtelco-directory-status`), which turns AWAY TIL
  and ON LEAVE into status periods, and the status model.
- **"NOT O/C" as a per-number restriction** belongs to
  [plan 04](../is-parity/04-on-call-scheduling.md)'s contact methods
  (plan 11 says the same). Here the text is preserved and nothing more.
- **Pager transports.** [Plan 05](../is-parity/05-delivery-and-escalation.md)
  and [messaging-follow-ons.md §E](messaging-follow-ons.md) (WCTP)
  need to know which paging carrier a pager id belongs to. The
  `.NNS…M` family probably encodes that, which is why it matters.
  Plan 16 says pager macros must be decoded before WCTP replies are
  useful (`16-two-way-messaging.md`, rollout). This plan decodes;
  those plans deliver.
- **miSecure.** [Plan 17](../is-parity/17-secure-messaging.md) §10
  option 1 maps `.150S` to a secure-app contact method once it's
  decoded. This plan produces the decoded handle. Plan 17 decides what
  to do with it.

## Goal

- A read-only report lists every macro family with counts, value
  shapes and example labels. It's the input to a short list of
  questions for NCI.
- A reviewed, in-code catalogue records each family's meaning. Only
  families marked **confirmed**, with a named source, are ever
  decoded. Unconfirmed families produce exactly today's output.
- Status text around numbers is kept verbatim.
- `--refresh` changes only the fields an operator hasn't touched. It
  never resurrects deleted entries, and its dry run shows exactly what
  it would change and what it would leave alone.

## Design

### D1. Macro report command (read-only)

`orbital:amtelco-macro-report {path} {--from-db} {--examples=5} {--with-values} {--out=}`

- **`DialMacroParser::parse(string $code): ?ParsedMacro`.** It returns
  `{opening, modifier (X or none), value, closing, clipped: bool}` and
  tolerates the clipped openings seen in the export. `familyKey()` is
  `opening/closing-shape`, for example `200S/210S`, `60R/14S`,
  `81S/0.1.1M`. The same parser is used by the mapper (D2), so the
  report and the import can't disagree.
- **From the export** (default): for every contact method with a
  `paging_code`, and for every macro found in procedure text, it
  aggregates by family:
  - count;
  - contact-method `type` breakdown;
  - value shape (digit count, email-shaped, handle);
  - top labels;
  - how many sit in procedure text;
  - how many are flagged private;
  - catalogue status (D2).
- **`--from-db`** scans `directory_entries.values` for `code …`
  fragments instead. This shows what's actually stored in production
  now, including entries operators have since edited. It's read-only,
  using one cursor over all teams and shared directories.
- **Output:** a console table plus two CSVs under
  `data/import-reports/` (created `0600`, like the existing reports):
  `amtelco-macros-<time>.csv` (one row per family) and
  `amtelco-macro-examples-<time>.csv` (up to `--examples` rows per
  family: directory id, listing id, label, type, value shape). Values
  are left out unless `--with-values`, because the examples file is
  for sending questions to NCI, and they can look a listing up by id
  in their own system.
- It writes nothing to the database, so it's safe to run against
  production at any time.

### D2. Macro catalogue and confirmation workflow

**Catalogue in code, not in a table.** `App\Services\Import\Amtelco\DialMacroCatalog`
holds one entry per family:

```php
'200S/210S' => [
    'status'     => 'confirmed',          // unconfirmed | confirmed | rejected
    'meaning'    => 'sms',                // sms | email_to_sms | secure_app | pager_numeric | pager_alpha | other
    'field'      => 'text_to',            // where a decoded value goes
    'normalise'  => 'bare_number',        // bare_number | email | handle | pager_id
    'service'    => null,                 // e.g. paging carrier, when the meaning needs one
    'source'     => 'Platform owner, 2026-11-24 import decisions',
],
'150S/151S' => ['status' => 'unconfirmed', 'meaning' => null, ...],
```

A table with an admin UI was considered and rejected. Changes are
rare, each one changes production data on the next refresh, and they
should go through code review with the evidence written next to them.
The import is a CLI either way.

**Mapper behaviour.**

- `placeNumber()` asks the catalogue about the parsed macro.
  - `confirmed`: put the normalised value in the entry's `field` and
    drop the "(code …)" text.
  - `unconfirmed`, `rejected` or unknown: keep today's output byte for
    byte. A unit test pins that output for every unconfirmed family in
    the table above.
- `isSmsMacro()` becomes a thin wrapper over the catalogue, kept for
  its callers and tests.
- `spellOutSmsMacros()` generalises to `spellOutConfirmedMacros()`.
- The raw macro is always kept in a new hidden owned field,
  `amtelco_dial` (label "Amtelco dial macros", one line per method:
  `<label>: <macro>`). So decoding can be redone or undone later
  without the export file.
- If a confirmed meaning needs a field that doesn't exist yet (say
  "MiSecure" or "Pager service"), it's added to `EXTRA_FIELDS`, and
  `ensureFields()` creates it on the next run as it does today
  (`DirectoryImporter.php:277-313`).

**Confirmation workflow** (per family, no exceptions):

1. Run D1 and send NCI the questions below, with listing ids from the
   examples file.
2. Record the answer in a PR. Set `status => confirmed`, fill in
   `meaning`, `field` and `normalise`, and set `source` to who at NCI
   answered, when, and through what channel. A PR that confirms a
   family without a source fails review, and a test checks that
   `source` is non-empty for every confirmed entry.
3. Run the import as a dry run with `--refresh --only-fields=<fields
   that family touches>` (D3). The report shows how many entries each
   family changes and lists any that are skipped because they were
   edited.
4. Follow the production procedure in "Migration and rollout".
5. If NCI's answer is "that macro is obsolete", mark it `rejected`.
   Its output stays raw, and it's listed in the report as rejected so
   nobody asks again.

**Questions for NCI** (to send, adapted to the report's final counts):

1. `.150S"<handle>".151S`, about 155, labelled MISECURE TXT: is the
   quoted value a miSecure user name, and does this macro send a
   miSecure message? Are those clinicians still on miSecure?
2. `.60R"<address>".13S` and `.14S`, about 197, labelled NONURGENT /
   NONURG EMAIL / WILL NOTDROP: does `.60R` send an email? Is the
   address an email-to-SMS gateway or a plain mailbox? What's the
   difference between the `.13S` and `.14S` endings (urgent vs
   non-urgent? a different template?)? And does "WILL NOTDROP" mean
   anything operationally?
3. `.60R"<10 digits>".138S` through `.145S` (and `.135S`, `.16S`),
   about 182: the same `.60R` opening, but with a phone number and a
   different ending per entry. What do the ending numbers select? Is
   it a carrier gateway per mobile carrier?
4. `.NNS"<id>".0.1.1M` and `.1.1.1M`, about 437, for NN = 19, 21, 46,
   50, 55, 71, 81, 88, 92, 97, 98: is NN the paging service or
   carrier? If so, which carrier is each NN, and how does it reach
   them (WCTP, SNPP, TAP, dial-up)? What's the difference between
   `.0.1.1M` and `.1.1.1M`? Are the 7-digit ids pager ids or local
   numbers?
5. `.200SX"<number>".211S`, 7 of them: is this the SMS macro with a
   different template, or something else?
6. Can NCI export Infinity's own dial macro / paging service
   configuration? That would answer 1–5 directly, and it would be the
   evidence to cite in the catalogue.
7. For the status words: should a number marked DO NOT USE, NOT IN
   USE, DOES NOT WORK or NO LONGER … still be stored as a callable
   number, or only kept as a note?

### D3. Safe `--refresh`

**Per-field provenance.** A new table, `directory_entry_import_states`:

| column | notes |
|---|---|
| `directory_entry_id` | unique, FK, cascade on delete |
| `source` | `amtelco` |
| `source_ref` | the listing id (same as `values.amtelco_ref`). Indexed together with the owner, which also replaces loading every entry to read `values['amtelco_ref']` (`DirectoryImporter.php:203-212`) |
| `mapper_version` | `DirectoryListingMapper::VERSION`, a constant bumped whenever the mapper's output changes |
| `field_hashes` | `{field_key: sha256(normalised value) or null}` for every owned field at the import's last write. Hashes, not values, so this table doesn't become a second copy of the directory |
| `imported_at` | last write by the import |

**Refresh rule per owned field.** Let `cur` be the entry's value now,
`base` the stored hash, and `new` what the mapper produces now.

| Situation | Action |
|---|---|
| `hash(cur) == base` | untouched since import: write `new` (or remove the key if `new` is absent) |
| `hash(cur) != base` | edited in Orbital: **keep `cur`**, report `edited` |
| no `base` for this key, `cur` empty | a field the import didn't own before (for example a newly decoded one): write `new` |
| no `base` for this key, `cur` non-empty | unknown history: keep `cur`, report `no_baseline` |

After a write, `field_hashes` is updated for the keys written. A kept
field keeps its old hash, so it stays "edited" on later runs.

**Whole-entry rules.**

- Load entries `withTrashed()`. A soft-deleted entry is never
  re-created or updated. It's reported as `deleted_in_orbital`. This
  also applies to runs without `--refresh`, and it fixes today's
  resurrection bug.
- Optimistic write: update `where id = ? and updated_at = ?` (the
  value loaded). If zero rows are affected, skip and report
  `changed_during_run`.
- Tags are attached on create only. A refresh doesn't re-add tags.

**Command options.**

- `--refresh` now means the safe refresh above.
- `--only-fields=a,b` limits a refresh to those owned fields, for a
  macro family re-run.
- `--force-fields=a,b` overwrites those fields even when they were
  edited. It needs `--force-reason="…"`, which goes into the report,
  and an interactive confirmation (or `--yes`).
- There's no "overwrite everything" switch. Today's set-replace
  behaviour is the same as `--force-fields` on every owned field, and
  it should be that awkward to ask for.

**Dry run fixed.** It loads existing entries and baselines read-only.
For shared directories it looks the `SharedDirectory` up by name
without creating it. So the dry run reports real `created`,
`updated`, `unchanged`, `kept_edited`, `no_baseline` and
`deleted_in_orbital` counts. "Updated" now means at least one field
actually changed. `DirectoryImportReport` gains those columns, plus a
second file, `-conflicts.csv`, with one row per kept field: entry id,
owner, `amtelco_ref`, field key, reason, and the current and new
values only with `--with-values`.

**Baseline for the entries already in production.** None of the
15,360 rows has provenance, and `updated_at` can't tell an importer
write from an operator edit (see above).
`orbital:amtelco-directory-baseline {path} {--dry-run}` reconstructs
the baseline: for each existing entry matched by ref, it runs **the
mapper exactly as it was at the last production run** and, field by
field:

- if `cur` equals the mapped value, it records that hash (untouched);
- if it differs, it records no hash, so that field becomes
  `no_baseline` and is kept and reported on the next refresh.

This only works while the mapper's output is unchanged since that
run. So **the baseline must be taken before any mapper change in this
plan (D2 decoding, D4 status notes) is deployed.** The command refuses
to run unless `DirectoryListingMapper::VERSION` equals the version it
was released with. The command writes only to the new table and never
to `directory_entries`.

### D4. Keep status text around numbers (TODO bug [11])

- In `placeNumber()`, the annotation is `raw` with the chosen value
  removed. That means the number in any formatting (digits, dashes,
  dots, brackets, spaces, a leading 1 or +1), the macro and the
  extension. What's left is trimmed, and repeated whitespace is
  collapsed. Punctuation such as "!!!!" is kept, because operators
  wrote it on purpose. If nothing alphabetic remains, there's no
  annotation.
- Where it goes:
  - Phone fields hold only the number (`:289-292`), so the annotation
    goes on a notes line: `"{label}: {annotation}"`.
  - Other fields get it in the parenthesised extras.
  - Notes-only placements append it.

  It's verbatim, with no interpretation. "NOT O/C" stays "NOT O/C".
- Proposed, and subject to the open question below: if the annotation
  starts with an explicit negative (DO NOT USE, DO NOT CALL, DO NOT
  CONTACT, NOT IN USE, DOES NOT WORK, NO LONGER), the number isn't
  placed in a phone field at all. It goes only to notes, and it's
  never promoted to `primary_phone` by the fallback at `:84-93`.
  That's reading plain English, not decoding a macro, but it changes
  what an operator dials, so it waits for an explicit yes.
- Plan 11's status importer reads the export, not these notes, so
  keeping the text as free text doesn't make plan 11's job harder.

## Edge cases

- **An operator edited `notes`**, which operators often append to.
  The status-note fix then doesn't reach that entry. It appears in the
  conflicts CSV as `edited` on `notes`, so a person can add the
  annotation by hand. A count of these is part of the dry-run sign-off.
- **An operator cleared a field the import fills.** `hash(null)`
  differs from the base, so it's treated as edited and kept empty.
  Deleting a value is an edit.
- **An entry moved between clients by an admin**, or a shared
  directory attached elsewhere: matching is by owner plus ref, as
  today. A moved entry looks new to its old owner and could be
  re-created there. The dry run reports refs that exist under a
  different owner, and they're skipped unless `--force-fields` is
  given.
- **Listing gone from a newer export:** left alone and reported as
  `missing_from_export`. The import never deletes.
- **One listing with two macros of different families:** each method
  is decided independently. One can be decoded while the other stays
  raw on the same entry.
- **A confirmed family whose value fails normalisation** (an "email"
  with no `@`, a 9-digit "number"): kept raw with its code, and counted
  in the report as `confirmed_but_unparsed`.
- **The `+`-for-`.` encoding in email-shaped values:** that's
  normalisation, so it's applied only once the family is confirmed as
  email. The catalogue's `email` normaliser does it, and a unit test
  covers it.
- **Mapper version mismatch during baseline:** the command refuses
  (D3), so the baseline can't be built from the wrong mapper.

## Migration and rollout

Production rules, from the 2026-11-24 import decisions: the k8s
`orbital` namespace on this host is production. Every write to it is
preceded by a database backup (`data/backups/orbital-pre-amtelco-*.dump`)
and a dry run whose report goes into `data/import-reports/`, and it
waits for the platform owner's sign-off on that report. That applies
to every numbered step below that writes. Reports and examples stay on
the host, are never committed and never go into plans or tickets.

1. **Ship D3's table, the baseline command, the dry-run fixes and the
   `withTrashed` fix**, with **no change to mapper output**. Run the
   baseline dry run, review the counts, back up, sign off, then run
   the baseline for real. From here on, `--refresh` is safe.
2. **Ship D1** (read-only). Run it on the export and with
   `--from-db`. Send NCI the questions.
3. **Ship D4 (status notes)** with a `VERSION` bump. Dry-run
   `--refresh --only-fields=notes,primary_phone,cell_phone,home_phone,pager,text_to`,
   review the conflicts, back up, sign off, run. This closes TODO bug
   [11] and plan 11's prerequisite.
4. **As NCI answers**, one PR per confirmed family (D2), each followed
   by its own `--only-fields` dry run → sign-off → run.
5. Update TODO.md: tick the bug and "make `--refresh` safe", and
   rewrite "decode the remaining macros" with the families still open.

## Tests

All fixtures are synthetic: made-up names, 555 numbers and
`example.test` addresses. Nothing comes from the export.

- **Parser:** every family shape in the table, including the clipped
  openings, `.210` without `S`, and `.211S` not being SMS.
- **Catalogue:**
  - an unconfirmed family produces today's output byte for byte (a
    golden test per family);
  - a confirmed family moves its value to its field and keeps the raw
    macro in `amtelco_dial`;
  - every confirmed entry has a non-empty `source`.
- **D4:**
  - an annotation is kept for each placement path (phone field, other
    field, notes-only);
  - formatting variants of the number are all stripped;
  - text that's only punctuation gives no annotation;
  - the negative-prefix rule is behind its flag.
- **D3** (feature test against an in-memory import):
  - an untouched field is refreshed;
  - an edited field is kept and reported;
  - a cleared field is kept;
  - a new owned field is filled;
  - a soft-deleted entry isn't resurrected, with and without
    `--refresh`;
  - a concurrent edit (bumping `updated_at` mid-run) is skipped;
  - tags aren't re-added;
  - `--only-fields` touches nothing else;
  - `--force-fields` needs a reason;
  - the dry run's counts match the real run's;
  - the dry run writes nothing (query count / DB unchanged).
- **Baseline:** matching fields get hashes and differing fields get
  none, and the command refuses on a version mismatch.
- **Report command:** counts and families from a synthetic export, and
  no values in the output without `--with-values`.

## Open questions

1. The seven NCI questions in D2. Nothing in D2 ships as decoding
   until they're answered.
2. The negative-prefix rule in D4: should a DO NOT USE / NOT IN USE
   number stay out of the phone fields? The proposal is yes, but it
   needs the platform owner's decision, and ideally NCI's (question 7).
3. Should the client import (`orbital:import-amtelco --refresh`, which
   overwrites name, greeting, operator note and passcode) get the same
   provenance treatment? It has the same exposure on fewer fields.
   It's not in the TODO, but it's the same pattern and could reuse the
   table with `source = amtelco_client`.
4. Once plan 04's contact methods exist, decoded pagers and SMS
   numbers should move out of flat `values` fields into them. Should
   this plan's `field` targets be chosen with that migration in mind
   (for example a separate `pager_service` field now)?

## Size

| Piece | Estimate |
|---|---|
| D1 parser + report command | 1.5 d |
| D2 catalogue, mapper refactor, golden tests | 2 d |
| D3 provenance table, safe refresh, dry-run and `withTrashed` fixes, report changes | 3–4 d |
| D3 baseline command | 1 d |
| D4 status notes | 1 d |
| Production runs (dry run, review, sign-off) per step | ~0.5 d each, plus NCI's turnaround |

That's about 1.5–2 weeks of engineering. The time NCI takes to answer
isn't included.
