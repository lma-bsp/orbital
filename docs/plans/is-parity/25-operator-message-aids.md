# Plan 25: Operator message aids — validation, read-back, spelling, shortcuts, AI clean-up

**Status:** proposed · part of [IS parity](README.md) · expands survey item S in [07](07-additional-features.md) · depends on 03 (message templates); builds on 01 (typed slots / engine) and 02 (runner UI); ships a first phase on today's two message forms without them

## Problem

### What IS does

An IS operator types messages under time pressure, often while the
caller is still talking. The IS message screen helps in a few ways:
script fields carry an input mask and type (phone, date, numeric),
required fields stop the script from going on, free-text fields can be
spell-checked (a medical dictionary is a common add-on for medical
accounts), and NCI's scripts usually include a "read the message back
to the caller" step before the message is filed. Operators also rely on
standard abbreviations and canned phrases ("PCB" → "Please call back")
that the account's instructions spell out.

*(This list comes from Amtelco's published feature summary — see the
07 survey's sources — and the way answering services usually build IS
scripts. Open question 1 asks NCI which of these their scripts use.)*

### What Orbital has today (checked in code)

**Two message forms, both hard-coded to four fields:**

- Single-account Workspace: Livewire properties `msgCallerName`,
  `msgCallerPhone`, `msgReason`, `msgUrgency`
  (`app/Filament/Operator/Pages/Workspace.php:57-66`). The form is a
  plain Blade form (`resources/views/filament/operator/pages/workspace.blade.php:10-100`).
- Multi-call desk: `$draft` array with the same four keys
  (`app/Livewire/MultiCallWorkspace.php:37-38`, `loadDraft()` at
  `:325-335`), saved to `OperatorCall.draft` on blur through
  `MultiCallDesk::saveDraft()` (`app/Services/Operator/MultiCallDesk.php:167-179`).
  View: `resources/views/livewire/multi-call-workspace.blade.php:245-304`.

**Validation:**

- Workspace checks only `msgCallerName` required/max 255 and `msgReason`
  required (`Workspace.php:185-188`). The phone is not validated at all,
  and the reason has no length limit.
- Multi-call checks name, reason, and `caller_phone` as
  `nullable|string|max:64` (`MultiCallWorkspace.php:204-211`). Any text
  is accepted as a phone number.
- The Workspace inputs use HTML `required` (`workspace.blade.php:22`,
  `:51`), so the browser shows its own tooltip while the server shows
  inline errors (`:25-27`, `:56-58`). The two kinds of error look
  different.
- The phone input is only `type="tel"` (`workspace.blade.php:34-38`,
  `multi-call-workspace.blade.php:270`). There is no mask, no formatting
  and no normalisation. Messages store whatever was typed.
- `App\Support\PhoneNumber::toE164()` (`app/Support/PhoneNumber.php:18-39`)
  is NANP-only. Its fall-through at `:38` turns a 7-digit local number
  into `+5551234`, which is not a valid E.164 number. It is used for
  caller ID, not for message fields.

**Typed slots exist but nothing enforces them:**

- `ClientSlot::TYPES` (`app/Models/ClientSlot.php:35-48`) lists `phone`,
  `email`, `date`, `datetime`, `address`, `masked` and others. The class
  docblock says the type governs "the validation the runtime applies
  before persisting a value" (`:21-24`). No such validation exists:
  `CallSessionController::captureField()` takes any string up to 4000
  characters (`app/Http/Controllers/Api/CallSessionController.php:67-75`).
- `ClientSlot` has no columns for format, constraints or required
  (`$fillable` at `:50-57`).
