# M5 design spec: web layer and screens

Status: design spec for milestone M5, part 2 of 2 (M5-D1), awaiting spec review (M5-R1). Part 1, `docs/specs/M5-forms.md`, holds the core, the test plan of the core, the corpus, the provisional decisions (P189 to P207), and all work packages. Screen designs: `docs/design/m5/`. Decision: ADR 0020. Designed against the tree at `1571ed8` (M4 close).

## 1. What changes from M4

- Nine routes for the form editor, source view, download, upload, and reset (section 2), all for Admins through the core's `forms.edit` check.
- Two controllers: `FormController` (editor, reset; WP5) and `FormSourceController` (source, download, upload; WP6).
- The record screen shows widths (column mode, `docs/design/m5/components.md` section 1) and real links (section 6.3). Nothing else on it changes.
- One new helper, `ViewScope::formLink`, the only way a form's address reaches an `href`; one new response, `Response::attachmentText`.
- A new action on the table's Fields page: "Edit the form".
- No script, no new token, no new font, no change to the CSP or the security headers.

## 2. Routes (M5-WP3)

Added to `src/Web/routes.php` after the table routes (`$table = '/projects/{project}/tables/{table}'`), all access `user` (signed in; the core decides the rest):

| Method | Path | Controller method | Answers |
|---|---|---|---|
| GET | `$table . '/form'` | `FormController::edit` | 200 |
| POST | `$table . '/form'` | `FormController::change` | 200, 422, or 303 (save) |
| GET | `$table . '/form/reset'` | `FormController::resetForm` | 200 |
| POST | `$table . '/form/reset'` | `FormController::reset` | 303 |
| GET | `$table . '/form/source'` | `FormSourceController::show` | 200 |
| POST | `$table . '/form/source'` | `FormSourceController::submit` | 200, 422, or 303 (save) |
| GET | `$table . '/form/download'` | `FormSourceController::download` | 200 file |
| GET | `$table . '/form/upload'` | `FormSourceController::uploadForm` | 200 |
| POST | `$table . '/form/upload'` | `FormSourceController::upload` | 303 or 422 |

The controllers do not exist when WP3 merges; `RouterTest` accepts pending controllers as in M4 (T-WEB-71). Exceptions map as everywhere (M1): `NotFound` 404, `AccessDenied` 403 (a Member), `ValidationFailed` handled by the controller (422). Every POST goes through the kernel's CSRF and `Origin` checks (M1, M2) before the controller runs.

## 3. Responses and helpers (M5-WP3) (security-critical)

### 3.1 `Response::attachmentText(string $body, string $fileName): self`

A 200 response whose body is `$body`, with `Content-Type: application/octet-stream`, `Content-Length`, `Content-Disposition` from `contentDisposition($fileName)` (attachment; the M4 cleaning of names: control and bidirectional characters, length, quotes), `Cache-Control: no-store`, and `sandboxed` true, so `SecurityHeaders` sends `FILE_CSP` (`default-src 'none'; frame-ancestors 'none'; sandbox`) and `nosniff` as for every response. No other media type is possible. `isDownload()` is true. `$body` may be any bytes (a refused stored text included).

### 3.2 `ViewScope::formLink(string $href): string`

