# M2 design spec: web layer and screens

Status: design spec for milestone M2, part 2 of 2 (M2-D1), awaiting spec review (M2-R1). Part 1, `docs/specs/M2-records.md`, has the core, its test plan, the provisional decisions (P84 to P109), and the work-package list; this part has the web layer those packages build on, the file ownership of the web packages (section 13), the keyboard procedure (section 10), and how screens are checked against the mock-ups (section 11). Screen-by-screen designs, with every UI string, are in `docs/design/m2/`.

The M1 web layer (`docs/specs/M1-screens.md` and its as-built notes) is extended, not replaced. Everything it specifies stays true unless a section below changes it.

## 1. What changes from M1

| Area | M1 | M2 |
|---|---|---|
| Routes | the M1 table | 12 more (section 2) |
| `/projects/{p}` | redirect to the tables page | redirect to the first table's list (P103) |
| Top bar | no Display link (P41) | Display link on every signed-in page; project search on list, filter, and search pages |
| Layout | one `main` | also sidebar plus `main` (list pages); record and settings widths |
| Template rules | ids literal | `id()` helper for generated ids (P96) |
| Strings | `Format::count` with `.one`/`.many` keys | plus the plural placeholder `{name|one|other}` (P97) |
| Scripts | `show-password.js` | plus `settings-preview.js` |
| Flash notices | key and params | plus an optional Undo action (`docs/design/m2/states.md` section 4) |
| Request | M1 fields | plus `origin` (M2-WP0, part 1 T-CF-1) |

## 2. Routes

Added to `src/Web/routes.php` (owned by M2-WP5, which also adds the new controllers to the pending list of `RouterTest::testRealRouteTableHandlersExist` so that the table loads before WP6 to WP8 create them). Literal segments come before placeholders, as in M1. All have access level `user`.

| Method | Pattern | Handler | Package |
|---|---|---|---|
| GET | `/settings` | `SettingsController::form` | WP8 |
| POST | `/settings` | `SettingsController::save` | WP8 |
| POST | `/settings/reset` | `SettingsController::reset` | WP8 |
| GET | `/projects/{project}/search` | `SearchController::index` | WP6 |
| GET | `/projects/{project}/tables/{table}` | `RecordListController::index` | WP6 |
| GET | `/projects/{project}/tables/{table}/filter` | `RecordListController::filter` | WP6 |
| GET | `/projects/{project}/tables/{table}/records/new` | `RecordController::newForm` | WP7 |
| POST | `/projects/{project}/tables/{table}/records` | `RecordController::create` | WP7 |
| GET | `/projects/{project}/tables/{table}/records/{record}` | `RecordController::show` | WP7 |
| POST | `/projects/{project}/tables/{table}/records/{record}` | `RecordController::update` | WP7 |
| POST | `/projects/{project}/tables/{table}/records/{record}/trash` | `RecordController::trash` | WP7 |
| POST | `/projects/{project}/tables/{table}/records/{record}/restore` | `RecordController::restore` | WP7 |

The `/settings` routes go with the account routes (before `/projects`); the search route after `/projects/{project}/sharing`; the list and record routes after `/projects/{project}/tables/{table}/delete` and before the field routes, with `records/new` before `records/{record}`. `{record}` matches one segment of `[A-Za-z0-9_-]+` (M1 router rule); the controller passes it to the core as a string, which answers `NotFound` for anything but a positive id (P104). `ProjectController::open` (M2-WP6) redirects to `/projects/{p}/tables/{first table key}` using `SchemaOperations::tables`, or to `/projects/{p}/tables` when there is none.

URL shapes are costly to reverse (ADR 0008); these follow the indicative shape of ADR 0008 decision 3 (`/projects/{project}/tables/{table}/records/{record}`) and the reserved `/settings` prefix.

### 2.1 Controllers

| Controller | Screens (design document) | Core calls |
|---|---|---|
| `RecordListController` | List view, filter (`list-and-search.md` sections 2, 3) | `RecordOperations::listing`, `filterOptions` |
| `SearchController` | Search results (`list-and-search.md` section 4) | `RecordOperations::search` |
| `RecordController` | Record form, new record, trashed, conflict, trash, restore (`record.md`) | `RecordOperations::blank`, `screen`, `redisplay`, `create`, `update`, `conflict`, `trash`, `restore` |
| `SettingsController` | Display settings (`settings.md`) | `SettingsOperations::get`, `save`, `reset` |
| `ProjectController` (changed) | `open` only | `SchemaOperations::tables` |

Rules (M1 section 2.8 still applies): controllers read view state only through `ListRequest::fromQuery($request->query)` and pass `ListRequest::toQuery()` back into every URL they build; they make no authorization decision. `RecordController::create` and `update` first obtain the screen (`blank` or `screen`) to learn `BoundForm::editableKeys()`, then read `f_<key>` with `Request::input` for exactly those keys (a missing checkbox gives `''`) and the hidden `version`; `ValidationFailed` gives `redisplay` and the 422 page with the problems mapped to `Ids::make('f', key)`; `Conflict` gives `conflict` and the 409 page; `NotFound` reaches the kernel (404). `trash` sets the flash `notice.record_trashed` with `title` and `undo` (section 3.4). Titles for notices and page titles fall back to `records.untitled` "{Item} {id}".

## 3. Shell and layout

### 3.1 Top bar (M2-WP5, `templates/partials/top-bar.php`)

