# M5 exit audit (M5-X)

Independent design-tier exit audit of milestone M5 (form editor, source editing, sanitizer) on the integration branch at `d8dc17d` (all M5 work packages, reviews, and follow-ups merged), against `docs/build/M5-M7-session-plan.md` sections 2 (M5 row) and 6.6, `docs/plan.md` section 8 (M5), `docs/ui-design-plan.md` section 11, `docs/specs/M5-forms.md`, `docs/specs/M5-screens.md`, `docs/design/m5/`, ADR 0020, `docs/formats/tiffin.md` sections 4.6 and 12, and the repository rules in `CLAUDE.md`. Every result below comes from something the auditor ran; tests, comments, ledger text, and author summaries were not taken as evidence on their own.

Evidence (scripts, logs, screenshots) is in the session scratchpad and is not committed. All data is synthetic (`CoreFixture`, `M2Fixture`, `M4Fixture`, `M5Fixture`, `FormFixture`, and hand-written forms using `example.com` addresses and generated keys).

## Set-up

- A scratch installation built from `M5Fixture` ("Home Inventory" with the edited Items form: widths, a heading, a link, a section "Where it is", the required "Serial number" hidden), plus a second project "Second Project" shared with the Member, for cross-project checks.
- A version 4 installation written by the M4-close code (`a8ca17b`, checked out by `git archive` into the scratchpad, `M4Fixture`), whose Items form was then edited by hand in the project file (the required "Serial number" slot removed, a link paragraph added, `generated` 0).
- Served by the PHP built-in server through the application's front controller (port 8110), data folder outside the application tree. Requests with a plain HTTP client (no script); keyboard and appearance runs in headless Chromium.

## Exit criteria

| Source | Exit criterion | Result | Evidence |
|---|---|---|---|
| Plan 2, plan.md 8 | Editor output equals hand-editable HTML | PASS | Auditor's own fixed-point run (seed `m5x-audit-7741`, 4,000 mutated documents; seed `m5x-audit-9902`, 6,000; seed `m5x-benign-31`, 2,000 non-canonical but valid rewritings: comments, single quotes, character references): every accepted text (361, 565, 862) gives a canonical text that parses and is a fixed point; 1,000 fresh `FormFixture` documents per seed satisfy `parse(serialize(d)) == d`. Through the web: a valid foreign draft and a hand-written source with comments are stored as canonical text; editor save, source view, download, and upload of the downloaded file give the same bytes (keyboard run) |
| Plan 2, plan.md 8 | Sanitizer test corpus passes | PASS | `SanitizerCorpusTest`, `FormParserTest`, `FormMutationTest`, `FormRejectionPrivacyTest` pass in both full runs; 66 mutations of the parser, the value rules, the `url` rule, the link node, and `formLink`: 57 killed, 9 survivors all equivalent (second-line checks, section below) |
| Plan 2, ui 11 | Editor output renders correctly under every appearance setting | PASS | Auditor's own run of the 38 `AppearanceMatrix` rows (independently confirmed to cover all 185 required pairs and the five extremes): record screen of "Stand mixer" and the editor at 1360 and 390 px, extremes also at 320 px, JavaScript off: 162 page states, each 200; checks 1 to 7 of M5-screens 10.3 all pass (no sideways scroll, no overlap, no clipping, labels tied, visual order equals source order, half and full widths within 2 px, 3 px focus ring on the link and on a button), no inline script or style |
| Plan 2 | The editor works by keyboard only | PASS | Auditor's own script (not WP7's K-4), key presses only after loading `/sign-in`, run with JavaScript on and off: 25 of 25 checks each (section below) |

## Plan section 6.6 items

