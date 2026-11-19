# TiffinDB build session plan: M2 and M3

Status: proposed, awaiting the maintainer's go-ahead. This is a session-level plan for the orchestrator. It does not replace the per-milestone design specs (`docs/specs/M2-*.md`, `docs/specs/M3-*.md`), which the design tier writes during the session under `docs/build/orchestration.md` section 3. `CLAUDE.md` overrides anything here. Build state stays in `docs/build/ledger.md`.

References: "plan N" is `docs/plan.md` section N; "ui N" is `docs/ui-design-plan.md` section N; "orch N" is `docs/build/orchestration.md` section N; "P n" is a provisional decision in ledger section 4.

## 1. Bottom line

- One orchestrated session delivers M2 (records, list view, auto-generated form, search and sort, display settings) and M3 (import and export framework, CSV and XLSX import, CSV, XLSX and JSON export, project archive), with no stop between them. It ends at the M3 exit audit.
- Running both in one session allows three things that separate sessions do not: the M3 design runs while M2 is being implemented; the two project-format steps (version 2 for forms, version 3 for the import log) are designed together; and the one shipped dependency (the XLSX library) is fetched and licence-checked in the first hour, so a blocked download surfaces before M3 depends on it.
- Size: 2 design packages, 2 spec reviews, about 22 work packages, 2 exit audits. Estimate 6 to 8 usage windows. Basis: M1 (13 packages, 2 specs) took about 3 windows. This is an extrapolation, not a measurement.
- Four maintainer answers before the start would remove rework (section 3). None blocks the start; each has a provisional default.

## 2. Scope and exit criteria

| Milestone | Scope (plan 8, ui 11) | Exit criteria |
|---|---|---|
| M2 | List view, auto-generated form, record create, read, update, move to trash, search and sort, display settings, search results, empty and error states, confirmation pages | A Member can add, find, and edit records by keyboard only; contrast tests pass and cover all 10 theme and accent combinations with the ui 4.2 figures; light-theme warning token at 7:1 or better |
| M3 | Import and export framework and plugin contract; CSV and XLSX import; CSV, XLSX, JSON export; project archive export and import; import steps 1 to 4; export screen; plugins screen | Round-trip tests on seeded synthetic fixtures; import flow usable by keyboard only |

Not in this session: relation picker, attachment upload and serving, trash screen, saved collections (M4); form editor and the full sanitizer (M5); legacy-product importer (M6); snapshot restore, project deletion, audit-log screen (P49).

Carried into the session from M1: every ledger section 7 item marked M2, and the unassigned nits listed in section 6.2 below.

## 3. Maintainer decisions that would help before the start

| # | Question | Default if not answered | Why it matters now |
|---|---|---|---|
| 1 | Exempt `composer.lock` as a named file (P8)? | No lock file; every shipped package pinned exactly in `composer.json` and listed in `docs/dependencies.md` (ADR 0008 decision 12) | M3 adds the first shipped dependency. Switching afterwards means redoing the pinning and its guard test |
| 2 | Auto-generated form flows into 2 or 3 columns on wide screens (ui 9.1, ADR 0009 A1)? | Yes: single-column source order, reflowing grid in the default form CSS | The M2 record screen is checked against `record.html`, which shows columns |
| 3 | Search behaviour | Case-insensitive substring match over text-like fields, per table and across tables; no full-text index | A full-text index changes the project file format; adding one later is a format step, removing one is harder |
| 4 | Should the session stop at M2 close for inspection? | No; one notification at M3 close | M0 and M1 each ended with a stop |

## 4. Session shape

| Phase | Work | Tier | Runs alongside |
|---|---|---|---|
| 0 | Resume set-up (section 5) and dependency probe (section 8.1) | orchestrator | - |
| 1 | M2 design (M2-D1), spec review (M2-R1), rulings applied | design | Phase 0 probe |
| 2 | M2 implementation, waves 1 to 4 | implementation, mechanical | M3 design (M3-D1) and its review (M3-R1), using one of the three agent slots |
| 3 | M2 exit audit, fixes, as-built notes, ledger close | design | - |
| 4 | M3 reconciliation (M3-D2): the M3 spec author is resumed with the M2 as-built notes and corrects the M3 spec | design | - |
| 5 | M3 implementation, waves 1 to 5 | implementation, mechanical | - |
| 6 | M3 exit audit, fixes, as-built notes, ledger close, end-of-session notification | design | - |

Rules specific to this session, in addition to orch 2 and 3:

