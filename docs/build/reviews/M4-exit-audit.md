# M4 exit audit (M4-X)

Independent design-tier exit audit of milestone M4 on the integration branch at `6f12d64` (all M4 work packages, reviews, and follow-ups merged), against `docs/build/M4-session-plan.md` sections 2, 5.3, 7, and 8, `docs/ui-design-plan.md` section 11, `docs/specs/M4-core.md`, `docs/specs/M4-screens.md`, ADR 0018, ADR 0019, `docs/formats/`, and the repository rules in `CLAUDE.md`. Every result below comes from something the auditor ran; tests, comments, and ledger text were not taken as evidence on their own. The earlier M4 reviews (`docs/build/reviews/M4-sec-*.md`) were read only after the results were formed.

Evidence (scripts, logs, screenshots) is in the session scratchpad and is not committed. All data is synthetic (`CoreFixture`, `M2Fixture`, `M4Fixture`, generated files).

## Set-up

- A scratch installation built from `M4Fixture` ("Home Inventory": 48 live items, 3 in the trash, 51 attachment rows with real files including all four image types, an HTML file and an SVG with a script, two rows sharing one file, three collections, one with a broken rule), plus a second project "Second Project" shared with the Member, whose one attachment was given id 9999 (an id that does not exist in Home Inventory), and a third Member granted on Home Inventory for the deactivation row. The data folder is outside the application tree (`config/local.php` `data_dir`).
- Served by the PHP built-in server through `public/index.php` (default PHP limits: `upload_max_filesize` 2 MiB, `post_max_size` 8 MiB), and by a second instance with the limits raised to 80 and 100 MiB for the 64 MiB core cap.
- Requests with a plain HTTP client (no script); keyboard and visual runs in headless Chromium.

## Exit criteria (session plan section 2)

| Area | Exit criterion | Result | Evidence |
|---|---|---|---|
| Relations | Linked records editable from a form, by keyboard only | PASS | Key presses only after loading `/sign-in`: choose a room through the picker with find, unlink a warranty, link one, "Add a new warranty and link it" (returns to the picker with it linked), back to the record. Also with JavaScript turned off. No pointer event recorded |
| Relations | Control matches the record mock-up | PASS | Record form at 1360 and 390 px against `record.html`: matches apart from the listed deviations M4 D-31 to D-38 and D-47 |
| Relations | Requiredness enforced (P105) | PASS | Save of a record with a required, empty attachment field: 422 with "Add at least one file here." in the summary and at the field; tests T-LNK-8, T-ATT-12, T-APP-104 pass |
| Attachments | Access control tested (matrix 5.3) | PASS | Every row re-run at request level (table below): 10 of 10 hold, row 10 on the built-in server only |
| Attachments | Control matches the record mock-up | PASS | File rows, image, Open/Replace/Remove, Add a file and Add an image as designed (D-31 to D-34) |
| Saved collections | Collection round-trips through archive export and import | PASS | Export through `/projects/{p}/export/archive`, import through `/projects/import`: attachment rows (72), links (72), and collections (3, key, name, match, rules, sort, times) equal; 66 stored files, each named by its own SHA-256 in source and copy; every file served from the copy hash-equal; the collection view opens in the copy |
| Saved collections | Editor matches the mock-up | PASS | "Under warranty" at 1360 and 390 px in four themes against `collection.html`: matches apart from D-39 to D-44 |
| Trash | Restore and empty work by keyboard only | PASS | Member: "In the trash", Restore one, Restore all in Items; Admin: Move to trash, "Empty the trash", confirmation stating 1 record and 2 files, Empty the trash; by key presses only, with and without JavaScript |
| Trash | Emptying removes files that nothing else links to | PASS | After the keyboard empty, both stored files of the emptied record (a GIF and a PDF) were gone from `attachments/`; T-TRASH-5/6 and T-APP-143 (shared file kept) pass |

## Plan section 7 items

