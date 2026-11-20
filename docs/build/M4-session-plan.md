# TiffinDB build session plan: M4

Status: proposed, awaiting the maintainer's go-ahead. This is a session-level plan for the orchestrator. It does not replace the M4 design specs (`docs/specs/M4-*.md`), which the design tier writes during the session under `docs/build/orchestration.md` section 3. `CLAUDE.md` overrides anything here. Build state stays in `docs/build/ledger.md`.

References: "plan N" is `docs/plan.md` section N; "ui N" is `docs/ui-design-plan.md` section N; "orch N" is `docs/build/orchestration.md` section N; "P n" is a provisional decision in ledger section 4; "format N" is `docs/formats/tiffin.md` section N.

## 1. Bottom line

- One orchestrated session delivers M4: the relation picker, attachment upload and serving, saved collections, and the trash screen. It ends at the M4 exit audit.
- Most of the storage already exists. Join tables, `_tiffin_attachments`, `_tiffin_attachment_links`, `RecordOperations::setLinks`, `TrashOperations` (list, restore, empty), the `collections.manage` permission, the reusable filter `Condition`, uploaded files in `Request`, and streamed download responses are in the tree. M4 is mainly screens plus three new core pieces: an attachment store, a multi-rule list query, and a collection store.
- One project-format step (version 4) adds `_tiffin_collections`. Attachments need no format change (format 8 already defines the storage).
- Size: 1 design package, 1 spec review, about 10 work packages, 1 exit audit. Estimate 3 to 4 usage windows. Basis: M1 (13 packages, 2 specs) took about 3 windows. This is an extrapolation, not a measurement.
- All design-tier work (specs, ADRs, screen designs, spec review, security reviews, exit audit) runs on an Opus model at a minimum (section 4).
- Seven maintainer answers would remove rework (section 3). None blocks the start; each has a provisional default.

## 2. Scope and exit criteria

| Area | Scope (plan 8, ui 6 and 11) | Exit criteria |
|---|---|---|
| Relations | Relation control on the record form as in `record.html`: open, unlink, choose or add links through a picker page; requiredness of relation fields enforced (P105) | Linked records editable from a form, by keyboard only; control matches the record mock-up |
| Attachments | Upload, remove, download, image display on the record form, image view page; access-checked serving endpoint; file removal when nothing links to a file | Attachment access control tested (matrix in section 5.3); control matches the record mock-up |
| Saved collections | `_tiffin_collections` (format version 4); collection editor as in `collection.html` (name, all or any, rule rows, sort, match preview); sidebar Collections group; collection shown through the list view | Collection round-trips through archive export and import; editor matches the mock-up |
| Trash | Trash screen per project: list by table, restore, empty (Admin, with confirmation); sidebar count becomes a link | Restore and empty work by keyboard only; emptying removes files that nothing else links to |

Not in this session: form editor and sanitizer (M5); legacy-product importer (M6); packaging and About page (M7); snapshot restore, project deletion, audit-log screen (P49; see section 3 row 6, these currently have no milestone).

Carried into the session: P105 (requiredness for relation and attachment fields), P74 (typed trash listing), the M3 low items in the ledger's "Open low items carried forward" paragraph, and the M2 open test gaps.

## 3. Maintainer decisions that would help before the start

| # | Question | Default if not answered | Why it matters now |
|---|---|---|---|
| 1 | Which file types may be attached? | Any type. Only PNG, JPEG, GIF, and WebP are ever shown in the page; everything else (PDF and SVG included) is download-only | An allowlist of types is safer but blocks ordinary household files; the serving rules below make "any type" defensible |
| 2 | Size limits and quotas | Per file: the smaller of the PHP upload limits and 64 MiB (same cap as imports, P116). No per-project quota; the project settings page shows total attachment size | Quotas need a storage counter and new failure states; adding them later is cheap |
| 3 | Thumbnails | None in v1. Images are served as stored and sized by CSS; uploads over a stated pixel size are still accepted | Generating thumbnails requires the `gd` extension (a new installer requirement) and an image-decoding attack surface |
| 4 | Who owns a collection? | Shared: every person with access to the project sees and may change every collection (Members hold `collections.manage` today) | Per-person collections change the table design and the archive rules |
| 5 | Collection rule operators | Text-like: contains, is, is empty, is not empty. Number, currency, rating, duration: is, less than, more than, is empty. Date and date-time: on, before, after, is empty. Choice, checkbox, relation: as the M2 filter. All or any across rules; no nested groups; at most 10 rules | The operator set is stored in the project file, so it is a format commitment |
| 6 | Take the P49 items (snapshot restore, project deletion, audit-log screen) into M4? | No. Recommend assigning them to M7 with the About page; record the assignment in the ledger | They have no milestone in plan 8; project deletion interacts with attachment cleanup |
| 7 | Should the session stop at M4 close for inspection? | Yes; one notification at M4 close, as at M3 | M5 (sanitizer) is the next security-heavy design |