1. M3-D1 starts only after M2-R1 is merged, so it designs against a reviewed M2 interface. It may not start M3 implementation; phase 4 is the gate.
2. The concurrency limit of three agents (orch 3) includes the M3 design agent during phase 2.
3. No M3 package is dispatched until M2 is recorded CLOSED in the ledger. If the M2 audit fails a criterion after root-cause analysis and one redesign, that is a blocker (orch 7) and M3 does not start.
4. Checkpoint after every merge: commit, push, ledger. The ledger names unpushed worktree work at all times.

## 5. Phase 0: resume set-up

1. Record the window start and the assigned integration branch in ledger section 1; arm the single resume reminder (orch 6).
2. Add `/.claude/` to `.git/info/exclude`. Rebuild the trimmed offline PHPUnit install in the scratchpad (orch 9). Run `composer lint` and `composer test`; expected 4777 tests, 1 skipped, as at M1 close.
3. Give every agent brief its own scratch subfolder and port (ledger usage log, window 4 process note). Treat any existing `scratchpad/app` as dirty.
4. Run the dependency probe (section 8.1) in the scratchpad. Nothing from it is committed in this phase.

## 6. M2

### 6.1 Design outputs (M2-D1, reviewed by M2-R1)

| Output | Content |
|---|---|
| `docs/specs/M2-records.md` | Core: record layer, search, form format and generator, appearance settings operation, core operations, test plan, work packages |
| `docs/specs/M2-screens.md` | Routes, controllers, templates, field rendering per type, stylesheet and script changes, strings, keyboard and visual procedures |
| `docs/design/m2/` | Designs for the screens with no mock-up: search results, empty states, error pages, confirmation pages (ui 6.2); component notes for list, record, and settings taken from the mock-ups |
| ADR 0013 (number indicative) | Record layer: paging, sort, filter, search semantics, lost-update guard, autosave position |
| ADR 0014 (number indicative) | Form format version 1 and `_tiffin_forms`; how stored forms are rendered before the M5 sanitizer exists; amends plan 4 for ui 9.1 if confirmed |
| `docs/formats/tiffin.md` | Project format version 2: `_tiffin_forms`, with the exact upgrade SQL |
| `docs/design/tokens.css` values | The corrected light-theme `--warn` or `--warn-soft` value (ui 9.4), chosen so no other published ratio drops below its target |

Design points the spec must settle, with the recommended direction:

| Point | Recommendation | Reason |
|---|---|---|
| Layering of form binding | `Core\Forms` takes plain values and field definitions; `Core\App` fetches records through `Core\Query` and passes them in | Avoids the Forms-to-Query dependency that ADR 0012 flagged as a needed amendment |
| Rendering stored form HTML before M5 | Parse the stored form into a small node model and re-emit only the documented vocabulary (default-deny); never print stored markup directly. Introduce the typed "safe HTML" value that ADR 0008 decision 4 reserved | M3 project-archive import brings in form HTML written elsewhere; without this, M3 opens a stored-XSS path a milestone before the sanitizer |
| Relation and attachment fields in M2 | Core link operations in M2 (M1 spec 8.3); on screen, show linked records and files read-only, with no picker or upload | Picker and upload are M4; the record mock-up is shared between M2 and M4 |
| Trash in M2 | "Move to trash" on the record screen with an undo in the confirmation notice (uses the existing `TrashOperations`); sidebar shows the trash count without a screen | The trash screen is M4 (brief 12.7) |
| Lost updates | The edit form posts the `_updated_at` it was loaded with; a mismatch shows a conflict page with both versions | Two household members editing the same record is a realistic case; cost is one hidden input and one comparison |
| Autosave | Not built in M2; explicit Save only. Recorded as provisional, with the mock-up's "All changes saved" line replaced by a saved-time line | Named as an enhancement with no defined behaviour (brief 12.21); not needed for the exit criteria |
| Filter on the list view | Query-string filter on one field at a time (choice, checkbox, relation, date range), not saved | Saved collections with rule rows are M4; the same filter model should be reusable there |
| Paging | 50 records per page; record screen "Previous" and "Next" follow the list's current sort and filter | Not specified anywhere; a constant |
| Keyboard-only criterion | Two layers: structural tests in the suite (labels, order, no positive `tabindex`, focus targets), and a scripted headless-browser run that uses key presses only, with evidence in the ledger | Matches how M1 handled visual checks; adds no committed dependency |
| Plural strings | Add count-aware lookup to `Strings` (one and other forms) | Removes "1 values in 1 records" (ledger section 7) and is needed by list counts and import reports |

### 6.2 Work packages (indicative; the M2 spec fixes ids, owned files, and acceptance tests)