Returns the escaped address when `$href` matches `~\Ahttps?://[\x21-\x7E]+\z~i` and holds none of `"`, `'`, `<`, `>`, `` ` ``, `\`; throws `\InvalidArgumentException` otherwise (the page fails closed; the parser makes this unreachable). It is a second guard behind the parser's `url` rule (M5-forms section 3.2, `HREF`), not a replacement.

`tests/web/TemplateRulesTest.php` (WP3) gains: `formLink` is a documented helper; its output is allowed only as the whole value of an `href` attribute, only in `templates/partials/form-inline.php`, and only on an `a` element whose other attributes are exactly `class="form-link"` and `rel="noopener noreferrer"` written literally; no template has a `target` attribute. Hostile cases in its provider: `formLink` in `src`, in `action`, in another partial, on an `a` with `target="_blank"`, and without `rel`.

### 3.3 What reaches a page from a form

| From the node model | How it is printed |
|---|---|
| Text runs, labels, legends, alt text, summaries | `e()` |
| Link address | `formLink()` in `href` only |
| Field keys, paths | `id()` for ids; `e()` in `name` and `value` of the editor's inputs and buttons (`name="label[3-2]"`: the path is checked by `EditorPath`'s pattern before it reaches a template, and printed through `e()`) |
| Spans | Only through literal class branches (`span-1` to `span-12`); never printed |
| Attachment ids (images) | The serving path through `url()` (M4) |
| Form text | Only as `textarea` content through `e()` on the source view; as the hidden `draft` `value` through `e()` on the editor; as the download body. Both page uses must give back the same bytes when posted: this holds because the parser refuses every character `e()` would replace (`ENT_DISALLOWED`: controls and noncharacters, M5-forms section 3.2) and browsers only change line breaks to CR LF, which XML reads as LF (T-WEB-79 with corpus T35) |

The hidden `draft` is the only attribute that carries form text; it is the canonical text, escaped, and it goes back to the core, which parses it again (M5-forms section 9.2).

## 4. Controllers

### 4.1 `FormController` (M5-WP5)

| Method | Calls | Renders |
|---|---|---|
| `edit` | `forms()->editor($actor, $p, $t)` | `pages/forms/edit` (layout `form`), 200 |
| `change` | `forms()->editor($actor, $p, $t, $input)` with `$input` from the request body: `draft`, `base`, `do` (strings), `label`, `legend`, `width` (arrays of strings; a non-array is `[]`, non-string entries dropped) | `saved` set: 303 to `.../form` with the flash of `docs/design/m5/editor.md` section 4. Else the page: 422 when `problems` is not empty, else 200 |
| `resetForm` | `forms()->resetScreen(...)` | `pages/forms/reset` (layout `form-narrow`) |
| `reset` | `forms()->reset(...)` | 303 to `.../form` with `notice.form_reset` or `notice.form_reset_unchanged` |

Page data for `edit` (built from `FormEditorScreen`): `table` (name, base path), `rows` (each `EditorRow` as plain arrays: `path`, `kind`, `depth`, `name` (field name, legend, or key), `typeLabel`, `required`, `label` (the input's value: `typed['label-' . path]` when set, else the slot's label, else `''`), `legend` (likewise), `width`, `span`, `placed`, `summary`, `imageHidden`, `canMoveUp`, `canMoveDown`, `children`, `errors` (problem text by input id), `focus` (the core's `focusAfter` control for this row, `up`, `down`, `first`, or `legend`, or null, for the `autofocus` of section 5.4)); `focusAddSection` (true when the core's focus is `add-section`), `notOnForm` (name, type label, required, key), `report`, `draft`, `base`, `state`, `isStandard`, `problems` (for the layout's summary: text and the input id `id('label', path)` or `id('legend', path)` it links to; form-wide problems link to nothing).

### 4.2 `FormSourceController` (M5-WP6)

| Method | Calls | Renders |
|---|---|---|
| `show` | `forms()->source($actor, $p, $t)` | `pages/forms/source` (layout `form`), 200 |
| `submit` | `forms()->source(..., ['text' => ..., 'base' => ..., 'do' => ...])` | `saved` set: 303 to `.../form/source` with the flash of `docs/design/m5/source.md` section 3. Else the page: 422 with problems, else 200 |
| `download` | `forms()->download(...)` | `Response::attachmentText($file->text, $file->fileName)` |
| `uploadForm` | `forms()->uploadScreen(...)` | `pages/forms/upload` (layout `form-narrow`) |
| `upload` | `UploadedFile` for field `file` (M3): its `problem()` first (`problem.upload.*`, 422); then `forms()->upload(..., $file->tmpPath)` | 303 to `.../form/source` with `notice.form_uploaded` or `notice.form_uploaded_rewritten`; `ValidationFailed`: the upload page, 422, with the problem written as `docs/design/m5/source.md` section 4 |

The text box's content after a refusal is the posted `text` exactly (escaped by the template). A posted `text` that is not a string is `''`.

## 5. The editor without script (P199)

### 5.1 One form

`form#form-editor` `method="post"` `action` the editor URL holds: the CSRF field; `input type="hidden" name="draft"` (the screen's `draft`); `input type="hidden" name="base"`; every row's inputs; every button. Each button is `button type="submit" name="do" value="..."`. There is no other form on the page except the layout's sign-out form, so no `form` attribute is needed.

