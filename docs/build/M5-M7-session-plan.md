# TiffinDB build session plan: M5, M6, and M7

Status: proposed, awaiting the maintainer's go-ahead. This is a session-level plan for the orchestrator. It does not replace the per-milestone design specs (`docs/specs/M5-*.md`, `docs/specs/M6-*.md`, `docs/specs/M7-*.md`), which the design tier writes during the session under `docs/build/orchestration.md` section 3. `CLAUDE.md` overrides anything here. Build state stays in `docs/build/ledger.md`.

References: "plan N" is `docs/plan.md` section N; "ui N" is `docs/ui-design-plan.md` section N; "orch N" is `docs/build/orchestration.md` section N; "P n" is a provisional decision in ledger section 4; "format N" is `docs/formats/tiffin.md` section N.

## 1. Bottom line

- One orchestrated session delivers M5 (form editor, source editing, sanitizer), M6 (legacy-product import plugin), and M7 (packaging, upgrade path, documentation, v1 release), and ends with the final audit of orch 10.
- M6 has a hard gate that only the maintainer can open: the reviewed output of the discovery run (`tools/discovery/`). Nothing in the repository guesses the legacy format, and this plan does not change that. The session order therefore depends on when the gate opens (section 4.2).
- M7 is split so that the gate cannot stall the build. Part 1 (administration screens, release build tool, upgrade path, documentation) does not depend on M6. Part 2 (release test, final visual pass, exit audit, final audit) runs after M6 closes, or after the maintainer decides to release without the importer.
- Most of the form machinery exists: form format 1, the default-deny parser, the node model, the serializer, the binder, the generator, `_tiffin_forms`, and the `forms.edit` permission are in the tree. M5 adds the write path (editor model, store writes, core operations), the screens, link rendering, width rendering, and the sanitizer corpus. No project-format step is expected for M5.
- The importer contract (API 1) cannot carry what M6 needs. It reads one table per import as text cells; it has no declared field types, no relations between source tables, and no files (`src/Core/Io/Contract/Importer.php`, `SourceTable.php`; `docs/specs/M3-io.md` section 1). M6 therefore includes a contract extension and a whole-source import path, not only a plugin.
- Size: 4 design packages, 3 spec reviews, 26 work packages, 3 exit audits, 1 final audit. Estimate 10 to 13 usage windows (M5 3 to 4, M6 3 to 4, M7 4 to 5). Basis: M1 (13 packages) took about 3 windows; the ledger's usage log does not record window counts for M2 to M4. This is an extrapolation, not a measurement.
- Nine maintainer answers would remove rework (section 3). Only the discovery output blocks anything; every other question has a provisional default.

## 2. Scope and exit criteria

| Milestone | Scope (plan 8, ui 6.2 and 11) | Exit criteria |
|---|---|---|
| M5 | Simple form editor for Admins (reorder, show and hide, width, grouping, labels); source view with validation; download and upload of a form as a file; reset to the standard form; sanitizer (the default-deny parser, hardened, with a test corpus); links in forms; widths on the record screen | Editor output equals hand-editable HTML; sanitizer test corpus passes; editor output renders correctly under every appearance setting; the editor works by keyboard only (plan 5, continuous) |
| M6 | `docs/formats/bento.md` from the discovery output; importer contract extension; whole-source import into a new project (tables, typed fields, relations, attachments); the legacy-product import plugin; seeded synthetic fixture generator; import screens and report | A synthetic fixture with the observed structure imports with relations and attachments; the import report is complete |
| M7 part 1 | Snapshot restore, project deletion, audit-log screen (P170); Admin recovery without shell access (P49); About page; favicon and app icon; release build tool; upgrade path and its tests; host and user documentation; carried-forward hardening items | Each screen meets its design; the build tool makes a reproducible archive whose contents pass the release rules; installations made by earlier code open under the release code |
| M7 part 2 | Release test from the built archive; visual pass of every screen in every theme; exit audit; final audit (orch 10) | The release archive installs by upload and setup page only (verified here on the PHP built-in server; the reference shared host is pending maintainer verification, orch 7); visual pass of every screen in every theme |

Not in this session: the WYSIWYG form editor (M8) and the MCP server (M9), which are post-v1 and need an explicit maintainer instruction; non-Apache hosting and desktop packaging (P5); creating a release tag or a hosted release (section 3 row 9).

## 3. Maintainer decisions that would help before the start

| # | Question | Default if not answered | Why it matters now |
|---|---|---|---|
| 1 | Discovery output for the legacy format (plan 6.4, ADR 0009 D1) | None possible. M6 does not start; the session runs M5, then M7 part 1, then stops (section 4.2) | M6 fixtures must reproduce the observed structure; guessing it would break the clean-room rule |
| 2 | Is v1 released without the legacy importer if the discovery output has not arrived when M7 part 1 closes? | No. The session stops with a release candidate and waits. ADR 0004 puts the importer in v1 scope | Decides whether M7 part 2 may run before M6 |
| 3 | Form CSS in v1. ADR 0003 stores a form as HTML "plus a scoped CSS block"; ADR 0014 reserved the `css` column and left it to M5 | The column stays reserved and ignored. Layout comes only from the placement attributes, shown through stylesheet classes. Per-form styling moves to M8 with the token-drawn choices of ADR 0006 | A CSS sanitizer is a second attack surface (addresses in `url()`, `@import`, selectors that leave the form). The strict CSP forbids style blocks in the page, so form CSS would also need its own serving route. The column exists, so adding it later needs no format step. This default narrows ADR 0003 and needs confirmation |
| 4 | What is stored when an Admin saves form source: the text as typed, or TiffinDB's own rewriting of it? | The rewriting (parse, then serialize). Comments and spacing are not kept, and the source view says so | Makes "editor output equals hand-editable HTML" exact and testable: every stored form is a fixed point of parse and serialize. Keeping typed text means two forms with the same meaning can differ |
| 5 | Links in forms (ADR 0009 E2) | Shown as real links from M5: `http` and `https` only, by the `url` field type's rules, with `rel="noopener noreferrer"`, no `target`. Images stay attachment references only | Settles the "no external URLs" rule of plan 4 for links. A link is followed by the person, not fetched by TiffinDB |
| 6 | More than one form per table | One form per table in v1: the default form is the one shown and edited. Other rows in `_tiffin_forms` are kept untouched and not listed | The table allows several forms, but no document describes choosing between them |
| 7 | Name of the legacy importer in the UI and in plugin metadata (ADR 0009 D5, P11) | Plugin id `legacy-package`, directory `plugins/import/legacy-package/`, name shown to Admins "Mac personal database package". The legacy product is named only in documentation prose and in `docs/formats/bento.md` | `CLAUDE.md` forbids the legacy name in the UI and in package metadata. Whether a file extension that contains the name may be shown in the UI is the maintainer's call; the default does not show it |
| 8 | Admin recovery without shell access (P27, P49) | Built: a one-time code file written into the data folder, read through the host's file manager, on the pattern of the setup code. The host documentation also recommends a second Admin | A lone Admin on a host with no shell has no recovery path today. Documentation only is cheaper; the maintainer may choose it |
| 9 | Release publication | The session builds the archive with a documented command, records its SHA-256 in the ledger, and stops. The maintainer creates the tag and any hosted release | Publication is outward-facing and not reversible |

