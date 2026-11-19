# M3 design spec: web layer and screens

Status: design spec for milestone M3, part 2 of 2 (M3-D1), reviewed (M3-R1) and reconciled with the M2 code and as-built notes (M3-D2). Part 1, `docs/specs/M3-io.md`, has the core, its test plan, the provisional decisions (P110 to P141), and the work-package list; this part has the web layer: uploads and downloads in the request pipeline, routes, controllers, templates, strings, the keyboard procedure, the visual check, and the file ownership of the web packages (section 11). Screen-by-screen designs with every string are in `docs/design/m3/`.

The M1 and M2 web layers (`docs/specs/M1-screens.md`, `docs/specs/M2-screens.md`, and their as-built notes) are extended, not replaced. Statements about M2 web classes are from the reviewed M2 spec, not the built code (part 1 section 18).

## 1. What changes from M2

| Area | M2 | M3 |
|---|---|---|
| Request | form fields, `origin` | plus `files` (uploaded files) and `contentLength` |
| Response | string body | plus a file body streamed from disk (`Response::download`) |
| Kernel | CSRF on every POST | first refuses a POST whose body exceeds `post_max_size` with 413 (PHP has then emptied the form, so the CSRF check would give a misleading 403) |
| Forms | `application/x-www-form-urlencoded` | upload forms use `enctype="multipart/form-data"` |
| Routes | M2 table | 21 more (section 3) |
| Top bar | Projects, People, Display | plus Plugins for Admins |
| Sidebar | no Import, Export | Import and Export for people with `io.use` (P132) |
| Project list | New project | plus "Import a project archive" for Admins |
| Scripts | `show-password`, `settings-preview` | plus `import-continue` |

## 2. Request pipeline (M3-WP3) (security-critical)

### 2.1 Uploads