| Item | Result | Evidence |
|---|---|---|
| Suite and lint | PASS | `composer test`: 9498 tests, 961746 assertions, OK (1 skipped: no comma-decimal locale). `composer lint`: 786 files, 0 failed |
| Matrix of section 5.3 re-run (request level, real install) | PASS | Table below |
| Hostile-upload corpus | PASS | Table below |
| Image signature plus HTML: nosniff, sandbox CSP, exact headers | PASS | A PNG signature followed by `<html><script>`: stored as `image/png`, served `200`, `Content-Type: image/png`, `Content-Disposition: inline` (`attachment` with `?download=1`), `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`, `X-Frame-Options: DENY`, `Cache-Control: no-store`, `Content-Length` exact, bytes equal (ADR 0018 decision 5 exactly) |
| A failed upload leaves no file and no row | PASS (as designed) | Every refusal (no or wrong CSRF token, zero bytes, over the PHP cap, over `post_max_size`, over the 64 MiB core cap, non-image in an images-only field) left the attachments folder, the staging folder, and the row and link counts unchanged. A database failure injected after placement (a trigger raising on the row insert, and on the link insert) gave 422 "The file could not be stored. Try again.", no row, no staging folder, and the placed file as an orphan, removed by the orphan sweep at the start of the next upload (checked on disk). This is the designed order (ADR 0018 decision 6: never a row without a file) |
| Version 3 file upgrades to version 4 after a snapshot, opens with plain tools | PASS | A version 3 installation built with the code at `60eb860` (before the format step) and opened through the current front controller: one `format-upgrade` snapshot (the snapshot is version 3), `user_version` 4, meta 4, `_tiffin_collections` and its two indexes exactly as `docs/formats/tiffin.md` 11.3, `integrity_check` ok, `foreign_key_check` empty, every pre-existing table row-for-row equal, the attachment served with its bytes, the new-collection page 200, no second snapshot on reopen. The file opens and lists its tables with Python's `sqlite3` and PHP's plain PDO SQLite driver |
| Archive round trip keeps attachment bytes and collections | PASS | Above (exit criterion row) |
| Member can link, unlink, upload, remove, build a collection, restore by key presses only | PASS | Keyboard run as described; the file was chosen with `setInputFiles` on the focused file input (the native dialog was not scripted). Every focused element showed the accent focus ring except the two date inputs (browser's own ring on the calendar button, as WP9 recorded) |
| Mutation checks | PASS | 23 of 23 mutations of security-critical code killed (table below) |
| Visual procedure (V-20 to V-25) | PASS | 17 screens at 1360 and 390 px in light, dark, high-contrast light and high-contrast dark, and at 320 px with the Largest text size: 153 states, each 200, none scrolls sideways, no inline script or style, no broken image |
| Manual screen-reader pass | NOT VERIFIED | As in M0 to M3 |
| Every screen works without JavaScript | PASS | The whole keyboard run, Member and Admin, passed with JavaScript turned off in the browser; the matrix, corpus, and round trip used a plain HTTP client |
| No inline scripts or styles | PASS | 0 inline `script` and 0 `style` elements or attributes in all 153 visual states; `TemplateRulesTest` passes; `collection-editor.js` removed (WP9) |
| SQL scanner covers new files | PASS after fix | New `Core\Query`, `Core\Io`, and `Core\Storage` files are scanned. `src/Core/App/Listings.php` (new in M4) composed a `SELECT COUNT(*)` statement with `Sql` outside the scanned directories: fixed (F-1) |
| Spec 8.1 wording (P183) | FIXED | As-built note in `docs/specs/M4-core.md` section 17: a collection key that is a valid key must be unique (`bad-metadata`); an invalid key is skipped and the row dropped on rebuild (`collections_dropped`) |
| Repository rules | PASS, one low finding | 117 files added since M3 close: every source file has the SPDX line and "TiffinDB contributors"; no email address other than reserved example domains; no personal or employer name; no IP address outside 192.0.2.0/24; `composer.json` and `docs/dependencies.md` unchanged. Finding F-4: the ledger's "Next action" line names a model tier |
| Hunt the flaky test | FOUND AND FIXED | Section below |

## Access-control matrix (plan section 5.3), request level

Real installation on the PHP built-in server, sessions from the sign-in form.

| # | Actor and case | Expected | Observed |
|---|---|---|---|
| 1 | Signed out, all 51 file addresses, with and without `?download=1` | 303 to sign-in, no bytes | 303 `/sign-in`, no file bytes |
| 2 | Member without a grant, all 51 ids | Not found | 404 for each, body identical to that of a nonexistent id |
| 3 | Granted Member, every live file, plain, `?download=1`, `?download=0`, `?download=yes`; and HEAD | 200 with the ADR 0018 headers | 200, exact `Content-Type` (the four image types; everything else `application/octet-stream`), disposition (inline only for the four image types without `download=1`), `nosniff`, sandbox CSP, `X-Frame-Options`, `Cache-Control: no-store`, `Content-Length`, bytes equal to the stored file; HEAD sends headers only |
| 4 | Granted Member, id 9999 that exists only in the second project | Not found | 404 (200 through the second project's own address) |
| 5 | Granted Member, file linked only from a record moved to the trash | Not found | 404 for the GIF after "Robot vacuum" was moved to the trash |
| 6 | Row present, file missing on disk | Not found, logged | 404; one log line `attachment file is missing; attachment=93`, without the name |
| 7 | Upload without a CSRF token, with a wrong one; over the cap | Refused, nothing stored | 403 for both; over the PHP cap 422, over `post_max_size` 413, over the core cap 422; attachments, staging, rows, links unchanged |
| 8 | Uploaded HTML, SVG with script, JavaScript, PHP | Stored, served only as a download with the sandbox CSP | All stored as `application/octet-stream`, served `attachment` with the sandbox CSP for every query |
| 9 | Deactivated person with an old cookie | Sign-in | 200 before deactivation; after the Admin deactivated them, 303 `/sign-in` |
| 10 | Direct requests to `data/...`, `projects/<p>/attachments/...`, `project.tiffin` paths | Not reachable | 404 or 303, never file bytes (built-in server, data folder outside `public/`; Apache `.htaccess` behaviour remains the maintainer's host check) |

## Hostile-upload corpus

Through `POST .../files/{field}` as the Member, each then served and checked.

| Case | Stored name | Type | Served |
|---|---|---|---|
| `page.html` with script | `page.html` | octet-stream | attachment, sandbox, nosniff |
| `pic.svg` with `onload` and script | `pic.svg` | octet-stream | attachment |
| `script.js`, `run.php` | as sent | octet-stream | attachment |
| PNG signature plus HTML | `polyglot.png` | `image/png` | inline (attachment with `download=1`), nosniff, sandbox CSP |
| `../../etc/passwd`, `dir\sub\win.txt`, `a/b\c.txt` | `passwd`, `win.txt`, `c.txt` | octet-stream | attachment |
| Control characters (`\x01 \x07 \x1b \x7f`), raw in the multipart header | `ctl[2Jbell.txt` | octet-stream | attachment |
| U+202E, U+200B, U+FEFF | removed (`invoicefdp.exe`, `exe.fdp`) | octet-stream | attachment |
| `"`, `;`, `\` in the name | kept as data | octet-stream | ASCII fallback with `_`, `filename*` percent-encoded; no extra header |
| NUL in the name | cut at the NUL by PHP (`nul`) | octet-stream | attachment |
| Invalid UTF-8 name | `file` | octet-stream | attachment |
| 1,000-character name | first 255 characters (extension lost: P187, deferred to M7) | octet-stream | attachment |
| Non-ASCII name | kept | octet-stream | `filename*` UTF-8 |
| HTML or SVG into the images-only Photo field | refused 422 | | nothing stored |
| Zero-byte file | refused 422 "That file is empty." | | nothing stored |
| Exactly 2 MiB (PHP cap) / 2 MiB + 1 | accepted / refused 422 | | nothing stored on refusal |
| Over `post_max_size` | refused 413 | | nothing stored |
| Exactly 64 MiB (core cap, raised PHP limits) / 64 MiB + 1 | accepted / refused 422 "larger than 64.0 MB" | | nothing stored on refusal |

The staging folder was empty after every case.

## Mutation checks

Each mutation applied alone to a copy of the tree, the named tests run, the source restored.

| Mutation | Killed by |
|---|---|
| CSRF check in the kernel disabled | `ArchiveScreenTest::testCsrfIsRequired` (and the M4 upload CSRF tests) |
| Serving opens the project without the grant check | `AttachmentAccessTest::testUngrantedMemberGetsNotFound` (core) |
| Serving drops `isServable` | `AttachmentAccessTest::testAFileLinkedOnlyFromATrashedRecordIsNotFound` |
| `isServable` ignores `_trashed_at` | `AttachmentsTest::testIsServable` |
| Each rule not parenthesised | `HostileCollectionTest::testEveryFragmentKeepsValuesBound` |
| The `any` group not parenthesised | same |
| `nosniff` header removed | `AttachmentAccessTest` (web) exact headers |
| `sandbox` dropped from `FILE_CSP` | same |
| `Response::file` not marked sandboxed | same |
| `Response::file` allows inline for any type | `ResponseTest::testFileRefusesInlineForAnyOtherType` |
| `Response::file` passes the stored type through | same |
| `MediaType::isInline` accepts any `image/*` | `AttachmentHostileTest::testSniff` |
| `Content-Disposition` ASCII fallback keeps `"` `;` `\` `/` | `FileServingTest::testDownloadNamesAreSafe` |
| `cleanName` keeps bidi and zero-width characters | `AttachmentHostileTest::testNames` |
| `cleanName` keeps `/` and `\` | same |
| Store accepts any non-empty hash | `AttachmentStoreTest::testPathsOnlyFromValidHashes` |
| Zero-byte uploads accepted | `AttachmentOperationsTest::testEveryRefusedUploadLeavesNothingStoredAndNoStagingFolder` |
| Core cap off by one | `AttachmentAccessTest::testAnUploadOverTheCapIsRefusedAndNothingIsStored` |
| Emptying the trash deletes rows still linked from live records | `TrashFilesTest::testEmptyFreesRowsNothingLinksAndReturnsTheirHashes` |
| `deleteFiles` ignores remaining rows | `AttachmentHostileTest::testTwoLinksOneRowAndTrashEmpty` (and T-ATT-8) |
| `contains` without LIKE escaping | `HostileCollectionTest::testLikeWildcardsAreLiteral` |
| Picker exclusion of linked records removed | `PickerTest::testCandidatesExcludeLinkedRecordsInDefaultOrderFiftyPerPage` |
| Emptying the trash with the Member permission | `ProjectOperationsTest::testAGrantedMemberIsRefusedAdminOnlyMethods` |

## The flaky test

Runs: the full suite 12 times on the unchanged branch (5 in file order, 4 in random order with seeds 101 to 404, 3 with JUnit logs), several of them two at a time, beside the mutation runs, the browser runs, and the servers, plus 90 isolated runs of T-ATT-8 under CPU and fsync load. All passed except one, and that one identified the cause.

**Cause 1 (most likely the observed failure): `EndToEndTest` checks the whole working tree.** Before and after each of its 14 tests it records size, modification time, and SHA-256 of every file of the repository (except `vendor`, `.git`, `.claude`, `.phpunit.cache`) and asserts the two are equal ("Nothing is written outside the temporary folder."). Any other process that changes a file of the working tree during that test fails it: during one audit run, editing a test file failed `testArchiveExportAndImportThroughTheRoutes`; touching `docs/build/ledger.md` every 0.2 s during the file alone failed 4 of its 14 tests. The orchestrator updates the ledger and specs between merges, so a suite running in the background while a file is saved fails one end-to-end test once, and a rerun passes. Fix: the tests now run the kernel, `CoreServices`, and the installer over a private copy of the application root (`public/`, `templates/`, `strings/`, `config/app.php`, made once per process) and check that copy; every path the application writes is resolved from that root, so a write outside the data folder is still caught (verified: a file written to the copy's `public/` fails the test), while edits elsewhere no longer can. With the same touch loop the 14 tests pass, and a full random-order run with the loop running passed.

**Cause 2: T-ATT-8 (`AttachmentsTest::testDeleteFilesWaitsForAnUploadOfTheSameContent`) is timing-dependent.** The child process slept 400 ms after "ready", and the test required `deleteFiles` to wait more than 200 ms and the row to be invisible before the commit. A stall of the test process of 250 ms fails it ("0.18 is greater than 0.2"); one of 450 ms fails the row check. It did not fail in 90 loaded runs, but it is not deterministic. Fix: the child holds its write until the test writes "go" on its standard input (so the row check cannot race the commit), keeps it 1 s, and prints the time it committed; the test asserts that `deleteFiles` started before that commit and returned after it. Stalls of 250, 450, and 800 ms injected at both points pass; making `deleteFiles` ignore the remaining row still fails the test.

**Not a failure: the assertion count varies by one.** `RepoFilesTest::testDirectoryWalkFallbackFindsTheRepositoryFiles` asserts once per file of the directory walk, which includes git-ignored files such as `config/local.php`; with that file present the suite counts one assertion more. Harmless; noted so that the varying total is not mistaken for a flaky test.

## Findings

| # | Severity | Finding | Status |
|---|---|---|---|
| F-1 | Low | `src/Core/App/Listings.php::count` (sidebar and collection summary counts) composed `SELECT COUNT(*) FROM <table> AS "r" WHERE <ListSql::where>` with the `Sql` fragment class in `Core\App`, outside the directories `SqlStringsTest` scans (P107: one composition path). Inputs were safe (a `TableDef` identifier and a `ListSql` fragment) | Fixed: new `Records::countMatching(string $tableKey, ListQuery $query): int`, shared with `Records::page` and the search count; `Listings::count` calls it; new `SqlStringsTest::testCoreAppComposesNoSqlFragments` (fails on the old code) |
| F-2 | Test determinism | `EndToEndTest` tree check fails when another process edits the working tree (cause 1 above) | Fixed |
| F-3 | Test determinism | T-ATT-8 wall-clock race (cause 2 above) | Fixed |
| F-4 | Low (repository rule, orchestration 8) | `docs/build/ledger.md` section 1 "Next action" names the model tier of the exit audit in parentheses; the ledger is to stay free of model names (session plan section 4) | Open: for the orchestrator to remove when it rewrites that line at M4 close (the auditor does not edit the ledger) |
| F-5 | Documentation | Spec 8.1 wording on invalid collection keys (P183) | Fixed in the as-built notes |

Carried, unchanged by this audit: P183 repeated snapshots on failing upgrades (M7); P187 long file names lose their extension (M7); the M3 low items and the host-check and discovery tasks.

Observed and accepted (no finding): sizes use 1,024-based units labelled "MB" (`Format::fileSize`, M2), so the 64 MiB cap reads "64.0 MB"; warranties already linked to another item show "Linked to {title}" without a button in the picker (one-on-the-other-side relation, as designed, P174).

## Not verified

Manual screen-reader pass; `.htaccess` behaviour on a real Apache server and the reference host's upload limits (maintainer's host check); file selection in a native dialog; browsers other than headless Chromium.

## Ledger text (for the orchestrator to paste)

```
### M4 exit results

Independent design-tier exit audit on the integration branch (all M4 packages and review follow-ups merged; audit branch `claude/wizardly-allen-m81ni0--M4-X`). Suite: `composer test` OK (9498 tests, 1 skipped: no comma-decimal locale); `composer lint` 786 files, 0 failed. Report: `docs/build/reviews/M4-exit-audit.md`. Evidence in the session scratchpad (not committed).

| Criterion | Result | Evidence |
|---|---|---|
| Suite and lint | PASS | As above; 12 further full runs (5 in order, 4 random order, 3 with JUnit logs, some two at a time under load) |
| Relations: linked records editable from a form by keyboard only; control matches `record.html` | PASS | Auditor's key-presses-only run on a real installation (picker with find, unlink, link, add a new warranty and link it), also with JavaScript off; deviations D-31 to D-38, D-47 |
| Attachments: access control (plan 5.3); control matches the mock-up | PASS | All 10 matrix rows re-run at request level on the built-in server (row 10 there only; Apache is the host check); exact ADR 0018 headers on all 51 files |
| Hostile uploads | PASS | HTML, SVG with script, PNG signature plus HTML (image/png inline, nosniff, sandbox CSP), path separators, control and bidi characters, invalid UTF-8, NUL, 1,000-character name, zero bytes, at and one byte over the PHP cap and the 64 MiB core cap: every refusal leaves no file, row, or staging folder |
| Failed upload leaves no file and no row | PASS | Refusals leave nothing; an injected database failure after placement leaves no row and an orphan file that the next upload's sweep removes (ADR 0018 decision 6) |
| Saved collections: archive round trip; editor matches `collection.html` | PASS | Rows, links, collections equal; 66 files hash-equal; deviations D-39 to D-44 |
| Trash: restore and empty by keyboard only; files freed | PASS | Member restore and restore all, Admin empty with 1 record and 2 files stated; both files gone from disk |
| Version 3 to 4 | PASS | File made with the pre-WP10 code: one `format-upgrade` snapshot, statements of format 11.3 exactly, data unchanged, opens with plain PDO and Python sqlite3 |
| Visual V-20 to V-25 | PASS | 17 screens, 153 states (1360 and 390 px in four themes, 320 px Largest): no sideways scroll, no inline code |
| Tests not vacuous | PASS | 23 of 23 security mutations killed (CSRF, grant and servable checks, rule parentheses, nosniff, sandbox, inline types, name cleaning, hash check, size checks, trash and file deletion, LIKE escaping, picker exclusion, Admin-only empty) |
| Repository rules | PASS (one low finding) | SPDX on all 117 new files; no names, emails, employers; dependencies unchanged. F-4: model tier named in ledger section 1 (remove at close) |
| Flaky test | FOUND, FIXED | `EndToEndTest` compared the whole working tree, so an edit by another process during the run (for example the ledger) failed one test once: now checks a private copy of the application root. T-ATT-8 depended on a 200 ms timing window: now asserts the order of events. Assertion total varies by one with git-ignored files (`RepoFilesTest` walk), harmless |
| Spec 8.1 wording (P183) | FIXED | As-built note, M4-core section 17 |

Fixes made by M4-X: F-1 `Listings::count` SQL moved to `Records::countMatching` with a new scanner test; F-2 and F-3 test determinism; as-built notes in M4-core section 17 and M4-screens section 13.3.

Not verified in M4: manual screen-reader pass; `.htaccess` behaviour on a real Apache server and the host's upload limits (host check); native file dialog; browsers other than Chromium.
```