Also open, with defaults in the sections named: whether the session stops at each milestone close (section 4.3); the largest legacy package the importer must accept (section 7.2 row 3); screenshots in the user documentation (section 8.2 row 9).

## 4. Session shape

### 4.1 Phases

| Phase | Work | Tier | Runs alongside |
|---|---|---|---|
| 0 | Resume set-up (section 5) | orchestrator | - |
| 1 | M5 design (M5-D1), spec review (M5-R1), rulings applied | design | - |
| 2 | M5 implementation, waves 1 to 4 | implementation | M7 design (M7-D1) and its review (M7-R1), using one of the three agent slots; M6-D0 if the discovery output has arrived |
| 3 | M5 exit audit (M5-X), fixes, as-built notes, ledger close, notification | design | - |
| 4 | M6: format document (M6-D0), design (M6-D1), review (M6-R1), waves 1 to 5, exit audit (M6-X) | design, implementation | - |
| 5 | M7 reconciliation (M7-D2): the M7 spec author is resumed with the as-built notes of the milestones closed so far; then M7 part 1, waves 1 and 2 | design, implementation | - |
| 6 | M7 part 2: release test and visual pass (wave 3), exit audit (M7-X), final audit (M7-A), fixes, final build, end-of-build notification | design, implementation | - |

### 4.2 Gates and order

| Gate | Opens when | Blocks |
|---|---|---|
| G-spec (per milestone) | That milestone's spec review is merged and its rulings are applied | Every work package of the milestone |
| G-close (per milestone) | The milestone before it, in the order below, is recorded CLOSED in the ledger | Every work package of the next milestone. Designs may run earlier (section 4.1) |
| G6-report | The maintainer supplies the reviewed discovery report (section 3 row 1) | M6-D0 |
| G6-doc | The maintainer approves the text of `docs/formats/bento.md` before it is committed (section 7.1) | M6-D1 and all M6 packages |
| G7-release | M6 is CLOSED, or the maintainer sends `DECIDE release-without-importer: yes` | M7 part 2 |

Order by gate state:

| State at M5 close | Order |
|---|---|
| G6-doc open | M5, M6, M7 part 1, M7 part 2 |
| G6-report open, G6-doc pending | M5, M7 part 1 while the approval is pending, then M6, then M7 part 2 |
| G6-report not open | M5, M7 part 1, then stop with a notification (section 11). The session resumes at M6 when the gate opens |

M7 part 1 never depends on M6. When M6 closes after M7 part 1, the only M7 work that follows it is M7-WP11 (documentation for the importer) and part 2.

### 4.3 Rules in addition to orch 2 and 3

1. Concurrency: at most three agents at once, including a design agent that runs alongside implementation (orch 3 step 3).
2. No implementation package of a milestone is dispatched before its G-spec and G-close gates are open. If an exit audit fails a criterion after root-cause analysis and one redesign, that is a blocker (orch 7) and the next milestone does not start.
3. Model floor for design work: the floor set by the maintainer for M4 (`docs/build/M4-session-plan.md` section 4) carries over to every design-tier call in this session: specs, ADRs, format documents, screen designs, spec reviews, rulings, root-cause redesigns, the security reviews named in sections 6.5, 7.5, and 8.5, the exit audits, and the final audit. The orchestrator sets the model explicitly on each call (orch 2 rule 1). If no model at or above the floor is available, that is a blocker. The ledger records only that the floor was met (orch 8).
4. Reviewer independence (orch 2 rules 3 and 5): a reviewer or auditor is a different agent instance from the author and receives the code, the spec, and the properties to check, never the author's conclusions. In M6 the fixture generator (M6-WP3) and the plugin (M6-WP4) are written by different agents, each from the format document only, so that one misreading cannot pass on both sides.
5. Checkpoint after every merge: commit, push, ledger. The ledger names unpushed worktree work at all times (orch 9).
6. Each agent brief names its own scratch subfolder and port, starts with `git merge --ff-only <assigned branch>`, and lists the owned files and the files it must not touch (orch 2 rule 6).
7. Mechanical tier: no package is planned as mechanical. A spec may mark a fully specified package or part as mechanical (for example third-party notices entries, the screen catalogue for the visual pass, or the fixture generator once the format tables are exact); its output is then reviewed under orch 2 rule 2.
8. Stops: the session does not stop between milestones. It sends one notification at each milestone close and continues. It holds only at G6-doc, at G7-release, and before any publication (section 3 row 9). The maintainer may send `PAUSE` or `STOP` at any time (orch 7).

## 5. Phase 0: resume set-up

1. Record the window start and the assigned integration branch in ledger section 1; arm the single resume reminder (orch 6).
2. Add `/.claude/` to `.git/info/exclude`. Rebuild the trimmed offline PHPUnit install with the pinned XLSX library (orch 9; ledger note "M3-WP0 merged"). Run `composer lint` and `composer test`; expected 9498 tests, 1 skipped, lint 786 files, as at M4 close.
3. Bring three stale status lines up to date in one commit: `CLAUDE.md` ("Status" paragraph still says M2 is next), `docs/plan.md` (status line says no application code exists), and orch 1 (role sentence, to match section 4.2 of this plan). These are statements of fact, not decisions.
4. Record in the ledger whether G6-report is open.

## 6. M5: form editor, source editing, sanitizer

### 6.1 Design outputs (M5-D1, reviewed by M5-R1)

| Output | Content |
|---|---|
| `docs/specs/M5-forms.md` | Sanitizer statement (what is accepted, what is refused, by which class); problem reporting with a position; stored-text rule; editor model and its operations; store writes; core operations and authorization; audit actions; test plan mapped to the exit criteria; the corpus catalogue; work packages with exact owned files |
| `docs/specs/M5-screens.md` | Routes, controllers, templates, the editor's no-script behaviour, source view, upload and download, reset confirmation, width classes on the record screen, strings, keyboard and visual procedures, the appearance matrix |
| `docs/design/m5/` | Designs for the screens with no mock-up (ui 6.2): simple form editor, source view, upload, reset confirmation; component notes |
| ADR 0020 (number indicative) | Form editing in v1: sanitizer position (parse and rebuild; whether the typed safe-HTML value of ADR 0008 decision 4 is still not needed), stored-text rule, links, form CSS position, one form per table, form images and attachment life (closes the P151 open point). Settles ADR 0009 E2 |
| `docs/formats/tiffin.md` | Sections 4.6 and 12 brought to as-built: `css` and `generated` wording, how links and widths are shown. No format step unless the design finds one unavoidable |

### 6.2 Design points the spec must settle, with the recommended direction

