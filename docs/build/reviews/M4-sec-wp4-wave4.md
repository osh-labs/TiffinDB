# M4 security review: WP4 core operations and wave 4 screens

Design-tier security review (orchestration section 2, rule 4) of the M4 integration branch at `cd6664f`: the WP4 core operations (merge `5baeb18`: `AttachmentOperations`, `CollectionOperations`, the `TrashOperations` changes, `Listings`, `Context::sweepStaging`, the new audit actions) and the wave 4 screens (merges `880f787` trash, `b4dc527` collections, `008e649` records, picker and files: controllers, templates, routes, `FileController::serve`, `Response::file` and `contentDisposition`). Checked against `docs/specs/M4-core.md`, `docs/specs/M4-screens.md`, ADR 0018 and 0019, `docs/build/M4-session-plan.md` sections 5.2, 5.3 and 8, and ledger P175 to P186. The reviewer did not write the code.

Method: every property was checked by reading the code and by sending hostile requests in two ways.

1. A real installation under the PHP built-in server (front controller `public/index.php`, real `CoreServices`, real sessions and CSRF, real `$_FILES` handling, PHP's default 2 MB upload and 8 MB post limits), set up through the installer screens, with a seed script and the scratch data folder kept outside the repository. Requests were sent with `curl` as an Admin, a Member with a grant, a second Member, and signed out.
2. `tests/web/M4HostileRequestsTest.php` (new, in the suite): the same cases through the kernel with real core operations, so they keep running.

No data in this review or its tests is real.

Result: **no blocking, high or medium findings.** The wave 4 code may stay merged. Three low findings and three notes follow.

## Access-control matrix (plan section 5.3), at the request level

| Actor | Case | Expected | Seen on the real server |
|---|---|---|---|
| Signed out | `GET /projects/{p}/files/{a}` | Sign-in, no bytes | 303 to `/sign-in`, empty body, page CSP |
| Member without a grant | Valid id of an ungranted project | Not found | 404 plain page |
| Member with a grant | File linked from a live record | 200 and the section 5.2 headers | 200; `application/octet-stream` with `attachment` for HTML and SVG; `image/png` inline (attachment with `download=1`); `Content-Length`; `nosniff`; `default-src 'none'; frame-ancestors 'none'; sandbox`; `Cache-Control: no-store` (P150 keeps `no-store`) |
| Member with a grant | Id that exists only in another project | Not found | 404 (ids are per project file; the id is looked up only in the project of the address) |
| Member with a grant | File linked only from a trashed record | Not found | 404 |
| Member with a grant | Row present, file missing | Not found, logged | 404; log line `attachment=<id>` with no name or path. A symbolic link to `/etc/passwd` and a directory at the file's place also gave 404 and no bytes |
| Any | Upload without CSRF token, or with a wrong one | Refused, nothing stored | 403; rows and files unchanged |
| Any | Upload over the cap | Refused, nothing stored | 3 MB file: 422 "larger than 2.0 MB" (the PHP limit is shown); 9 MB request: 413 before the application; nothing stored, staging folder empty |
| Any | Zero-byte upload | Refused | 422 "That file is empty" |
| Any | HTML, SVG with script, names with `\`, `"`, `;`, control bytes, ANSI escapes, a right-to-left override, 3,000 characters | Stored; served only as a download with the sandbox | Stored with the neutral type; served as `application/octet-stream`, `attachment`; names cleaned (`b%22;.html`, `r[31mxfdp.exe`, cut to 255 code points); `Content-Disposition` printable ASCII only |
| Deactivated person with an old cookie | Any | Sign-in | 303 to `/sign-in` even with the session row left in place (`active` set to 0 directly); in the core, the old `Actor` gets `AccessDenied` from `serve`, `file`, and `add` (`Context::requireCurrentActor`) |
| Direct request | `attachments/` under the data folder | Not reachable | Not re-tested here: the data folder is outside `public/` and the built-in server serves only files under `public/`; the host check remains the only confirmation on a real server |

## Other properties checked

| # | Property | Result | Run |
|---|---|---|---|
| 1 | Authorization in the core before any work | Holds. Every public method of `AttachmentOperations`, `CollectionOperations` and `TrashOperations` starts with `Context::project` for its permission; `AttachmentOperations::open` then calls `requireCurrentActor`. Controllers read the core's screen first (`uploadScreen`, `picker`) and build addresses only from returned keys and ids. `FileController::removeForm` also asks `auth()->require` before the core. | read, yes |
| 2 | IDOR across records, tables, fields, projects, collections | Holds. The file page, replace, remove form and remove POST refuse another record's file, another table, a relation or text field in the field slot, a leading zero, another project, and a trashed record (404). The picker refuses an attachment field and a trashed record. A collection key is not found through another project (view, edit, delete). Link add with a trashed or foreign target is 422 and with a trashed source 404 (`Records::addLink` and `removeLink` check the source is live in the write). | yes |
| 3 | CSRF on every new state-changing route | Holds. Restore, restore all, empty, collection create, edit and delete, link add and remove, file remove, upload, and the new record from a picker are 403 without a token, with a wrong token, with an empty token, and with another person's token; nothing changes (test compares rows before and after). A JSON body carrying a token field is also 403. | yes |
| 4 | Empty-trash confirmation | Holds for a missing, empty, zero, negative, non-digit or array `expected` (the page asks again, nothing deleted) and for a Member (403 on GET and POST). A forged larger count is accepted: see L1. | yes |
| 5 | Admin-only | Holds. `trash.empty` is not open to granted Members; the empty link is not shown to them; the POST is 403. | yes |
| 6 | Open redirects | Holds. `from` must match `<field>-<id>` and name a relation whose other table is the table in the address and a live origin record; `//evil.example`, `https://...`, path segments, a wrong-side relation, and duplicates are 404. The picker's `back` is honoured only as exactly `record`; any other value returns to the picker. `q` and `page` are re-encoded by the URL generator (`page` must be digits). `collection` in a record or restore query is dropped when it names no collection; no foreign link is rendered. | yes |
| 7 | Output escaping | Holds. Project, table, field, record, note, collection and file names holding `<img src=x onerror=...>`, `<script>` and quotes appear only escaped on the trash list, one-table trash, empty confirmation, record page, file page, remove page, upload and replace pages, picker (with a hostile `q`), new record from a picker, collection editor and list, and the sidebar. Translation parameters are escaped by `$this->t`. | yes |
| 8 | Audit log | Holds. `attachment.added` and `attachment.removed` carry field, record, attachment id and size only; `collection.saved` and `collection.deleted` carry table, rule count and match; `trash.emptied` carries record and file counts. No file name, content, hash or path appears. See L2. | yes |
| 9 | Error pages | Holds. 403, 404, 413 and 422 pages carry no file system path; log lines name ids only. | yes |
| 10 | Hostile collection rule input | Holds. A field of another table, arrays where strings belong, a `rules` string, 40 rules, `remove-0`, an over-long `remove-` number, an injected test name, an injected relation record, a 100,000-character value, a 5,000-character name: 200 or 422, never 500, and nothing is stored. A sort token not offered is dropped (stored `NULL`). `%' OR '1'='1` is stored as a value and matches nothing. | yes |
| 11 | Rules edited by another tool | Holds. `rules` not JSON, `match_mode` not `all` or `any`, `sort` `zz;--`, and a hostile name: the collection, editor, delete page, list, trash and record pages are 200 and the rules read are logged by collection key. A collection whose table is gone is left out of the sidebar (P181) and its pages are 404 (note N2). | yes |
| 12 | Sidebar counts | Hold. One count query per collection through `ListSql`; a collection whose table is missing is skipped; results are cached per request by data version. | read, yes |
| 13 | File and row order on add and remove; orphan sweep | Holds. Placement and the row insert run in one `BEGIN IMMEDIATE` write; `sweepFiles` lists stored hashes inside its own write, so a concurrent placement cannot be swept; freed files are deleted after commit. Three uploads of the same bytes gave three rows and one file. | read, yes |

## Findings

### L1 (low): a forged `expected` larger than the real count skips the second confirmation, and the count is read outside the write

`src/Core/App/TrashOperations.php:162` and `:167`. `empty()` re-asks only when more records wait than `expected`; it does not compare the file count, and it counts before the snapshot and the delete, which are separate steps. So (a) an Admin who posts a large `expected` (their own valid CSRF token is needed) skips the page that states the counts; (b) another person who trashes a record while the `empty-trash` snapshot is being taken has that record deleted without it being in the confirmation and without it being in the snapshot, so it cannot be recovered from that snapshot. Case (a) is by design (P82 pattern) and needs an Admin acting on purpose; case (b) is a narrow race. Suggested later hardening: count again inside the `Trash::empty` write and refuse when the count differs from `expected` (equal, not only "not more"), and take the snapshot inside the same lock or compare after it. Confirmed by running for (a) (test `testOnlyAnAdminEmptiesTheTrashAndAMissingOrSmallerExpectedAsksAgain`, marked intended); (b) by reading only.

### L2 (low): an empty-trash POST with nothing to delete still writes `trash.emptied`

`src/Core/App/TrashOperations.php:174`. The audit entry is written even when `$waiting` is 0, with `records: 0, files: 0`. Repeated POSTs by an Admin (each with a valid token) add one row each; the entry is harmless but is noise in a log meant to record deletions. Suggested: audit only when something was deleted, or keep it and document it. Confirmed by running (two rows for one real emptying and one empty POST).

### L3 (low): a long file name keeps its start and loses its extension

`src/Core/Query/Attachments.php:319`. A 3,000-character name is cut to its first 255 code points, so `aaaa....html` is stored and sent as `aaaa...a` with no extension. Not exploitable (every non-image is sent as `application/octet-stream` with `attachment`), but the user's download loses its type hint. Suggested: keep the last extension when cutting. Confirmed by running.

## Notes

- N1. `Cache-Control` for files is `no-store`, stricter than the plan's `private`; this is the spec's decision (P150) and is listed for the maintainer in M4-core section 9.
- N2. A collection whose table is missing (only possible in a file another tool edited) cannot be opened or deleted from the screens (404); it is hidden from the sidebar, so no page breaks. Not a security matter.
- N3. Matrix row "direct request to `attachments/`" cannot be confirmed in this container; it rests on the installer probe and the host check as the plan says.
