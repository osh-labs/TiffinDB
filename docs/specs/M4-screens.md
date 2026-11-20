# M4 design spec: web layer and screens

Status: design spec for milestone M4, part 2 of 2 (M4-D1), awaiting spec review (M4-R1). Part 1, `docs/specs/M4-core.md`, has the core, its test plan, the provisional decisions (P146 to P174), and the work-package list; this part has the web layer: file responses, routes, controllers, shell changes, templates, strings, the stylesheet and the one new script, the keyboard procedure, the visual check, the deviations from the mock-ups, and the file ownership of the web packages (section 12). Screen-by-screen designs with every string are in `docs/design/m4/`.

The M1 to M3 web layers (`docs/specs/M1-screens.md`, `M2-screens.md`, `M3-screens.md`, and their as-built notes) are extended, not replaced. Statements about M3 web classes are from the code at M3 close (`ab2c96e`), marked "(as built)".

## 1. What changes from M3

| Area | M3 | M4 |
|---|---|---|
| Response | `download()` (attachment, page CSP) | plus `file()`: inline or attachment, sandboxed CSP (section 2) |
| Routes | M3 table | 21 more (section 3) |
| Sidebar | Tables, Data, "In the trash" count as text | plus Collections group with counts and "New collection"; the trash line becomes a link to the trash screen |
| Record form | Relation and attachment fields shown, never changed | Relation control, linked-record rows with Unlink, file rows with Open, Replace, Remove, Add a file, images; notes about unsaved edits and new records |
| Scripts | `show-password`, `settings-preview`, `import-continue` | plus `collection-editor` (optional Preview on field change) |
| New screens | none for M4 | picker, upload, file page, remove confirmation, collection editor, collection view, delete collection, trash, empty-trash confirmation |

## 2. File responses (M4-WP5) (security-critical)

- `Response::file(string $path, string $mediaType, string $fileName, bool $inline): Response`: status 200; `Content-Type` = the media type exactly (no charset; must match `Response::MEDIA_TYPE`); `Content-Length`; `Content-Disposition` = `Response::contentDisposition($fileName, $inline)` (the M3 function gains the `$inline` flag: `inline; filename=...; filename*=...` or `attachment; ...`, same escaping); the response is marked **sandboxed**. The path rules of `download()` apply (absolute regular file, not a link). No after-send callback (the file stays).
- `inline` may be true only when the media type is one of `image/png`, `image/jpeg`, `image/gif`, `image/webp` (else `\InvalidArgumentException`): the web layer cannot send anything else inline even if the core were wrong.
- `SecurityHeaders::apply` (as built: replaces every security header) uses `SecurityHeaders::FILE_CSP` = `default-src 'none'; frame-ancestors 'none'; sandbox` instead of the page policy when `$response->isSandboxed()`; every other header (`nosniff`, `X-Frame-Options: DENY`, `Cache-Control: no-store`, ...) is unchanged (P150).
- HEAD on the serving route sends the headers only (as built for GET routes).
- The serving controller passes the core's `ServedFile` straight to `Response::file`; it builds no path itself.

## 3. Routes

Added to `src/Web/routes.php` (WP5, with the new controllers in the pending list of `RouterTest`). All access level `user`; authorization is in the core. `$table` is `/projects/{project}/tables/{table}` and `$record` is `$table . '/records/{record}'` (as built for `$table`).