| ID | Title | Tier | Depends on | Security-critical |
|---|---|---|---|---|
| M2-WP0 | Carried-forward items: plural strings, `Fs::entries` and stat helpers, `ProjectRegistry::abandoned`, P82 confirmation bound to the count, `Origin` check when `Sec-Fetch-Site` is absent (audit F-2), hex-host rule in `UrlType`, losing first-Admin POST gives a redirect | implementation | none | Yes |
| M2-WP1 | Tokens: warning token fix in both token files; contrast tests for all 10 combinations against the ui 4.2 figures | mechanical | none | No |
| M2-WP2 | `Core\Query` records: create, read, update, list, sort, filter, search, counts, required fields, auto-number on create, link operations, lost-update guard | implementation | none | Yes (SQL identifiers) |
| M2-WP3 | `Core\Forms`: form format, generator, node model and binder; project format version 2 migration | implementation | none | Yes (emits HTML) |
| M2-WP4 | `Core\App`: record, search, form, and own-settings operations; form regeneration on table and field changes; layer-rule test updates | implementation | WP2, WP3 | Yes |
| M2-WP5 | Web components: input and display partials for the 17 types, data table, sidebar and list shell, paging, find box, empty state, confirmation page, stylesheet, settings-preview script, shared strings | implementation | none | Yes (templates) |
| M2-WP6 | List view and search results screens | implementation | WP4, WP5 | Yes |
| M2-WP7 | Record screens: new, view and edit, move to trash and undo, conflict page, record paging | implementation | WP4, WP5 | Yes |
| M2-WP8 | Display settings screen; error and empty-state pages | implementation | WP4, WP5 | Yes |
| M2-WP9 | End-to-end tests (Member adds, finds, edits), screen-structure tests, keyboard-only run, visual check against `list.html`, `record.html`, `settings.html` | implementation | WP6, WP7, WP8 | No |

Waves (at most three agents, one slot held by M3-D1 from wave 1 until its review merges):

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP2, WP3, then WP5, WP0, WP1 as slots free | M2-R1 merged |
| 2 | WP4 | WP2 and WP3 merged |
| 3 | WP6, WP7, WP8 | WP4 and WP5 merged |
| 4 | WP9 | wave 3 merged |

Notes for briefs, from the ledger: email values are escaped and `mailto:` local parts are `rawurlencode`d; every attribute is escaped with `ENT_QUOTES`; screen packages do not change `app.css`, shared partials, or another package's strings; screen tests on `FakeServices` mirror real preconditions.

### 6.3 Exit audit (M2-X)