| # | Point | Recommendation | Reason |
|---|---|---|---|
| 1 | What "sanitizer" means here | Keep ADR 0014 decision 4: stored form text is never printed. The parser refuses a whole form on any rule break; the page is built from the node model by templates. No stripping, no safe-HTML type | One path already holds for M2 to M4 and for archive import; a second, lenient path is the classic sanitizer failure |
| 2 | Problem reporting | `FormRejected` carries a reason code only (no position). Add the line number and a message key per reason so the source view can say what is wrong and where, without echoing content into logs | The source view needs "validation" (plan 4) |
| 3 | Stored text | Section 3 row 4: every write stores `serialize(parse(text))`. Property: parse of the stored text succeeds and serializing it again gives the same bytes | Exit criterion 1 |
| 4 | Editor without JavaScript | One page per table's form. Every control is a submit button that redisplays the page (the P163 pattern): move up, move down, hide, show, width, label, add a section, rename a section, remove a section. The working copy travels in a hidden field as form text and is parsed again on every request; Save writes it. No server-side drafts | Every screen works without scripts; a hidden draft is untrusted input and takes the same parser as everything else |
| 5 | What the simple editor edits | Sections (`fieldset` with `legend`) and field slots: order, visibility, width, label, grouping. Static elements already in a form (headings, text, dividers, images, from source editing) appear as fixed rows that can be moved or removed and are otherwise kept exactly | ADR 0003 scope; ADR 0006 needs the editors to round-trip without loss |
| 6 | Width | `data-tiffin-span` from a short list (full, two thirds, half, one third) on the 12-column grid, shown through stylesheet classes; one column on narrow screens; source order equals reading order. `data-tiffin-col` and `data-tiffin-row` are kept and shown in reading order, as now. Generated forms keep the P95 auto-fit layout | No inline styles (ui 10); ADR 0006 keeps explicit placement for M8 |
| 7 | Hidden fields | Hiding removes the slot; fields with no slot are listed under "Not on this form" with an Add button. The editor warns when a required field is hidden (requiredness applies only to fields the form shows, P118 and P157) | Format 12.4 already defines a missing slot |
| 8 | Reset | "Back to the standard form" through a confirmation page that offers a download of the current form first; sets `generated` to 1 and regenerates. No form history in v1 | Plan decision 12 gives undo for records only |
| 9 | Download and upload | Download as an attachment with a neutral media type and `nosniff`, never shown in the page. Upload: one file, at most the format's 262,144 bytes, parsed against the table; slots that name no field of this table are accepted and reported (format 12.4) | A form file comes from outside like any upload |
| 10 | Form images | The simple editor does not create images. Source editing may name an attachment; the save reports an image whose file cannot be shown. A form reference still keeps no attachment alive (P151 stands) | A form-owned file store is new storage design; out of v1 |
| 11 | Authorization and audit | New `Core\App` form operations, Admin only (`forms.edit`); a Member is refused in core. Audit actions `form.saved`, `form.reset` (amends ADR 0012 decision 4). Form writes update the project's `updated_at` | Plan 4: Members cannot edit forms; authorization lives in core |
| 12 | Corpus | Text fixtures under `tests/core/forms/corpus/`, each with its expected verdict and reason: script and event attributes; `style`, `class`, `id`; foreign elements (SVG, MathML, `iframe`, `object`, `form`, `input`, `template`); DOCTYPE and entity attacks; processing instructions; CDATA; namespaces and prefixed attributes; `javascript:`, `data:`, and `vbscript:` addresses with case, white space, and character-reference disguises; control and bidirectional characters; invalid UTF-8 and other encodings; the size, element, and depth limits at and over the edge; duplicate attributes and duplicate fields; texts that an HTML parser and an XML parser read differently. Plus a seeded mutation test: a changed valid form is either refused or renders with only the allowed elements and no inline code | Exit criterion 2; the differential cases are where a parse-and-rebuild design can still fail |
| 13 | Appearance matrix | Editor output on the record screen under 4 themes, 4 accents, 6 typefaces, 5 text sizes, 3 line spacings: a pairwise-reduced set plus the extremes (320 px, largest text, very roomy), at 1360 px and 390 px. Checks: no sideways scroll, no overlap or clipping, labels tied to controls, visual order equal to source order | Exit criterion 3; the full product is 1,440 combinations per width |

### 6.3 Work packages

Indicative. The M5 spec fixes ids, exact owned files, and acceptance tests; it may move a file between packages but must keep concurrent packages disjoint.

| ID | Title | Tier | Depends on | Security-critical |
|---|---|---|---|---|
| M5-WP1 | Sanitizer: parser problem positions, link and limit rules, fixed-point serializer, corpus and mutation tests | implementation | none | Yes |
| M5-WP2 | Editor model and store writes: pure edit operations on the node model; save, reset, and read for editing in the store | implementation | none | No |
| M5-WP3 | Web components and rendering: binder and record-form partials (widths, links, image notes), editor and source partials, stylesheet, layouts for every M5 page, all M5 routes, shared strings, entry link | implementation | none | Yes (templates) |
| M5-WP4 | `Core\App` form operations: open for editing, apply an edit to a draft, save, save source, upload, download, reset; authorization; audit actions; layer-rule and message-key test updates | implementation | WP1, WP2 | Yes |
| M5-WP5 | Editor screens: editor page and its actions, reset confirmation | implementation | WP3, WP4 | Yes |
| M5-WP6 | Source screens: source view with problems, download, upload | implementation | WP3, WP4 | Yes |
| M5-WP7 | End-to-end tests; round trips between editor, source, file, and project archive; keyboard-only run; appearance matrix run; visual check against `docs/design/m5/` | implementation | WP5, WP6 | No |

Owned files and acceptance:

| ID | Owned files (new files in italics are indicative names) | Must not touch | Acceptance |
|---|---|---|---|
| M5-WP1 | `src/Core/Forms/FormParser.php`, `FormRules.php`, `FormSerializer.php`, `FormRejected.php`, `FormFormat.php`; `tests/core/forms/FormParserTest.php`, `FormSerializerTest.php`; *`tests/core/forms/corpus/`*, *`SanitizerCorpusTest.php`*, *`FormMutationTest.php`* | Node classes, `FormStore.php`, anything under `src/Web`, `templates` | Every corpus case gives its recorded verdict and reason; fixed-point property holds for every accepted case and for 1,000 seeded generated forms; a rejection names a line; no rejection message or log line holds form content |
| M5-WP2 | Node classes in `src/Core/Forms/` (`FormDocument.php`, `Section.php`, `FieldSlot.php`, `Heading.php`, `Paragraph.php`, `Divider.php`, `Image.php`, `Placement.php`, inline classes); `FormStore.php`, `StoredForm.php`, `FormGenerator.php`; *`FormEditor.php`*; `tests/core/forms/FormStoreTest.php`, `FormNodesTest.php`, `FormGeneratorTest.php`, *`FormEditorTest.php`* | WP1 files, `FormBinder.php` | Each edit operation gives a document the serializer writes and the parser accepts; static elements survive every operation unchanged; save sets `generated` 0, reset sets it to 1; a table with no stored row can be edited and saved; schema changes after an edit still add and remove slots (ADR 0014 decision 3) |
| M5-WP3 | `src/Core/Forms/FormBinder.php`, `BindData.php`, `Bound*.php`; `templates/partials/record-form/`, `templates/partials/form-inline.php`, *`templates/partials/forms/`*; `templates/layout.php`; `public/assets/css/app.css`; `src/Web/routes.php`; `src/Web/Sidebar.php`; *`strings/en/forms.php`*; `tests/web/TemplateRulesTest.php` (a `PENDING_FORMS` list per screen package, P141), `ComponentsTest.php`, `tests/core/forms/FormBinderTest.php` | Controllers, page templates, `src/Core/App` | Widths render through classes with no inline style; a link renders with the fixed `rel` and no `target`; a link with a refused address cannot reach a page (parser refuses it; the template has no path for it); every M5 page has its layout entry (lesson of P184 to P186); hostile label, legend, and text values are escaped |
| M5-WP4 | *`src/Core/App/FormOperations.php`* and its value classes; `src/Core/App/Operations.php`; `src/Core/System/AuditAction.php`; `strings/en/problems.php`; *`tests/core/app/FormOperationsTest.php`*, *`M5PermissionsTest.php`*; `tests/core/app/MessageKeysTest.php`, `tests/repo/CoreLayerRulesTest.php` | `src/Core/Forms`, `src/Web` | A Member is refused on every method; a draft from a hidden field is parsed before use; a stale or foreign table key gives not found; one audit row per save and reset; nothing is written when the parser refuses |
| M5-WP5 | *`src/Web/Controller/FormController.php`*; *`templates/pages/forms/edit.php`*, *`reset.php`*; its own `PENDING_FORMS` lines; *`tests/web/FormEditorScreenTest.php`* | `app.css`, shared partials, `routes.php`, another package's strings | Every edit action works as a plain form post; CSRF refused without a token; a tampered draft gives the page with problems, never a 500; focus lands on the moved row |
| M5-WP6 | *`src/Web/Controller/FormSourceController.php`*; *`templates/pages/forms/source.php`*, *`upload.php`*; its own `PENDING_FORMS` lines; *`tests/web/FormSourceScreenTest.php`*, *`FormHostileRequestsTest.php`* | As WP5 | A refused source shows the problem with its line and keeps the typed text in the box, escaped; download headers are attachment, neutral type, `nosniff`; upload over the cap or without CSRF stores nothing |
| M5-WP7 | *`tests/web/M5EndToEndTest.php`*, *`tests/roundtrip/FormRoundTripTest.php`*; `tests/web/KeyboardStructureTest.php`, `ScreenStructureTest.php`; residue fixes the orchestrator assigns | Product code, except assigned residue | Editor to source to file to upload gives byte-identical stored text; an edited form survives archive export and import; keyboard-only run and the appearance matrix run recorded in the ledger |

### 6.4 Waves

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP1, WP2, WP3 | M5-R1 merged (the third slot goes to M7-D1 when one of these finishes first) |
| 2 | WP4 | WP1 and WP2 merged |
| 3 | WP5, WP6 | WP3 and WP4 merged |
| 4 | WP7 | wave 3 merged |

### 6.5 Reviews

| Package | Reviewer tier | Properties to confirm by running |
|---|---|---|
| WP1 | design (security) | The reviewer's own hostile forms, not from the corpus, are refused or render clean; mutation of each parser rule fails a test; no external entity or DTD is ever loaded |
| WP3 | design (security) | Nothing from a form reaches a page except through the escaping helper; link `href` values come only from the node model after the address rule |
| WP4, WP5, WP6 together | design (security) | Hidden draft, source save, upload, and download paths; Member and cross-project attempts; CSRF and origin refusals |
| WP2, WP7 | implementation | Spec conformance; tests not vacuous |

### 6.6 Exit audit (M5-X)

Independent design-tier audit against section 2, plus: hostile forms of the auditor's own through each entry path (hidden draft, source save, upload, archive import, a project file edited by hand); every rendered page holds only allowlisted elements and no inline code; fixed-point property with the auditor's own seed; mutation checks on the parser and on the link rule; a Member refused on every form operation in core; a version 4 file made by the M4 code opens and its forms can be edited; the editor by key presses only, also with JavaScript off; the appearance matrix. Manual screen-reader pass: recorded as not verified, as in M0 to M4.

## 7. M6: legacy-product import

### 7.1 Gate procedure (G6-report, G6-doc)

1. The maintainer runs `tools/discovery/discover-structure.php` by the procedure in `tools/discovery/README.md` and supplies the reviewed report. The report stays in the session scratchpad. It is never committed, in whole or in part.
2. M6-D0 (design tier) writes `docs/formats/bento.md` in TiffinDB's own words: container layout, databases, tables, columns, how field definitions and field types are stored, how records, links, and files are stored, and which versions the report covers. It uses a name from the report only where the name is clearly the product's own and not chosen by a person.
3. The orchestrator sends the text to the maintainer for approval before the commit (plan 6.4: identifier names can themselves be personal). This is a hold, headed `BLOCKER` (orch 7). M7 part 1 continues meanwhile.
4. If the report shows that field definitions are stored as rows and structure alone does not show the mapping, the README's `--value-shapes` step is needed. That is a second maintainer round; M6-D0 states exactly which table it needs.

### 7.2 Design outputs (M6-D1, reviewed by M6-R1) and design points

| Output | Content |
|---|---|
| `docs/specs/M6-legacy-import.md` | Contract extension, untrusted-source reader, whole-source import pipeline, type map, relation and attachment handling, report, core operations, screens, test plan, work packages with exact owned files |
| `docs/design/m6/` | Start page, review of a whole source, progress, report |
| ADR 0021 (number indicative) | Importer contract API 2 and the whole-source import path (amends ADR 0015) |
| ADR 0022 (number indicative) | Legacy importer: name and id (section 3 row 7), supported versions, type map, what is not imported |
| `docs/formats/tiffin.md` | Import log wording for a whole-source import; a format step only if the log row cannot hold the report additively |