### 5.2 Names and values

| Name | Value |
|---|---|
| `do` | `apply`, `save`, `add-section`, `add-<field key>`, `up-<path>`, `down-<path>`, `hide-<path>`, `remove-<path>`, `ungroup-<path>` |
| `label[<path>]` | Text, at most 60 characters by `maxlength` (the core checks again) |
| `legend[<path>]` | Text, at most 100 by `maxlength` |
| `width[<path>]` | `auto`, `full`, `two-thirds`, `half`, `one-third`, `other` |

Paths use `-` (`3-2`), so PHP's request parsing leaves the bracketed keys as they are.

### 5.3 Request size

The draft is at most 262,144 bytes of canonical text. Every M5 POST must fit the PHP `post_max_size` limit (8 MB by default). A request larger than the limit arrives with an empty body; the kernel's CSRF check then refuses it (403), as for any oversized post since M1. The upload is limited by the core's 262,144 bytes and by `UploadLimits::effective(FormFormat::MAX_BYTES)`; the page states 256 KB.

### 5.4 Focus without script

After a move, add, hide, remove, ungroup, or add-section, exactly one control on the returned page carries `autofocus="autofocus"`, chosen by the core's focus (M5-forms section 6.3) and the rules of `docs/design/m5/editor.md` section 4. No other page in M5 uses `autofocus`. `TemplateRulesTest` already allows only literal attribute names; `autofocus` is written in a literal branch.

### 5.5 Enter

The first submit button in tree order is the visually hidden Apply (`tabindex="-1"`), so implicit submission from a text box sends `do=apply` (P163; K-3 confirmed the pattern in headless Chromium; assumption for other browsers, M4 section 16 item 7).

## 6. Templates

### 6.1 Shared partials (M5-WP3)