| Method | Pattern | Handler | Package |
|---|---|---|---|
| GET | `/projects/{project}/files/{attachment}` | `FileController::serve` | WP6 |
| GET | `/projects/{project}/collections/{collection}` | `CollectionController::show` | WP7 |
| GET | `/projects/{project}/collections/{collection}/edit` | `CollectionController::editForm` | WP7 |
| POST | `/projects/{project}/collections/{collection}/edit` | `CollectionController::update` | WP7 |
| GET | `/projects/{project}/collections/{collection}/delete` | `CollectionController::deleteForm` | WP7 |
| POST | `/projects/{project}/collections/{collection}/delete` | `CollectionController::delete` | WP7 |
| GET | `/projects/{project}/trash` | `TrashController::index` | WP8 |
| POST | `/projects/{project}/trash/restore` | `TrashController::restore` | WP8 |
| POST | `/projects/{project}/trash/restore-all` | `TrashController::restoreAll` | WP8 |
| GET | `/projects/{project}/trash/empty` | `TrashController::emptyForm` | WP8 |
| POST | `/projects/{project}/trash/empty` | `TrashController::empty` | WP8 |
| GET | `$table . '/collections/new'` | `CollectionController::newForm` | WP7 |
| POST | `$table . '/collections'` | `CollectionController::create` | WP7 |
| GET | `$record . '/links/{field}'` | `LinkController::picker` | WP6 |
| POST | `$record . '/links/{field}/add'` | `LinkController::add` | WP6 |
| POST | `$record . '/links/{field}/remove'` | `LinkController::remove` | WP6 |
| GET | `$record . '/files/{field}/new'` | `FileController::uploadForm` | WP6 |
| POST | `$record . '/files/{field}'` | `FileController::upload` | WP6 |
| GET | `$record . '/files/{field}/{attachment}'` | `FileController::show` | WP6 |
| GET | `$record . '/files/{field}/{attachment}/remove'` | `FileController::removeForm` | WP6 |
| POST | `$record . '/files/{field}/{attachment}/remove'` | `FileController::remove` | WP6 |