| # | Point | Recommendation | Reason |
|---|---|---|---|
| 1 | Contract | Add a second importer interface under contract API 2 for sources that describe a whole project: tables with declared field types, relations between source tables, row pages per table with link cells as source ids, and file streams. API 1 plugins are unchanged | Plan 6.1 already describes this neutral model; API 1 was cut down to what CSV and XLSX need |
| 2 | Target | Always a new project, with no grants, as archive import does. No merge into an existing project | Reuses the reviewed new-project path; a merge needs conflict rules nobody has asked for |
| 3 | Upload | The legacy database is assumed to be a package (a folder), which a browser cannot upload as one file; the person uploads a zip of it. The 64 MiB cap (P116) stays by default. If the discovery output shows files inside the package, the design adds a second source: a zip the Admin places in an inbox folder under the data folder and picks from a list (no path is typed) | Assumption, unverified until the report. A package with photographs can exceed any shared host's upload limit; the real limits are known only from the host check |
| 4 | Reading the source database | The zip and the database inside it are hostile input: the zip checks of archive import, read-only and immutable open, no trust in the schema, no view or trigger ever run. Plugins may not hold SQL text (`PluginLayerRulesTest`), so the core gives the plugin a read-only source reader through the contract: list tables and columns, page through a named table | To confirm in the design that the layer test refuses SQL in plugins as the ledger describes; if so the reader belongs in core |
| 5 | Commit | The M3 staging job model: read in chunks across requests, stage files, then one final transaction creates the project content; files are placed by the attachment store inside that write. A failure leaves no project and no files | Plan 6.1; ADR 0015 decision 1; ADR 0018 |
| 6 | Review step | One page: tables, fields, proposed types, what will not be imported. The Admin may rename or skip a table or a field. Types are changed afterwards in the schema editor | Plan 6.1 asks for review; a full mapping table for many tables at once is a large screen with little gain |
| 7 | Type map | A table in the spec from every type observed in `docs/formats/bento.md` to a TiffinDB type, or to text; anything not mapped is imported as text where possible and always listed in the report (plan 6.4). Calculated and encrypted fields (deferred in plan 3.4) are reported, not imported | Exit criterion 2 |
| 8 | Report | Per table: records read and imported; per field: type chosen, values emptied and why; links made and links whose target was missing; files imported, missing, over the cap, or over 100 per field (P147); source features not imported (layouts, saved searches, anything else observed) | "Import report complete" is checked against the generator's ground truth |
| 9 | Legacy layouts and saved searches | Not imported. Forms are generated (plan 6.1). Listed in the report | Clean-room; TiffinDB has its own form format |
| 10 | Versions and templates | Only the versions the report covers; anything else is refused with a plain message. Template files are out of v1 unless the report covers them and they share the structure | `CLAUDE.md` open decision |
| 11 | Fixtures | Generated at test time by a seeded generator with a documented seed, with a ground-truth description of what it wrote. No binary fixtures committed | `CLAUDE.md` fixture rules |

### 7.3 Work packages

Indicative; the M6 spec fixes ids, exact owned files, and acceptance tests.

| ID | Title | Tier | Depends on | Security-critical |
|---|---|---|---|---|
| M6-WP1 | Contract API 2 values and interface; registry accepts API 2; untrusted-source reader | implementation | none | Yes |
| M6-WP2 | Whole-source import pipeline: job, chunked read, file staging, preparation, single commit into a new project, report, import log | implementation | WP1 | Yes |
| M6-WP3 | Seeded synthetic fixture generator and its structure test against `docs/formats/bento.md` | implementation | WP1 | No |
| M6-WP4 | The plugin: manifest, importer, type map, plugin tests against a hand-built source | implementation | WP1 | Yes |
| M6-WP5 | `Core\App` operations for a whole-source import: start, continue, review, commit, cancel; authorization; audit; strings | implementation | WP2 | Yes |
| M6-WP6 | Screens and components: start, progress, review, report; routes, layouts, stylesheet, entry link | implementation | WP5 | Yes |
| M6-WP7 | End-to-end import of the fixture with relations and attachments; report check against ground truth; hostile corpus; failure injection; timing; keyboard-only run; visual check | implementation | WP3, WP4, WP6 | No |

| ID | Owned files (indicative) | Must not touch | Acceptance |
|---|---|---|---|
| M6-WP1 | New files in `src/Core/Io/Contract/`; `src/Core/Io/Plugin/`; *`src/Core/Io/Source/`*; `tests/core/io/ContractTest.php`, `tests/core/io/plugin/`, `tests/repo/PluginLayerRulesTest.php` | Existing API 1 contract files except additive changes the spec names | API 1 plugins load and pass unchanged; the reader never runs a view or trigger, never writes beside the source, and refuses identifiers it did not list itself |
| M6-WP2 | *`src/Core/Io/ProjectImport/`*; `src/Core/Io/IoLimits.php`, `StagingArea.php`, `ImportLog.php`; *`tests/core/io/projectimport/`* | The API 1 pipeline classes (`Reader`, `Preparer`, `Committer`), `src/Core/App` | One transaction; injected failure at each step leaves no project folder, no registry row, no file; limits enforced per table and in total |
| M6-WP3 | *`tests/Support/LegacyFixture.php`*, *`tests/core/io/LegacyFixtureTest.php`* | Everything else | Same seed gives the same bytes; the generated structure matches every table and column the format document lists; ground truth lists every record, link, and file written |
| M6-WP4 | *`plugins/import/legacy-package/`* (name per ADR 0022); *`tests/plugins/legacy-package/`* | `src/` | Plugin layer rules pass; every type in the format document has a row in the type map and a test; unknown versions refused; the legacy product's name appears in no manifest field |
| M6-WP5 | *`src/Core/App/ProjectImportOperations.php`* and value classes; `Operations.php`, `AuditAction.php`; `strings/en/problems.php`; *`tests/core/app/ProjectImportOperationsTest.php`*; `MessageKeysTest.php` | `src/Core/Io`, `src/Web` | A Member is refused on every method; a job belongs to the Admin who started it (P134); one audit row per import |
| M6-WP6 | *`src/Web/Controller/ProjectImportController.php`*; *`templates/pages/project-import/`*; `templates/layout.php`, `app.css`, `routes.php`, `strings/en/import.php`, `TemplateRulesTest.php`; *`tests/web/ProjectImportScreenTest.php`* | `src/Core` | Works without JavaScript with a Continue button per chunk; hostile table and field names escaped; the legacy product's name appears in no string or template |
| M6-WP7 | *`tests/roundtrip/LegacyImportTest.php`*, *`tests/web/M6EndToEndTest.php`*, *`tests/core/io/LegacyHostileTest.php`*; assigned residue | Product code, except assigned residue | Exit criteria of section 2, by test; 20,000 and 50,000 record timings recorded |

### 7.4 Waves

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP1 | M6-R1 merged and M5 CLOSED |
| 2 | WP2, WP3, WP4 | WP1 merged |
| 3 | WP5 | WP2 merged |
| 4 | WP6 | WP5 merged |
| 5 | WP7 | WP3, WP4, WP6 merged |

### 7.5 Reviews and exit audit

Design-tier security review (orch 2 rule 4: every importer that parses user-supplied files, plugin loading): WP1, WP2, WP4, and WP5 with WP6. Implementation-tier review: WP3, WP7.

M6-X: independent design-tier audit against section 2, plus: fixture import with the auditor's own seed, report compared line by line with the ground truth; hostile corpus (traversal names in the zip, a database with triggers and views, sizes that lie, row counts past the caps, a truncated database, an unknown version); a failed import leaves nothing; a Member refused in core; the new project opens with plain SQLite tools and matches `docs/formats/tiffin.md`; clean-room check (no vendor material; the legacy name only in documentation prose and the format document; none in templates, strings, or `plugin.json`); mutation checks. Not verifiable here: an import of a real legacy file. That is a maintainer-side trial on the maintainer's own machine with a release build, outside the repository, recorded as pending maintainer verification.