- The per-type rules live only as step parameters in the goal catalogue
  (`IntakeGoal.data_fields`, cast at `app/Models/IntakeGoal.php:53`).
  `gather_date` has `min_date`/`max_date`
  (`database/seeders/IntakeGoalLibrarySeeder.php:229-230`).
  `gather_masked` has `pattern` and `confirm_last` (`:293-294`).
  `gather_address` has `require_postal`/`default_country` (`:358-359`).
  Every `gather_*` step has `required`. These are prompt text for the
  LLM. Nothing executes them. (Plan 01's engine is meant to.)
- `data_fields` describe the *step's configuration form*. They do not
  describe the caller-facing input. The editor renders them through
  `RendersStepParamsForm` (`app/Filament/Resources/Concerns/RendersStepParamsForm.php:45-89`).
  This plan keeps that split: rules a *step* sets stay in `step_params`,
  and rules that belong to the *value* move to the slot.

**Read-back exists only as words for the AI:** `gather_text` says
"Read the answer back to confirm" (`IntakeGoalLibrarySeeder.php:132`),
`gather_phone` says "Read each digit group back" (`:159`), and
`save_message` says "Read back the included fields" (`:429`). The
operator forms have no read-back step. Plan 02's seeded "Standard
message" orchestration puts *read back after save*
(02, "Defaults and templates"). This plan needs it *before* save.

**Spelling:** no view sets `spellcheck` or `lang` on a message field.
The only explicit `spellcheck` attribute in `resources/views` is
`spellcheck="false"` on the API-token input
(`resources/views/api/api-token-manager.blade.php:109`). Operators get
the browser's default checker: the default dictionary only, no medical
terms, no client names, and behaviour that differs between browsers for
single-line inputs. There is no spelling package in `composer.json` or
`package.json`.

**Shortcuts and abbreviations:** none. No model, table or JS for them.

**Keyboard:** the message forms have no key bindings. The only global
key handler belongs to the softphone (`resources/js/softphone.js:94`).
`_onKeyDown` (`:135-175`) takes digits, `*`, `#` and Backspace whenever
the softphone is expanded or on a call and focus is *not* in an input
(`:139-166`). Any operator shortcut has to work alongside it. Plan 02's
"number keys pick choices" clashes with it directly.

**AI providers on the PHP side:**

- `config/orbital.php:150-171` lists `anthropic`, `openai` (with
  `base_url`), `openrouter` and `elevenlabs`, with no Ollama entry.
  `default_llm_provider` is not read anywhere in `app/`. Only
  `default_llm_model` is read (`app/Jobs/ProcessMessageWithAgentJob.php:305`).
- Every PHP LLM call is a hand-built Anthropic HTTP request:
  `ChatService` (`app/Services/Chat/ChatService.php:77-81`, with the
  model hard-coded), `ProcessMessageWithAgentJob:304`,
  `ProcessEmailWithAgentJob:189` and `SmartIngestClient:31`. There is
  no shared client interface.
- Ollama is used only for **embeddings**: `services.ollama.url`
  (`config/services.php:94-96`), `OllamaEmbedder` (`app/Services/Knowledge/OllamaEmbedder.php:24-45`,
  `/api/embeddings`) and `OllamaEmbeddingProvider`. The Helm chart runs
  Ollama by default and pulls only `nomic-embed-text`
  (`helm/orbital/values.yaml:355-377`). The values comment already
  mentions "(and inference)". Platform Settings has the Ollama URL
  (`app/Services/Settings/SettingsRegistry.php:1012-1018`).
- Persona LLM choices are `anthropic` / `openai` / `openrouter` /
  `local` (`app/Filament/Resources/ClientResource/Pages/ManageClientPersonas.php:52-58`).
  That setting belongs to the Python worker and does not help PHP.

**PHI handling:** no client is flagged as handling PHI. A search of
`app/` and `config/` for `hipaa`/`phi`/`baa` finds nothing. Telescope is
on by default (`config/telescope.php:19`). Outside `local` it keeps only
failed requests, failed jobs and exceptions
(`app/Providers/TelescopeServiceProvider.php:24-28`). A failed Livewire
save would therefore store the draft message's text in Telescope.

**Tenant scoping pitfall:** `BelongsToTeam`'s global scope filters
operators by *their own* `currentTeam`. Operators hold platform roles
and are not super-admins (`app/Models/User.php:184-256`). Code that
works across clients therefore bypasses the scope and filters by the
call's client explicitly: `ClientCallBrief` (`app/Services/Operator/ClientCallBrief.php:27-30`)
and `MultiCallWorkspace::render()` (`:290`). Every new per-client lookup
in this plan must follow that pattern.

## Goal

Every message an operator files, on either current form now and the
plan 02 runner later, should be:

1. **Typed and formatted**: phones, emails, dates/DOBs, addresses and
   masked values are parsed, normalised and shown the same way
   everywhere. The rules come from one registry that the plan 01
   engine, the AI `captureField` path and the operator UI all share.
2. **Complete**: required fields block save. Missing information
   becomes either an explicit "caller declined" value or a partial
   message under the existing `PartialMessagePolicy`.
3. **Confirmed**: a read-back pane shows the message as it will be
   delivered, with spoken hints, and records whether the caller
   confirmed it.
4. **Spelled right**, using the client's own vocabulary (staff names,
   streets) and a medical word list where the client needs one.
5. **Fast to type**: platform-wide and per-client text shortcuts, plus
   keyboard control of the whole form.
6. **Optionally polished by AI**: the operator can ask for a clean-up
   suggestion and accepts or dismisses it. The AI never changes a
   message on its own. The provider is chosen per platform and per
   client, with self-hosted Ollama as the default and hosted providers
   allowed for PHI only where a BAA is recorded.
7. **Flagged when risky**: profanity, card numbers and SSNs in free
   text, and PHI headed for a plain channel are warned about or
   blocked, and every override is recorded.

Out of scope: secure delivery itself (17), message revisions after
save (03), QA review of operator typing (22).

## Design

### 1. Slot types: one registry for validation and formatting

New `app/Services/Script/SlotTypes/`. It goes under plan 01's namespace
because it *is* the "validate inputs against slot types" piece of
`ScriptEngine::submit()`. It is built first, so the current forms and
`captureField` can use it before the engine exists.

```php
interface SlotType {
    public function key(): string;                       // 'phone', 'date', …
    public function parse(?string $raw, SlotRules $rules): SlotValue;
}

final class SlotValue {
    public mixed $value;          // canonical stored value (E.164, ISO date, address array…)
    public ?string $display;      // "(555) 123-4567 x12", "03/04/1962 (age 64)"
    public ?string $spoken;       // "five five five, one two three, four five six seven"
    /** @var list<Issue> */ public array $errors;    // block save / advance
    /** @var list<Issue> */ public array $warnings;  // shown at read-back, can be overridden
}
```

`SlotRules` merges, lowest to highest precedence: type defaults, then
`client_slots.constraints`, then the step's `step_params` (`min_date`,
`pattern`, `require_postal`, …), then the plan 03 template field
(`required`).