Independent design-tier audit against section 2, plus: the security baseline for the new surface (escaping of every field type's display and input, authorization in core for every record operation, a Member with no grant gets "not found"); data portability (format version 2 file opens with plain SQLite tools and matches `docs/formats/tiffin.md`; a version 1 file upgrades after a snapshot); mutation checks; visual procedure at 1360 px and 390 px in four themes and at 320 px Largest. Manual screen-reader pass: recorded as not verified, as in M0 and M1.

## 7. M3

### 7.1 Design outputs (M3-D1, reviewed by M3-R1, reconciled by M3-D2)

| Output | Content |
|---|---|
| `docs/specs/M3-io.md` | `Core\Io` neutral model, importer and exporter interfaces, plugin manifest and loading, import pipeline, type inference, staging and commit, import log, archive export and import, core operations, test plan, work packages |
| `docs/specs/M3-screens.md` | Upload handling in the request pipeline, streamed downloads, routes, import steps 1 to 4, export, plugins screens, keyboard procedure |
| `docs/design/m3/` | Designs for import steps 1, 3, 4, export, and plugins (ui 6.2) |
| ADR 0015 (number indicative) | Plugin contract, plugin namespace and directory layout, enable and disable storage; import commit model (settles ADR 0009 E1) |
| ADR 0016 (number indicative) | XLSX library adoption and the shipped-dependency pinning rule as applied (settles P6; applies ADR 0008 decision 12 or the lock-file exemption) |
| ADR 0017 (number indicative) | Project archive format and import rules; JSON export format |
| `docs/formats/tiffin.md` | Project format version 3: `_tiffin_import_log` and any staging table |
| `docs/formats/json-export.md` | JSON export format, documented for use without TiffinDB |
| System database | Schema version 2 if plugin enable and disable state is stored there |

Design points the spec must settle, with the recommended direction:

| Point | Recommendation | Reason |
|---|---|---|
| Single transaction versus chunked import (E1) | Read the file in chunks across requests into a staging table that no screen shows; the final step moves all staged rows into the target in one transaction. Without JavaScript each chunk is a "Continue" button; a script presses it automatically | Keeps the mock-up's promise ("saved all together, or not at all") and still fits shared-host time limits (plan 10). `meta` refresh is already forbidden by the template rules |
| CSV reader and writer | Native PHP streams with TiffinDB's own delimiter and encoding detection; no library | Keeps CSV import and export, the portability minimum, free of the shipped dependency |
| XLSX | The streaming library chosen under P6, read and write, one table per sheet | Plan 6.3 |
| Project archive | Core feature, not a plugin. Export from a `VACUUM INTO` copy plus `attachments/`, never the live file with WAL sidecars. Import always creates a new project with a new key and no grants | Plan 6.3 calls it the native path; brief 12.17 and 12.23 |
| Archive import hardening | Treat the zip and the SQLite file as hostile: entry-name allowlist, no path traversal, size and count caps, `application_id` and format checks, `integrity_check`, schema allowlist (no triggers, views, or unexpected objects), `trusted_schema` off, forms re-parsed with the M2 node model and regenerated on failure | First feature that opens a database file TiffinDB did not write |
| Uploads | First file uploads in the application: size cap from PHP limits shown before upload, staging folder inside the data directory, files named by the application, removed on completion or after a timeout | Security baseline, plan 7 |
| Snapshot before import | Reason `import`, taken before the commit step (ADR 0012 decision 3 already anticipates it) | Adds one snapshot reason to the format document |
| Relations on import | The review step can map a column to "Link to <table>", matching by the target's title field and adding missing targets, as `import.html` shows | The mock-up is the accepted target |
| Formula-like cells in CSV export | Offer a spreadsheet-safe option that neutralises leading `=`, `+`, `-`, `@`; default to exact values so round-trips hold. Security design to confirm the default | Escaping changes data; not escaping carries a known spreadsheet risk. A judgement call for the design-tier reviewer |
| Member visibility | Import, Export, and Plugins are Admin-only (`io.use`, `plugins.manage` in the M1 permission table); the sidebar hides them for Members | Brief 12.19 |
| Round-trip fixtures | Generated at test time from a seeded generator with a documented seed; no binary fixtures committed. XLSX read tests also use a workbook assembled by hand in the test, so a reader and writer sharing one bug cannot pass each other | `CLAUDE.md` fixture rules; test independence |

### 7.2 Work packages (indicative; the M3 spec fixes ids, owned files, and acceptance tests)

| ID | Title | Tier | Depends on | Security-critical |
|---|---|---|---|---|
| M3-WP0 | Shipped dependency: pins in `composer.json`, `docs/dependencies.md` rows with versions and source references, licence test and CI updated, installer requirement check and `tools/host-check/` extended if the library needs further PHP extensions | implementation | probe (section 8.1) | Yes |
| M3-WP1 | `Core\Io`: neutral model, interfaces, inspect, review plan, coercion and type inference, staging, atomic commit, report, import log; project format version 3 | implementation | none | Yes |
| M3-WP2 | Plugin registry: manifest format, directory scan, loading, enable and disable, system schema step | implementation | none | Yes |
| M3-WP3 | Web plumbing and components: uploaded files in `Request`, limits, staging folder, streamed download `Response`, step indicator, mapping table, file input, shared strings | implementation | none | Yes |
| M3-WP4 | CSV import and export plugins | implementation | WP1, WP2 | Yes |
| M3-WP5 | XLSX import and export plugins | implementation | WP0, WP1, WP2 | Yes |
| M3-WP6 | JSON export plugin and format-document tests | implementation | WP1, WP2 | No |
| M3-WP7 | Project archive export and import | implementation | WP1 | Yes |
| M3-WP8 | `Core\App`: import, export, plugin, and archive operations; audit actions; snapshot before import | implementation | WP1, WP2, WP7 | Yes |
| M3-WP9 | Import screens, steps 1 to 4 | implementation | WP3, WP8, at least WP4 | Yes |
| M3-WP10 | Export, archive, and plugins screens | implementation | WP3, WP8 | Yes |
| M3-WP11 | Seeded fixture generator, round-trip tests, end-to-end and keyboard-only runs, visual check against `import.html` | implementation | WP4 to WP10 | No |

Waves:

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP1, WP2, WP3, then WP0 as a slot frees | M2 CLOSED and M3-D2 merged |
| 2 | WP4, WP5, WP6, then WP7 | WP1 and WP2 merged (WP5 also needs WP0) |
| 3 | WP8 | WP7 merged |
| 4 | WP9, WP10 | WP3 and WP8 merged |
| 5 | WP11 | wave 4 merged |

Every importer, the plugin loader, the archive import, and the upload path get a design-tier security review (orch 2 rule 4).

### 7.3 Exit audit (M3-X)

Independent design-tier audit against section 2, plus: round trips CSV to project to CSV, XLSX to project to XLSX, project to JSON checked against `docs/formats/json-export.md`, archive export to archive import compared table by table; hostile-input corpus for CSV, XLSX, and archive (oversized, malformed, traversal names, unexpected SQLite objects); an import that fails part-way leaves the target unchanged; a Member is refused every import, export, and plugin operation in core; shipped packages match `docs/dependencies.md` exactly; no network call in application code; licence allowlist test passes with the new packages.

## 8. Cross-milestone items

### 8.1 Dependency probe (phase 0)

Purpose: find out in the first hour whether the XLSX library can be obtained and used, because a blocked host is a maintainer blocker (orch 7) and M3-WP5 depends on it.

1. In an empty scratch directory, obtain the candidate library at a tagged release by the orch 9 method.
2. Read its own licence file at that tag and its Composer manifest: licence must be on the `CLAUDE.md` allowlist; the PHP constraint must admit 8.3; list every required PHP extension and every transitive package with its licence.
3. Record the result in the ledger. Pass: M3-D1 writes it into ADR 0016. Fail on licence or PHP floor: M3-D1 selects an alternative by ADR. Fail on download: raise `BLOCKER`; M2 and every M3 package except WP0 and WP5 continue.

Not known until the probe runs: which release admits PHP 8.3, and whether the library needs extensions beyond `pdo_sqlite`, `zip`, and `xmlreader`. ADR 0009 B5 holds only an unverified reading of a development branch.

### 8.2 Project format steps

| Version | Milestone | Adds |
|---|---|---|
| 2 | M2 | `_tiffin_forms` |
| 3 | M3 | `_tiffin_import_log`, staging table if adopted, snapshot reason `import` |

Two steps, not one combined step, so that M2 can close on a documented format. Both are designed in phase 1 and phase 2 by agents that see each other's drafts through the specs.

### 8.3 Files touched by both milestones

`src/Web/routes.php`, `src/Web/Request.php`, `src/Web/Response.php`, `src/Web/Kernel.php`, `public/assets/css/app.css`, `src/Core/App/Operations.php`, `src/Core/Schema/FormatMigrations.php`, `docs/formats/tiffin.md`, `docs/dependencies.md`. Each has exactly one owning package per milestone; M3 packages start from the M2-closed branch, so there is no concurrent ownership.

## 9. Risks

| Risk | Effect | Mitigation |
|---|---|---|
| XLSX library download blocked, or no release admits PHP 8.3 | M3-WP5 cannot be built | Phase 0 probe; CSV kept dependency-free; alternative by ADR; blocker raised early |
| M3 spec written against an M2 design that changes during implementation | M3 rework | M3-D1 starts after M2-R1; M3-D2 reconciliation gate before any M3 package |
| Stored form HTML reaches the page unsanitised through archive import | Stored XSS | Default-deny node model in M2; archive import re-parses forms; design-tier review of both |
| Hostile SQLite file in an archive | Code paths running attacker-defined triggers or views | Schema allowlist, `trusted_schema` off, integrity checks; design-tier review |
| Large imports exceed shared-host limits | Failed or partial imports | Staged chunks with one final transaction; limits unverified until the maintainer's host check |
| Usage limit mid-wave | Unpushed worktree work lost on container reclaim | Checkpoint after every merge; ledger lists unpushed packages; side branches only with maintainer permission (orch 3 step 5) |
| Session length | Context loss across 6 to 8 windows | Ledger is the only state; every resume starts from its recorded next action |
| Keyboard-only and screen-reader claims rest on scripted checks | False assurance | Report scripted keyboard evidence as such; record the manual screen-reader pass as not verified |

## 10. Assumptions

1. The three-tier model mapping and the instruction to run M2 and M3 without a stop are given in the session prompt (orch introduction).
2. The integration branch is the branch assigned to that session; it starts from `main`, which holds all of M0 and M1.
3. The build environment is as in orch 9: no Apache, Composer dist downloads refused, headless Chromium available.
4. Provisional decisions P1 to P83 stand unless the maintainer changes them; new open points are settled under orch 5 (cheapest to reverse, recorded in the ledger and in an ADR before code).
5. ADR numbers 0013 to 0017 are indicative; the design tier assigns them in order.

## 11. End of session

One notification at M3 close: suite and lint totals, both exit tables, the provisional decisions added in the session, the deferred maintainer tasks (unchanged: discovery run, host check, now including any extension the XLSX library needs), and the next action (M4 design). Then stop; M4 needs a new instruction.