## 8. M7: packaging, upgrade path, documentation, release

### 8.1 Design outputs (M7-D1, reviewed by M7-R1, reconciled by M7-D2)

| Output | Content |
|---|---|
| `docs/specs/M7-release.md` | Release archive contents and exclusions; build tool; version scheme; upgrade procedure and tests; documentation outline; carried-forward items with their owners; release test procedure; screen catalogue for the visual pass; work packages with exact owned files |
| `docs/specs/M7-screens.md` | Snapshots (list, take, restore), project deletion, audit log, Admin recovery, About page, icons; routes, templates, strings, keyboard and visual procedures |
| `docs/design/m7/` | Designs for those screens (ui 6.2 lists the About page; the others come from P49 and P170) |
| ADR 0023 (number indicative) | Release packaging, versioning, and the upgrade path |
| ADR 0024 (number indicative) | Snapshot restore, project deletion, audit-log screen, Admin recovery |

### 8.2 Design points the spec must settle, with the recommended direction

| # | Point | Recommendation | Reason |
|---|---|---|---|
| 1 | Archive contents | `public/`, `src/`, `templates/`, `strings/`, `plugins/`, `config/app.php`, `vendor/` without development packages, the root `.htaccess`, `LICENSE`, `README.md`, a third-party notices file, `tools/admin/`, `tools/host-check/`, `docs/host/`, `docs/user/`, `docs/formats/`, `docs/dependencies.md`. Not shipped: `tests/`, `tools/dev/`, `tools/discovery/`, `docs/build/`, `docs/specs/`, `docs/design/`, `.github/` | Plan 3.2; the application has no build step, so the archive is source. The "Source code" link stays the AGPL section 13 offer |
| 2 | Build tool | `tools/release/build.php`: takes a prepared `vendor/`, checks every shipped package against `docs/dependencies.md` (version and source reference, ADR 0016 decision 2), writes a zip with sorted entries, fixed times, and fixed modes, plus its SHA-256. Two runs give the same bytes. It does not download anything | Composer dist downloads are refused in the build environment (orch 9); a reproducible archive lets the maintainer rebuild and compare |
| 3 | Release rules test | Fails on: a test or development file in the archive; `config/local.php`; any data file pattern of `.gitignore`; a nested `.htaccess` with rewrite directives under `vendor/` (ledger section 7); a file with no SPDX header where one is required; a shipped package missing from the dependency table | Ledger section 7 item for M7 packaging |
| 4 | Version | `config/app.php` `version` becomes `1.0.0` in the final build commit; release candidates are `1.0.0-rc.N`. A `CHANGELOG.md` starts at 1.0.0. The tag is the maintainer's (section 3 row 9) | Asset addresses already carry the version (ADR 0008) |
| 5 | Upgrade path | Procedure: replace the application folders, keep the data folder and `config/local.php`; the system database and each project upgrade on first use after a snapshot (already built). Tests: installations generated at every earlier project format (1 to current) and system schema (1 to current) open under the release code; a data folder written by a newer version is refused with a plain page; a simulated next version step runs through the existing test seam. Stale files left by an upload over an old copy: the procedure says which folders to delete first | Plan 8 M7 scope; only project-file steps were described before (context brief 12.23) |
| 6 | Snapshot restore | Admin only (`snapshots.manage`), through a confirmation page; takes a snapshot of the current state first; replaces the project file inside the project lock. Snapshots hold the database, not the files: after a restore, attachment rows whose file is gone are reported on the page (serving already answers "not found"), and files no row names are swept | P49; the file mismatch is the part that needs design |
| 7 | Project deletion | Admin only, confirmation page that states record, file, and snapshot counts and requires the project name to be typed; offers the project archive first; removes the registry row, grants, and the project folder. Recursive removal is allowed for a project folder by an explicit rule (amends P64 and P127). Audit `project.deleted` | Irreversible; no trash for projects in v1 |
| 8 | Audit-log screen | Admin only (`audit.view`), newest first, 50 per page, filter by person, project, and action; values escaped; read-only | P49; `AuditOperations::recent` exists |
| 9 | Documentation | `docs/host/`: requirements, install by upload and setup page, data folder placement, HTTPS and reverse proxies (the `Origin` note from the ledger), upgrade, backup and restore (archive, and by hand per format 13.4), password reset script, Admin recovery, plugins and their full privileges (plan 6.2, stated plainly), limits, reading the host check. `docs/user/`: first steps, records, lists and search, collections, files, trash, forms, import and export, display settings. Synthetic examples only; no screenshots in v1 | `CLAUDE.md` rules on examples; screenshots would need a seeded generator and add binary files |
| 10 | About page and icons | `/about` for signed-in people: version, licence, "Source code" link, third-party notices; server facts (PHP and SQLite versions) for Admins only. Favicon from `docs/brand/tiffindb-mark-small.svg`, app icon from `docs/brand/tiffindb-app-icon.svg`, both served from `public/` | ui 10 and 11; plan 7 |
| 11 | Static assets | `public/.htaccess` sets a CSP and cache headers for static files (ledger section 7). Behaviour on Apache is reasoned, not run, until the host check (orch 9) | Assets are served by Apache without the application's headers |
| 12 | Probe port | An optional `probe_port` key in `config/local.php` closes the P80 port question | P80 deferred it to M7 |
| 13 | Manual checks | A short procedure for the manual screen-reader pass, for the maintainer; the build has never verified it | Plan 5 asks for one per milestone; recorded as not verified since M0 |

### 8.3 Work packages

Indicative; the M7 spec fixes ids, exact owned files, and acceptance tests. Part 1 is WP0 to WP9; part 2 is WP10 and WP11.

| ID | Title | Tier | Depends on | Security-critical |
|---|---|---|---|---|
| M7-WP0 | Carried-forward hardening (section 9.3): `Origin` malformed state, probe port key, overlong file name keeps its extension (P187), error suppression in the maintainer tools, remaining low items the spec confirms are still open | implementation | none | Yes |
| M7-WP1 | Core administration: snapshot restore, project deletion, audit listing with filters, repeated failed-upgrade snapshots (P183), audit actions | implementation | none | Yes |
| M7-WP2 | Core Admin recovery (section 3 row 8) | implementation | none | Yes |
| M7-WP3 | Web components: About and administration partials, icons in the page head, `public/.htaccess` static headers, stylesheet, layouts and routes for every M7 page, shared strings, navigation links | implementation | none | Yes |
| M7-WP4 | Screens: snapshots (list, take, restore) and project deletion | implementation | WP1, WP3 | Yes |
| M7-WP5 | Screens: audit log, About page, recovery | implementation | WP1, WP2, WP3 | Yes |
| M7-WP6 | Release build tool, release rules test, third-party notices, CI step, version | implementation | none | Yes |
| M7-WP7 | Upgrade-path tests: generators for earlier formats, newer-version refusal, simulated next step | implementation | WP1 | No |
| M7-WP8 | Host documentation and `README.md` | implementation | WP1, WP2, WP6 | No (security statements reviewed by the design tier) |
| M7-WP9 | User documentation | implementation | M5 CLOSED | No |
| M7-WP10 | Release test from the built archive (both layouts: `public/` as the web root, and the whole tree under the web root); screen catalogue visual pass in every theme; whole-application keyboard structure run | implementation | part 1 merged; G7-release | No |
| M7-WP11 | Documentation for the legacy importer; consistency pass over `docs/host/` and `docs/user/` against the as-built screens | implementation | M6 CLOSED | No |