| Item | Result | Evidence |
|---|---|---|
| Own hostile forms through the hidden draft, source save, upload | PASS | 43 hostile texts of the auditor's own (none from the corpus), each through all three paths, plus 6 hostile files (Latin-1, UTF-16, a PNG, an HTML page with a script, empty, 262,145 bytes) and 3 array-shaped requests: 142 cases, each 422, nothing stored (stored row compared before and after), refused source text kept exactly in the text area (escaped), every page clean. Table below |
| Own hostile forms through archive import | PASS | A refused form placed in an exported archive is dropped and the default form generated again; an accepted non-canonical form with a comment holding `</textarea><script>`, U+202E, U+FDD0, U+0085 is stored as the archive held it (P209) and renders clean on the record, editor, source, upload, and reset pages |
| Own hostile forms in a project file edited by hand | PASS | 12 forms written straight into `_tiffin_forms` (script, `onerror`, `javascript:` and apostrophe links, an entity bomb, format 2, invalid UTF-8 as a blob, and five accepted forms holding markup-like text): every Admin and Member page 200 and clean, refused forms shown with the generated layout, download gives the stored bytes exactly with the sandbox headers |
| Every rendered page holds only allowlisted elements and no inline code | PASS | Every page fetched in the runs above was parsed: only elements the templates write, inside `form#record-form` only elements of the record-form partials, no `on*` or `style` attribute, no `style` element, no `script` without `src`, every `href`, `src`, `action` relative or `http(s)`, no `target`, every `a.form-link` with `rel="noopener noreferrer"`. Also 162 appearance states: 0 inline script or style |
| Fixed-point property with the auditor's own seed | PASS | Above (exit criterion row 1) |
| Mutation checks on the parser and on the link rule | PASS | 57 of 66 killed; 9 equivalent (section below). Further mutations of the new surface: `FormOperations` checking `project.view` instead of `forms.edit` (killed by `M5PermissionsTest`), a tampered draft replaced by the stored form instead of refused (killed by `FormOperationsTest`), the download as `text/html` and not sandboxed (both killed by `FormSourceScreenTest`) |
| A Member refused on every form operation in core | PASS | All 11 `FormOperations` calls (editor read, editor hide, editor save, source read, check, save, upload page, upload, download, reset page, reset) × 7 actor cases: granted Member `AccessDenied`; ungranted Member, a project the Member cannot see, an unknown project, a table of another project, a malformed table key `NotFound`; 77 of 77 as expected, stored forms, audit rows, and project metadata unchanged. With the project file moved away, the Member still gets `AccessDenied` on all 11 (refused before the project is opened) |
| A version 4 file made by the M4 code opens and its forms can be edited | PASS | The M4-written file with a hand-edited form: editor state `stored`; hide, width, label, add section, save (`generated` 0); add a field (the new field appended to the first section, the hidden fields stay hidden); source save of the same text with CR LF (`changed` false, `rewritten` false); download equals the source text; record screen built. Afterwards `user_version` 4, `format_version` 4, `integrity_check` ok, `foreign_key_check` empty, only `_tiffin_fields` and the Items table changed (by the added field), no `format-upgrade` snapshot; opens with Python's `sqlite3`. Served pages clean; Member gets 403 on every form route and sees the link on the record |
| Editor by key presses only, also with JavaScript off | PASS | Section below |
| Appearance matrix on a sample including the extremes | PASS | Above (exit criterion row 3): all 38 rows, not only a sample |
| Manual screen-reader pass | NOT VERIFIED | As in M0 to M4 |

## Further items