- `Request` gains `public readonly array $files` (field name => `UploadedFile`) and `public readonly ?int $contentLength` (from `CONTENT_LENGTH`, digits only, else null). `Request::fromGlobals` gains a fifth argument, the `$_FILES` array; `public/index.php` passes it (WP3 owns `public/index.php` for that one change).
- `UploadedFile` (`TiffinDB\Web`): `string $field`, `string $clientName`, `int $size`, `int $error` (PHP's `UPLOAD_ERR_*`), `?string $tmpPath`. Built only for entries whose `name`, `tmp_name`, `size`, `error` are scalars (array-shaped entries from `name="x[]"` are dropped: single files only), whose field name matches `^[a-z_]{1,32}\z`, and, when `error` is `UPLOAD_ERR_OK`, whose `tmp_name` passes `is_uploaded_file()`; otherwise `tmpPath` is null. `clientName` is the basename after the last `/` or `\`, kept as text only (never a path); the core cleans it again.
- `UploadedFile::problem(): ?string` maps errors to message keys: `UPLOAD_ERR_NO_FILE` `problem.upload.missing`; `INI_SIZE`, `FORM_SIZE` `problem.upload.too_large`; `PARTIAL`, `NO_TMP_DIR`, `CANT_WRITE`, `EXTENSION`, or a null `tmpPath` `problem.upload.failed`.
- Controllers pass `tmpPath` and `clientName` to the core (`ImportOperations::start`, `ArchiveOperations::import`), which moves the file into its staging folder (part 1 section 6.1). A web test seam (`Request` constructed directly) lets tests supply a temporary file; `is_uploaded_file` is only consulted in `fromGlobals`.
- Upload limits shown on screens: `UploadLimits::effective(): int` (WP3) = the smallest of `IoLimits::MAX_UPLOAD_BYTES` as reported by the core screen objects (`maxBytes`), `upload_max_filesize`, and `post_max_size` (ini values parsed with `K`, `M`, `G` suffixes; `0` or `-1` means no PHP limit). The page states it with `Format::fileSize`. Upload forms do not use the deprecated `MAX_FILE_SIZE` field.

### 2.2 Downloads

- `Response::download(string $path, string $mediaType, string $fileName, bool $removeAfter): Response`: status 200; headers `Content-Type` (the media type, plus `; charset=utf-8` for `text/csv` and `application/json`), `Content-Length` (file size), `Content-Disposition: attachment; filename="<ascii>"; filename*=UTF-8''<percent-encoded>`, `Cache-Control: no-store`; security headers are added by the kernel as for every response. `<ascii>` is the file name with every byte outside printable ASCII, `"`, `\`, `/`, and `;` replaced by `_`; `<percent-encoded>` is `rawurlencode` of the UTF-8 name. The media type must match `^[a-z]+/[a-z0-9.+-]+\z` (else `\InvalidArgumentException`); the path must be an absolute regular file.
- `Response::send` streams a file body in 65,536-byte chunks with `fopen`/`fread`/`echo` and `flush`; on `HEAD` it sends headers only. With `removeAfter`, the file and its job folder are removed after sending (or after headers on `HEAD`) through a callback the controller supplies (`Response::withAfterSend(\Closure $done)`), which calls `ExportOperations::finish($jobKey)` so the web layer never deletes files itself. Output buffering is ended before streaming (`while (ob_get_level() > 0) ob_end_flush();` inside `send`).
- In tests, `Response::body()` reads a file body into a string; `send` is exercised with the emit callback.

### 2.3 Oversized POST

In `Kernel`, before the CSRF check and after the `Sec-Fetch-Site`/`Origin` checks: a POST whose `contentLength` exceeds `post_max_size` (when that is not 0) gets the 413 page `error.too_large.title` "That file is too large" / `error.too_large.body` "The largest file this installation accepts is {max}." with a link back to the start page; nothing else runs. `Kernel` reads `post_max_size` through `Services::uploadLimits()` (a new method returning `UploadLimits`), so tests can set it.

## 3. Routes

Added to `src/Web/routes.php` (WP3, with the new controllers added to the pending list of `RouterTest`). All access level `user`; authorization is in the core.

| Method | Pattern | Handler | Package |
|---|---|---|---|
| GET | `/projects/import` | `ArchiveController::form` | WP10 |
| POST | `/projects/import` | `ArchiveController::import` | WP10 |
| POST | `/projects/import/{job}` | `ArchiveController::rename` | WP10 |
| GET | `/projects/{project}/import` | `ImportController::start` | WP9 |
| POST | `/projects/{project}/import` | `ImportController::upload` | WP9 |
| GET | `/projects/{project}/import/{job}` | `ImportController::show` | WP9 |
| POST | `/projects/{project}/import/{job}` | `ImportController::saveMapping` | WP9 |
| GET | `/projects/{project}/import/{job}/reading` | `ImportController::readingForm` | WP9 |
| POST | `/projects/{project}/import/{job}/reading` | `ImportController::reading` | WP9 |
| POST | `/projects/{project}/import/{job}/continue` | `ImportController::continue` | WP9 |
| POST | `/projects/{project}/import/{job}/cancel` | `ImportController::cancel` | WP9 |
| GET | `/projects/{project}/import/{job}/report` | `ImportController::report` | WP9 |
| GET | `/projects/{project}/export` | `ExportController::form` | WP10 |
| POST | `/projects/{project}/export` | `ExportController::export` | WP10 |
| POST | `/projects/{project}/export/archive` | `ExportController::archive` | WP10 |
| GET | `/admin/plugins` | `PluginController::index` | WP10 |
| GET | `/admin/plugins/{kind}/{plugin}` | `PluginController::show` | WP10 |
| GET | `/admin/plugins/{kind}/{plugin}/enable` | `PluginController::enableForm` | WP10 |
| POST | `/admin/plugins/{kind}/{plugin}/enable` | `PluginController::enable` | WP10 |
| POST | `/admin/plugins/{kind}/{plugin}/disable` | `PluginController::disable` | WP10 |

Order: `/projects/import` routes before `/projects/{project}` (a literal; `import` is not a valid key, so no key can collide); the project import routes after `/projects/{project}/sharing`, with `/reading`, `/continue`, `/cancel`, `/report` before nothing that could match them; plugins routes after `/admin/people` routes. `ImportController::show` routes by job state: `reading` and `preparing` show the progress page (step 3 layout for preparing, a "Reading the file" variant for reading), `review` the review page, `ready` the progress page with the final "Saving" step, `failed` the failure page; a job that is done no longer exists (its key is the report's key). The report URL works after the job folder is gone, from `_tiffin_import_log`.

URL shapes (costly to reverse, ADR 0008) follow ADR 0008 decision 3: project-scoped under `/projects/{project}/`, instance administration under `/admin/`. P129.

### 3.1 Controllers

| Controller | Screens (design document) | Core calls |
|---|---|---|
| `ImportController` | Steps 1 to 4, reading options, failure (`import.md`) | `ImportOperations::*` |
| `ExportController` | Export (`export.md`) | `ExportOperations::screen`, `export`, `archive`, `finish` |
| `ArchiveController` | Import a project archive, name taken, archive report (`archive.md`) | `ArchiveOperations::*` |
| `PluginController` | Plugins list, plugin details, enable confirmation (`plugins.md`) | `PluginOperations::*` |

Rules (M1 section 2.8, M2 section 2.1): no authorization decision in controllers; `AccessDenied` reaches the kernel (403 page), `NotFound` (404); `ValidationFailed` re-renders the same page with 422 and the error summary. The upload controllers check `UploadedFile::problem()` first and render 422 with that problem on the file field (`Ids::make('f', 'file')`), without calling the core. The `continue` POST runs one chunk (`readChunk` or `prepareChunk`, or `commit` when the job is `ready`) and answers 303 to `GET .../import/{job}` (or to the report after a commit), so reloading never repeats a POST.

## 4. Shell changes (WP3)

- **Top bar**: `nav.top-nav` gains `shell.nav.plugins` "Plugins" to `/admin/plugins` after People, shown when `Shell::$isAdmin` (as People); `Shell::$nav` gains `plugins`.
- **Sidebar** (`docs/design/m2/list-and-search.md` section 6): when `canImportExport` is true, a group after Tables and before the trash line: `h2.sidebar-heading` `sidebar.data` "Data", then `a.sidebar-link` `sidebar.import` "Import a file" to `/projects/{p}/import` and `a.sidebar-link` `sidebar.export` "Export" to `/projects/{p}/export`, (`Sidebar::build` gains a last parameter `bool $canImportExport = false`; as built it is `build(string $projectKey, string $projectName, array $tables, int $trashCount, ?string $currentTableKey)`). The import and export pages themselves use breadcrumbs (ADR 0009 A3), not the sidebar, so neither link is ever current.
- **Project list** (`templates/pages/projects/index.php`, WP10): for Admins, after "New project", `a.button` `projects.import_archive` "Import a project archive" to `/projects/import`.
- **Breadcrumbs**: import pages: project name (to `/projects/{p}`), `import.crumb` "Import"; export: project name, `export.crumb` "Export"; archive import: `projects.crumb` "Projects", `archive.crumb` "Import a project archive"; plugins: `plugins.crumb` "Plugins" (and the plugin name on its pages).

## 5. Templates

### 5.1 Shared partials (WP3)

| Partial | Data | Output (`docs/design/m3/components.md`) |
|---|---|---|
| `steps` | `label` (key), `steps` (list of `{label (key), state ('done'|'now'|'next')}`) | `ol.steps` with `aria-label`; each `li.step` (`.step-done`, `.step-now` with `aria-current="step"`, `.step-next`): `span.step-number` ("Step 1, done", "Step 2, now", "Step 3") then the label |
| `file-field` | `name`, `label` (key), `help` (key), `helpParams`, `accept` (list of extensions), `problems` | `div.field`: label for `id('f', name)`, `p.help`, errors, `input type="file"` `id`, `name`, `accept` built from the literal list `.csv,.txt` etc. (each item `^\.[a-z0-9]{1,10}\z`), `required="required"`, `aria-describedby` |
| `facts` | `heading` (key), `id`, `items` (list of `{term (key), value (plain text)}`) | `section.panel` `aria-labelledby`: `h2`, `dl.facts` of `div` with `dt`, `dd` |
| `warning-panel` | `heading` (key), `headingParams`, `items` (list of `{key, params}`) | `section.warning-panel` `aria-labelledby` with `h2` and `ul` (`docs/design/m3/components.md` section 4) |
| `progress` | `label` (key), `done`, `total` (int or null), `status` (key), `statusParams` | `div.progress`: `p` with `id`, a native `progress` element (`max`, `value`; no `value` when the total is unknown) labelled by that `p`, and `p.progress-status` `role="status"` |
| `continue-form` | `action` (path), `label` (key), `auto` (bool; false for the final "Save the rows", which always waits for a press) | `form.continue-form` POST with `csrf` and `button.button.button-primary.button-large`; with `auto`, the form carries `data-auto-continue="1"` for the script |

### 5.2 Upload forms

`form` with `method="post"`, `enctype="multipart/form-data"`, the CSRF field, and a `file-field`. `TemplateRulesTest` (WP3) allows the literal attribute `enctype="multipart/form-data"` on `form` and nothing else for `enctype`.

## 6. Strings

| File | Owner | Content |
|---|---|---|
| `strings/en/io.php` (new) | WP3 | Shared I/O strings: step labels (`steps.import.*`), `upload.*`, `progress.*`, `format.*` names for the core-known plugins' fallback |
| `strings/en/shell.php` | WP3 | `shell.nav.plugins`, `sidebar.data`, `sidebar.import`, `sidebar.export` |
| `strings/en/errors.php` | WP3 | `error.too_large.title`, `error.too_large.body` |
| `strings/en/problems.php` | WP3 | Part 1 section 14, exactly |
| `strings/en/import.php` (new) | WP9 | `docs/design/m3/import.md`, notices |
| `strings/en/export.php` (new) | WP10 | `docs/design/m3/export.md` |
| `strings/en/archive.php` (new) | WP10 | `docs/design/m3/archive.md` |
| `strings/en/plugins.php` (new) | WP10 | `docs/design/m3/plugins.md` |
| `strings/en/projects.php` | WP10 | `projects.import_archive`, `projects.crumb` (if not present) |

Plugin-supplied text (names, setting labels and help, choice labels, `readAs`, descriptions) is printed with `e()`, never through `t()` (P133).

**Unused-key rule and pending keys (P141).** As built, `TemplateRulesTest` fails when a key in a strings file is printed by no template (and is not under a runtime prefix or in `PENDING_KEYS`), and `testPendingKeysAreStillPending` fails once a template prints a pending key. Shared keys that WP3 adds but only wave-4 templates print (for example `upload.*` help texts passed to `file-field` or `progress.*` texts passed to `progress`; keys under a runtime prefix such as `steps.import.` need no entry) are listed by WP3 in two new constants of `TemplateRulesTest`, each on its own lines: `PENDING_IMPORT` (keys printed only by WP9 templates) and `PENDING_EXPORT` (keys printed only by WP10 templates), with `PENDING_KEYS = [...self::PENDING_IMPORT, ...self::PENDING_EXPORT]`. WP9 empties `PENDING_IMPORT` and WP10 empties `PENDING_EXPORT` (only that constant's lines of `TemplateRulesTest.php` each); everything else in the file stays WP3's. A strings file owned by WP9 or WP10 holds only keys its own templates print, so it needs no pending entry. `RUNTIME_PREFIXES` in `TemplateRulesTest` gains `import.warning.`, `import.issue.`, `import.type.`, `plugins.status.`, `problem.archive.`, `steps.import.`.

## 7. Stylesheet and script

`public/assets/css/app.css` (WP3 only) gains every class of `docs/design/m3/components.md`. M0 to M2 rules stay (rem with the px exceptions, tokens only, no colour literal, no `url()`, no `@import`, breakpoints in `em`).

`public/assets/js/import-continue.js` (WP3): two SPDX `//` lines, strict mode, an IIFE. On `DOMContentLoaded`, if exactly one `form[data-auto-continue="1"]` exists and the document is visible, it disables the button, sets the status text to the button's `data-working` text (a literal string from the template), and calls `form.requestSubmit()` after 400 ms (one timer, so a keyboard user can still read the status; with `prefers-reduced-motion` it does the same: nothing moves). It uses no network API, storage, `eval`, `innerHTML`, or inline styles, and touches only that form and its status element. Without JavaScript the button works as a normal submit. `HtmlAssertions::assertNoInlineCode` and the layout's script rule (M2-screens 3.3) allow this third file; only the progress page requests it.

## 8. Page data and statuses

| Page | Status | Template (owner) |
|---|---|---|
| Step 1 (choose a file) | 200; 422 with upload or format problems | `templates/pages/import/start.php` (WP9) |
| Reading the file / preparing / saving (progress) | 200 | `templates/pages/import/progress.php` (WP9) |
| Step 2 (review) | 200; 422 with mapping problems | `templates/pages/import/review.php`, `templates/partials/import/mapping-table.php` (WP9) |
| Reading options | 200; 422 | `templates/pages/import/reading.php` (WP9) |
| Step 4 (report) | 200 | `templates/pages/import/report.php` (WP9) |
| Import failed | 200 | `templates/pages/import/failed.php` (WP9) |
| Export | 200; 422; or the download | `templates/pages/export/form.php` (WP10) |
| Import a project archive; name taken (the result is a 303 to the new project with a flash notice) | 200; 422; 303 | `templates/pages/archive/form.php`, `name.php` (WP10) |
| Plugins list; plugin; enable confirmation | 200 | `templates/pages/plugins/index.php`, `show.php`, `enable.php` (WP10) |

A Member who opens any of these URLs gets 403 for a project they are granted (the core says access denied) and 404 otherwise; the sidebar and top bar never link them there.

## 9. Accessibility and the keyboard criterion

M1 section 7 and M2 section 10 apply.

### 9.1 Structural checks (T-KBD-2, WP11, `tests/web/KeyboardStructureTest.php` extended)

Rendered through the kernel with real core objects as an Admin, for: step 1 (empty, with an upload problem), reading progress, review (new table and existing table, with warnings, with 422 problems), reading options, preparing progress, report (with issues and without), failure, export (each format selected by default in turn), archive import (form, name taken, result), plugins list, plugin page, enable confirmation. The M2 checks 1 to 7 hold, plus:

1. The mapping table's controls each have an accessible name naming the column ("Bring in name", "Field name for name", "Type for name") as the mock-up does, through `aria-label` built from the escaped heading.
2. Each radio group (target table, export format, export scope) is a `fieldset` with a `legend`.
3. The progress page's status is a live region (`role="status"`) and the Continue button follows it in reading order.
4. The file input has a visible label and its help text (accepted formats and the size limit) is referenced by `aria-describedby`.

### 9.2 Scripted keyboard run (K-2, WP11; evidence for the exit criterion)

As M2's K-1 (headless Chromium with Playwright in the scratchpad, key presses only after the first load of `/sign-in`, focus name and screenshot after each step), with a CSV file generated by `IoFixture` in the scratch folder, on a scratch installation built from `tests/Support/M2Fixture.php` (as built, `M2Fixture::build(CoreFixture, ?Operations, string $seed = M2Fixture::SEED)` makes the "Home Inventory" project with Items, Rooms, and Warranties; `IoFixture` builds on it rather than making a second project generator):

1. Sign in as the synthetic Admin; open "Home Inventory" by keyboard.
2. In the sidebar, Tab to "Import a file", Enter.
3. Tab to the file input; press Enter or Space to open the chooser. Choosing the file in the native dialog is not keyboard-scriptable in a headless browser, so the script sets the file with Playwright's `setInputFiles` on the focused input (the one non-keyboard step, recorded as such); Tab to "Continue", Enter.
4. On the review page: Tab through the target radios (arrow keys to "A new table called"), the table name, and every row of the mapping table; untick one column with Space; change one type with the arrow keys; Tab to "Import {count} rows", Enter.
5. On the progress page with JavaScript disabled for this step (second browser context): Tab to "Continue", Enter, until "Save the rows" appears; with JavaScript enabled, the reading and checking parts advance without key presses. Then Tab to "Save the rows" (never pressed automatically), Enter; the report appears.
6. On the report: Tab to "Open the table", Enter; the list shows the imported records.
7. Export: sidebar "Export", choose CSV and the table by arrow keys, Tab to "Download", Enter; the download event fires with a `.csv` file.
8. Throughout: visible focus ring on every focused element, no focus trap, no step needs a pointer except step 3's file selection.

The exit auditor records K-2 and states that file selection in a native dialog was not scripted.

## 10. Web tests and visual check

### 10.1 Tests

| ID | Test | Asserts | Owner |
|---|---|---|---|
| T-WEB-40 | `RequestTest`, `ResponseTest` (extended) | `files` built only from well-formed single entries; array-shaped entries dropped; `is_uploaded_file` consulted in `fromGlobals` only; `contentLength`; `download` headers for ASCII, non-ASCII, quotes, semicolons, and slashes in names; bad media types refused; streaming in chunks; HEAD sends no body; after-send callback runs once | WP3 |
| T-WEB-41 | `GateTest` (extended) | Oversized POST gets 413 before CSRF, with no core call; a POST at the limit passes to CSRF; `post_max_size` 0 means no check | WP3 |
| T-WEB-42 | `ComponentsTest`, `ShellTest`, `TemplateRulesTest` (extended) | The partials of 5.1 (escaping of plain-text values, `aria-current` on the current step, progress without a total), Plugins tab only for Admins, sidebar Data group only with `canImportExport`, `enctype` rule and its negative cases, the third script | WP3 |
| T-WEB-43 | `ImportScreenTest` | Every state in `import.md` through the kernel with real core objects: upload problems (each `UPLOAD_ERR_*`, no file), format choice, review defaults from a CSV, review 422 with every mapping problem linked from the summary, reading options re-reading, continue chunks (303 each time), commit and report, target changed, cancel, failure page, report after the job folder is gone; plugin text and file names containing markup escaped; a Member gets 403 and an ungranted Member 404 on every import route | WP9 |
| T-WEB-44 | `ExportScreenTest` | Form defaults; each format and scope downloads with the right headers and a body that the core's own reader accepts; disabled plugins not offered; tampered format, scope, and table give 422; archive download; Member 403 | WP10 |
| T-WEB-45 | `ArchiveScreenTest` | Archive import success (303 and the notice with the change counts), name taken then renamed, each `problem.archive.*` shown; Member 403 | WP10 |
| T-WEB-46 | `PluginScreenTest` | List with every status, plugin page, enable confirmation for a third-party plugin, enable and disable with notices, Broken and Unavailable without buttons; Member 403 on every route | WP10 |
| T-WEB-47 | `ProjectScreensTest` (extended) | "Import a project archive" link for Admins only | WP10 |
| T-SCR-3 | `ScreenStructureTest` (extended) | Every new GET route in each state: well-formed, no inline code, shell assertions, `assertForm`, title, `h1` | WP11 |
| T-KBD-2 | `KeyboardStructureTest` (extended) | Section 9.1 | WP11 |
| T-E2E-8 | `EndToEndTest` | From an empty folder with real `CoreServices`: Admin creates a project, imports a seeded CSV into a new table through the routes (upload, continue until done, report), sees the records in the list, exports CSV and gets the same bytes back (T-RT-1 through the web) | WP11 |
| T-E2E-9 | `EndToEndTest` | An import whose commit fails (injected through the core seam) leaves the project file identical; the failure page offers to start again | WP11 |
| T-E2E-10 | `EndToEndTest` | Archive export through the route, archive import through the route, the new project lists the same records; the Member granted on the original does not see the new project | WP11 |

### 10.2 Visual check (V-15 to V-19)

"Matches" as M2 section 11. The review page has a mock-up (`import.html`); the others are checked against `docs/design/m3/`.

| ID | Screens |
|---|---|
| V-15 | Review (mock-up): Admin, the mock-up's synthetic CSV (`inventory-sample.csv` generated by `IoFixture` with 52 rows and the eight columns shown), at 1360 px and 390 px |
| V-16 | Step 1, progress (reading, preparing, saving, without and with script), report, failure |
| V-17 | Export with each format chosen |
| V-18 | Archive import form, name taken, the notice on the new project |
| V-19 | Plugins list, plugin page, enable confirmation |

Each at 1360 px and 390 px light, one pass in each other theme at 1360 px, and 320 px at the Largest text size; no page-level horizontal scroll (the mapping table scrolls inside its box, as in the mock-up).

### 10.3 Deviations from `import.html` (proposed)

Deviation numbers are per milestone: M2 also has a D-15 to D-19 (`docs/specs/M2-screens.md` section 14.4). Cite these as "M3 D-15" and M2's as "M2 D-15" everywhere (ledger, reports, audits).

| # | Mock-up | M3 | Reason |
|---|---|---|---|
| D-15 | "Import 52 rows" and "Back" are links | "Import 52 rows" is a submit `button`; "Back" is a POST button "Start again" that cancels the job, plus a "Change how the file is read" link | The primary action submits the mapping |
| D-16 | `condition` proposed as Rating | Proposed as Number, Rating offered in the menu | P117 |
| D-17 | No step for reading a large file | A "Reading the file" progress page between steps 1 and 2 for files over 2,000 rows | P112 |
| D-18 | Target table name input has only an `aria-label` | The same, plus the radio's visible text "A new table called" (unchanged); the input gets `id('f', 'table_name')` for the error summary | Error summary links |
| D-19 | Warnings always exact | "In the first 5,000 rows" wording for larger files | P120 |
| D-20 | No Main navigation in the top bar | M1 navigation kept (as M2 D-1) | Consistency |
| D-21 | `value` ("329.00; 149.00") proposed as Currency | The same, because the heading is a money word (P138); a two-decimal column with another heading is proposed as Number | P138 |

## 11. File ownership for the web packages

| Package | Owned files | Acceptance |
|---|---|---|
| WP3 | `src/Web/Request.php`, `Response.php`, `Kernel.php`, `UploadedFile.php` (new), `UploadLimits.php` (new), `Services.php`, `Shell.php`, `Sidebar.php`, `routes.php`; `public/index.php` (the `$_FILES` argument only); `templates/layout.php`; `templates/partials/top-bar.php`, `sidebar.php`, `steps.php`, `file-field.php`, `facts.php`, `warning-panel.php`, `progress.php`, `continue-form.php` (new); `strings/en/io.php` (new), `shell.php`, `errors.php`, `problems.php`; `public/assets/css/app.css`; `public/assets/js/import-continue.js` (new); `tests/Support/HtmlAssertions.php`; `tests/web/RequestTest.php`, `ResponseTest.php`, `GateTest.php`, `ComponentsTest.php`, `ShellTest.php`, `TemplateRulesTest.php`, `RouterTest.php`, `StringsTest.php`, `KernelTest.php`; `tests/web/ScreenStructureTest.php` (only a list of the four M3 controllers left out of its "every GET route is visited" count: as built, that assertion counts every route whose controller class exists, so WP9 and WP10 would fail it until WP11 visits their routes; WP11 removes the list); `tests/web/fixtures/*`; `src/Web/CoreServices.php` (the `uploadLimits()` implementation only; WP8 owns the file in wave 3 to pass the plugins root) | T-WEB-40 to T-WEB-42 pass; every class in `docs/design/m3/components.md` exists in `app.css`; M0 to M2 web and stylesheet tests pass; the real route table loads with the new controllers pending; design-tier review of the upload and download code and the script |
| WP9 | `src/Web/Controller/ImportController.php`; `templates/pages/import/*.php`; `templates/partials/import/*.php`; `strings/en/import.php`; `tests/web/ImportScreenTest.php`; the `PENDING_IMPORT` lines of `tests/web/TemplateRulesTest.php` (to empty them); and, only to pass `canImportExport` to `Sidebar::build`, `src/Web/Controller/RecordListController.php` and `SearchController.php` (M2 files) | T-WEB-43 passes; design-tier security review (upload path, templates) |
| WP10 | `src/Web/Controller/ExportController.php`, `ArchiveController.php`, `PluginController.php`; `templates/pages/export/*.php`, `templates/pages/archive/*.php`, `templates/pages/plugins/*.php`, `templates/pages/projects/index.php`; `strings/en/export.php`, `archive.php`, `plugins.php`, `projects.php`; `tests/web/ExportScreenTest.php`, `ArchiveScreenTest.php`, `PluginScreenTest.php`, `ProjectScreensTest.php` (the new link assertions only); the `PENDING_EXPORT` lines of `tests/web/TemplateRulesTest.php` (to empty them) | T-WEB-44 to T-WEB-47 pass; design-tier security review (downloads, archive upload, plugin enabling) |
| WP11 | `tests/web/EndToEndTest.php`, `ScreenStructureTest.php` (visits every M3 GET route and removes WP3's exclusion list), `KeyboardStructureTest.php` (exists since M2-WP9: extended with M3 test methods, the M2 methods unchanged); plus part 1 section 16 | T-E2E-8 to T-E2E-10, T-SCR-3, T-KBD-2 pass; K-2 and V-15 to V-19 run and reported |

WP9 and WP10 must not change `app.css`, `layout.php`, shared partials, `routes.php`, or another package's strings; missing pieces are reported to the orchestrator and routed to a follow-up on WP3's files (M1 and M2 rule). WP9 and WP10 run in the same wave and share no file except `tests/web/TemplateRulesTest.php`, where each changes only its own pending constant's lines (P141; the orchestrator merges WP9 and WP10 one at a time, and the two edits touch different lines). `RecordListController.php` and `SearchController.php` are WP9's only.