## 4. Session shape

| Phase | Work | Tier |
|---|---|---|
| 0 | Resume set-up: record the window and the assigned integration branch in ledger section 1; add `/.claude/` to `.git/info/exclude`; rebuild the trimmed offline PHPUnit install and the pinned XLSX library (orch 9); run `composer lint` and `composer test`, expected 8414 tests, 1 skipped, as at M3 close | orchestrator |
| 1 | M4 design (M4-D1), spec review (M4-R1), rulings applied | design |
| 2 | Implementation, waves 1 to 5 | implementation, mechanical |
| 3 | Exit audit (M4-X), fixes, as-built notes, ledger close, end-of-session notification | design |

Model floor for design work (maintainer instruction, overrides the session prompt's mapping where that is lower): every design-tier agent runs on an Opus model at a minimum; a more capable model is allowed, a less capable one is not. This covers M4-D1 and every spec, ADR, format document, and screen design it produces, the spec review M4-R1, design rulings and root-cause redesigns, the design-tier security reviews of section 6, and the exit audit M4-X. The orchestrator sets the model explicitly on each of these calls (orch 2 rule 1) and does not dispatch a design-tier task if no such model is available; that is a blocker (orch 7). The ledger stays free of model names (orch 8); it records only that the floor was met.

Rules in addition to orch 2 and 3: no package is dispatched before M4-R1 is merged; checkpoint (commit, push, ledger) after every merge; each agent brief names its own scratch subfolder and port.

## 5. Design

### 5.1 Outputs (M4-D1, reviewed by M4-R1)

| Output | Content |
|---|---|
| `docs/specs/M4-core.md` | Attachment store, attachment and link operations, picker candidate query, multi-rule list query, collection store, format version 4 migration, archive and JSON export changes, trash operations, test plan, work packages |
| `docs/specs/M4-screens.md` | Routes, controllers, templates, record-form controls, picker, upload, image view, collection editor, trash screen, sidebar changes, strings, keyboard and visual procedures |
| `docs/design/m4/` | Designs for the screens with no mock-up (ui 6.2): relation picker, attachment upload and image view, trash and restore; component notes for the relation and file controls and the collection editor taken from `record.html` and `collection.html` |
| ADR 0018 (number indicative) | Attachments: accepted types, type detection, limits, serving headers, removal of unlinked files |
| ADR 0019 (number indicative) | Saved collections: storage, rule model, operators, ownership; relation picker behaviour |
| `docs/formats/tiffin.md` | Format version 4: `_tiffin_collections` with the exact upgrade SQL; section 8 updated from "arrives in M4" to as-built |
| `docs/formats/json-export.md` | Collections added to the export; format number raised if the change is not purely additive |

### 5.2 Design points the spec must settle, with the recommended direction

| Point | Recommendation | Reason |
|---|---|---|
| Picker without JavaScript | A separate page per relation field (`.../records/{record}/links/{field}`): find box, paged list of the other table's live records, radio buttons when the side allows one link and checkboxes when many, one Save. It posts through `RecordOperations::setLinks` with the record's version token | Every screen must work without scripts; the version token already covers link ids (P85) |
| Links on a record not yet saved | The new-record form shows the relation and file sections with a note that they can be filled after the first Save; Save returns to the same record | Holding pending links and uploads for an unsaved record needs server-side drafts; this is the cheapest reversible choice. Deviation from the mock-up to record |
| "Add a new one and link it" | From the picker, a link to the other table's new-record form that returns to the picker with the new record ticked | The mock-up's "Link a warranty" implies it; without it the user leaves the record to create the target |
| Unsaved edits when leaving for the picker | Picker and upload buttons are separate forms, as the trash form is; the spec states that unsaved field edits on the record form are not carried, and the design says so next to the controls | Nested forms are not allowed; carrying edits needs drafts |
| Attachment store | New `Core\Storage` class: write the upload to a temporary file inside the project folder, hash it (SHA-256), move it to `attachments/<2>/<64>` only if absent, then insert the `_tiffin_attachments` row and link in one transaction. A file is never named from user text | Format 8; content addressing gives de-duplication and safe names |
| Type detection | By TiffinDB's own check of leading bytes for the four image types; every other file is stored with a neutral media type. The browser-supplied type and the file extension are never trusted. The original name is kept as data and cleaned for the download header | Avoids depending on the `fileinfo` extension and on its database varying by host |
| Serving | One route, authorized in core: the actor needs `records.view` on the project, and the attachment must be linked from a live record field. Headers: the four image types inline with their exact type, everything else `application/octet-stream` with `Content-Disposition: attachment`; `nosniff`; a response CSP of `default-src 'none'; sandbox`; `Cache-Control: private`. A missing grant and a missing file both give "not found" | Plan 7; stops a stored HTML or SVG file from running in the application's origin |
| Removing files | Unlinking removes the link. The stored file and its `_tiffin_attachments` row are removed when no link remains, after the transaction commits; emptying the trash does the same for the deleted records (`Trash::empty` already receives the attachment fields). A sweep for files with no row runs with the staging sweep | Format 8 allows two links to one file; a crash between commit and unlink must leave only a harmless orphan, never a dangling row |
| Images in forms | The form binder's image elements (attachment references, P91) start rendering through the serving route | Was "nothing in M2" in `docs/design/m2/record.md` |
| Uploads per request | One file per request (P126 stays); "Replace photo" is remove plus add in one transaction | Keeps the request parser unchanged |
| Collections storage | `_tiffin_collections`: key, table key, name, match (`all` or `any`), rules as JSON of `Condition` values, sort, position, created and updated columns. Rules that name a removed field or choice are shown as broken and ignored, never an error page | Reuses the M2 condition object (P89); schema changes must not break a collection |
| Multi-rule query | `ListQuery` takes a list of conditions and a match mode; `ListSql` composes them with the existing fragment type. The one-condition filter becomes the one-rule case | Keeps one SQL composition path (P107); security-critical |
| Collection screens | A collection opens in the list view (same paging, find, sort) at `/projects/{p}/collections/{c}`; the editor has a Preview button that reloads the count and first rows, and a script may press it on change | The mock-up's live preview must have a no-script form |
| Archive and export | Archive rebuild copies collections after validating every rule against the rebuilt schema; JSON export lists them | Otherwise an archive round trip loses them |
| Trash screen | `/projects/{p}/trash`: one section per table with a count, each row a title, when and by whom it was trashed, and Restore; "Restore all in this table"; "Empty the trash" for Admins only, behind a confirmation page that states the record and file counts (P2). Links to trashed records stay stored and reappear on restore (format 7) | Uses the existing `TrashOperations` |
| Requiredness | Relation and attachment fields marked required are enforced on Save once the record exists; imports still enforce none (P118) | Closes P105 without blocking record creation |
| Audit actions | `attachment.added`, `attachment.removed`, `collection.saved`, `collection.deleted`, `trash.emptied` (amends ADR 0012 decision 4) | Core feature from M1 |

### 5.3 Access-control test matrix for attachments (exit criterion)

Each row is a core test and a request-level test against the serving route.

| Actor | Case | Expected |
|---|---|---|
| Signed out | Any attachment address | Redirect to sign-in; no bytes |
| Member without a grant on the project | Valid attachment id | Not found |
| Member with a grant | Attachment linked from a live record | 200 with the headers of section 5.2 |
| Member with a grant | Attachment id from another project | Not found |
| Member with a grant | Attachment linked only from a record in the trash | Not found |
| Member with a grant | Attachment row present, file missing on disk | Not found, logged |
| Any | Upload without a CSRF token, or over the size cap | Refused; nothing stored |
| Any | Uploaded HTML, SVG, or script file | Stored; served only as a download with the sandbox CSP |
| Deactivated person with an old cookie | Any | Redirect to sign-in |
| Direct web request | `attachments/` path under the data folder | Not reachable (installer probe and `.htaccess`; on a real server this is confirmed only by the host check) |

## 6. Work packages (indicative; the M4 spec fixes ids, owned files, and acceptance tests)

| ID | Title | Tier | Depends on | Security-critical |
|---|---|---|---|---|
| M4-WP0 | Carried-forward items (section 2) | implementation | none | No |
| M4-WP1 | Attachment store: temporary write, hashing, placement, type check, removal, orphan sweep | implementation | none | Yes |
| M4-WP2 | `Core\Query`: attachment link operations, picker candidate query, requiredness for relation and attachment fields, file removal on unlink and on emptying the trash | implementation | WP1 | Yes |
| M4-WP3 | Multi-rule `ListQuery` and `ListSql`; operators of section 3 row 5; collection store; format version 4 migration; archive rebuild and JSON export changes | implementation | none | Yes (SQL composition) |
| M4-WP4 | `Core\App`: link, attachment, collection, and trash-screen operations; download authorization; audit actions; layer-rule test updates | implementation | WP2, WP3 | Yes |
| M4-WP5 | Web components: relation control, file row, upload control, image, rule row, sidebar Collections group and trash link, file response headers, stylesheet, shared strings | implementation | none | Yes (templates, headers) |
| M4-WP6 | Record screens: picker pages, unlink, upload, remove, image view, serving controller | implementation | WP4, WP5 | Yes |
| M4-WP7 | Collection screens: editor, preview, collection list view, delete with confirmation | implementation | WP4, WP5 | Yes |
| M4-WP8 | Trash screen: list, restore, empty with confirmation | implementation | WP4, WP5 | Yes |
| M4-WP9 | End-to-end tests, access-control matrix at request level, keyboard-only runs, archive round trip with real attachment files and collections, visual check against `record.html` and `collection.html` | implementation | WP6, WP7, WP8 | No |

Waves (at most three agents):

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP1, WP3, WP5, then WP0 as a slot frees | M4-R1 merged |
| 2 | WP2 | WP1 merged |
| 3 | WP4 | WP2 and WP3 merged |
| 4 | WP6, WP7, WP8 | WP4 and WP5 merged |
| 5 | WP9 | wave 4 merged |

The attachment store, the serving route, the upload path, and the multi-rule SQL get a design-tier security review (orch 2 rule 4).

## 7. Exit audit (M4-X)

Independent design-tier audit against section 2, plus: the matrix of section 5.3 re-run by the auditor; a hostile-upload corpus (HTML, SVG with script, a file whose name holds path separators, control characters, and a very long name; a zero-byte file; a file at and just over the cap); a failed upload leaves no file and no row; version 3 file upgrades to version 4 after a snapshot and opens with plain SQLite tools; the archive round trip keeps attachment bytes (hash equal) and collections; a Member can link, unlink, upload, remove, build a collection, and restore by key presses only; mutation checks; visual procedure at 1360 px and 390 px in four themes and at 320 px Largest. Manual screen-reader pass: recorded as not verified, as in M0 to M3.

## 8. Risks

| Risk | Effect | Mitigation |
|---|---|---|
| Uploaded active content served in the application's origin | Stored XSS | Download-only for all but four image types, sandbox CSP, `nosniff`, own type check; design-tier review |
| File and database disagree after a crash | Dangling row or orphan file | Order of operations in section 5.2 (file before row on add, row before file on removal); sweep; test with injected failures |
| Collection rules name fields that later change | Error pages or wrong matches | Rules validated on every read; broken rules shown and ignored |
| Multi-rule SQL composition | Injection or slow queries | Single composition class, scanner test extended, `EXPLAIN QUERY PLAN` check on a 20,000-record fixture (the M3 join-order lesson) |
| Shared-host upload limits lower than assumed | Uploads fail on the reference host | The effective limit is shown before upload; real figures come only from the maintainer's host check |
| Mock-up shows controls with no defined no-script behaviour | Rework after review | Designs in `docs/design/m4/` before code; deviations listed in the spec as in M2 and M3 |

## 9. Assumptions

1. The tier-to-model mapping is given in the session prompt (orch introduction), subject to the design-tier floor in section 4.
2. The integration branch is the branch assigned to that session; it starts from `main`, which holds all of M0 to M3.
3. The build environment is as in orch 9: no Apache, headless Chromium available.
4. Provisional decisions P1 to P144 stand unless the maintainer changes them; new open points are settled under orch 5.
5. ADR numbers 0018 and 0019 are indicative.
6. Not known until the design runs: whether the collection editor's rule rows can be added and removed without scripts in one page (expected: "Add a rule" and "Remove" as submit buttons that redisplay the form), and whether any M3 exporter needs a contract change to carry collections.

## 10. End of session

One notification at M4 close: suite and lint totals, the exit table, the provisional decisions added, the deferred maintainer tasks (unchanged: discovery run, host check), and the next action (M5 design). Then stop; M5 needs a new instruction.