| Type | Parses | Canonical | Errors | Warnings |
|---|---|---|---|---|
| `phone` | `555.123.4567`, `(555) 1234567 ext 12`, `+44…`, 7 digits if `constraints.default_area_code` set | E.164 + optional `ext` | < 10 NANP digits with no default area code; letters (other than `x`/`ext`) | NANP area code not assignable (N11, 555-01xx fictional); same as caller ID (that's fine, but say so) |
| `email` | trims; lowercases domain | string | no `@`, no TLD | likely typo domain (`gmial.com` → `gmail.com`, from a small list) |
| `date` | `3/4/62`, `03041962`, `March 4 1962`, `tomorrow`; US order by default | ISO `Y-m-d` | unparseable; outside `min_date`/`max_date` | day ≤ 12 with `constraints.order = dmy` clients (ambiguous) |
| `date` + `format: dob` | as above; 2-digit year resolves into the past | ISO | in future; age > 120 | age < 18 when `constraints.adult_only` |
| `datetime`, `duration` | as above, in the client's timezone (`Team::displayTimezone()`) | ISO 8601 / seconds | — | time in the past for "callback at" slots |
| `number` | digits, decimals | number | non-numeric; outside min/max | — |
| `address` | structured: line1, unit, city, state, ZIP(+4), country | JSON | missing line1; missing ZIP when `require_postal` | ZIP doesn't match state (bundled ZIP→state table); street suffix normalised per USPS Pub. 28 (`street` → `St`) |
| `masked` | applies `pattern` (9/A/*) | raw string | doesn't fit the mask | — (read-back obeys `confirm_last`) |
| `choice`, `boolean` | label or value | value | not in `choices` | — |
| `string` | trims, collapses whitespace | string | over `constraints.max` (default 4000, matching `captureField`) | — |
| `string` + `format: proper_name` | fixes ALL CAPS / all lowercase only (keeps `McDonald`, `de la Cruz`) | string | — | — |
| `credit_card` | Luhn | `{last4, exp}` only; the PAN is never stored | Luhn fail | — |

Phone parsing uses `giggsey/libphonenumber-for-php-lite`, a new
composer dependency. `App\Support\PhoneNumber::toE164()` becomes a thin
wrapper over it, which fixes the `+5551234` fall-through
(`PhoneNumber.php:38`). Its callers (`CallSessionController::context`,
handoff) keep their signatures.

**Client-side mirror:** formatting only, so the operator sees
`(555) 123-4567` as they type. It uses Alpine's `x-mask`, which ships
with Livewire (Filament's `TextInput::mask()` uses the same directive).
The server value is always authoritative: the browser never decides
validity.

**Where it's wired:**

- `CallSessionController::captureField` validates against the team's
  slot type when one exists, stores the canonical value and returns the
  issues to the worker. The AI can then re-ask, and plan 01 later
  carries this over as-is.
- The legacy forms: Workspace and MultiCallWorkspace replace their
  `validate([...])` arrays with a `MessageFieldSet` built for the
  client. Until plan 03 lands, that set is the four fixed fields with
  types `string(proper_name)`, `phone`, `string` and `choice`.
- Plan 02 `ScriptRunner`: renders inputs from `SlotValue`.

### 2. Required fields and "caller declined"

- **Source of truth:** the plan 03 template field `required`. Before 03
  ships, it is the fixed rules (name and reason required). A step's
  `required` param controls only whether the runner can *advance* past
  that step. It never controls saving.
- **Three levels per field:** `required` (blocks save), `recommended`
  (warning at read-back, can be overridden) and `optional`. Recommended
  is new and is stored on the template field. Callback number is the
  typical use.
- **"Caller declined / unknown"** chip on a field. It shows only when
  `client_slots.allow_declined` is true or the template field allows
  it. It stores `{"declined": true, "reason": "refused"|"unknown"}` in
  `messages.fields`, which satisfies `required`. Delivery templates
  render it as "Caller declined to give". IS operators handle this by
  typing "REFUSED" into the field. A typed marker can be reported on
  and can't be mistaken for a real value.
- **Partial save** is unchanged (`PartialMessagePolicy`, `Workspace.php:235-289`,
  `MultiCallDesk::savePartialMessage`). It skips read-back and the
  required checks, because it already marks the message incomplete.
- The HTML `required` attributes are removed so every error looks the
  same: inline and server-side. Errors are announced to screen readers
  through `aria-describedby` and `aria-invalid`.

### 3. Read-back

`App\Services\MessageAids\ReadBackRenderer::render(MessageFieldSet, values): ReadBack`
returns the fields in template order, each with:

- label, display value, `spoken` hint (from `SlotValue`)
- for names, an optional letter-by-letter spelling with a NATO
  phonetic toggle ("S as in Sierra")
- for `masked`, only the last `confirm_last` characters (the existing
  step param, `IntakeGoalLibrarySeeder.php:294`)
- outstanding warnings from slot types, content scan (§6) and spelling
  (§4), and the delivery summary line ("Will be sent by SMS to
  Dr. Patel"), once plan 05 exists

**Policy per client** (`teams.message_aids.read_back`):

- `off`: no pane.
- `offer`: this is the default. Save files the message immediately. A
  separate "Read back" control (Alt+R) opens the pane.
- `require`: Save opens the pane. Filing needs one of three outcomes:
  - **Caller confirmed** (Enter) → `confirmed`
  - **Caller hung up / couldn't wait** → `unconfirmed`, saved and
    flagged
  - **Edit** (Esc) → back to the form

Corrections made after Edit reopen the pane when Save is pressed again.

**Outcome recorded** in `messages.aids.read_back`: `{status, at, by}`.
With plan 01 it also becomes `script_events`
(`read_back_presented`, `read_back_confirmed`).

**AI parity:** plan 01's `save_message` step handler gives the voice
agent `ReadBack::spokenScript()`, so the AI reads back from the same
rendering the operator sees. The handler gets `read_back_confirmed`
through the same `submit_step` call.

**Plan 02 amendment** (described here; plan 02 is not edited): the
seeded "Standard message" orchestration should run *read back → save*,
and the read-back is the interactive stage of the `save_message`
handler rather than a separate step.

**Multi-call:** read-back state lives in the call's draft
(`OperatorCall.draft.read_back_open`), so switching tabs and coming
back reopens the pane for that call only.

### 4. Spelling

There are two layers, and neither sends text off the operator's machine.

**a. Native spellcheck as the baseline.** Every free-text field gets
`spellcheck="true" lang="en-US"`. The `lang` value comes from the
client and can be set per field, for example for Spanish-language
clients. Name, phone, email, address and masked fields get
`spellcheck="false"`. Constant red underlines on surnames teach
operators to ignore them.

**b. Orbital spell pass** runs in the read-back pane and on demand
(Alt+K). It lists unknown words with suggestions. A click replaces the
word, "Ignore" skips it for this message, and "Add to client
dictionary" needs a permission. It uses
[nspell](https://github.com/wooorm/nspell) (Hunspell-compatible, MIT)
in a Web Worker, loaded by Vite from our own origin. There is no CDN
and no network call per word.

**Dictionaries**, merged per call:

- `en_US`: `dictionary-en` (SCOWL-derived, permissive licence).
- `medical`: optional per client. The licence needs checking (open
  question 2). The fallback is a word list Orbital builds from public
  sources: FDA NDC product and ingredient names (public domain) plus
  RxNorm prescribable content (free; no UMLS licence needed for that
  subset). It ships as a generated `.dic` under `resources/dictionaries/`
  and is rebuilt by `php artisan orbital:build-medical-dictionary`.
- **Client lexicon**: built by `SpellingLexicon::for(Team $client)`
  from:
  - directory entry names for the client and its bound shared
    directories (`DirectoryEntry`)
  - the client name and account aliases
  - `spelling_words` rows for the client
  - platform-global `spelling_words` rows

  It is cached under the key `lexicon:{team_id}:{version}`, and
  writes to those sources bump the version. It reaches the browser as a
  word list in the component payload. It holds names that are already
  on the operator's screen (directory, call brief), so it reveals
  nothing new.
- Medical words are **never** auto-corrected or suggested as
  replacements for a non-medical word. They only stop a correctly
  spelled drug name from being flagged.

Spell results are advisory. Unresolved words are counted in
`messages.aids.spelling_unresolved`, which plan 22 QA can sample, and
they never block a save.

### 5. Text shortcuts and abbreviation expansion

**Table `text_shortcuts`:**

| Column | Notes |
|---|---|
| `team_id` | Nullable. Null means platform-global, edited in the admin panel only. |
| `trigger` | `;pcb`. The default sigil is `;`. A bare-word trigger (`pcb`) needs `bare = true` and is refused if it's a dictionary word. |
| `expansion` | Text with a small set of `{{ }}` variables: `caller_name`, `caller_phone`, `client_name`, `now_time`, `now_date`, `operator_initials`. These are resolved in the browser from the draft and call context. Plan 03's `TemplateEvaluator` is *not* used here, because expansion must be instant and client-side. |
| `applies_to` | `all_free_text` or a list of slot keys |
| `mode` | `auto` (expand on space or punctuation after the trigger) or `manual` (Tab / Ctrl+Space) |
| `description` | Shown in the picker |
| `created_by`, `updated_by`, timestamps, soft deletes | |

Unique on (`team_id`, `trigger`). A client row overrides a global row
with the same trigger.

**Behaviour** (Alpine directive `x-shortcuts`, `resources/js/operator/shortcuts.js`):

- Expansion is one undo step. Backspace straight after an expansion
  restores the trigger text.
- Case follows the trigger: `;PCB` → upper-case expansion. Expansions
  never run inside phone, email, masked or address fields.
- **Ctrl+/** opens a filtered picker of the shortcuts that apply here,
  for operators who don't remember a trigger.
- Medical abbreviations are not shipped as global shortcuts. Ambiguous
  ones on the Joint Commission "Do Not Use" list (`U`, `IU`, `QD`,
  `QOD`, `MS`, `MSO4`, `MgSO4`) are refused as triggers whenever the
  client handles PHI.
- Only `messages.aids.shortcuts_used` (a count) is recorded, not which
  shortcut was used. Expanded text is part of the message anyway.

**Import:** if NCI's account instructions contain abbreviation lists
(Info Pages, `ClientLegacyRecord`), a one-off review screen proposes
them as client shortcuts. Nothing is created without someone accepting
it.

### 6. Content warnings: profanity, card data, PHI

**Table `content_rules`:**

| Column | Notes |
|---|---|
| `team_id` | Nullable; null means global |
| `kind` | `word_list` or `regex` or `detector` |
| `detector` | One of `luhn_card`, `ssn`, `dob_in_summary`, `phi_to_plain_channel` |
| `pattern` / `words` | |
| `severity` | `info`, `warn` or `block` |
| `applies_to` | |
| `operator_message` | |
| `enabled` | |

`ContentScanner::scan(MessageFieldSet, values, Team): list<Issue>` runs
server-side in two places:

- on read-back, or on Save when read-back is off
- inside `saveMessage` itself, which is authoritative

**Seeded global rules:**

- **Card number in free text** (`luhn_card`), **block**. Cards belong
  in a `credit_card` slot, which stores only last4/exp. The operator
  must remove the number. Nothing can override this.
- **SSN-like** pattern next to "social", "SSN" or "ss#", **warn**.
- **Profanity**, **warn**, from a short editable list. Override choices
  are "Caller's own words": the matched word is wrapped in quotes and
  marked as a quote in `aids`, or "Remove". It is never auto-masked,
  because a threat or an abusive caller may be exactly what the client
  needs to see.
- **PHI to a plain channel**, **warn**, only for `handles_phi` clients
  once plan 05/17 exist. It fires when the delivery body (plan 03
  per-channel template) for an unencrypted SMS/email contains a field
  flagged `phi` on the template, such as DOB or MRN. It points to the
  plan 17 secure link. This plan only raises the warning. Routing is
  plan 05/17's job.

**Override:** a `warn` needs a click, which the operator can do with
the `message_aids.override_warning` permission (given to operators by
default). The override is recorded as
`messages.aids.overrides[] = {rule_id, by, at}`. A `block` cannot be
overridden by anyone.

### 7. AI "clean up" suggestion

**Shared LLM client.** This is new: `app/Services/Ai/LlmClient`, with
`complete(LlmRequest): LlmResponse`. Drivers:

- `OllamaChatClient`: `POST {services.ollama.url}/api/chat`,
  `format: json`, `stream: false`
- `AnthropicClient`: Messages API
- `OpenAiCompatibleClient`: OpenAI, OpenRouter, vLLM and LM Studio
  through `base_url`; `config/orbital.php` already has
  `providers.openai.base_url`

Config moves to `orbital.ai.providers.ollama` (`url` from
`services.ollama.url`, `chat_model`). Moving `ChatService` and the
other three hard-coded call sites onto this client is a separate
follow-up, not part of this plan.

**Settings:**

- Platform setting `orbital.message_aids.cleanup`:
  - `provider` (`off` | `ollama` | `anthropic` | `openai` |
    `openrouter`), default `ollama` when Ollama is enabled
  - `model`. Default `qwen2.5:7b-instruct`, added to the Helm
    `ollama.models` list (`helm/orbital/values.yaml:376-377`) when
    clean-up is enabled.
  - `timeout_seconds` (default 8)
  - `hosted_phi_allowed` (bool, default false). Turned on only when the
    platform operator records a BAA with the hosted provider. The
    Settings helper text says so.
- Per client (`teams.message_aids.ai_cleanup`): `inherit` | `off` |
  `on`.
- New boolean column `teams.handles_phi`, shared with plan 17.

**Resolution rule:** if the client `handles_phi` and the resolved
provider is hosted and `hosted_phi_allowed` is false, clean-up is
**unavailable** for that client and the button says why. There is no
silent fallback to another hosted provider. Falling back from a hosted
provider to Ollama is fine.

**Flow:**

1. The operator presses "Suggest clean-up" (Alt+C) on a free-text
   field, or the read-back pane offers it for the reason/notes fields
   when the client has it on.
2. A Livewire action dispatches `SuggestMessageCleanup` on a Horizon
   `ai-aids` queue. Waiting synchronously would block the operator's
   component for the whole LLM call. The job carries `team_id`,
   `operator_call_id` or the session key, the field key, and a sha256
   of the field text.
3. The prompt is fixed and versioned in `resources/prompts/message-cleanup.v1.txt`:
   - fix spelling, grammar and punctuation, and expand *non-medical*
     shorthand
   - keep every name, number, drug name and medical abbreviation
     exactly as written
   - do not add, infer or remove facts
   - keep negations
   - return JSON `{text, changes:[{from,to}]}`

   Context is limited to the field text, the client's lexicon words
   and the field label. No caller ID, no other fields and no client
   notes are sent.
4. **`CleanupGuard`** rejects the suggestion (the operator sees "No
   suggestion") if any of these hold:
   - the multiset of digit sequences differs
   - negation tokens (`no`, `not`, `denies`, `without`, `never`, `n't`)
     differ in count
   - a capitalised token in the input or a lexicon word is missing from
     the output
   - length changed by more than ±40%
   - it's empty or invalid JSON
5. The component polls (`wire:poll.750ms` while pending) and shows a
   word-level diff with **Accept** (Alt+A) and **Dismiss** (Esc). If
   the field's hash has changed since the request because the operator
   kept typing, the suggestion is dropped.
6. **Accept** replaces the text and records
   `messages.aids.ai_cleanup = {provider, model, prompt_version,
   original_text, accepted_at, by}`. The original stays with the
   message so a supervisor can see what the operator actually typed.
   **Dismiss** records `{dismissed_at}` only.

**PHI and logging:**

- The job never logs request or response bodies.
- `ClientRequestWatcher` is told to ignore the LLM hosts
  (`config/telescope.php:147-150`, `ignore_hosts`).
- Failed jobs keep only IDs, because the payload holds no text: the job
  re-reads the draft by ID.
- **`ai_aid_requests`** audit table: `team_id`, `user_id`, `provider`,
  `model`, `prompt_version`, `latency_ms`, `outcome` (`suggested` /
  `guard_rejected` / `timeout` / `error`), token counts. No text. It
  answers "which provider saw client X's data, and when" and supports
  cost reporting (plan 09).

**Trial desk:** works in trial mode, with `ai_aid_requests.is_trial`
recorded.

### 8. Keyboard shortcuts

A small registry (`resources/js/operator/keys.js`, an Alpine plugin)
owns every message-form binding and shows a cheat sheet on `?` (Shift+/)
when focus is outside a text field.

| Keys | Action |
|---|---|
| Alt+S | Save (opens read-back when the policy is `require`) |
| Enter (in read-back) | Caller confirmed |
| Esc | Close read-back / dismiss suggestion / leave picker |
| Alt+R | Open read-back |
| Alt+P | Save partial (only when the client keeps partials) |
| Alt+U | Toggle urgency |
| Alt+K | Spell pass |
| Alt+C / Alt+A | Suggest clean-up / accept it |
| Alt+Q | Mark focused field "caller declined" |
| Alt+1…9 | Focus field *n* |
| Alt+[ / Alt+] | Previous / next call tab (multi-call) |
| Ctrl+/ | Shortcut picker |
| Tab or Ctrl+Space | Expand a manual shortcut |

**Rules:**

- **Avoid browser and OS keys:** Alt+D, Ctrl+L (address bar), Alt+←/→
  (history), Ctrl+K/Cmd+K (Filament global search), Ctrl+W/T/N, F5,
  and Windows Firefox menu accelerators Alt+F/E/V/H/B/T.
- On macOS, Option+letter produces characters, so the handler matches
  on `event.code` and calls `preventDefault()`.
- **Softphone coexistence:** the registry handles only modified keys
  and keys typed in our fields. The softphone's bare-digit capture
  (`softphone.js:155-166`) keeps working outside fields. Plan 02's
  "number keys pick choices" has to be bound as Alt+digit, or only
  while a choice group has focus. This plan records that constraint
  for 02.
- **Configurable later:** the map lives in `config/orbital.php` under
  `operator.keys`. Per-user rebinding is open question 6.

### 9. Shared UI pieces

Shared pieces, so the two current forms and the future runner don't
fork:

- `<x-operator.message-field :field :value :issues>`: a Blade component
  rendering input or textarea with `spellcheck`, `lang`, `x-mask`,
  `x-shortcuts`, the declined chip and inline issues.
- `resources/views/components/operator/read-back.blade.php`: the pane.
- `App\Livewire\Concerns\HasMessageAids`: a trait for Workspace,
  MultiCallWorkspace and later ScriptRunner. Actions:
  `openReadBack()`, `confirmReadBack(string $outcome)`,
  `requestCleanup(string $field)`, `acceptCleanup()`,
  `dismissCleanup()`, `overrideIssue(string $code)`,
  `addWordToClient(string $word)`.
- **Phase 1 changes to the current forms:**
  - Workspace: `workspace.blade.php:10-100`. Replace the hand-written
    inputs and the `validate()` at `Workspace.php:185-188`.
  - MultiCallWorkspace: `multi-call-workspace.blade.php:245-304`.
    Replace the `validate()` at `MultiCallWorkspace.php:204-211`.
    `MultiCallDesk::saveDraft()` keeps its column clamps
    (`MultiCallDesk.php:173-178`) and stores the canonical phone in the
    draft.
- **Phase 2:** plan 02's `ScriptRunner` uses the same component and
  trait. The legacy forms are deleted together with plan 02's removal
  of the fixed form.

### Data model summary

| Change | Notes |
|---|---|
| `client_slots`: `format` (string, nullable), `constraints` (jsonb), `allow_declined` (bool, default false), `spellcheck` (bool, null means by type) | `BelongsToTeam` already |
| `teams`: `handles_phi` (bool, default false), `message_aids` (jsonb: `read_back`, `dictionaries[]`, `lang`, `ai_cleanup`, `profanity`) | Edited on ClientResource |
| `messages.aids` (jsonb, nullable) | read_back, overrides, ai_cleanup, shortcuts_used, spelling_unresolved |
| `text_shortcuts` | `BelongsToTeam`, nullable `team_id` |
| `spelling_words` (`team_id` nullable, `word`, `source` manual/import, `created_by`) | unique (`team_id`, lower(`word`)) |
| `content_rules` | as §6 |
| `ai_aid_requests` | metadata only |
| Platform settings section `message_aids` in `SettingsRegistry` | next to "Operator Window" (`SettingsRegistry.php:690-697`) |

### Permissions and tenant isolation

**New Spatie permissions:**

- `message_aids.manage_global`: super-admin. Covers global shortcuts,
  words and rules, and the AI provider.
- `message_aids.manage_client`: supervisor. Covers client shortcuts,
  words, rules and the client's aid policy.
- `message_aids.add_word`: off for operators by default. "Add to
  client dictionary" from the spell pass.
- `message_aids.override_warning`: on for operators.

There are no portal permissions in this plan (open question 5).

**Reads.** A single `MessageAidsResolver::for(Team $client)` is the
only way the UI reads shortcuts, words, rules and the lexicon. It
queries `withoutGlobalScope('team')->where(fn ($q) => $q->where('team_id', $client->id)->orWhereNull('team_id'))`.
That is the same explicit-client pattern as `ClientCallBrief:27-30`.
It is needed because the global scope would otherwise filter an
operator to their own `currentTeam`. The client always comes from the
loaded account or the `OperatorCall`, never from a request parameter
the browser could change.

**Writes.** `BelongsToTeam`'s `creating` hook fills `team_id` from the
author's `currentTeam` when it is empty, for non-super-admins. A
supervisor saving a "global" row would therefore end up with their own
team's ID. Global rows can only be created through the admin panel
(super-admin). Client rows set `team_id` explicitly from the
ClientResource page, the same way slots are managed on
`ManageClientIntakeFields`.

**Lexicon cache** keys include `team_id`. The browser payload for a
call holds only that call's client's lexicon and shortcuts. On the
multi-call desk this means one payload per tab, swapped on
`select()`.

### Audit

- Per message: `messages.aids`, which plan 03 revisions copy into each
  revision.
- Per session: `script_events`, once plan 01 exists. The event types
  are `read_back_presented`, `read_back_confirmed`,
  `read_back_unconfirmed`, `issue_overridden`, `field_declined`,
  `ai_cleanup_suggested`, `ai_cleanup_accepted` and
  `ai_cleanup_dismissed`.
- AI calls: `ai_aid_requests`, with no text.
- Config changes: `created_by`/`updated_by` and soft deletes on
  shortcuts, words and rules. Changes to `teams.message_aids` and
  `handles_phi` go through the existing `TeamUpdated` event
  (`Team::$dispatchesEvents`), plus a log line with the changed keys.

## Edge cases

**Caller details**
- **Caller won't give a name:** use the declined chip. If the field
  doesn't allow declined, use a partial save.
- **Mobile caller from another area code with no number given:** the
  operator can press "Use caller ID", which fills the phone from the
  call's caller number. A warning says "same as caller ID" so the
  read-back confirms it.
- **7-digit number:** valid only with the client's `default_area_code`.
  Today that input would become `+5551234`.
- **International and extension numbers:** libphonenumber handles them.
  `ext` is kept separately and shown as "x12".
- **DOB entered as `3/4/62`:** this is 1962 (into the past), shown with
  the age. `3/4/26` for a DOB is 1926, or 2026 only if that isn't in
  the future. The age display catches mistakes at read-back.

**Read-back and drafts**
- **Hang-up during read-back:** mark it "unconfirmed" and save. The
  call-ended event from `followSoftphone` doesn't close the pane.
- **Multi-call:** read-back open on call A, then call B is answered.
  The pane state belongs to A's draft and B gets a clean form. A clean-up
  job for A that finishes while B is on screen goes to A's draft only.
  The job carries `operator_call_id`, and the component ignores results
  for other calls.
- **Message saved twice:** see Bugs. Read-back makes this worse if
  "Save again" keeps creating new messages, so fix it first.

**Shortcuts and spelling**
- **Shortcut trigger typed on purpose,** for example a caller's word
  that matches a bare trigger: Backspace undoes it. Bare triggers are
  refused if they are dictionary words.
- **Spanish-speaking caller or client:** the per-client `lang` and an
  `es` dictionary (dictionary-es). Native spellcheck follows `lang`.
- **Very long reason** (pasted text): the spell pass caps at 2000 words.
  Clean-up refuses fields over 4000 characters.

**AI clean-up**
- **Ollama down or slow:** the button disables after a timeout or a
  health check (the existing Health checks cover Ollama URL
  reachability through Settings), with a tooltip. Messages are never
  delayed.
- **Clean-up removes a fact** ("caller denies chest pain" → "caller
  has chest pain"): `CleanupGuard`'s negation-count check rejects it.
  Anything subtler is why acceptance is always manual and the original
  is kept.

**Content and access**
- **Supervisor edits after save** (plan 03): the same aids and checks
  apply to the edit form.
- **Card number in the reason** that the caller insists on: the block
  can't be overridden. The operator switches to a `credit_card` slot
  if the script has one, or records "caller attempted to give card
  number".
- **Super-admin viewing the operator panel:** gets the same resolver
  output. The global-scope bypass changes nothing here because the
  resolver filters by client explicitly.

## Migration / rollout

1. **Phase A, no new UI behaviour:**
   - slot types registry
   - `PhoneNumber` on libphonenumber
   - type validation in `captureField`, logged as warnings first and
     enforced after a week of log review
   - the migrations
2. **Phase B, current forms:**
   - field component
   - typed validation, required/declined
   - read-back with the default `offer`
   - native spellcheck attributes
   - keyboard registry

   Behind platform setting `orbital.message_aids.enabled`, default off.
   Try it on the Multi-call trial page first (`app/Filament/Pages/MultiCallTrial.php`).
3. **Phase C:**
   - spell pass with dictionaries and lexicon
   - shortcuts plus the admin and client editors
   - content rules with seeds
4. **Phase D, AI clean-up:**
   - `LlmClient`
   - Ollama chat model added to Helm (`ollama.models`), and to the
     compose `local-ai` profile docs
   - off by default; enabled per platform, then per client
5. **Phase E, with plan 02:** the runner uses the same component. The
   legacy forms are removed.

**Existing messages:** `aids` stays null. Phone values are not
rewritten. A read-only report lists stored `caller_phone` values that
don't parse, for NCI to review.

## Tests

Pest, in `tests/Feature/Operator/MessageAids/` and
`tests/Unit/Script/SlotTypes/`. These belong in the answering-service
test suite and run without telephony.

**Slot types (unit, table-driven)**

- **phone:**
  - `555-123-4567` → `+15551234567` / `(555) 123-4567`
  - `5551234` with no default area code → error
  - with `default_area_code=614` → `+16145551234`
  - `+44 20 7946 0958` → valid
  - `555 123 4567 x 12` → ext 12
  - `call me` → error
- **date(dob):**
  - frozen clock 2026-11-24: `3/4/62` → `1962-03-04`, age 64
  - `12/31/2030` → error (future)
  - `1/1/1890` → error
- **email:** `John@GMIAL.com` → `John@gmial.com` plus the `gmail.com`
  suggestion warning.
- **address:**
  - missing ZIP with `require_postal` → error
  - `123 main street apt 4` → `123 Main St`, unit `Apt 4`
  - ZIP 43215 with state CA → warning
- **masked:** `999-99-9999` accepts `123-45-6789`, rejects `12-345`.
  Read-back shows only the last 4 when `confirm_last=4`.
- **credit_card:** stores only last4/exp. No 13–19-digit Luhn-valid
  number appears anywhere in the stored row.

**Precedence:** step `min_date` overrides slot `constraints.min_date`,
and a template `required` overrides a step's `required: false` for
saving.

**Operator forms (Livewire)**

- **Required:**
  - Workspace save without a name → inline error, no `Message` row
  - with declined name (`allow_declined`) → saved, `fields`/`aids` show
    declined, delivery body says "Caller declined to give"
- **Read-back:**
  - client `require`: `saveMessage` opens the pane and doesn't create a
    row
  - `confirmReadBack('confirmed')` creates exactly one row with
    `aids.read_back.status=confirmed`
  - `unconfirmed` creates a row flagged as such
  - `offer`: save creates the row directly
- **Partial save** with read-back `require` → saved without read-back,
  `is_partial` true. This guards against regressions to `PartialMessagePolicy`.
- **Multi-call:**
  - two calls, read-back open on A, select B: B has no pane
  - back to A: pane restored
  - a clean-up result for A doesn't touch B's draft
- **Phone canonicalisation** through `MultiCallDesk::saveDraft`: the
  draft stores E.164 and the view shows the formatted number.
- **Content rules:**
  - reason containing `4111 1111 1111 1111` → blocked, with no override
    action available
  - profanity → warning; override recorded with user and rule
  - a client rule in client A doesn't fire for client B

**Tenant isolation**

- Client A's shortcut `;dr` and word "Xiomara" are not in the resolver
  output for client B. Globals are in both.
- Operator (non-super-admin, `currentTeam` = their own team) loading
  client A gets A's shortcuts. This is the regression test for the
  global-scope pitfall.
- A supervisor creating a client shortcut through ClientResource for
  client A gets `team_id = A`, not their `currentTeam`. A non-super-admin
  cannot create a `team_id = null` row (403).
- The lexicon cache for A is not served for B. Bumping A's version
  doesn't invalidate B.

**AI clean-up** (HTTP faked with `Http::fake`)

- **Provider resolution:**
  - `handles_phi` client + `anthropic` + `hosted_phi_allowed=false` →
    unavailable, no request sent (`Http::assertNothingSent`)
  - same with `ollama` → request to `/api/chat`
- **Guard:**
  - a suggestion that changes `555-1234` to `555-1243` → rejected
  - one that drops "denies" → rejected
  - a pure spelling fix → shown
- **Stale:** the operator edits the field after the request → the
  result is discarded.
- **Accept:** `aids.ai_cleanup.original_text` holds the original and
  the field holds the new text.
- **Dismiss:** text unchanged.
- **Audit:** an `ai_aid_requests` row has provider, model and outcome,
  and no column contains the field text (assert by searching the row's
  JSON for a sentinel string).
- **Logging:** with Telescope on, no `client_request` entry is recorded
  for the LLM host.

**AI path parity** (with plan 01): `captureField` with
`callback_phone = "five five five…"` (the worker sends digits) is
stored as E.164. Invalid input returns issues in the JSON so the worker
can re-ask. The `save_message` handler's spoken read-back equals
`ReadBackRenderer` output for the same fields.

**Shortcuts (JS, Vitest)**

- `;pcb ` → "Please call back ". Backspace restores `;pcb`.
- `;PCB` → upper case.
- No expansion in a phone field.
- A client override beats a global trigger.

**Keyboard (JS)**

- Alt+S in a textarea saves.
- A digit typed in the reason field is not sent to the softphone.
- A digit with focus on the body while in-call still goes to the
  softphone. This pins the coexistence rule.

## Open questions

1. Which IS aids do NCI's operators actually use today? Spell check
   with the medical dictionary? Read-back as a script step on every
   account, or only some? Any IS abbreviation lists we can export?
2. The medical dictionary source. Licensed options such as OpenMedSpel
   are GPL. A generated FDA NDC + RxNorm list is safe but drug-only.
   Is drug and anatomy coverage enough, or does NCI need a commercial
   list?
3. Does NCI (the platform operator) have a BAA with any hosted LLM
   provider? If not, AI clean-up is Ollama-only for medical clients.
   Is the cluster's Ollama GPU-backed? A 7B model on CPU may miss the
   8-second budget.
4. Should read-back default to `require` for medical clients
   (`handles_phi`) and `offer` for everyone else?
5. Should client admins manage their own shortcuts or dictionary words
   in the portal? CLAUDE.md says clients don't take calls, so not in
   this plan.
6. Per-operator key rebinding and personal shortcuts: needed, or are
   platform-wide bindings enough?
7. Is profanity a warning everywhere, or do some clients want verbatim
   messages with no prompt at all (`teams.message_aids.profanity = off`)?

## Bugs and gaps noticed while writing this plan

- **Duplicate messages from "Save again":** `MultiCallDesk::saveMessage()`
  always calls `Message::create` (`MultiCallDesk.php:189-213`). It never
  updates the `message_id` it saved before. The multi-call view offers
  "Save again" once a message is saved (`multi-call-workspace.blade.php:302`),
  so each extra save puts another message in the client's inbox. It
  should update the existing message, or create a revision once plan 03
  exists.
- **Workspace message list may be empty for real operators (suspected):**
  `Workspace::table()` uses `Message::query()`
  (`Workspace.php:359-363`), which keeps the `BelongsToTeam` global
  scope. For a non-super-admin operator with a `currentTeam`, that adds
  `team_id = <operator's team>` on top of `team_id = activeTeamId`.
  `MultiCallWorkspace` deliberately uses `withoutGlobalScopes()` (`:290`).
  Not tested against a real operator account.
- **Invalid E.164 values:** `PhoneNumber::toE164()` returns `+` plus the
  digits for 7-digit input (`PhoneNumber.php:38`), so invalid numbers
  are stored in `caller_number`/`called_number`.
- **No slot-type validation on the AI path:** `captureField` doesn't
  enforce `ClientSlot.type`, although the model's docblock says the
  runtime does (`ClientSlot.php:21-24` vs `CallSessionController.php:67-75`).
- **No phone validation in the Workspace form:** no rule at all for the
  phone, and none for the reason's length (`Workspace.php:185-188`).
- **Unused provider setting:** `orbital.ai.default_llm_provider` is
  never read (`config/orbital.php:151`). Every PHP LLM call is
  Anthropic with a hard-coded or default model.

## Size estimate

| Piece | Estimate |
|---|---|
| Slot types registry + libphonenumber + `captureField` wiring + unit tests | 4 d |
| Field component, `HasMessageAids`, required/declined, read-back (both forms) | 5 d |
| Keyboard registry + softphone coexistence + cheat sheet | 2 d |
| Spell pass (nspell worker, dictionaries, lexicon, add-word) + medical list build command | 4 d |
| Shortcuts (model, editors, Alpine directive, picker, import review) | 4 d |
| Content rules (model, scanner, seeds, override UI) | 2.5 d |
| `LlmClient` (3 drivers) + clean-up job, guard, diff UI, audit table, Helm model | 5 d |
| Tenant/permission tests, Telescope hardening, docs in Settings helpers | 2 d |

**Total ≈ 6–7 weeks** for one developer. Phases A–C (everything except
AI) are about 4.5 weeks, deliver most of the value, and can ship before
plans 01–03. Phase D is about 1.5 weeks. Phase E is folded into plan
02's estimate.