| Item | Result | Evidence |
|---|---|---|
| Suite and lint | PASS | `composer test`: 10807 tests, 2236484 assertions, OK (1 skipped: no comma-decimal locale), in order and again with `--order-by=random --random-order-seed=5517`, same counts. `composer lint`: 846 files, 0 failed |
| CSRF and `Origin` on the M5 POST routes | PASS | Editor, reset, source, upload: no token, a wrong token, a foreign `Origin`: 12 of 12 answered 403, stored form unchanged |
| Archive export and import of an edited form | PASS | The M5 fixture's edited Items form exported through the Export page and imported through "Import a project archive": stored bytes identical, "Serial number" still absent from the record form, every form page clean |
| P197 and P208 on upgrade and import (as built) | PASS, as designed | A hidden required field stays hidden after archive import, and a record created in the imported copy without it is saved (303). On the M4-written file, a field hidden by hand stays hidden after an M5 editor save and after a field is added (under the M4 code it would have come back). Recorded in M5-forms section 17 item 6 |
| P209: archive import keeps accepted text as given | CONFIRMED, one finding | As built: the archive's text is parsed and inserted unchanged; `regenerate` rewrites it only when the adjustment changes it; nothing relies on stored text being canonical (save token hashes the stored text, every reader parses, the editor's draft is canonical, the next save writes canonical text, checked). No form accepted at import is refused later, except through F-1, which this item uncovered: an accepted non-canonical text whose canonical text is over the limit is rewritten by `regenerate` (a slot for a deleted field removed) to 1,572,193 bytes, and then refused as too large. Comments are not checked for characters, so a comment holding U+0085 or U+FDD0 comes back from the source view changed (comments are dropped on save; harmless) |
| P151 and P195: form images do not keep attachments alive | CONFIRMED | No attachment code reads `_tiffin_forms` (only the form store, the schema editor, the format migrations, the archive rebuild, and the collection store's key check name it) |
| Repository rules | PASS | 81 files added since M4 close: every new or changed PHP, JS, and CSS file has the SPDX line and "TiffinDB contributors"; no email address other than reserved example domains; no personal or employer name; no model name in a committed file; no network call in application code; `composer.json` and `docs/dependencies.md` unchanged since `a8ca17b` |
| Spec wording against the code | FIXED (docs only) | M5-screens 9.1 item 3 and 9.2 steps 3 and 6 named the move buttons "Move Brand up"; as built (WP3b) they are "Move up: Brand". Fixed, with as-built notes in M5-forms section 17 and M5-screens section 14 (P242 not listed in section 15, second-line checks, empty elements, F-1, P209, P197/P208, focus after Check, matrix row count) |

## Own hostile forms (hidden draft, source save, upload)

Each text below went through all three paths; every one was refused (422) and stored nothing; pages clean.

| Group | Texts |
|---|---|
| Events and namespaces | `onfocus` on a link; `xmlns` (XHTML) on a `p`; `xmlns:xlink` on the root; undeclared prefix element (`svg:script`) and attribute (`x:onclick`); upper-case `DATA-TIFFIN-FORM` |
| Addresses | literal tab inside an address; literal line feed in `java` / `script:`; an apostrophe break-out (`'onmouseover='`); a CR character reference in the scheme; full-width `ｊａｖａｓｃｒｉｐｔ:`; U+2044 in the host; image source with a trailing space, with `#x`, as `https://` |
| Characters | U+2028 in text; U+0085 in a label; U+2067 in a legend; U+FEFF in the middle; `&#x110000;`; a surrogate reference `&#55296;`; NUL |
| Structure | `br`, `a` in the root; `img` and `fieldset` in a `p`; `--` inside a comment; DOCTYPE after the root; `<?xml-stylesheet?>` after the root; an undefined entity; `svg use` with a `data:` address; `button formaction`; `meta refresh` in a section; span 13 and -1; placement on `strong`; a label of 31 base letters with combining marks (62 code points); nine levels through `strong`/`em`; a legend after a block; a root attribute given twice in two quote styles; role `details onload` |

Accepted for contrast (stored canonical, rendered escaped, no page problem): a label `"><script>…`; text `</textarea><script>…`; a legend `</legend><img onerror…>`; addresses with `%22`, with `javascript:` in the path, IPv6 with a port, `HtTpS://`; alt text holding markup; a literal tab in a label (XML attribute normalization makes it a space).

## Keyboard run (auditor's own)

Fresh installation from `M5Fixture` with the Items form reset to the standard form, headless Chromium at 1360 px, key presses only after loading `/sign-in` (the one exception: `setInputFiles` on the focused file input), run twice: JavaScript on, 25 of 25 checks; JavaScript off, 25 of 25.

Steps: sign in; Home Inventory, Tables and fields, "Change the fields of Items", "Edit the form"; Width of Category to Half by arrow keys (no submit), Label for Category "Kind", Enter applies both, no `autofocus`; "Move down: Purchased" twice, focus stays on it; "Hide Notes" (listed under "Not on this form"), "Add Notes to the form" (focus on "Move up: Notes"); "Add a section" (focus on "Section name, section 4"), name it "Storage", move Room into it by repeated Enter; "Remove section Files and keep what is in it" (focus on the first former child); "Save the form" (notice; stored text holds the edits); Source, type an `iframe` line, Check (422, "Line 25: Forms cannot hold the element iframe."), Shift+Tab to the summary link, Enter puts focus in the text area, delete the line, Save ("Nothing had changed…"); "Download the form" (named `Items form.html`, bytes equal to the stored text); upload the same file (bytes unchanged); "Back to the standard form", "Download the current form" present, "Use the standard form" (notice); sign out; the Member sees no editor link on the record and the editor address gives 403. A 3 px focus ring on every focused element; no 5xx.

## Mutation survivors (all equivalent)

| Mutation | Why no test fails |
|---|---|
| Walk's second-root check removed | libxml refuses a second root first ("extra content") |
| Text outside the root check removed | libxml refuses text before or after the root first |
| Duplicate attribute check removed | libxml refuses a redefined attribute first |
| Root namespace condition removed | A namespace needs an `xmlns` attribute, refused as an unknown attribute; a prefixed root is not named `div` |
| Element namespace check removed | Same: unreachable, documented in the code as a second line |
| Parser's own `url` type call removed (only `FormRules::isHref` kept) | `isHref` calls the same `url` type |
| `url` scheme pattern widened | `parse_url` scheme check and the authority pattern still demand `http` or `https` (removing all three is killed) |
| `url` user and password check removed | The authority pattern admits no `@` (removing both is killed) |
| `url` ASCII-only check removed | `FILTER_VALIDATE_URL` refuses non-ASCII |

## Findings

| # | Severity | Finding | Repro | Owner |
|---|---|---|---|---|
| F-1 | Medium (not blocking; fails safe) | `FormStore::regenerateOne` writes `serialize(adjusted)` without the size check that every save path and `saveDefault` have, so TiffinDB itself can store a form longer than the 262,144 bytes of format 12.1, which its own parser then refuses. Consequence: the record screen falls back to the generated layout (a hidden field, required or not, shows again), the editor opens as "could not be read", and a log line is written on every read. No unsafe content reaches a page | (a) Through the screens only: save in the source view a form whose canonical text is 20 bytes under the limit, then add a field to the table: stored 262,171 bytes, editor state `refused`, `problem.form.too_large`. (b) Through archive import: an accepted text of 262,144 bytes whose paragraph holds `'` characters (corpus Z9 shape) plus a slot for a deleted field: stored 1,572,193 bytes | WP2 residue (`src/Core/Forms/FormStore.php`): leave the stored text unchanged (or skip the append) when the adjusted canonical text is over `FormFormat::MAX_BYTES`; add a test |
| F-2 | Low (docs, fixed) | M5-screens 9.1 item 3 and 9.2 used the pre-WP3b button names; P242 is recorded in ADR 0020 and the ledger but not in M5-forms section 15 as the ledger says | Read the spec against `strings/en/forms.php` | Fixed here (docs only) |
| I-1 | Information | The error summary is announced but not focused after Check (M5-screens 9.2 step 9 said focused); keyboard access is intact | Keyboard run | As-built note; no change asked |
| I-2 | Information | A field `div`, `hr`, `img`, or `br` holding only white space is refused (`content`), as format 12.1 ("Empty") says; a hand editor may trip on it | `<div data-tiffin-field="…">` line feed `</div>` | As-built note |
| I-3 | Information | An archive's `generated` flag is trusted: an edited form marked `generated` 1 is generated again on import (edit lost, safe) | Archive with the flag changed | As-built note |

## Result

All exit criteria of plan section 2 and the items of section 6.6 pass, except the manual screen-reader pass (not verified, as in M0 to M4). No blocking finding. F-1 is a robustness defect in a path that fails safe; it is recommended for a fix before M7 work that touches upgrade or archives.
