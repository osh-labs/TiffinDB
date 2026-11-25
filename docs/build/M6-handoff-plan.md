# TiffinDB build plan: M6, legacy-product import (deferred, for hand-over)

Status: proposed. M6 is deferred to near the end of the v1 build by maintainer instruction (2026-11-22) and is likely to be done by another developer. This document is written to be read on its own. It does not replace the M6 design spec (`docs/specs/M6-*.md`), which is written first, under `docs/build/orchestration.md` section 3. `CLAUDE.md` overrides anything here. Build state stays in `docs/build/ledger.md`. The plan for M5 and M7 is `docs/build/M5-M7-session-plan.md`.

References: "plan N" is `docs/plan.md` section N; "orch N" is `docs/build/orchestration.md` section N; "P n" is a provisional decision in ledger section 4; "format N" is `docs/formats/tiffin.md` section N.

## 1. Bottom line

- M6 delivers the import of a legacy-product database into a new TiffinDB project: tables, typed fields, relations, and files, with a complete report.
- Order in the build: M5, M7 part 1, then M6, then M7 part 2 (release test, audits, release). The release waits for M6 unless the maintainer decides otherwise (`docs/build/M5-M7-session-plan.md` section 3 row 1).
- Hard gate: the reviewed output of the maintainer's discovery run (`tools/discovery/`). Nothing in the repository guesses the legacy format, and this plan does not change that.
- M6 is more than a plugin. The importer contract (API 1) reads one table per import as text cells; it has no declared field types, no relations between source tables, and no files (`src/Core/Io/Contract/Importer.php`, `SourceTable.php`; `docs/specs/M3-io.md` section 1). M6 adds a contract extension and a whole-source import path.
- The only real legacy database available is one file of about 12 GB as a zip (maintainer statement). Every size limit in the tree is far below that: uploads 64 MiB, project archives 4 GiB in total, 50,000 rows per import, and a one-hour sweep of unfinished projects (`IoLimits`, `ProjectOperations::ABANDONED_SECONDS`). M6 is designed for that file from the start: no browser upload for a large source, resumable steps with bounded memory, a command-line driver, a dry run, and a maintainer trial on the real file (sections 5 and 9).
- The developer who builds M6 does not need the real file. The work is done from `docs/formats/bento.md` and a synthetic fixture. The maintainer runs the trial.
- Size: 2 design packages, 1 spec review, 9 work packages, 1 exit audit. As an orchestrated agent build, about 4 to 5 usage windows; this is an extrapolation from M1, and the widest error in the v1 estimate. No estimate is given for a human developer.

## 2. Scope, exit criteria, starting state

| Scope | Exit criteria |
|---|---|
| `docs/formats/bento.md` from the discovery output; importer contract extension; whole-source import into a new project (tables, typed fields, relations, attachments); the large-source path (inbox folder, resumable file staging, command-line driver, dry run); the import plugin; seeded synthetic fixture generator with a scale profile; import screens and report | A synthetic fixture with the observed structure imports with relations and attachments (plan 8); the import report is complete (plan 8); a synthetic source at the scale of the real file (section 5 row 16) imports within the memory ceiling and survives interruption at any step; the maintainer trial on the real file passes or is waived (section 9) |

Not in M6: legacy layouts and saved searches (section 5 row 9); merging into an existing project; template files unless the report covers them.

Starting state. M6 starts from the branch that holds M0 to M5 and M7 part 1. It relies on the following; the first task of M6-D1 is to confirm each against the tree and to read the as-built notes at the end of `docs/specs/M3-io.md`, `docs/specs/M4-core.md`, and the M5 and M7 specs.