| Partial | Data | Notes |
|---|---|---|
| `forms/tabs` | `base` (the table's path), `current` | `docs/design/m5/components.md` section 3 |
| `forms/row` | one row array (section 4.1), `number` (section count), `children` (rendered by recursion through the same partial) | Section 4 of components; every string key there |
| `forms/report` | `report` (`unknown`, `notShown` list of {name, required}, `requiredHidden` bool, `images`, `emptyLinks`), `table`, `full` (bool: include the not-shown list) | Section 5 of components |
| `record-form/blocks` | `nodes`, `columns` | Writes the nodes of a container: in column mode `div.form-area > div.form-cols` and one `record-form/cell` per node; otherwise each node through `record-form/block` (as M4) |
| `record-form/cell` | `node` | `div.cell` with the literal span class for `node.span` `1` to `12` (thirteen literal branches), holding `record-form/block` |
| `record-form/block` | `node` | Dispatches on `kind` to `section`, `field`, `static` (the M4 dispatch moved out of `section.php` and `pages/records/form.php`) |
| `record-form/section` (changed) | `node` gains `columns` | Chooses column mode before the M2 `record-grid`; children through `record-form/blocks` |
| `form-inline` (changed) | link nodes gain `href` | Section 6.3 |

### 6.2 Record screen (M5-WP3)

`RecordController::nodes` (as built) adds to each node array `span` (the bound node's span, or null) and, for sections, `columns`; the root's `columns` (from `BoundForm::$columns`) is passed to the page as `columns`. `RecordController::inlines` adds `href` for `link` nodes and drops a link whose text, after its children are converted, holds no character other than white space. `templates/pages/records/form.php` replaces its root loop by `record-form/blocks` with `nodes` and `columns`. Nothing else in the record controller or page changes.

### 6.3 Links

`form-inline` writes a link node as `<a class="form-link" href="<?= $this->formLink($node['href']) ?>" rel="noopener noreferrer">` + its children through `form-inline` + `</a>`, all on one line ending in a PHP tag (no white space added, as built).

### 6.4 Layout (M5-WP3)

`templates/layout.php` gains `form` (`main#main.page.page-form`) and `form-narrow` (`main#main.page.page-form.page-form-narrow`), before the default branch. Every M5 page passes one of them (T-SCR-5 asserts the `main` class per page, the P184 lesson).

### 6.5 Editor and reset pages (M5-WP5)

`templates/pages/forms/edit.php` and `reset.php`: exactly `docs/design/m5/editor.md` and `reset.md`, composed from the shared partials. They print only their own strings (`strings/en/form-editor.php`) and the shared ones through the partials.

### 6.6 Source and upload pages (M5-WP6)

`templates/pages/forms/source.php` and `upload.php`: exactly `docs/design/m5/source.md`. The `textarea` content is `<?= $this->e($data['text']) ?>` directly after the start tag and before the end tag, with no white space added (a leading line feed after `<textarea>` is dropped by HTML parsers, so the template writes a line feed first: `<textarea ...>` newline `<?= ... ?></textarea>`, and the test compares the parsed value).

## 7. Strings

| File | Owner | Holds |
|---|---|---|
| `strings/en/forms.php` | WP3 | `forms.entry` "Edit the form"; `forms.tabs.*` (components section 3); `forms.row.*`, `forms.width.*` (components section 4); `forms.report.*` (components section 5) |
| `strings/en/form-editor.php` | WP5 | `forms.editor.*`, `forms.reset.*`, `notice.form_saved`, `notice.form_saved_unchanged`, `notice.form_saved_standard`, `notice.form_reset`, `notice.form_reset_unchanged` (`docs/design/m5/editor.md`, `reset.md`) |
| `strings/en/form-source.php` | WP6 | `forms.source.*`, `forms.upload.*`, `notice.form_source_saved`, `notice.form_source_rewritten`, `notice.form_source_unchanged`, `notice.form_uploaded`, `notice.form_uploaded_rewritten` (`docs/design/m5/source.md`) |
| `strings/en/problems.php` | WP4 | `problem.form.*` (M5-forms section 4.3) |
| `strings/en/archive.php` | WP2 | `archive.change.forms_extended` (M5-forms section 7.3) |

Every key printed by a WP3 partial is used by that partial, so no key is pending and `TemplateRulesTest` gets no `PENDING_` list for M5. The texts are exactly those in the design documents. No string holds `<`, `>`, or an address.

## 8. Stylesheet (M5-WP3)

`public/assets/css/app.css` gains a section "M5: forms" with the classes of `docs/design/m5/components.md` sections 1 to 6 and the two layouts (`.page-form` max width as `.page-record`; `.page-form-narrow` as `.page-record-narrow`). The span rules sit inside `@container (min-width: 40rem)` only. `TokenContrastTest` and the M0 rem rule apply. `.form-link` reuses the link colours. No rule uses an attribute selector on `data-tiffin-*` (layout comes from classes the templates write, never from stored attributes).

## 9. Keyboard criterion

### 9.1 Structural checks (T-KBD-4, WP7, `tests/web/KeyboardStructureTest.php` extended)

Rendered through the kernel with real core objects as the synthetic Admin, for: editor (standard form; edited form with every row kind, a missing slot, an `other` span, a placed block, an empty section; after a move with `autofocus`; a 422 with a label problem; a refused draft; a conflict), source (stored, generated, refused stored, after Check, a 422), upload (plain, a 422), reset (both states), and the record screen with widths and a link. The M2 checks 1 to 7 hold, plus:

1. The editor's first submit button in tree order is the hidden Apply with `tabindex="-1"`; no other element has a `tabindex` other than `-1` or `0`.
2. At most one element has `autofocus`, and only on the editor after a change; it is the control the core's `focusAfter` names (M5-forms section 6.3): a button or the name box of the row it names, or "Add a section".
3. Every button's accessible name starts with its visible text and names its row ("Move Brand up", "Hide Serial number", "Remove section Where it is and keep what is in it", "Add Serial number to the form").
4. Every input, select, and textarea has a label whose text starts its accessible name; the textarea's problem is in its description.
5. Rows are `li` of labelled `ol` lists; a section's children are a nested `ol` inside its `li`.
6. No form is nested; every editor control belongs to `form#form-editor`.
7. Record screen: every `a.form-link` has text and `rel="noopener noreferrer"`, and no `target`; cells appear in the DOM in source order.

### 9.2 Scripted keyboard run (K-4, WP7; evidence for the exit criterion)

As K-3 (headless Chromium with Playwright in the scratchpad, key presses only after the first load of `/sign-in`, focus name and screenshot after each step), on a scratch installation built from `tests/Support/M5Fixture.php`. Run twice: once as is, once with JavaScript disabled in the browser context (M5 has no script; the second run proves it).

1. Sign in as the synthetic Admin; open "Home Inventory", Tables, Items (the Fields page) by keyboard; Tab to "Edit the form", Enter.
2. Tab to the Width menu of Name; arrow keys to "Two thirds" (the menu does not submit); Tab to the Label box of Name; type "Item name"; press Enter (Apply). The page returns with the label and width applied and no autofocus.
3. Tab to "Move Brand up", Enter; focus is on "Move Brand up" in its new place (read `document.activeElement`).
4. Tab to "Hide Serial number", Enter; "Serial number" is listed under "Not on this form" with its required note.
5. Tab to "Add a section", Enter; focus is on the new section's name box; type "Where it is"; Enter.
6. Move Room down repeatedly into "Where it is" (Enter on "Move Room down" until it is inside; focus follows).
7. Tab to "Save the form", Enter; the editor shows "The form for Items has been saved."
8. Open "Stand mixer" from the Items list; the record shows Name two thirds wide at 1360 px, no Serial number, Room under "Where it is", and the manual link (`https://example.com/manual`) is focusable and named by its text.
9. Back to the editor; Tab to "Source", Enter; in the text area press Control+End and type a new paragraph with an `onclick` attribute; Tab to "Check", Enter; the error summary is focused and reads "Line {n}: The element p cannot have the attribute onclick, or is missing one it needs."; Enter on its link puts focus in the text area.
10. Remove the typed line by key presses (Shift+Up, Delete), Tab to "Save", Enter; the notice says TiffinDB rewrote the text when it did.
11. Tab to "Download the form", Enter; the download event fires with `Items form.html` (Playwright download capture).
12. Tab to "Upload a form file", Enter; the file input is focused by Tab; the downloaded file is set with `setInputFiles` on the focused input (the one non-keyboard step, as K-3); Tab to "Upload and use it", Enter; the source view shows the notice; the stored text is unchanged (byte compare by the script).
13. Tab to "Back to the standard form", Enter; Tab to "Download the current form" (present); Tab to "Use the standard form", Enter; the editor says it is the standard form again.
14. Sign out; sign in as the synthetic Member; the record screen shows no editor link anywhere; typing the editor address gives the 403 page.
15. Throughout: visible focus ring on every focused element, no focus trap, no step needs a pointer except step 12's file selection.

## 10. Appearance matrix (V-30, WP7; evidence for the exit criterion)

### 10.1 Settings and rows

Five settings (ui-design-plan section 8, as built): theme 4 (light, dark, high-contrast light, high-contrast dark), accent 4 (teal, indigo, plum, graphite), typeface 6 (Inter, Atkinson Hyperlegible, Lexend, Source Serif 4, device sans, device serif), text size 5 (small, standard, large, larger, largest), line spacing 3 (standard, roomy, very roomy). The full product is 1,440 per width.

`tests/Support/AppearanceMatrix.php` (WP7) builds the rows deterministically, with no randomness:

1. Candidates: every combination, except that the two high-contrast themes take only the default accent (the server omits the accent there), in lexicographic order of the value lists above (900 candidates).
2. Required pairs: every pair of values of two different settings, except pairs of a high-contrast theme with a non-default accent.
3. Repeat until every required pair is covered: take the first candidate, in order, that covers the most uncovered pairs; add it.
4. Add five extremes, whether covered or not: (light, teal, Inter, largest, very roomy); (high-contrast dark, -, Source Serif 4, largest, very roomy); (dark, plum, Lexend, small, standard); (high-contrast light, -, Atkinson Hyperlegible, larger, roomy); (light, graphite, device serif, largest, standard).

`tests/web/AppearanceMatrixTest.php` is not added; instead `M5EndToEndTest` (T-E2E-16) asserts that `AppearanceMatrix::rows()` covers every required pair and has at most 45 rows, so the set used by V-30 is checked in the suite. The rows are recorded in the ledger with the run.

### 10.2 What is rendered

The record screen of "Stand mixer" with the M5 fixture form (`tests/Support/M5Fixture.php`): a `Details` section in column mode with fields at two thirds, one third, half, half, one third three times, full (long text), and automatic; a heading and a paragraph with a link inside `Details`; a section "Where it is" without widths (auto-fit stays); Files; Warranties; one hidden required field. Also the editor page itself for the same table, because the editor is "editor output" too. Each row at 1360 px and 390 px; the five extremes also at 320 px. About 45 rows × 2 pages × 2 widths plus 10, about 190 screenshots in the scratchpad.

### 10.3 Checks (by script, per screenshot)

1. No sideways scroll: `document.documentElement.scrollWidth <= innerWidth`.
2. No overlap: within each `.form-cols` and `.record-grid`, the bounding boxes of direct children do not intersect (1 px tolerance).
3. No clipping: every label, legend, heading, paragraph, input, select, and button inside the form has `scrollWidth <= clientWidth + 1`, or `overflow` visible, and lies horizontally within its cell.
4. Labels tied: every input, select, and textarea in the form has at least one label (`labels.length`), or `aria-labelledby`.
5. Visual order equals source order: in each container, children sorted by (top rounded to 4 px, then left) are in DOM order.
6. Widths take effect where they should: at 1360 px and Standard text, a half cell is (container width − gap) / 2 ± 2 px; at 390 px every cell is the container's width ± 2 px.
7. Focus ring visible on a focused link and button (one sample per row: the 3 px outline's computed width).

The script prints pass and fail counts per check; failures are screenshots to look at. The ledger records the counts. A failure is a defect for WP3 (stylesheet) or WP7 residue.

## 11. Web tests and visual check

### 11.1 Tests

| ID | Test | Asserts | Owner |
|---|---|---|---|
| T-WEB-71 | `RouterTest` (extended) | The nine routes, methods, access `user`, pending controllers | WP3 |
| T-WEB-72 | `ComponentsTest`, `StringsTest` (extended) | `forms/tabs` (current, `aria-current`, download link), `forms/row` (every kind, inputs and their names, `aria-label`s, hidden moves at the edges, escaping of hostile names, keys, summaries, alt), `forms/report` (each line, plural forms); every `forms.*` key exactly as the design | WP3 |
| T-WEB-73 | `FormSourceScreenTest` | Download headers: 200, `application/octet-stream`, attachment with the cleaned name (quotes, bidirectional characters, long names), `no-store`, `nosniff`, sandbox CSP, body bytes equal the stored text (also refused text and generated text); HEAD without body | WP6 |
| T-WEB-74 | `RecordScreenTest`, `TemplateRulesTest`, `ResponseTest` (extended) | Links: `a.form-link` with `href` and `rel`, no `target`; empty links not written; `formLink` refuses `javascript:` and others when called directly; the template rules of section 3.2 with their hostile cases; `Response::attachmentText` headers and sandbox | WP3 |
| T-WEB-75 | `FormEditorScreenTest`, `FormSourceScreenTest` | Every M5 POST route refuses a missing or wrong CSRF token and a cross-origin `Origin` (403), stores nothing | WP5 (its routes), WP6 (its routes) |
| T-WEB-76 | `RecordScreenTest` (extended) | Widths: column mode markup and classes for each span, `full` and no span as whole-width cells, auto-fit `Details` unchanged without numeric spans, root column mode, no `style` attribute anywhere on the page | WP3 |
| T-WEB-77 | `FormHostileRequestsTest` | Through the routes: the refused corpus cases as source text (422, text kept escaped, nothing stored); hostile uploads (UTF-16, a PNG, an HTML page with a script, 262,145 bytes, 0 bytes, a name with `../` and U+202E); array-shaped `text`, `do`, `base`; a Member (403) and an ungranted person (404) on every M5 route; a table key of another project and a malformed key (404) | WP6 |
| T-WEB-78 | `FormEditorScreenTest` | Editor states of `editor.md`; each `do` value; `autofocus` rules; 422 with summary links to the inputs and typed text kept; tampered `draft` (refused corpus texts, arrays, truncated) is 422, never 500; the hidden `draft` value read from the parsed page for corpus T35, T15, and an edited form, posted back with CR LF line breaks and `do=save`, saves nothing new (`notice.form_saved_unchanged`); unknown `do` is `apply`; save 303 and the three notices; conflict 422; reset confirmation both states and the reset post | WP5 |
| T-WEB-79 | `FormSourceScreenTest` | Source states of `source.md`; Check and Save; "Line" and "Near line" texts; corpus D20 and a text holding a closing `textarea` tag stay inside the `textarea` (parsed page value equals the posted text); for corpus T35, T15 (tab and line feed), and an edited form, the `textarea` value read from the parsed page, with line breaks sent as CR LF, parses to a document whose canonical text equals the stored text; upload states and notices | WP6 |
| T-WEB-80 | `SchemaScreensTest` (extended) | The Fields page shows "Edit the form" to the path of the editor | WP3 |
| T-SCR-5 | `ScreenStructureTest` (extended) | Every new GET route in each state: well-formed, no inline code, shell assertions, `assertForm`, title, `h1`, `main` class of its layout | WP7 |
| T-KBD-4 | `KeyboardStructureTest` (extended) | Section 9.1 | WP7 |
| T-E2E-15 | `M5EndToEndTest` | From an empty folder with real `CoreServices`: an Admin creates a project with Items and Rooms, arranges the form (hide, widths, section, label, move), saves; a Member opens a record: the hidden field is absent, widths and the link render, saving the record works with a hidden required field empty; the Member is refused every form route; a new field added by the Admin appears at the end of the first section and the hidden one stays hidden | WP7 |
| T-E2E-16 | `M5EndToEndTest` | Source save of a hand-written form with comments, then archive export and import into a new project: the form is the same bytes; `AppearanceMatrix::rows()` covers every required pair in at most 45 rows | WP7 |

### 11.2 Visual check (V-30 to V-34)

"Matches" as M2 section 11. M5 screens have no mock-up; they are checked against `docs/design/m5/`.

| ID | Screens |
|---|---|
| V-30 | Appearance matrix (section 10) |
| V-31 | Editor: standard form, edited form with every row kind, a 422 with a label problem, after a move (focus ring on the moved button) |
| V-32 | Source view: stored, refused stored, after Check with the report, a 422 with "Line"; download link |
| V-33 | Upload page and its 422; reset confirmation in both states |
| V-34 | Record screen with widths and a link (the record mock-up `record.html` for everything else, as M4 V-20) |

V-31 to V-34 at 1360 px and 390 px light, one pass in each other theme at 1360 px, and 320 px at Largest; no page-level sideways scroll.

## 12. Deviations and points for the maintainer

1. The source text area uses the device monospace font regardless of the typeface setting (components section 6).
2. No live preview in the editor (M5-forms section 16 item 5).
3. The editor answers changes with 200 pages (POST), so reloading after a change asks to resubmit; Save answers 303.
4. Links do not show their address or an "outside link" mark (components section 2).
5. "Upload a form file" replaces the form at once, without a separate confirmation; the page says so and offers the download first through the reset page and the form tools.

## 13. File ownership for the web packages

| Package | Files |
|---|---|
| WP3 | `src/Web/routes.php`, `src/Web/ViewScope.php`, `src/Web/Response.php`, `src/Web/Controller/RecordController.php` (node conversion), `templates/layout.php`, `templates/partials/forms/*`, `templates/partials/record-form/*`, `templates/partials/form-inline.php`, `templates/pages/records/form.php` (root loop), `templates/pages/tables/fields.php` (action), `public/assets/css/app.css`, `strings/en/forms.php`, the tests named in section 11.1 for WP3 |
| WP5 | `src/Web/Controller/FormController.php`, `templates/pages/forms/edit.php`, `reset.php`, `strings/en/form-editor.php`, `tests/web/FormEditorScreenTest.php` |
| WP6 | `src/Web/Controller/FormSourceController.php`, `templates/pages/forms/source.php`, `upload.php`, `strings/en/form-source.php`, `tests/web/FormSourceScreenTest.php`, `tests/web/FormHostileRequestsTest.php` |
| WP7 | `tests/web/M5EndToEndTest.php`, `KeyboardStructureTest.php`, `ScreenStructureTest.php`, `FormRenderMutationTest.php`, `tests/roundtrip/FormRoundTripTest.php`, `tests/Support/M5Fixture.php`, `AppearanceMatrix.php` |

When a screen package needs a change in a WP3 file, it reports it; the orchestrator assigns it to WP3's author or to WP7 as residue (P184 to P186).

## 14. As-built notes

To be appended by the M5 exit audit (M5-X).