| ID | Owned files (indicative) | Must not touch | Acceptance |
|---|---|---|---|
| M7-WP0 | `src/Web/Kernel.php`; the one file that cleans attachment names (P187; the spec names it); `tools/host-check/`, `tools/discovery/` and their tests; other files the spec lists, each with one item | Files owned by WP1 to WP3 | Each item has a test that fails before the change; the host check still parses on its PHP floor by inspection (no old binary here) |
| M7-WP1 | `src/Core/Storage/Snapshots.php`, `Fs.php`; `src/Core/App/SnapshotOperations.php`, `ProjectOperations.php`, `AuditOperations.php`; `src/Core/System/AuditLog.php`, `AuditAction.php`, `ProjectRegistry.php`; their tests | `src/Web`, `Kernel.php` | A Member is refused; restore under injected failure leaves the current file intact; deletion removes exactly the project folder and never follows a link; a second failed upgrade does not push out an older snapshot |
| M7-WP2 | *`src/Core/Auth/Recovery.php`* (or under `Core\Install`, per the spec); *`src/Core/App/RecoveryOperations.php`*; `strings/en/problems.php`; tests | WP1 files | The code is one-time, expires, is throttled, is compared in constant time, and is never echoed; recovery ends every session of the account and is audited; nothing is disclosed to a visitor who has no file access |
| M7-WP3 | `templates/layout.php`, *`templates/partials/admin/`*, `templates/partials/footer.php`, `top-bar.php`; `public/assets/css/app.css`; `public/.htaccess`, *icon files under `public/`*; `src/Web/routes.php`, `Sidebar.php`; *`strings/en/admin.php`*, *`about.php`*; `TemplateRulesTest.php`, `HtaccessTest.php`, `ShellTest.php` | Controllers, page templates | Icons load under the CSP; no inline code; every M7 page has its layout entry; `.htaccess` rules covered by `HtaccessTest` |
| M7-WP4 | *`src/Web/Controller/SnapshotController.php`*, `ProjectController.php`; *`templates/pages/snapshots/`*, `templates/pages/projects/` (delete page); screen tests | `app.css`, shared partials, `routes.php` | Confirmation pages re-validated on the second post; typed-name check in core; CSRF refusals |
| M7-WP5 | *`src/Web/Controller/AuditController.php`*, *`AboutController.php`*, *`RecoveryController.php`*; their page templates; screen tests | As WP4 | Hostile audit values escaped; server facts absent for a Member; recovery pages give the same response whether or not the account exists |
| M7-WP6 | *`tools/release/`*, *`tests/tools/ReleaseBuildTest.php`*, *`tests/repo/ReleaseContentsTest.php`*; `config/app.php`; `.github/workflows/ci.yml`; `docs/dependencies.md`; *`THIRD-PARTY-NOTICES.md`*, *`CHANGELOG.md`*; `.gitignore` if needed | `src/`, `public/` | Two builds are byte-identical; the rules of section 8.2 row 3 each have a failing case |
| M7-WP7 | *`tests/roundtrip/UpgradePathTest.php`*, *`tests/Support/HistoricFixture.php`* | Product code; a defect found goes to the file's owner | Every earlier format opens with one snapshot per step and unchanged data; newer-version refusal shown |
| M7-WP8 | *`docs/host/`*, `README.md` | `docs/user/` | Every command and path in the text exists in the tree (checked by a test or by the reviewer running it); no personal data; plugin privileges stated plainly |
| M7-WP9 | *`docs/user/`* | `docs/host/` | Every screen name and button label quoted matches `strings/en/` (checked by a test) |
| M7-WP10 | *`tests/web/ReleaseInstallTest.php`*; assigned residue | Product code, except assigned residue | Setup from the unpacked archive to a first record with no step outside the browser; visual pass counts in the ledger |
| M7-WP11 | Named sections of `docs/user/` and `docs/host/` | Everything else | As WP8 and WP9 |

### 8.4 Waves

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP0, WP1, WP6, then WP2 and WP3 as slots free | M7-D2 merged and the previous milestone CLOSED |
| 2 | WP4, WP5, then WP7, WP8, WP9 as slots free | WP1, WP2, WP3 merged; WP8 also needs WP6 (WP9 needs only M5 CLOSED and may fill a free slot in wave 1) |
| 3 | WP10, WP11 | Part 1 merged and G7-release open (WP11 also needs M6 CLOSED) |

### 8.5 Reviews, exit audit, final audit

Design-tier security review (orch 2 rule 4): WP0 (request pipeline), WP1 (deletion, restore), WP2 (account recovery), WP3 (headers, templates), WP4 with WP5, WP6 (what ships), and the security statements in WP8. Implementation-tier review: WP7, WP9, WP10, WP11.

M7-X: independent design-tier audit against section 2, plus: the archive rebuilt by the auditor has the recorded SHA-256; install from the archive in both layouts on the built-in server; an installation made by the code at M4 close upgrades under the archive; restore, deletion, and recovery attacked as a Member and across projects; shipped packages equal `docs/dependencies.md`; no network call in application code (as M3: none under a system-call trace); visual pass of every screen in every theme at 1360 px and 390 px, and at 320 px with largest text. Recorded as pending maintainer verification: the reference shared host (orch 7), `.htaccess` behaviour on Apache, the manual screen-reader pass, a real legacy file.

M7-A (orch 10): final design-tier audit of the whole codebase by an agent that took no earlier part in the session: repository rules, licences, SPDX coverage, the security baseline of plan 7, accessibility checks, portability exports. Findings are fixed by the owning package's author; the archive is rebuilt; the hash in the ledger is replaced.

## 9. Cross-milestone items

### 9.1 Format and schema steps

| Item | Expected | If the design finds otherwise |
|---|---|---|
| Project format (now version 4) | No step in M5 or M7. M6: none if the import log's JSON report can hold a whole-source report additively | A step is designed with its exact upgrade SQL in `docs/formats/tiffin.md` section 11, taken after a snapshot, as versions 2 to 4 were |
| Form format (now 1) | Unchanged. M5 adds no element or attribute | A new attribute means form format 2; avoid in v1 |
| System schema (now version 2) | Version 3 only if Admin recovery needs a table | Designed in ADR 0024 |
| Plugin contract (now API 1) | API 2 added in M6, API 1 kept | ADR 0021 |
| JSON export format (now 1) | Unchanged | - |

### 9.2 Files with one owner per wave