In reading order: brand link; `nav.top-nav` (unchanged); when the page data holds `search` (`['project' => key, 'projectName' => name, 'q' => ?string]`), the project search form of `list-and-search.md` section 5; `nav.account-nav`: **`a.top-pill` to `/settings` holding the `top-pill-icon` SVG (half-filled circle, from the mock-up, `aria-hidden="true"`) and `shell.nav.display` "Display", with `aria-current="page"` when `Shell::$nav` is `settings`**, then the user chip and Sign out as in M1. `Shell::$nav` gains the value `settings`. This reverses the Display part of P41; the Plugins tab stays absent until M3.

### 3.2 Layout variants (M2-WP5, `templates/layout.php`)

The page data key `layout` selects the shape of the area between the top bar and the footer; every class string is a literal alternative in the layout:

| `layout` | Markup | Used by |
|---|---|---|
| absent | `main#main.page` (M1) | M1 pages, filter page, error pages |
| `list` | `div.app-columns` > the `sidebar` partial (from page data `sidebar`) then `main#main.page.page-list` | List view, search results |
| `record` | `main#main.page.page-record` | Record form and new record with a `Details` grid |
| `record-narrow` | `main#main.page.page-record.page-record-narrow` | Other record screens (trashed, conflict, forms without a grid) |
| `settings` | `main#main.page.page-settings` | Display settings |

Breadcrumb, flash notice, and error summary are printed by the layout at the top of `main` in that order, as in M1. The `sidebar` data is built by `TiffinDB\Web\Sidebar::build(string $projectKey, string $projectName, array $tables, int $trashCount, ?string $currentTableKey): array` (WP5), from the `project` (read through its `key` and `name` properties), `tables`, and `trashCount` of `RecordListing` or `SearchResults` (M2-records section 7.2; the filter page has no sidebar). It takes strings rather than `ProjectRecord` because the web layer may not name `Core\System` classes (T-WEB-23, M2-records section 2.1).

### 3.3 Scripts

`data['script']` may be `show-password` or `settings-preview`; the layout writes the one requested, exactly as M1 section 4.1 does, with `asset('js/<name>.js')` and `defer`. No page loads both. `HtmlAssertions::assertNoInlineCode` allows exactly one `script` element whose `src` is one of the two files.

### 3.4 Flash notice with Undo

`Shell::checkFlash` keeps M1's rules and additionally accepts the param `undo` only when it matches `^/projects/[a-z2-7]{12}/tables/[a-z2-7]{12}/records/[1-9][0-9]{0,18}/restore\z`; otherwise `undo` is removed before rendering. With `undo`, the layout renders the `notice` partial's undo variant (`docs/design/m2/states.md` section 4): a POST form to `url(undo)` with the CSRF field and an "Undo" button. The flash is stored through `Services::setFlash` as in M1; params remain strings and integers.

## 4. Templates

### 4.1 Shared partials added or changed (M2-WP5)