Order: the project-level routes after `/projects/{project}/export/archive` and before `/projects/{project}/search`; the table-level collection routes after `$table . '/filter'`; the record-level routes after `$record . '/restore'`, with `/files/{field}/new` before `/files/{field}/{attachment}` (the router's first match wins; `new` is not an id, and the core refuses non-digit ids anyway). URL shapes follow ADR 0008 decision 3 and ADR 0012 decision 5 (P35); they are bookmarkable and costly to reverse.

## 4. Controllers

| Controller | Screens (design document) | Core calls |
|---|---|---|
| `RecordController` (changed) | Record form controls, new record with `from` (`record-controls.md`) | `RecordOperations::screen`, `blank`, `redisplay`, `update`, `create`, `linkOrigin` |
| `LinkController` (new) | Picker (`picker.md`) | `RecordOperations::picker`, `link`, `unlink` |
| `FileController` (new) | Upload, file page, remove confirmation, serving (`files.md`) | `AttachmentOperations::*` |
| `CollectionController` (new) | Collection view, editor, delete (`collections.md`) | `CollectionOperations::*` |
| `RecordListController`, `SearchController` (changed) | Sidebar arguments | `RecordOperations::listing`, `search`, `CollectionOperations::summaries` (through `RecordListing`/`SearchResults`) |
| `TrashController` (new) | Trash, empty confirmation (`trash.md`) | `TrashOperations::screen`, `restore`, `restoreAll`, `emptyImpact`, `empty` |
| `ProjectController` (changed) | Project settings: attachment totals | `ProjectOperations::settings` |

Rules (M1 section 2.8, M2 section 2.1, M3 section 3.1): no authorization decision in controllers; `AccessDenied` reaches the kernel (403 page), `NotFound` (404); `ValidationFailed` re-renders the same page with 422 and the error summary. Every POST answers 303 on success (to the page named in the design), so reloading never repeats a POST. Specific rules:

1. `FileController::upload` checks `UploadedFile::problem()` first and renders 422 with that problem on the file field (`Ids::make('f', 'file')`), without calling the core (M3 rule). The upload form's field name is `file`.
2. `FileController::serve` reads `download` as exactly `'1'` (anything else is inline-if-allowed); any core exception other than `NotFound`/`AccessDenied` is not caught (500 page, logged by the kernel).
3. `LinkController::add`/`remove` read `target` (string), `back` (exactly `record` or `picker`; anything else `picker`), and, for `picker`, `q` and `page`, which they put back on the 303 address as query parameters (no other parameter is copied). Problems from `link` re-render the picker with 422 and the summary.
4. `RecordController::newForm` and `create` read `from` from the query string or the POST body; with `from`, `linkOrigin` must succeed (else 404); success answers 303 to the picker of the origin record and field with a flash notice.
5. `CollectionController::create`/`update` read `action` (`save`, `preview`, `add`, `remove-<n>`); anything but `save` re-renders the editor with 200 (no state is stored); `save` with problems re-renders with 422.
6. `TrashController::empty` posts back `expected` (the record count shown); `ConfirmationRequired` re-renders the confirmation page (200) with the new counts and a notice that the trash changed.
7. `TrashController::restore` reads `table`, `record`, `view` (exactly `table`, else all tables), and `page`, and answers 303 to `/projects/{p}/trash` (with `table` and `page` when `view=table`); `restoreAll` reads `table` and answers 303 to `/projects/{p}/trash`.

## 5. Shell changes (WP5)

- **Sidebar** (`docs/design/m2/list-and-search.md` section 6, `docs/design/m4/components.md` section 2): `Sidebar::build` (as built: `build(string $projectKey, string $projectName, array $tables, int $trashCount, ?string $currentTableKey, bool $canImportExport = false)`) gains three last parameters: `array $collections = []` (a list of `array{key: string, name: string, count: int}`, plain data, so WP5 needs no core class), `?string $currentCollectionKey = null`, and `?string $newCollectionTableKey = null`. It returns `collections` (each `name`, `count`, `path` `/projects/{p}/collections/{key}`, `current`), `newCollectionPath` (`/projects/{p}/tables/{t}/collections/new`, or null when no table is given), and `trashPath` `/projects/{p}/trash`. Keys are validated with the existing pattern.
- The sidebar partial draws, after Tables and before Data: `div.sidebar-group` with `h2.sidebar-heading` `sidebar.collections` "Collections", one `a.sidebar-link` per collection (name and `span.sidebar-count`; `aria-current="page"` when current), then `a.sidebar-link.sidebar-add` `sidebar.new_collection` "New collection" when `newCollectionPath` is set. With no collections and no path, the group is not drawn.
- The trash line becomes `a.sidebar-link` to `trashPath` holding `span` `sidebar.trash` (M2 text) and `span.sidebar-count`; never current (the trash screen uses a breadcrumb).
- **Breadcrumbs**: picker, upload, file page, remove confirmation: project, table, record title, then the page (`links.crumb` "Choose {items}", `files.upload.crumb`, the file name, `files.remove.crumb`); collection editor: project, table, `collections.crumb` "Collection: {name}" or `collections.new.crumb` "New collection" (mock-up); trash: project, `trash.crumb` "Trash".

## 6. Templates

### 6.1 Shared partials (WP5)

| Partial | Data | Output (`docs/design/m4/components.md`) |
|---|---|---|
| `link-control` | `labelId`, `link` (null or `{path, title}`), `choosePath` (null when the person cannot edit or the record is new), `item` (the other table's item name, plain), `table` (its name, plain), `required` (bool), `isNew` (bool) | Section 3 of the components document: the linked title as a link or "No {item} chosen", the choose link, the help, the leave note or the new-record note |
| `link-rows` | `labelId`, `rows` (list of `{path, title, detail (plain or null), target (int)}`), `unlinkForm` (an id from `id()`, or null), `linkPath` (null or path), `item`, `isNew` | `ul.link-list` of `li.link-row.linked-row`, each with the title link, `span.link-detail`, and the Unlink button (`form` = `unlinkForm`, `name="target"`, `value` = target, `aria-label` "Unlink {title}"); then the link button, the unlink help, the leave note |
| `file-rows` | `labelId`, `files` (list of `{name, typeKey ('file.type.pdf'|'file.type.image'|'file.type.other'), size (formatted), openPath, replacePath (or null), removePath (or null), image (null or {src, alt})}`), `addPath` (or null), `imagesOnly`, `isNew` | `ul.file-list` of `li.file-row`: an image link when `image`, the name and facts, Open, Replace, Remove links with `aria-label`s naming the file; then the add link and the leave note |
| `leave-note` | none | `p.help.leave-note` `components.leave_note` |
| `file-field` (M3, changed) | `accept` may be an empty list (no `accept` attribute is written) | unchanged otherwise |

Each partial prints its own strings (`strings/en/components.php`), escapes every plain value, and takes paths only as values for `$this->url()`. Generated ids use `id()` (P96).

### 6.2 Images in templates

`img` elements appear only in `file-rows`, the file page, and `record-form/static.php`, always as `<img class="..." src="<?= $this->url($path) ?>" alt="<?= $this->e($alt) ?>" />` where `$path` is built by the controller from validated keys and an id as `/projects/{p}/files/{a}`. `TemplateRulesTest` (WP5) gains: an `img` `src` must be a `url()` call (already refused otherwise as built), no `srcset`, `width`/`height` not printed (no decoding, sizes unknown; the stylesheet bounds the box).

### 6.3 Pages

| Page | Status | Template (owner) |
|---|---|---|
| Record form, new record (changed) | 200; 422 | `templates/pages/records/form.php`, `templates/partials/record-form/field.php`, `static.php` (WP6) |
| Picker | 200; 422 after a refused link | `templates/pages/links/picker.php` (WP6) |
| Upload | 200; 422 | `templates/pages/files/new.php` (WP6) |
| File page | 200 | `templates/pages/files/show.php` (WP6) |
| Remove confirmation | 200 | `templates/pages/files/remove.php` (WP6) |
| Collection view | 200 | `templates/pages/records/list.php` in collection mode (WP7) |
| Collection editor (new, existing) | 200; 422 | `templates/pages/collections/editor.php`, `templates/partials/collections/rule-row.php`, `preview.php` (WP7) |
| Delete collection | 200 | `templates/pages/collections/delete.php` (WP7) |
| Trash; one table's trash | 200 | `templates/pages/trash/index.php` (WP8) |
| Empty the trash | 200 | `templates/pages/trash/empty.php` (WP8) |
| Project settings (changed) | 200 | `templates/pages/projects/settings.php` (WP6, the totals line only) |

## 7. Strings

| File | Owner | Content |
|---|---|---|
| `strings/en/components.php` | WP5 | `components.*` and `links.*`/`files.*` texts the shared partials print (`docs/design/m4/components.md`) |
| `strings/en/shell.php` | WP5 | `sidebar.collections`, `sidebar.new_collection` |
| `strings/en/problems.php` | WP5 | Part 1 section 11 `problem.*` rows, exactly |
| `strings/en/records.php` | WP6 | New-record notes, notices for links and files (`record-controls.md`) |
| `strings/en/links.php` (new) | WP6 | Picker (`picker.md`) |
| `strings/en/files.php` (new) | WP6 | Upload, file page, remove (`files.md`) |
| `strings/en/projects.php` | WP6 | `projects.settings.files` (totals) |
| `strings/en/collections.php` (new) | WP7 | Editor, view, delete, tests, `collection.broken.*` (`collections.md`) |
| `strings/en/lists.php` | WP7 | Collection-mode list texts |
| `strings/en/trash.php` (new) | WP8 | Trash screen and confirmation (`trash.md`) |

Unused-key rule and pending keys (P141 pattern): WP5 lists keys of its files that only wave-4 templates print in three new constants of `TemplateRulesTest`, each on its own lines: `PENDING_RECORDS` (WP6), `PENDING_COLLECTIONS` (WP7), `PENDING_TRASH` (WP8), merged into `PENDING_KEYS` with the M3 ones. Each wave-4 package empties only its own constant. `RUNTIME_PREFIXES` gains `collection.broken.`, `collections.test.`, `file.type.`.

## 8. Stylesheet and script

`public/assets/css/app.css` (WP5 only) gains every class of `docs/design/m4/components.md`. M0 to M3 rules stay (rem with the px exceptions, tokens only, no colour literal, no `url()`, no `@import`, breakpoints in `em`).

`public/assets/js/collection-editor.js` (WP5): two SPDX `//` lines, strict mode, an IIFE. On `DOMContentLoaded`, if exactly one `form[data-collection-editor]` exists, it listens for `change` on `select[data-rule-field]` and `input[name="match"]` inside it and then calls `form.requestSubmit(previewButton)` where `previewButton` is the visible `button[data-preview]` (so the server receives `action=preview`). It uses no network API, storage, `eval`, `innerHTML`, or inline styles; without JavaScript the Preview button does the same. `HtmlAssertions::SCRIPT_SRC` and the layout's script rule allow this fourth file; only the editor page requests it (`script` = `collection-editor`).

## 9. Page data and statuses

A Member who opens the empty-trash pages gets 403 (the core says access denied), and 404 for a project they are not granted; the trash screen does not show "Empty the trash" to Members. Everything else in this part is open to granted Members. An ungranted person gets 404 on every route, including the serving route.

## 10. Accessibility and the keyboard criterion

M1 section 7, M2 section 10, and M3 section 9 apply.

### 10.1 Structural checks (T-KBD-3, WP9, `tests/web/KeyboardStructureTest.php` extended)

Rendered through the kernel with real core objects as a granted Member (Admin for the empty pages), for: record form with a one-link relation (linked and empty), a many-link relation (rows and empty), files (image, PDF, other) and no files; a new record; picker (one-link and many-link, with find, page 2, a taken candidate, a trashed-link note, a 422); upload (plain and replace, with a 422); file page (image and PDF); remove confirmation; collection editor (new, existing with a broken rule, after Add, after Remove, a 422); collection view (with a broken-rules notice); delete collection; trash (all tables, one table page 2, empty); empty confirmation. The M2 checks 1 to 7 hold, plus:

1. Every Unlink, Link, Choose, Open, Replace, and Remove control has an accessible name that starts with its visible text and names its record or file ("Unlink Stand mixer, manufacturer warranty", "Open receipt-stand-mixer.pdf").
2. Every `form` attribute names an existing `form` element's id; no form is nested.
3. Each rule row is a `fieldset` with a `legend` ("Rule 1"); its controls have the mock-up's `aria-label`s ("Field for rule 1", "Test for rule 1", "Value for rule 1"); "Remove" has `aria-label` "Remove rule 1".
4. The collection editor's first submit button in tree order is the hidden Preview (`tabindex="-1"`); the visible Preview, Add a rule, and Save collection follow in reading order.
5. The picker's candidate list is a list (`ul`) whose rows each hold one title and at most one button; the find box has a visible label.
6. Every image has an `alt`; image links have the file name as their accessible name.

### 10.2 Scripted keyboard run (K-3, WP9; evidence for the exit criterion)

As M3's K-2 (headless Chromium with Playwright in the scratchpad, key presses only after the first load of `/sign-in`, focus name and screenshot after each step), on a scratch installation built from `tests/Support/M4Fixture.php`:

1. Sign in as the synthetic Member; open "Home Inventory", Items, "Stand mixer" by keyboard.
2. Tab to "Choose a different room", Enter. On the picker: Tab to the find box, type "gar", Enter; Tab to "Choose Garage", Enter; the picker shows Garage as chosen; Tab to "Back to Stand mixer", Enter; the record shows Garage.
3. Tab to "Unlink Stand mixer, manufacturer warranty", Enter; the record reloads with the notice and no linked warranty.
4. Tab to "Link a record" (no warranty is linked after step 3), Enter; Tab to "Add a new warranty and link it", Enter; type a name; Tab to Save, Enter; the picker shows the new warranty as linked; back to the record.
5. Tab to "Add a file", Enter; Tab to the file input; the file (a generated PNG) is set with `setInputFiles` on the focused input (the one non-keyboard step, as K-2); Tab to "Add the file", Enter; the record shows the image.
6. Tab to the image's "Open", Enter; the file page shows the image; Tab to "Remove", Enter; on the confirmation Tab to "Remove the file", Enter; the record shows the notice.
7. Save the record with a required relation emptied (fixture field "Room" set required for this run): the 422 summary links to the relation control; follow the link with Enter; focus lands on the control's label region; choose a room as in step 2.
8. Collections: from the Items list, sidebar "New collection", Enter; type a name; arrow keys on the match radios; in rule 1 choose the field with the arrow keys, press Enter in the name box (preview); Tab to "Add a rule", Enter; fill rule 2; Tab to "Remove rule 2", Enter; Tab to "Save collection", Enter; the collection view lists the matching records; open one record and use Next (stays in the collection).
9. Sidebar "In the trash" link, Enter; Tab to "Restore {title}", Enter; the notice says it was restored.
10. Tab to "Restore all in Items", Enter.
11. Sign out; sign in as the synthetic Admin; trash screen; Tab to "Empty the trash", Enter; the confirmation states records and files; Tab to "Empty the trash" (destructive button), Enter; the trash screen shows it empty; the emptied record's file is gone from the attachments folder (checked by the script on disk).
12. Throughout: visible focus ring on every focused element, no focus trap, no step needs a pointer except step 5's file selection.

The exit auditor re-runs K-3 and records that file selection in a native dialog was not scripted.

## 11. Web tests and visual check

### 11.1 Tests

| ID | Test | Asserts | Owner |
|---|---|---|---|
| T-WEB-50 | `RecordScreenTest` (extended) | Relation control, rows, Unlink forms (`form` ids exist, values are the targets), file rows with paths, images only for the four types, notes for new records, leave notes, required relation and attachment 422 with summary links, `from` new-record flow (303 to the picker), hostile titles and file names escaped, Member and ungranted access | WP6 |
| T-WEB-51 | `LinkScreenTest` | Picker states of `picker.md`; add and remove (303 with `q` and `page` kept, `back=record` to the record); 422 for `trashed_holds` and `target_taken`; tampered `target`, `back`, `page`; ungranted 404 | WP6 |
| T-WEB-52 | `FileScreenTest` | Upload form (limit text from `UploadLimits::effective`), upload success (303, notice), each `UPLOAD_ERR_*`, core problems (empty, too large, not image, too many, no space) as 422; replace; file page; remove confirmation and remove | WP6 |
| T-WEB-53 | `FileServingTest` | `serve` headers per type (four images inline, `?download=1` attachment, PDF and others `application/octet-stream` attachment), `Content-Length`, sandbox CSP and `nosniff` present, body bytes equal, HEAD without body; names with quotes, semicolons, non-ASCII, bidi characters | WP6 |
| T-WEB-54 | `ProjectScreensTest` (extended) | Settings totals line | WP6 |
| T-WEB-55 | `ResponseTest`, `SecurityHeadersTest` (extended) | `Response::file` inline only for the four types; disposition encoding; sandboxed flag selects `FILE_CSP`; other headers unchanged | WP5 |
| T-WEB-56 | `ComponentsTest`, `ShellTest`, `TemplateRulesTest`, `RouterTest`, `StringsTest` (extended) | Partials of 6.1 (escaping, `aria-label`s, `form` ids), sidebar Collections group and trash link, `img` rules, fourth script, route table with pending controllers, problem strings exactly as part 1 section 11 | WP5 |
| T-WEB-57 | `CollectionScreenTest` | Editor states of `collections.md` (new, existing, add, remove, preview, field change, broken rules, 422 with every problem linked from the summary), save (303 to the view), view (rules applied, find and sort kept, notice for broken rules), delete; hostile names and values escaped; posted arrays of the wrong shape give 422, never 500; ungranted 404 | WP7 |
| T-WEB-58 | `ListScreenTest`, `SearchScreenTest` (extended) | Sidebar collections with counts and current; "New collection" path for the current table; trash link | WP7 |
| T-WEB-59 | `TrashScreenTest` | Trash states of `trash.md`, restore (303, notice), restore all, empty confirmation with counts, empty (303), changed counts re-ask, Member 403 on empty pages, ungranted 404 | WP8 |
| T-WEB-60 to T-WEB-69 | `AttachmentAccessTest` | The request rows of the access-control matrix (part 1 section 12.9) | WP9 |
| T-WEB-70 | `HostileUploadTest` | Corpus: HTML, SVG with script, a PNG signature followed by HTML, a name with `../`, `/`, `\`, control characters, U+202E, a 1,000-character name, a zero-byte file, a file at the core cap and one byte over (with the PHP limits raised in the test seam), each through the route: stored or refused as specified, served only as specified, no file left in staging, no row on refusal | WP9 |
| T-SCR-4 | `ScreenStructureTest` (extended) | Every new GET route in each state: well-formed, no inline code, shell assertions, `assertForm`, title, `h1` | WP9 |
| T-KBD-3 | `KeyboardStructureTest` (extended) | Section 10.1 | WP9 |
| T-E2E-11 | `EndToEndTest` | From an empty folder with real `CoreServices`: Admin creates a project with Items, Rooms, Warranties, a photo field; a Member links, unlinks, uploads, opens, and removes through the routes | WP9 |
| T-E2E-12 | `EndToEndTest` | Collections end to end: create, view, edit, delete through the routes; archive export and import keep it | WP9 |
| T-E2E-13 | `EndToEndTest` | Trash end to end: trash a record with a file, restore, trash again, empty as Admin; the file is gone and a file shared with a live record stays | WP9 |
| T-E2E-14 | `EndToEndTest` | A version 3 project folder (built from the documented blocks) opens through the routes, is upgraded after one snapshot, and its collections page works | WP9 |

### 11.2 Visual check (V-20 to V-25)

"Matches" as M2 section 11. The record form and collection editor have mock-ups (`record.html`, `collection.html`); the others are checked against `docs/design/m4/`.

| ID | Screens |
|---|---|
| V-20 | Record form (mock-up): Member, "Stand mixer" with Room linked, one warranty, a receipt PDF and a photo, at 1360 px and 390 px |
| V-21 | Picker (one-link and many-link), upload, file page (image, PDF), remove confirmation, new record notes |
| V-22 | Collection editor (mock-up): "Under warranty" with the mock-up's two rules (second as "is after"), preview, at 1360 px and 390 px |
| V-23 | Collection view, sidebar Collections group, delete confirmation |
| V-24 | Trash (all tables, one table), empty confirmation |
| V-25 | Project settings totals line; error and 422 states of each new page |

Each at 1360 px and 390 px light, one pass in each other theme at 1360 px, and 320 px at the Largest text size; no page-level horizontal scroll.

### 11.3 Deviations from the mock-ups (proposed)

Numbered on from M3 (cite as "M4 D-31").

`record.html`:

| # | Mock-up | M4 | Reason |
|---|---|---|---|
| D-31 | "Choose a different room", "Link a warranty", "Add a file", "Replace photo", "Remove" are buttons | Links styled as buttons to their own pages; only "Unlink" is a button | P155: separate pages, no nested forms, no script |
| D-32 | "Open" on a PDF (opens the file) | "Open" goes to the file page, which offers Download; a PDF is never shown in a page | P146, P172 |
| D-33 | File "Remove" is immediate | A confirmation page | P152: removing a file cannot be undone |
| D-34 | Photo placeholder box | The stored image, bounded to 15 rem tall, linking to the file page; "Replace" with `aria-label` "Replace {file}", plus "Add an image" | P148; the field may hold more than one image |
| D-35 | No note about unsaved edits | `p.help` "Save your changes first: the buttons here leave this page." under the relation and file controls | P155 |
| D-36 | Legend "Linked warranties" | The stored legend, the field's name ("Warranties") | P93 (generated legends are data) |
| D-37 | "All changes saved" | "Last saved ..." | M2 D (P86), unchanged |
| D-38 | Not shown: new record | Notes "You can add links once this record is saved." in place of controls | P156 |
| D-47 | "Link a warranty" | "Link a record" when nothing is linked, "Link another warranty" otherwise; "Choose a record" when no room is chosen | English articles ("a"/"an") cannot be chosen from a table's item name; "another" and "a different" always fit |

`collection.html`:

| # | Mock-up | M4 | Reason |
|---|---|---|---|
| D-39 | "Add a rule", "Remove" are script buttons; live preview | Submit buttons that redisplay the form; a "Preview" button; the count updates on Preview (or on field change with script) | P163 |
| D-40 | "is on or after" | "is after" | P160 |
| D-41 | Preview columns Name, Room, Purchased, Value | Title plus the first three list columns (the list's column rule) | P163; same columns as the list |
| D-42 | "Save collection" and "Cancel" are links | "Save collection" is the primary submit button; "Cancel" a link to the collection or the table | Forms submit |
| D-43 | No delete | "Delete this collection" link to a confirmation page (existing collections) | P164 |
| D-44 | Value inputs only for some tests | A value control for every test that takes one; none for "is empty", "is not empty", "is ticked" | P160 |

`list.html` (sidebar):

| # | Mock-up | M4 | Reason |
|---|---|---|---|
| D-45 | "Trash" with a count | "In the trash" with a count, now a link | M2 wording kept (P103) |
| D-46 | "New collection" always | Shown on table and collection lists (it needs a table), not on the search page | The editor is per table |

## 12. File ownership for the web packages

| Package | Owned files | Acceptance |
|---|---|---|
| WP5 | `src/Web/Response.php`, `SecurityHeaders.php`, `Sidebar.php`, `routes.php`; `templates/layout.php` (the fourth script only); `templates/partials/sidebar.php`, `file-field.php`, `link-control.php`, `link-rows.php`, `file-rows.php`, `leave-note.php` (new); `strings/en/components.php`, `shell.php`, `problems.php`; `public/assets/css/app.css`; `public/assets/js/collection-editor.js` (new); `tests/Support/HtmlAssertions.php`; `tests/web/ResponseTest.php`, `SecurityHeadersTest.php`, `ComponentsTest.php`, `ShellTest.php`, `TemplateRulesTest.php`, `RouterTest.php`, `StringsTest.php`; `tests/web/ScreenStructureTest.php` (only a list of the four new M4 controllers left out of its "every GET route is visited" count; WP9 removes it); `tests/web/fixtures/*` | T-WEB-55, T-WEB-56 pass; every class in `docs/design/m4/components.md` exists in `app.css`; M0 to M3 web tests pass; the route table loads with the new controllers pending; design-tier review of `Response::file`, `SecurityHeaders`, the partials, and the script |
| WP6 | `src/Web/Controller/RecordController.php`, `LinkController.php` (new), `FileController.php` (new), `ProjectController.php` (settings totals only); `templates/pages/records/form.php`; `templates/partials/record-form/field.php`, `static.php`; `templates/pages/links/*.php`, `templates/pages/files/*.php` (new); `templates/pages/projects/settings.php` (totals line only); `strings/en/records.php`, `links.php` (new), `files.php` (new), `projects.php` (`projects.settings.files` only); `tests/web/RecordScreenTest.php`, `LinkScreenTest.php` (new), `FileScreenTest.php` (new), `FileServingTest.php` (new), `ProjectScreensTest.php` (totals only); the `PENDING_RECORDS` lines of `tests/web/TemplateRulesTest.php` | T-WEB-50 to T-WEB-54 pass; design-tier security review (upload path, serving controller, templates) |
| WP7 | `src/Web/Controller/CollectionController.php` (new), `RecordListController.php`, `SearchController.php` (sidebar arguments only); `templates/pages/collections/*.php`, `templates/partials/collections/*.php` (new); `templates/pages/records/list.php`; `strings/en/collections.php` (new), `lists.php`; `tests/web/CollectionScreenTest.php` (new), `ListScreenTest.php`, `SearchScreenTest.php` (sidebar only); the `PENDING_COLLECTIONS` lines of `tests/web/TemplateRulesTest.php` | T-WEB-57, T-WEB-58 pass; design-tier security review (templates, posted rule arrays) |
| WP8 | `src/Web/Controller/TrashController.php` (new); `templates/pages/trash/*.php` (new); `strings/en/trash.php` (new); `tests/web/TrashScreenTest.php` (new); the `PENDING_TRASH` lines of `tests/web/TemplateRulesTest.php` | T-WEB-59 passes; design-tier review of the templates and the bound confirmation |
| WP9 | `tests/web/EndToEndTest.php`, `ScreenStructureTest.php` (visits every M4 GET route and removes WP5's exclusion list), `KeyboardStructureTest.php` (M4 methods added, earlier ones unchanged), `AttachmentAccessTest.php` (new), `HostileUploadTest.php` (new); plus part 1 section 14 | T-WEB-60 to T-WEB-70, T-SCR-4, T-KBD-3, T-E2E-11 to T-E2E-14 pass; K-3 and V-20 to V-25 run and reported |

WP6, WP7, and WP8 must not change `app.css`, `layout.php`, shared partials, `Sidebar.php`, `routes.php`, or another package's strings; missing pieces are reported to the orchestrator and routed to a follow-up on WP5's files (M1 to M3 rule). WP6, WP7, and WP8 run in the same wave and share no file except `tests/web/TemplateRulesTest.php`, where each changes only its own pending constant's lines (P141 pattern).