`src/Web/routes.php`, `templates/layout.php`, `public/assets/css/app.css`, `src/Web/Sidebar.php`, `tests/web/TemplateRulesTest.php`, `src/Core/App/Operations.php`, `src/Core/System/AuditAction.php`, `strings/en/problems.php`, `tests/core/app/MessageKeysTest.php`, `tests/web/KeyboardStructureTest.php`, `docs/formats/tiffin.md`, `docs/dependencies.md`, `config/app.php`, `.github/workflows/ci.yml`.

In each milestone the components package owns the first five and adds the routes, layouts, and pending string lists for every screen package before wave 3 (the M3 and M4 pattern, P141). Screen packages do not change the stylesheet, shared partials, `routes.php`, or another package's strings. When a screen package needs a change in a components file, it reports it; the orchestrator assigns it to the components author or to the end-to-end package (as P184 to P186).

### 9.3 Carried-forward items

Candidates read from the ledger. M5-D1 and M7-D1 confirm each against the tree before assigning it, because some were closed in M4-WP0 and M4-WP9 without a ledger line per item.

| Item | Source | Assigned to |
|---|---|---|
| Whether form images keep attachments alive | P151 | M5-D1 (section 6.2 row 10) |
| `Origin` value that is overlong or malformed is read as absent; add a refuse state | M2-WP0 review | M7-WP0 |
| Reverse-proxy risk of the `Origin` check, for the deployment documentation | M2-WP0 review | M7-WP8 |
| Operator-configured probe port | P80 | M7-WP0 |
| Repeated failed format upgrades each take a snapshot | P183 | M7-WP1 |
| Overlong file name loses its extension | P187 | M7-WP0 |
| Error-suppression operators in `tools/host-check/` and `tools/discovery/`; discovery-tool nits (internal-name allowlist, fail-closed extension fallback, README wording) | ledger section 7, M3 exit | M7-WP0 |
| Static assets served without a CSP; nested `.htaccess` scan of release contents | ledger section 7 | M7-WP3, M7-WP6 |
| Snapshot restore, project deletion, audit-log screen | P49, P170 | M7-WP1, WP4, WP5 |
| Admin recovery without shell access | P27, P49 | M7-WP2, WP5 |
| Sign-in throttle nits (bucket cleared outright on success; takeback after a window reset) | M1-WP2 round 3 | M7-D1 decides: fix in WP0 or accept with a note |
| Import lows: "Check again" loses a typed table name; over-200-column CSV message; no test for the XLSX early stop; commit does not re-read the actor inside the write | M3 exit, M3-WP8 review | M7-D1 decides per item |
| Bidirectional control characters kept in shown file names and headings | M2-WP5, M3-WP9, M3-WP10 reviews | M7-D1 decides |
| Words that break mid-word at 320 px with largest text | M1-WP8d, M2 exit | M7-WP3 if a rule fixes it; else accepted |
| Stale design documents (`docs/design/m1/installer.md`, `docs/design/m3/export.md`) | M3 notes | M7-WP11 consistency pass, if still stale |
| XLSX late-chunk time; commit and rebuild times on a shared host; upload limits; direct access to `attachments/` | M3 and M4 exits | Host check (maintainer) |

## 10. Risks

| Risk | Effect | Mitigation |
|---|---|---|
| Discovery output arrives late or never | M6 cannot start; v1 scope of ADR 0004 not met | M7 split (section 4.2); G7-release decision is the maintainer's |
| Legacy format differs by version, or field definitions are rows that structure alone does not explain | Extra maintainer rounds; importer covers fewer files | M6-D0 states what is missing; unsupported versions refused plainly; unmapped types imported as text and reported |
| Fixture and importer share one misreading of the format | Tests pass, real files fail | Different authors from the format document only (section 4.3 rule 4); maintainer trial on real files after the build |
| Importer tested only on synthetic data | Unknown behaviour on real files | Stated as pending maintainer verification; never claimed as verified |
| A form text that an XML parser and a browser read differently | Stored XSS | Stored text is never printed; pages are built from the node model; differential cases in the corpus; design-tier review; M5-X hostile forms |
| Hidden-field draft in the editor is trusted by mistake | Parser bypass | Draft parsed on every request by the same parser; review property in section 6.5 |
| Form CSS default (section 3 row 3) is not what the maintainer wants | M5 rework | Column reserved; adding CSS later is additive; asked before the start |
| Snapshot restore leaves rows without files | Broken attachments shown as present | Reported on the restore page; serving already answers "not found"; sweep for unreferenced files |
| Project deletion removes the wrong folder | Data loss | Containment check, no link following, typed-name confirmation, archive offered first; design-tier review |
| Release archive differs from what was tested | Untested code ships | Reproducible build; hash in the ledger; M7-WP10 and M7-X test the built archive, not the working tree |
| Apache behaviour is reasoned, not run | Install fails on the reference host | Host check is the maintainer's; recorded as pending, never as passed |
| Session length (10 to 13 windows) | Context loss; unpushed work lost on reclaim | Ledger is the only state; checkpoint after every merge |

## 11. Assumptions and unknowns

1. The tier-to-model mapping is given in the session prompt (orch introduction), subject to the design-tier floor of section 4.3 rule 3.
2. The integration branch is the branch assigned to that session; it starts from `main`, which holds all of M0 to M4.
3. The build environment is as in orch 9: no Apache, Composer dist downloads refused, headless Chromium available.
4. Provisional decisions P1 to P188 stand unless the maintainer changes them; new open points are settled under orch 5 and recorded from P189 onward.
5. ADR numbers 0020 to 0024 are indicative; the design tier assigns them in order.
6. File names shown as new in sections 6.3, 7.3, and 8.3 are indicative. Existing files named there were read in the tree at the time of writing.
7. Not known until the discovery report: the container form (package, single file, or archive), whether files are inside it, how field types and links are stored, which versions exist, and so the size of M6-WP2 and M6-WP4. The M6 estimate carries the widest error.
8. Not known until M6-D1 reads the plugin layer test: whether a plugin may open a database itself. Section 7.2 row 4 assumes it may not.
9. Not known until the host check: upload limits, whether the data folder can sit outside the web root, and execution-time limits on the reference host.

## 12. Notifications and end of session

- At M5 close and at M6 close: one notification with suite and lint totals, the exit table, and the provisional decisions added. The session continues.
- If the session stops after M7 part 1 because G6-report or G7-release is not open: one notification with the same content, the release-candidate hash, and the two ways forward (supply the discovery report, or `DECIDE release-without-importer: yes`).
- At the end of the build (orch 10): one notification with the archive build command and SHA-256, the test summary, the three exit tables, every provisional decision still awaiting confirmation, and the deferred maintainer tasks with their procedures:

| Task | Procedure |
|---|---|
| Reference shared host check (M7 exit criterion) | `tools/host-check/README.md`, then install the archive by upload and setup page |
| Trial import of a real legacy file | On the maintainer's machine with the release build; nothing from it enters the repository |
| Manual screen-reader pass | The procedure written under section 8.2 row 13 |
| Tag and publish the release | Maintainer only (section 3 row 9) |
| Confirm or change the provisional decisions | Ledger section 4 |

Then idle. M8 and M9 need a new instruction.