| Relied on | Where |
|---|---|
| Plugin contract API 1, plugin registry, plugin layer rules | `src/Core/Io/Contract/`, `src/Core/Io/Plugin/`, `tests/repo/PluginLayerRulesTest.php`, ADR 0015 |
| Staging area and the import job model (chunks, Continue button) | `src/Core/Io/StagingArea.php`, `ImportJob.php`, ADR 0015 decision 1 |
| Checks for a hostile zip and a hostile database file | `src/Core/Io/Archive/ZipCheck.php`, `UntrustedProject.php`, ADR 0017 |
| New-project creation in state `creating`, and its sweep | `src/Core/App/ProjectOperations.php`, `src/Core/System/ProjectRegistry.php` |
| Attachment store: hashing, placement, orphan sweep | `src/Core/Storage/AttachmentStore.php`, ADR 0018 |
| Import log and report shape | `src/Core/Io/ImportLog.php`, format 4.7 |
| Command-line tool pattern | `tools/admin/reset-password.php` |
| Release build tool and release rules test (M7 part 1; names indicative) | `tools/release/`, `tests/repo/ReleaseContentsTest.php` |
| Host check with a free-space line (M7-WP0) | `tools/host-check/` |
| Places for the importer chapter in the documentation (M7 part 1) | `docs/host/`, `docs/user/` |

## 3. Decisions for the maintainer

| # | Question | Default if not answered | Why it matters |
|---|---|---|---|
| 1 | Discovery output for the legacy format (plan 6.4, ADR 0009 D1) | None possible. M6 does not start | M6 fixtures must reproduce the observed structure; guessing it would break the clean-room rule |
| 2 | Name of the legacy importer in the UI and in plugin metadata (ADR 0009 D5, P11) | Plugin id `legacy-package`, directory `plugins/import/legacy-package/`, name shown to Admins "Mac personal database package". The legacy product is named only in documentation prose and in `docs/formats/bento.md` | `CLAUDE.md` forbids the legacy name in the UI and in package metadata. Whether a file extension that contains the name may be shown in the UI is the maintainer's call; the default does not show it |
| 3 | Where the 12 GB import runs | On the command line, with the same core job the browser uses: either on the host over SSH, or on a local machine that has PHP, after which the finished project folder is copied to the host by the documented by-hand procedure (format 9 and 13.4). The browser path with a Continue button also works from the inbox folder, for hosts with no shell | A shared host's time limits and disk quota are not known until the host check. The local route needs no upload of the source at all, only of the result |
| 4 | Limits for a whole-source import | Set in the M6 spec from the real counts in the discovery report, with headroom. No limit may be below the real file's figure. A value over a limit is reported with a count and never dropped silently. A file is limited by free disk space, not by the 64 MiB upload cap | The existing caps were chosen for CSV and XLSX uploads |
| 5 | Who holds the real file | The maintainer only. The developer who builds M6 works from the format document and the synthetic fixture, and receives trial findings as structure and counts | The file is personal data; `tools/discovery/README.md` rule 1 keeps real files out of the repository and out of any hosted session |

## 4. Gates and gate procedure

| Gate | Opens when | Blocks |
|---|---|---|
| G6-start | M5 is CLOSED and M7 part 1 is merged, or the maintainer instructs an earlier start | All M6 work except M6-D0 |
| G6-report | The maintainer supplies the reviewed discovery report (section 3 row 1) | M6-D0 |
| G6-doc | The maintainer approves the text of `docs/formats/bento.md` before it is committed (step 3 below) | M6-D1 and all M6 packages |
| G-spec | The M6 spec review (M6-R1) is merged and its rulings are applied | Every M6 work package |
| G6-trial | The maintainer's trial on the real file passes (section 9), or the maintainer waives it | M6 CLOSED. Until then M6 is recorded as "audited, trial pending" |

Procedure for G6-report and G6-doc:

1. The maintainer runs `tools/discovery/discover-structure.php` by the procedure in `tools/discovery/README.md` and supplies the reviewed report. For the 12 GB file the tool must be run on the unpacked package folder, not on the zip: it does not open zip entries over 1 GiB (`MAX_EXTRACT_BYTES`), so a large database inside the zip would be listed and skipped. With the report the maintainer also states, as numbers only: the unpacked size, and the free space on the machine where the import will run (section 3 row 3). The report stays in the session scratchpad. It is never committed, in whole or in part.
2. M6-D0 (design tier) writes `docs/formats/bento.md` in TiffinDB's own words: container layout, databases, tables, columns, how field definitions and field types are stored, how records, links, and files are stored, and which versions the report covers. It also records the scale figures the report gives (record counts per table, file count, total and largest file size) and where the bytes are: files beside the database, or values inside it. Those figures set the limits and the scale fixture (section 5 rows 16 and 11). It uses a name from the report only where the name is clearly the product's own and not chosen by a person.
3. Whoever leads M6 sends the text to the maintainer for approval before the commit (plan 6.4: identifier names can themselves be personal). This is a hold. In an orchestrated build it is headed `BLOCKER` (orch 7).
4. If the report shows that field definitions are stored as rows and structure alone does not show the mapping, the README's `--value-shapes` step is needed. That is a second maintainer round; M6-D0 states exactly which table it needs.