| Partial | Data | Output |
|---|---|---|
| `top-bar` | `shell`, optional `search` | Section 3.1 |
| `sidebar` | `sidebar` array | `docs/design/m2/list-and-search.md` section 6 |
| `top-search` | `search` array | `list-and-search.md` section 5 |
| `notice` (changed) | as M1, plus optional `undo` (path) and `undoTitle` | M1 output; with `undo`, `div.notice.notice-row` and the undo form |
| `empty-state` | `title`, `titleParams`, `body`, `bodyParams`, optional `link` `{label, labelParams, path, query}` | `states.md` section 1 |
| `pager` | `from`, `to`, `total`, `page`, `pages`, `path`, `query` | `list-and-search.md` section 2 item 7 |
| `record-table` | `caption` (plain text), `columns` (list of `{field (FieldDef), header (plain text), numeric (bool), sort ('ascending'|'descending'|null)}`), `rows` (list of `{path, query, title (plain text), cells: list of {field, value, links, moreLinks, fileCount}}`), `itemNames` (table key => item name, for untitled links) | `list-and-search.md` section 2 item 6 |
| `field-input` | `field` (FieldDef), `key`, `text` (the bound field's `inputText`), `submitted` (bool), `required`, `invalid`, `describedBy` (list of `help`, `err`), `label` (plain text), `currencyName` (plain text or null) | The control for one editable field (section 5.1); its `value` attributes use `$this->inputValue(...)` |
| `form-inline` | `nodes` (list of inline nodes) | Text, `strong`, `em`, `br`; links as their text. Recursive through `partial('form-inline', ...)` |

The bound-form partials that use these (`templates/partials/record-form/section.php`, `field.php`, `static.php`) belong to M2-WP7 (`record.md` section 2.1).

### 4.2 `TiffinDB\Web\Ids` and the `id()` helper

`Ids::make(string $prefix, string|int ...$parts): string` returns `$prefix . '-' . implode('-', $parts)` after checking that `$prefix` matches `^[a-z][a-z-]{0,15}\z` and each part `^[a-z0-9]{1,24}\z` (field and table keys, record ids); anything else throws `\InvalidArgumentException`. `ViewScope::id(string $prefix, string|int ...$parts): string` returns the same value, escaped. Controllers use `Ids::make` to build the `field` values of error-summary problems, so the summary links match the controls.

### 4.3 Template rule changes (`tests/web/TemplateRulesTest.php`, M2-WP5)

- `$this->value(...)` and `$this->inputValue(...)` are allowed in element text and as the whole value of a `value` attribute.
- `$this->id(...)` is allowed as the whole value of `id`, `for`, `form`, and `aria-labelledby`; `aria-describedby` may hold one or more `<?= $this->id(...) ?>` separated by single literal spaces. Other attributes may not use it. The M1 rule that `id` values are otherwise literal stays, so every generated id goes through the validated helper (P96).
- `formaction` is checked like `action` (a literal or `$this->url()` value, never starting `//` or `/\`).
- The `script` element is allowed only in `templates/layout.php`, in the two forms of section 3.3.
- `RUNTIME_PREFIXES` gains `currency.`, `file.type.`, `size.`, `sort.`, `lists.cond.`, `settings.theme.`, `settings.accent.`, `settings.size.`, `settings.spacing.`, `settings.face.` (keys built at run time from type ids, codes, and setting values).
- Negative cases for each new rule in a temporary tree, as M1 did.

### 4.4 Form conventions for record forms

M1 section 4.5 applies, with these additions:

1. Control names are `f_<field key>`; ids are `id('f', key)`, help `id('help', key)`, errors `id('err', key)`. The version is `input type="hidden" name="version"`.
2. Required fields have `required="required"` on the control and the label marker `input.required` "(required)" in `span.label-optional`; optional fields carry no marker (the M1 "(optional)" marker is not used on record forms, P101).
3. The Save button is outside the `form` element and names it with `form="record-form"`; the trash form is a separate `form` (no nested forms).
4. The record form never sets `novalidate`; the browser's checks for `type="url"`, `email`, `date`, `time`, `datetime-local`, and `required` are a bonus; the core checks everything.
5. `autocomplete="off"` is not set; browsers may offer remembered values.

## 5. Field rendering per type

### 5.1 Inputs (`field-input` partial)

Common attributes: `class="input"` (except checkbox and radios), `id`, `name`, `aria-describedby` when help or errors exist, `aria-invalid="true"` with errors, `required="required"` when required. `value` and text content are the bound field's `inputText`, escaped, except where the table says otherwise.

| Type | Element and attributes | Value shown |
|---|---|---|
| `text` | `input type="text"` `maxlength` = the field's `max_length` | as typed or `toText` |
| `long_text` | `textarea rows="4"` `maxlength` = `max_length` | as typed; line breaks kept |
| `number` | `input type="text"` `inputmode="numeric"` (decimals 0) or `"decimal"` | `toText` (no thousands separators) |
| `currency` | `input type="text"` `inputmode="decimal"`; the label reads `input.currency.label` "{label} ({currency})" where `{currency}` is `currency.<CODE>` when defined (section 6.3), else the code | `toText` (`329.00`) |
| `date` | `input type="date"` | `YYYY-MM-DD` |
| `time` | `input type="time"` `step="60"`, or `step="1"` with the `seconds` option | `HH:MM`, or `HH:MM:SS` with seconds |
| `date_time` | `input type="datetime-local"` `step` as time | `YYYY-MM-DDTHH:MM`, or with `:SS` |
| `duration` | `input type="text"`; the help line is the field's help followed by `input.duration.help` "Hours and minutes, for example 1:30." or, with seconds, `input.duration.help_seconds` "Hours, minutes, and seconds, for example 1:30:00." | `H:MM`, or `H:MM:SS` with seconds |
| `checkbox` | `input type="checkbox" value="1"` inside the wrapping label (`record.md` section 2.1); `checked="checked"` when the value is true or the typed text is `1` | none |
| `choice` | `select`: first `option value=""` `input.choice.none` "Not chosen", then one `option` per choice label in list order, the current one `selected="selected"` (exact match on the label) | none |
| `rating` | radio group (`record.md` section 2.1): `input type="radio"` `value` 1 to `max`, names `f_<key>`; labels: `input.rating.low` "{n}, lowest" for 1, `input.rating.high` "{n}, highest" for `max`, the digit for the others; then a radio `value=""` `input.rating.none` "Not rated"; the current value `checked="checked"` (no value: "Not rated") | none |
| `url` | `input type="url"` `maxlength="2048"` | as typed |
| `email` | `input type="email"` `maxlength="254"` | as typed |
| `phone` | `input type="tel"` `maxlength="40"` | as typed |
| `auto_number`, `relation`, `attachment` | no input (`record.md` section 2.1) | |

Typed text that the type would refuse is shown back exactly as typed; for `date`, `time`, and `datetime-local` inputs a browser may show such text as empty, but the error message stays at the field.

### 5.2 Display (`value()` helper)

`cell` is the list's table cell; `text` is plain display (conflict page, search excerpts' field values, filter descriptions). Every output is text through `e()` or `t()`; **no value is rendered as a link in M2** except record titles and linked records, which link to record screens built with `url()`. Web addresses, emails, and phone numbers are shown as text; when a later milestone makes them links, `mailto:` local parts must be `rawurlencode`d, `tel:` targets reduced to digits and `+`, and `href` values passed only after the field type's own validation (ledger section 7 notes).

| Type | `cell` | `text` |
|---|---|---|
| `text` | the text, cut to 80 characters with "…" | the text |
| `long_text` | the first line, cut to 80 characters with "…" | the text; line breaks kept with `span.text-multiline` (`white-space: pre-line`) |
| `number` | `Format::decimal(value, decimals)` ("1,234.5" with decimals 1) | the same |
| `currency` | `Format::money(value, code, minor_digits)`: `$`, `€`, `£`, `¥` before the amount for USD, EUR, GBP, JPY, otherwise the code and a space ("CHF 12.00"); thousands separators; a leading `-` for negatives ("-$5.00") | the same |
| `date` | `Format::date` "14 Mar 2025" | the same |
| `time` | `HH:MM`, or `HH:MM:SS` with seconds | the same |
| `date_time` | `value.date_time` "{date}, {time}" | the same |
| `duration` | `H:MM`, or `H:MM:SS` with seconds | the same |
| `checkbox` | `value.yes` "Yes" or `value.no` "No" | the same |
| `choice` | the label | the same |
| `rating` | `value.rating` "{value} of {max}" | the same |
| `url`, `email`, `phone` | the text, cut to 80 characters with "…" | the text |
| `auto_number` | the number, no separators | the same |
| `attachment` | `value.files` "{count|# file|# files}", or `span.cell-none` `value.none` "None" | the same |
| `relation` | up to three titles (untitled: "{Item} {id}" with the linked table's item name) joined by ", ", then `value.more_links` " and {count} more" when there are more; none: `span.cell-none` "None" | the same |
| empty value (other types) | empty cell | `records.conflict.empty` is the caller's choice |

Two view-scope helpers (WP5) produce this text, escaped, so templates never call formatting classes themselves: `$this->value(FieldDef $field, mixed $value, string $mode = 'text')` returns the display of a scalar value per the table (for `relation` and `attachment` the templates print the links, counts, and "None" themselves from the data they receive), and `$this->inputValue(FieldDef $field, string $text, bool $submitted)` returns the `value` of section 5.1 (typed text unchanged when `$submitted`, else the seconds trimmed for `time`, `date_time`, and `duration` without the `seconds` option). Both delegate to `TiffinDB\Web\ValueText` (WP5), which uses `Format` (section 7); controllers use `ValueText` for plain text outside templates (conflict rows, search excerpts, filter conditions), and pass it to `t()` or `e()`. Text is escaped once, at output. The template rules (section 4.3) allow `value()` and `inputValue()` in element text and in `value` attributes.

### 5.3 Sort options (WP6, `strings/en/lists.php`)

The menu label is `sort.<group>.asc` or `.desc` with `{field}`; the short direction word (for the sorted column header) is `sort.<group>.asc_short` or `.desc_short`:

| Group (field types) | Ascending | Descending | Short |
|---|---|---|---|
| `text` (text, long text, web address, email, phone, relation) | "{field}, A to Z" | "{field}, Z to A" | "A to Z" / "Z to A" |
| `number` (number, currency, rating, auto-number) | "{field}, lowest first" | "{field}, highest first" | "lowest first" / "highest first" |
| `duration` | "{field}, shortest first" | "{field}, longest first" | "shortest first" / "longest first" |
| `date` (date, date and time) | "{field}, oldest first" | "{field}, newest first" | "oldest first" / "newest first" |
| `time` | "{field}, earliest first" | "{field}, latest first" | "earliest first" / "latest first" |
| `checkbox` | "{field}, not ticked first" | "{field}, ticked first" | "not ticked first" / "ticked first" |
| `choice` | "{field}, in list order" | "{field}, in reverse list order" | "list order" / "reverse list order" |

Plus `sort.added.desc` "Newest added first", `sort.added.asc` "Oldest added first", `sort.changed.desc` "Recently changed first" (short forms `sort.added.desc_short` "newest added first" and so on; these columns are not shown, so the short forms appear only in the lead).

## 6. Strings

### 6.1 Files

| File | Owner | Content |
|---|---|---|
| `strings/en/components.php` (new) | WP5 | Section 6.3 |
| `strings/en/shell.php` | WP5 | adds `shell.nav.display` "Display", `shell.search.label` "Search all tables in {project}", `shell.search.placeholder` "Search {project}", `sidebar.*` (section 6.3) |
| `strings/en/format.php` | WP5 | adds section 7 keys |
| `strings/en/problems.php` | WP5 | adds part 1 section 8 keys, exactly |
| `strings/en/lists.php` (new) | WP6 | `docs/design/m2/list-and-search.md` sections 2 to 4, section 5.3 here, and `search.*` |
| `strings/en/records.php` (new) | WP7 | `docs/design/m2/record.md`, and the notices `notice.record_added`, `notice.record_saved`, `notice.record_trashed`, `notice.record_restored` |
| `strings/en/settings.php` (new) | WP8 | `docs/design/m2/settings.md` and `notice.settings_saved`, `notice.settings_reset` |
| `strings/en/schema.php` | WP8 | the confirmation strings rewritten with the plural placeholder (section 6.2): `schema.confirm.body` "This change clears {values|# value|# values} in {records|# record|# records}." and the M1 `_one` variants removed |

Every package keeps its strings used by its own templates (the M1 unused-key test applies per file set).

### 6.2 Plural placeholder (`TiffinDB\Web\Strings`, WP5)

In any string, `{name|singular|plural}` is replaced by the singular text when the parameter `name` is the integer 1 and by the plural text otherwise; inside either text, `#` is replaced by the number formatted with `number_format` (so `{count|# record|# records}` gives "1 record", "1,234 records"). Rules: `name` matches `[a-z_]+`; the two texts contain no `{`, `}`, or `|`; the parameter must be an `int` (`\InvalidArgumentException` otherwise); plain `{name}` placeholders keep their M1 behaviour. English needs only one and other; another language would need more forms and a richer syntax, which is a later decision. `Format::count` and its `.one`/`.many` keys stay for M1 strings. `StringsTest` (T-WEB-24) covers 0, 1, 2, 1234, a negative number, a non-int parameter, and malformed patterns.

### 6.3 Shared strings (`strings/en/components.php` and `shell.php`, WP5)

| Key | Text |
|---|---|
| `sidebar.label` | Project |
| `sidebar.project` | Project |
| `sidebar.switch` | Switch project |
| `sidebar.tables` | Tables |
| `sidebar.schema` | Tables and fields |
| `sidebar.trash` | In the trash |
| `pager.label` | Pages of records |
| `pager.showing` | Showing {from} to {to} of {total} |
| `pager.previous` | Previous |
| `pager.next` | Next |
| `value.yes` | Yes |
| `value.no` | No |
| `value.none` | None |
| `value.rating` | {value} of {max} |
| `value.files` | {count|# file|# files} |
| `value.more_links` | and {count} more |
| `value.date_time` | {date}, {time} |
| `input.required` | (required) |
| `input.choice.none` | Not chosen |
| `input.rating.low` | {n}, lowest |
| `input.rating.high` | {n}, highest |
| `input.rating.none` | Not rated |
| `input.duration.help` | Hours and minutes, for example 1:30. |
| `input.duration.help_seconds` | Hours, minutes, and seconds, for example 1:30:00. |
| `input.currency.label` | {label} ({currency}) |
| `input.auto_number_new` | Given when you save |
| `input.links.none` | No linked records |
| `input.links.help` | Linked to the {table} table. |
| `input.files.none` | No files |
| `input.file.facts` | ({type}, {size}) |
| `file.type.pdf` | PDF |
| `file.type.image` | image |
| `file.type.other` | file |
| `notice.undo` | Undo |
| `notice.undo_label` | Undo: restore {title} |
| `currency.USD` | US dollars |
| `currency.EUR` | euros |
| `currency.GBP` | pounds sterling |
| `currency.JPY` | Japanese yen |
| `currency.CAD` | Canadian dollars |
| `currency.AUD` | Australian dollars |
| `currency.NZD` | New Zealand dollars |
| `currency.CHF` | Swiss francs |
| `currency.SEK` | Swedish kronor |
| `currency.NOK` | Norwegian kroner |
| `currency.DKK` | Danish kroner |
| `currency.INR` | Indian rupees |

`records.untitled` "{Item} {id}" is in `records.php` (WP7) and `lists.untitled` in `lists.php` (WP6), the same text, because each package's templates use their own.

## 7. Formatting (`TiffinDB\Web\Format`, WP5)

| Method | Output |
|---|---|
| `date(string $ymd): string` | `date.day` "{day} {month} {year}" with the M1 month keys ("14 Mar 2025") |
| `time(string $hms, bool $seconds): string` | `14:30` or `14:30:15` |
| `dateTime(string $value, bool $seconds): string` | `value.date_time` with `date` and `time` |
| `duration(int $seconds, bool $withSeconds): string` | `H:MM` or `H:MM:SS` (hours not padded) |
| `decimal(int|float $n, int $decimals): string` | `number_format($n, $decimals)` |
| `money(int $minor, string $code, int $digits): string` | Section 5.2 |
| `fileSize(int $bytes): string` | below 1,024: `size.bytes` "{count|# byte|# bytes}"; below 1,048,576: `size.kb` "{n} KB" (whole, rounded up); else `size.mb` "{n} MB" (one decimal) |
| `savedAt(string $timestamp): string` | `date.at` "{date} at {time}" with `{date}` from `relativeDate`, lower-cased for `date.today` and `date.yesterday` through `date.today_lower` "today" and `date.yesterday_lower` "yesterday", and `{time}` as `HH:MM` in the configured time zone |

New keys in `strings/en/format.php`: `date.at`, `date.today_lower`, `date.yesterday_lower`, `size.bytes`, `size.kb`, `size.mb`, and `value.date_time` (in `components.php`).

## 8. Stylesheet and script

`public/assets/css/app.css` (WP5 only) gains every class of `docs/design/m2/components.md`, plus `.text-multiline` (`white-space: pre-line`) and the 320 px fix for the M1 add-person label (part 1 section 11). M0 and M1 rules stay: rem only with the px exceptions, no colour token redefined, no colour literal, no `url()`, no `@import`; any breakpoint in `em`.

`public/assets/js/settings-preview.js` (WP5) behaves as `docs/design/m2/settings.md` section 3. It starts with the two SPDX `//` lines, runs in strict mode inside an IIFE, uses no network, storage, timers, `eval`, `innerHTML`, or inline styles, and touches only the class list of `#settings-preview`.

`public/assets/css/tokens.css` and `docs/design/tokens.css` already carry the corrected `--warn` (part 1 section 9); M2 packages do not change token values.

## 9. Contrast

Part 1 section 9 and T-DES-7. The ui-design-plan section 4.5 table is the source the test reads.

## 10. Accessibility and the keyboard criterion

M1 section 7 applies to every M2 screen. In addition:

### 10.1 Structural checks (T-KBD-1, WP9, `tests/web/KeyboardStructureTest.php`)

Rendered through the kernel with real core objects (`CoreFixture` plus the M2 synthetic fixture), as a Member, for: list view (with records, empty, no matches, filtered), filter steps 1 and 2 for each filterable type, search results (with and without matches), record form (every type, with errors), new record, trashed record, conflict page, display settings:

1. No element has a positive `tabindex`; no `tabindex` except the M1 error summary's `-1`.
2. Every `input` (other than hidden), `select`, `textarea`, and `button` has an accessible name: a `label` with matching `for`, a wrapping `label`, or `aria-label`; every radio group sits in a `fieldset` with a `legend`.
3. Every interactive element is a native focusable element (`a` with `href`, `button`, `input`, `select`, `textarea`); `span.button[aria-disabled]` elements are not focusable and carry no `href`.
4. Document order of focusable elements equals the reading order in the design documents (asserted as the sequence of accessible names).
5. Each form that changes data has exactly one submit button with the primary class or is the trash or undo form; the record form's Save button has `form="record-form"` and the record form contains a one-line text input when the table has one (implicit submission possible).
6. Every error message is referenced by `aria-describedby` from its control, and every problem in the error summary links to an element id present on the page.
7. No `accesskey`, no `autofocus`.

### 10.2 Scripted keyboard run (K-1, WP9; evidence for the exit criterion)

With the preinstalled headless Chromium (Playwright, in the scratchpad, not committed) against `composer serve` on a scratch installation built from the M2 synthetic fixture, port and folder from the brief. Only keyboard events are sent (`keyboard.press`, `keyboard.type`); no clicks, no `focus()` calls, no URL navigation after the first load of `/sign-in`. After every step, the script records the focused element's accessible name and takes a screenshot showing the focus ring.

1. Sign in as the synthetic Member: Tab to Username, type, Tab to Password, type, Enter.
2. On the project list: Tab to "Home Inventory", Enter. The list of Items opens.
3. Tab to "Add item", Enter. Type a name; Tab through every control, typing a value into each kind (text, a choice by arrow keys, a date by typing digits, a number, a rating by arrow keys, the checkbox by Space). Press Enter in the name field. Expect the record page with "The item has been added."
4. Tab to "Back to list", Enter. Tab to the find box, type part of the new name, Enter. Expect one matching row.
5. Tab to that row's title link, Enter. Change one field; Tab to Save, Enter. Expect "Your changes have been saved." and the new value.
6. Tab to "Move to trash", Enter; on the list, Tab to "Undo", Enter. Expect the record restored.
7. Throughout: the focus ring is visible on every focused element (screenshot check), focus never stays on one element across three Tab presses (no trap), and no step needed a pointer.

The run's result (each step passed or failed, with the focused names) is reported by WP9 and recorded by the exit auditor in the ledger. A manual screen-reader pass remains "not verified", as in M0 and M1.

## 11. Matching the mock-ups

### 11.1 Screens with a mock-up: list view, record form, display settings

"Matches" is defined as in M1 section 8.1: same elements in the same reading order with the same text except section 11.3; measurements within 1 px at 1360 px; colours equal the tokens the mock-up uses; side-by-side screenshots show no other difference. Synthetic data equal to the mock-ups: project "Home Inventory" with tables Items (48 records), Rooms (9), Warranties (12); Items fields in this order: Name (text, title), Room (relation to Rooms, one), Category (choice: Appliance, Cookware, Electronics, Furniture, Outdoor, Tool), Purchased (date), Value (currency USD), Condition (rating 5), Receipt (attachment), Serial number (text), Under warranty (checkbox), Manual (web address), Notes (long text), Photo (attachment, images only), Warranties (relation to Warranties, many); the record "Stand mixer" with the mock-up's values; signed in as "Example Admin" for the list and "Example Member" for the record and settings. The fixture is generated by `tests/Support/M2Fixture.php` (WP9) from a documented seed; attachments rows are inserted by plain SQL with synthetic names and no files (serving is M4).

Measurements at 1360 px (from `docs/design/m2/components.md` section 9 and M1 section 8.1 for the shell):

| Element | List view | Record form | Settings |
|---|---|---|---|
| Top bar | 68 high; search box 48 high, at most 520 wide | 68 | 68 |
| Sidebar | at least 260 wide; padding 24 16 32; links 48 high | none | none |
| Main | padding 28 32 40 | max 1120, padding 20 20 48 | max 1120, padding 32 24 48 |
| `h1` | 34 | 34 | 34 |
| Controls | find box, sort, Apply, Filter 44 high | inputs 48 high, border 2, radius 8 | radio cards 64 (colours, accents), 76 (lettering) |
| Cards | controls panel and table radius 12, border 1 | fieldsets padding 24, radius 12, legend 22 | fieldsets padding 20 24, radius 12 |
| Table | cells padding 14 16; header 16 | | |
| Buttons | Add item 44; pager 44 | Save, Back to list, Move to trash 48 | Save settings, Back to standard 48 |
| Details grid | | three columns of at least 300 at 1360 px | |
| Footer | 16, padding 16 24 | the same | the same |

At 390 px: the sidebar stacks above `main`; the table scrolls inside its box; the record grid is one column; the settings preview wraps below the form; nothing scrolls sideways; the top bar wraps without overlap.

### 11.2 Screens without a mock-up

Filter, search results, new record, trashed record, conflict page, empty states, undo notice, and 422 states "match" when their elements, order, and strings are exactly as in `docs/design/m2/`, shared parts measure as in 11.1 and M1 8.1, and they render in all four themes with no page-level horizontal scroll at 390 px and at 320 px with the Largest text size.

### 11.3 Deviations from the mock-ups accepted in M2 (proposed; confirmed or extended by the WP9 report)

| # | Screen | Mock-up | M2 | Reason |
|---|---|---|---|---|
| D-1 | List, search | No main navigation in the top bar | M1's Projects (and People) navigation kept | Consistent top bar; M1 behaviour |
| D-2 | List | "48 records, sorted by name" | "48 records, sorted by Name, A to Z" | The lead names the sort option exactly |
| D-3 | List | Import button; sidebar Collections, New collection, Import, Export; Trash is a link | absent; "In the trash" as text; "Tables and fields" link added | Later milestones (P103) |
| D-4 | List | No Apply button | Apply button | WCAG 3.2.2; works without script (P102) |
| D-5 | List | 10 rows, "Showing 1 to 10 of 48", Next enabled | 48 rows, "Showing 1 to 48 of 48", both disabled | 50 per page (P84) |
| D-6 | Record | Category before Room | Room before Category | One field order serves both mock-ups; the fixture follows the list |
| D-7 | Record | "Item 9 of 48. All changes saved." | "Item 9 of 48. Last saved today at 14:32." | No autosave (P86) |
| D-8 | Record | "Choose a different room", Open, Remove, Add a file, Replace photo, photo placeholder, Unlink, Link a warranty, "Unlinking does not delete..." | read-only lists of links and file names; no buttons; no photo | Picker, upload, and serving are M4 |
| D-9 | Record | Rating labels "1, poor" and "5, excellent" | "1, lowest" and "5, highest", plus "Not rated" | The schema has no per-field end labels; ratings must be clearable |
| D-10 | Record | Save and Back to list are links | Save is a `button`; Back to list a link | Save submits the form |
| D-11 | Record | "Item number 0009. Added ... Records in the trash can be restored." | "Added ... by .... Last changed ... by .... Records in the trash can be restored." | No padded auto-number option in format 1 |
| D-12 | Settings | `settings.md` section 5 | | |
| D-13 | All | Warning colour `#7A4300` | `#703D00` | 7:1 target (P99) |
| D-14 | All | (M1 deviations, `docs/specs/M1-screens.md` 8.3 and P83) | unchanged | |

### 11.4 Procedure (V-9 to V-14)

Run by WP9 and again by the exit auditor, as M1 section 8.4 (headless browser, `composer serve`, scratch installation, screenshots in the scratchpad only):

| ID | Screens |
|---|---|
| V-9 | List view: Admin with the mock-up data; Member; empty table; no matches; filtered; with the undo notice |
| V-10 | Record form: "Stand mixer" as Member; new record; with errors; trashed; conflict |
| V-11 | Display settings: default; each theme selected and saved; preview after a change with and without JavaScript |
| V-12 | Filter steps 1 and 2 for choice, checkbox, relation, date |
| V-13 | Search results: matches in three tables, "Show all" link, no matches, no search text |
| V-14 | Every M1 screen once at 1360 px light (top bar with Display link), and the add-person page at 320 px Largest (no mid-word break) |

For each: 1360 px and 390 px light with the measurements of 11.1; one pass in each other theme at 1360 px; 320 px at the Largest text size. Record pass or deviation per screen and width.

## 12. Web tests

| ID | Test | Asserts | Owner |
|---|---|---|---|
| T-WEB-24 | `StringsTest` (extended) | Plural placeholder (section 6.2); `problems.php` equals the M1 table plus part 1 section 8 | WP5 |
| T-WEB-25 | `FormatTest` (extended) | Every new method, including money for each symbol and a code, negatives, durations past 24 hours, file sizes at 1,023, 1,024, 1,048,576, and `savedAt` across midnight in a non-UTC zone | WP5 |
| T-WEB-26 | `TemplateRulesTest` (extended) | Section 4.3 with negative cases; `Ids::make` refuses bad prefixes and parts | WP5 |
| T-WEB-27 | `ComponentsTest` (new) | Every partial of section 4.1 rendered through `View` with real templates and strings: `field-input` for all 14 editable types (required, invalid, help, typed text, seconds options, currency label), the `value()` helper in both modes for every type with a column, including empties, and `record-table` cells for relation and attachment values; every value containing `<script>`, `"`, `'`, `&`, and `{name}` is escaped once and not treated as a placeholder; well-formed; no inline code; `assertForm` on forms | WP5 |
| T-WEB-28 | `ShellTest` (extended) | Display link on every signed-in shell with `aria-current` on settings; search form only with `search` data; the four layouts; sidebar markup and current item; undo notice rendered for a valid path and dropped for an invalid one (`//evil`, a path with `..`, an extra segment); script element only as requested | WP5 |
| T-WEB-29 | `ListScreenTest`, `SearchScreenTest` | Every state in `list-and-search.md` sections 2 to 4 with real core objects (`CoreFixture`): strings, order, counts, caption, `aria-sort`, links carrying the state, pager links and disabled states, filter bar and invalid-filter notice, refused search text, empty states for Admin and Member; filter steps per type; search groups, excerpts (cut and ellipsis), "Show all"; `/projects/{p}` redirect; ungranted Member 404; a stored form or field value containing markup never appears unescaped | WP6 |
| T-WEB-30 | `RecordScreenTest` | Every state in `record.md`: form for every type from the generated layout and from a stored form with headings and paragraphs (links shown as text), required markers, help, position and neighbours with state, meta line; create, update, 422 with typed values and summary links matching control ids, 409 conflict rows and the re-post form (hidden fields, current version, no `_csrf` copied), trash with undo flash, restore, trashed page, POST to a trashed record 404; a stored form containing `<script>` (written into the file by plain SQL) renders the generated layout and no script | WP7 |
| T-WEB-31 | `SettingsScreenTest` | Saved values checked; preview classes match the saved settings (accent omitted with high contrast); save and reset redirect with notices and the next page's root classes change; tampered values give 422 with problems at the fieldsets; script element present only here | WP8 |
| T-WEB-32 | `SchemaScreensTest` (extended) | The clearing-values confirmation posts the displayed count; a re-post after more values appeared shows the confirmation again with the new counts; singular and plural wording ("1 value in 1 record", "3 values in 2 records") | WP8 |
| T-SCR-2 | `ScreenStructureTest` (extended) | Every new GET route in each of its states: well-formed, no inline code, shell assertions, `assertForm`, title, `h1` | WP9 |
| T-KBD-1 | `KeyboardStructureTest` | Section 10.1 | WP9 |
| T-E2E-5 | `EndToEndTest` | From an empty folder through the kernel with real `CoreServices` (M1 T-E2E-1 set-up): Admin creates the project, Items with fields of several types, shares it; the Member signs in, adds a record through `POST .../records`, finds it with `q`, opens it, edits it with the version, sees the change in the list. **A Member can add, find, and edit records** (the keyboard part is K-1 and T-KBD-1) | WP9 |
| T-E2E-6 | `EndToEndTest` | Two sessions edit the same record: the second save gets the 409 page; "Save my version anyway" saves | WP9 |
| T-E2E-7 | `EndToEndTest` | A project folder holding a version 1 file (built by the test from the documented DDL) is opened through the list route: the snapshot appears, the list shows the records, `PRAGMA user_version` is 2 | WP9 |

## 13. File ownership for the web packages

| Package | Owned files | Acceptance |
|---|---|---|
| WP5 | `src/Web/Strings.php`, `Format.php`, `ViewScope.php`, `Shell.php`, `Ids.php` (new), `Sidebar.php` (new), `ValueText.php` (new), `routes.php`; `templates/layout.php`; `templates/partials/top-bar.php`, `sidebar.php`, `top-search.php`, `notice.php`, `empty-state.php`, `pager.php`, `record-table.php`, `field-input.php`, `field-inputs/*.php` (if split), `form-inline.php`; `strings/en/components.php`, `shell.php`, `format.php`, `problems.php`; `public/assets/css/app.css`, `public/assets/js/settings-preview.js`; `tests/Support/HtmlAssertions.php`; `tests/web/StringsTest.php`, `FormatTest.php`, `TemplateRulesTest.php`, `ShellTest.php`, `ComponentsTest.php` (new), `RouterTest.php`, `ViewTest.php`; `tests/web/fixtures/templates/*`, `tests/web/fixtures/strings/*` | T-WEB-24 to T-WEB-28 pass; every class in `docs/design/m2/components.md` exists in `app.css`; the stylesheet rules tests (T-DES-4 to T-DES-6) and the M1 web tests pass; the real route table loads with the new controllers pending; design-tier review of templates and the script |
| WP6 | `src/Web/Controller/RecordListController.php`, `SearchController.php`, `ProjectController.php`; `templates/pages/records/list.php`, `templates/pages/records/filter.php`, `templates/pages/search.php`; `strings/en/lists.php`; `tests/web/ListScreenTest.php`, `SearchScreenTest.php`; `tests/web/ProjectScreensTest.php` (only the `open` redirect assertions) | T-WEB-29 passes; design-tier review of templates |
| WP7 | `src/Web/Controller/RecordController.php`; `templates/pages/records/form.php`, `trashed.php`, `conflict.php`; `templates/partials/record-form/*.php`; `strings/en/records.php`; `tests/web/RecordScreenTest.php` | T-WEB-30 passes; design-tier security review (stored forms, record writes, re-post) |
| WP8 | `src/Web/Controller/SettingsController.php`, `src/Web/Controller/FieldController.php`; `templates/pages/settings.php`, `templates/pages/fields/confirm.php`; `strings/en/settings.php`, `strings/en/schema.php`; `tests/web/SettingsScreenTest.php`, `tests/web/SchemaScreensTest.php`; plus the core files listed in part 1 section 12 | T-WEB-31, T-WEB-32, T-APP-80 pass; design-tier review |
| WP9 | `tests/web/EndToEndTest.php`, `tests/web/ScreenStructureTest.php`, `tests/web/KeyboardStructureTest.php` (new), `tests/Support/M2Fixture.php` (new) | T-E2E-5 to T-E2E-7, T-SCR-2, T-KBD-1 pass; K-1 and V-9 to V-14 run and reported |

WP6, WP7, and WP8 must not change `app.css`, `layout.php`, shared partials, `routes.php`, or another package's strings; a missing class, partial, string, or route is reported to the orchestrator, who routes it to a follow-up on WP5's files (M1 rule).