## 5. Design outputs (M6-D1, reviewed by M6-R1) and design points

| Output | Content |
|---|---|
| `docs/specs/M6-legacy-import.md` | Contract extension, untrusted-source reader, whole-source import pipeline, type map, relation and attachment handling, report, core operations, screens, test plan, work packages with exact owned files |
| `docs/design/m6/` | Start page, review of a whole source, progress, report |
| ADR, next free number | Importer contract API 2 and the whole-source import path (amends ADR 0015) |
| ADR, next free number | Legacy importer: name and id (section 3 row 2), supported versions, type map, what is not imported |
| `docs/formats/tiffin.md` | Import log wording for a whole-source import; a format step only if the log row cannot hold the report additively |

| # | Point | Recommendation | Reason |
|---|---|---|---|
| 1 | Contract | Add a second importer interface under contract API 2 for sources that describe a whole project: tables with declared field types, relations between source tables, row pages per table with link cells as source ids, and file streams. API 1 plugins are unchanged | Plan 6.1 already describes this neutral model; API 1 was cut down to what CSV and XLSX need |
| 2 | Target | Always a new project, with no grants, as archive import does. No merge into an existing project | Reuses the reviewed new-project path; a merge needs conflict rules nobody has asked for |
| 3 | Source and size | No browser upload for a large source. The Admin puts the unpacked package folder into an inbox folder under the data folder, by SFTP or the host's file manager; TiffinDB lists what is there and the Admin picks one. No path is typed, links are not followed, and the choice is checked for containment. A zip through the browser stays possible under the 64 MiB cap (P116). A zip in the inbox is accepted only if the design can read its entries as streams without unpacking it whole (Zip64); otherwise the inbox takes unpacked folders only | The real file is about 12 GB zipped. Unpacking on the server would need the space twice over; an unpacked folder needs it once |
| 4 | Reading the source database | The zip and the database inside it are hostile input: the zip checks of archive import, read-only and immutable open, no trust in the schema, no view or trigger ever run. Plugins may not hold SQL text (`PluginLayerRulesTest`), so the core gives the plugin a read-only source reader through the contract: list tables and columns, page through a named table | To confirm in the design that the layer test refuses SQL in plugins as the ledger describes; if so the reader belongs in core |
| 5 | Commit | Rows follow the M3 staging job model: read and prepare in chunks, then one final transaction writes records, links, and file rows. Files cannot wait for that transaction at this size. They are placed in resumable steps before it, into the new project's folder while the project is in state `creating` and not visible: each step hashes and places a bounded number of bytes, by hard link from the inbox where the file system allows it and by copy otherwise. Placement is content-addressed, so a repeated step does no harm. Cancel or failure removes the unfinished project. This amends ADR 0018 decision 4 for imports | Plan 6.1 ("single transaction") holds for the database; 12 GB of files cannot be hashed and placed inside one request or one transaction |
| 5a | Sweeps | The one-hour sweep of `creating` projects (P57) and the 24-hour staging sweep (P127) must never remove a running or paused import. The job has an explicit state and a heartbeat; only a job the Admin cancelled, or one idle past a much longer stated period, is removed | An import of this size can take hours across many steps |
| 6 | Review step | One page: tables, fields, proposed types, what will not be imported. The Admin may rename or skip a table or a field. Types are changed afterwards in the schema editor | Plan 6.1 asks for review; a full mapping table for many tables at once is a large screen with little gain |
| 7 | Type map | A table in the spec from every type observed in `docs/formats/bento.md` to a TiffinDB type, or to text; anything not mapped is imported as text where possible and always listed in the report (plan 6.4). Calculated and encrypted fields (deferred in plan 3.4) are reported, not imported | Exit criterion 2 |
| 8 | Report | Per table: records read and imported; per field: type chosen, values emptied and why; links made and links whose target was missing; files imported, missing, over the cap, or over 100 per field (P147); source features not imported (layouts, saved searches, anything else observed) | "Import report complete" is checked against the generator's ground truth |
| 9 | Legacy layouts and saved searches | Not imported. Forms are generated (plan 6.1). Listed in the report | Clean-room; TiffinDB has its own form format |
| 10 | Versions and templates | Only the versions the report covers; anything else is refused with a plain message. Template files are out of v1 unless the report covers them and they share the structure | `CLAUDE.md` open decision |
| 11 | Fixtures | Generated at test time by a seeded generator with a documented seed, with a ground-truth description of what it wrote. No binary fixtures committed. A scale profile, used only by the scale runs, takes its record and file counts from the figures in `docs/formats/bento.md` | `CLAUDE.md` fixture rules |
| 12 | Memory and time | Every step has a fixed memory ceiling that does not grow with the source (the figure is set in the spec, under the host check's 128M threshold) and a time budget per web request. A test asserts the peak. If files are stored as values inside the source database, they are read in slices by SQL, never as one value | PHP's database layer returns a whole value at once; a single large value would otherwise exceed memory |
| 13 | Command-line driver | A script under `tools/admin/` runs the same core job to the end, or resumes it, with no web time limit. It names the acting Admin, is audited like the web path, prints progress and the report, and can print the report with table and field names replaced by placeholders | Section 3 row 10; the pattern of `tools/admin/reset-password.php` |
| 14 | Dry run | Reads, prepares, and reports without creating a project or placing a file | Lets the maintainer see the whole report for the real file before anything is written (section 9) |
| 15 | Resume | Job state is on disk. A job stopped at any point (time limit, closed browser, killed process, restart) continues from its last finished step and gives the same project as an uninterrupted run. A lock stops the web path and the command line from driving one job at once | An import of hours will be interrupted |
| 16 | Fit table | The spec holds a table of every limit that applies to a whole-source import (records per table, source rows, cells, new linked records, files per field, bytes per file, total bytes, entries) beside the real file's figure from the report and the chosen value. The scale fixture is the real figures times 1.5 in counts; in bytes it is as large as the build machine's disk allows, stated in the ledger | Makes "will it work" a checked table, not an expectation. The existing caps (50,000 rows, 100,000 source rows, 10,000 new linked records, 100 files per field) were set for spreadsheets |
| 17 | Disk space | Before the first step the job works out the bytes it needs (zero extra per file when hard links work, the full size when it must copy) and refuses to start if free space less the reserve is short, stating both figures | A failure at 11 of 12 GB wastes hours |
| 18 | After the import | The spec lists what must still work on a project of that size and how each is checked on the scale fixture: lists and search, the file page and download as a stream, project totals, the orphan-file sweep, snapshots (database only), JSON export, and project archive export. Archive export is capped at 4 GiB today; the design either lifts that for export or gives a plain refusal, and the host documentation describes backup by copying the folder (format 9) | A project that imports but cannot be backed up has not worked |

## 6. Work packages

Indicative; the M6 spec fixes ids, exact owned files, and acceptance tests.

| ID | Title | Tier | Depends on | Security-critical |
|---|---|---|---|---|
| M6-WP1 | Contract API 2 values and interface; registry accepts API 2; untrusted-source reader | implementation | none | Yes |
| M6-WP2 | Whole-source import pipeline: job with state, heartbeat, and lock; chunked read; resumable file staging by hard link or copy; disk-space check; preparation; single commit into a new project; dry run; report; import log; sweep rules | implementation | WP1 | Yes |
| M6-WP3 | Seeded synthetic fixture generator, with a scale profile, and its structure test against `docs/formats/bento.md` | implementation | WP1 | No |
| M6-WP4 | The plugin: manifest, importer, type map, plugin tests against a hand-built source | implementation | WP1 | Yes |
| M6-WP5 | `Core\App` operations for a whole-source import: list the inbox, start, continue, review, dry run, commit, cancel; authorization; audit; strings | implementation | WP2 | Yes |
| M6-WP6 | Screens and components: start with the inbox list, space check, progress with Continue, review, dry-run report, report; routes, layouts, stylesheet, entry link | implementation | WP5 | Yes |
| M6-WP7 | End-to-end import of the fixture with relations and attachments; report check against ground truth; hostile corpus; failure injection; scale runs by web steps and by command line with peak memory recorded; kill-and-resume runs; the section 5 row 18 checks; keyboard-only run; visual check | implementation | WP3, WP4, WP6, WP8 | No |
| M6-WP8 | Command-line driver: run, resume, dry run, report with names replaced | implementation | WP5 | Yes |
| M6-WP9 | Fixes for defects found by the maintainer trial (section 9). Sized after the trial; may be empty | implementation | G6-trial result | As the defect |

| ID | Owned files (indicative) | Must not touch | Acceptance |
|---|---|---|---|
| M6-WP1 | New files in `src/Core/Io/Contract/`; `src/Core/Io/Plugin/`; *`src/Core/Io/Source/`*; `tests/core/io/ContractTest.php`, `tests/core/io/plugin/`, `tests/repo/PluginLayerRulesTest.php` | Existing API 1 contract files except additive changes the spec names | API 1 plugins load and pass unchanged; the reader never runs a view or trigger, never writes beside the source, and refuses identifiers it did not list itself |
| M6-WP2 | *`src/Core/Io/ProjectImport/`*; `src/Core/Io/IoLimits.php`, `StagingArea.php`, `ImportLog.php`; the attachment store's placement method for a linked or copied file (the spec names it); *`tests/core/io/projectimport/`* | The API 1 pipeline classes (`Reader`, `Preparer`, `Committer`), `src/Core/App` | One database transaction; injected failure at each step leaves no project folder, no registry row, no file; a job stopped after any step and resumed gives a byte-identical project file and the same file hashes; peak memory under the ceiling with a source many times larger than the ceiling; a running or paused job survives both sweeps; the start is refused with both figures when space is short; a dry run writes nothing under `projects/` |
| M6-WP3 | *`tests/Support/LegacyFixture.php`*, *`tests/core/io/LegacyFixtureTest.php`* | Everything else | Same seed gives the same bytes; the generated structure matches every table and column the format document lists; ground truth lists every record, link, and file written; the scale profile builds the fit table's counts without holding the source in memory |
| M6-WP4 | *`plugins/import/legacy-package/`* (name per the naming ADR); *`tests/plugins/legacy-package/`* | `src/` | Plugin layer rules pass; every type in the format document has a row in the type map and a test; unknown versions refused; the legacy product's name appears in no manifest field |
| M6-WP5 | *`src/Core/App/ProjectImportOperations.php`* and value classes; `Operations.php`, `AuditAction.php`; `strings/en/problems.php`; *`tests/core/app/ProjectImportOperationsTest.php`*; `MessageKeysTest.php` | `src/Core/Io`, `src/Web` | A Member is refused on every method; a job belongs to the Admin who started it (P134); one audit row per import |
| M6-WP6 | *`src/Web/Controller/ProjectImportController.php`*; *`templates/pages/project-import/`*; `templates/layout.php`, `app.css`, `routes.php`, `strings/en/import.php`, `TemplateRulesTest.php`; *`tests/web/ProjectImportScreenTest.php`* | `src/Core` | Works without JavaScript with a Continue button per chunk; hostile table and field names escaped; the legacy product's name appears in no string or template |
| M6-WP7 | *`tests/roundtrip/LegacyImportTest.php`*, *`tests/web/M6EndToEndTest.php`*, *`tests/core/io/LegacyHostileTest.php`*; assigned residue | Product code, except assigned residue | Exit criteria of section 2, by test. The scale runs are opt-in (not in `composer test`) and their times, peak memory, and sizes go in the ledger |
| M6-WP8 | *`tools/admin/import-source.php`*, *`tests/tools/ImportSourceToolTest.php`* | `src/Web`; `src/Core` except through the WP5 operations | Runs the fixture to the end; killed at seeded points and resumed, it gives the same project as one uninterrupted run; refuses an unknown or inactive Admin; the dry run and the names-replaced report print no source value |

## 7. Waves

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP1 | M6-R1 merged and the starting state of section 2 confirmed |
| 2 | WP2, WP3, WP4 | WP1 merged |
| 3 | WP5 | WP2 merged |
| 4 | WP6, WP8 | WP5 merged |
| 5 | WP7 | WP3, WP4, WP6, WP8 merged |
| after the trial | WP9 | The maintainer returns trial findings |

## 8. Reviews and exit audit

Design-tier security review (orch 2 rule 4: every importer that parses user-supplied files, plugin loading): WP1, WP2, WP4, WP5 with WP6, and WP8 (a tool that acts as an Admin outside the web sign-in). Implementation-tier review: WP3, WP7.

M6-X: independent design-tier audit against section 2, plus: fixture import with the auditor's own seed, report compared line by line with the ground truth; hostile corpus (traversal names in the zip, a database with triggers and views, sizes that lie, row counts past the caps, a truncated database, an unknown version); a failed import leaves nothing; a Member refused in core; the new project opens with plain SQLite tools and matches `docs/formats/tiffin.md`; clean-room check (no vendor material; the legacy name only in documentation prose and the format document; none in templates, strings, or `plugin.json`); mutation checks. Scale, by the auditor's own run: the scale fixture imported by web steps and by the command line; peak memory under the ceiling; the process killed at points of the auditor's choosing and resumed; hard-link and copy placement both exercised; a start refused for lack of space; every check of section 5 row 18. The audit states plainly how far the byte scale it reached is below 12 GB. Not verifiable here: the real file. That is section 9.

## 9. Maintainer trial on the real file (G6-trial)

The build can show that the importer handles a synthetic source of the right shape and scale. Only the maintainer can show that it handles the real one. M6 is not recorded CLOSED until this trial passes or the maintainer waives it.

1. After M6-X whoever leads M6 gives the maintainer the procedure and the exact commit to use (a release candidate made with the M7 build tool, or a checkout of the branch).
2. The maintainer runs the command-line dry run on the unpacked real package, on the machine chosen under section 3 row 3, and reads the report. Nothing from the real file enters the repository or the session except report text the maintainer has read and chosen to send, with names replaced if they are personal. That text is not committed.
3. The dry run passes when: it ends without an error; peak memory stays under the ceiling; every table and field of the source appears in the report as imported, imported as text, or not imported with a reason; the record, link, and file counts equal the source's own counts in the discovery report; nothing is over a limit of the fit table.
4. The maintainer then runs the real import and checks the result in the browser by their own judgement: a few records per table against the legacy application, links, files that open.
5. Findings go to M6-WP9 as structure and counts, never as values. The trial is repeated after the fixes. Two failed rounds on one class of defect need a root-cause redesign (orch 3 step 4).
6. Limits of this trial: one file, so one version of the legacy application. Other versions stay refused (section 5 row 10) and the user documentation says so.

## 10. Who does the work

If an orchestrated agent session builds M6:

1. `docs/build/orchestration.md` applies in full: tiers, at most three agents at once, isolated worktrees, a different agent instance for every review, design-tier review of every security-critical package, only the orchestrator pushes, the ledger after every state change. The design-tier model floor of `docs/build/M4-session-plan.md` section 4 applies.
2. The session starts from the ledger and this plan: record the window and branch, rebuild the trimmed offline test install (orch 9), run `composer lint` and `composer test`, and compare the totals with the ledger.
3. The fixture generator (M6-WP3) and the plugin (M6-WP4) are written by different agents, each from the format document only, so that one misreading cannot pass on both sides.

If a human developer builds M6:

1. `CLAUDE.md` applies as written: the public-repository rules, licensing, the assigned branch, `composer test` and `composer lint` before each commit, decisions recorded in `docs/decisions/` before code.
2. The package table of section 6 is then an order of work. The dependencies still hold; the wave table matters only if several people work at once, and then their owned files must not overlap.
3. The spec and the ADRs still come first, and the spec is still reviewed by a second person or a design-tier agent.
4. Every package marked security-critical in section 6 is reviewed by someone other than its author, who runs the code against hostile input and does not only read it.
5. If one person writes both the fixture generator and the plugin, a second person checks the generator against `docs/formats/bento.md` table by table, and the exit audit uses a source built by hand, not by the generator.
6. The ledger is updated at each state change (work-package table, provisional decisions from the next free P number, exit results).

In both cases the exit audit (section 8) is done by someone who took no part in the build, and the real file never leaves the maintainer (section 3 row 5).

## 11. What M6 hands back

| Item | To |
|---|---|
| As-built notes at the end of the M6 spec; the M6 exit table in the ledger | M7 part 2 (its spec is reconciled with them before it starts) |
| Operations on a very large project that the exit audit found slow or capped (section 5 row 18): each either fixed inside M6 or listed as a stated limit | M7-WP11 (documentation) |
| Screen names, button labels, the command-line usage text, and the list of what is not imported | M7-WP11 |
| One more shipped plugin and one more admin tool; no new dependency is expected. If one is needed, the licence rules of `CLAUDE.md` and `docs/dependencies.md` apply | M7 release build and release rules test |
| M6 CLOSED in the ledger | Gate G7-release of the M5 and M7 plan |

## 12. Risks

| Risk | Effect | Mitigation |
|---|---|---|
| Discovery output arrives late or never | M6 cannot start; v1 scope of ADR 0004 not met | The rest of v1 is built first; the release decision is the maintainer's |
| Legacy format differs by version, or field definitions are rows that structure alone does not explain | Extra maintainer rounds; importer covers fewer files | M6-D0 states what is missing; unsupported versions refused plainly; unmapped types imported as text and reported |
| Fixture and importer share one misreading of the format | Tests pass, real files fail | Different authors from the format document only (section 10); maintainer trial on real files after the build |
| Importer tested only on synthetic data | Unknown behaviour on the real file | Dry run and trial on the real file before M6 closes (section 9); M6-WP9 holds the fixes |
| The real file exceeds a limit nobody tested | The one import that matters fails | Fit table from the report's real figures; scale fixture at 1.5 times the counts; no silent drop; dry run reports every overrun before anything is written |
| The host cannot hold or process 12 GB (quota, time limits, no hard links) | Import cannot finish on the reference host | Command-line driver; local conversion and folder copy; space check before the start; the host figures are asked for at the gate |
| The build machine's disk is smaller than the real file | Byte scale tested only in part | Counts tested in full, bytes as far as the disk allows and stated; memory ceiling proven independent of size; the trial is the proof at full size |
| An import of hours is swept, interrupted, or driven twice | Lost work or a damaged project | Job state, heartbeat, lock, resume tests with seeded kill points |
| M6 is built last, by someone who did not build the rest | Wrong assumptions about the tree; rules missed | Starting-state table of section 2, confirmed first; as-built notes in every spec; section 10 |
| Only one real file exists | Other versions of the legacy application untested | Unknown versions refused with a plain message; stated in the user documentation |

## 13. Assumptions and unknowns

1. Not known until the discovery report: the container form (package, single file, or archive); where the 12 GB is (files beside the database, or values inside it); record and file counts and the largest single file; how field types and links are stored. These set the size of M6-WP2 and M6-WP4.
2. Not known until the maintainer says: where the import will run, the free space there, and whether PHP with `pdo_sqlite` is available on the machine that holds the file. The 12 GB figure is the maintainer's statement of the zip size; the unpacked size is not known.
3. Not known until M6-D1 reads the plugin layer test: whether a plugin may open a database itself. Section 5 row 4 assumes it may not.
4. File names shown as new in section 6 are indicative. Existing files named in this plan were read in the tree when it was written, before M5 and M7 part 1; section 2 requires them to be confirmed again.
5. ADR numbers are the next free ones when M6-D1 runs. The decision records must stay numbered without gaps (`DecisionRecordsTest`).
6. The build machine has no Apache and may have less free disk than the real file needs (orch 9); section 5 row 16 says how the scale runs deal with that.
