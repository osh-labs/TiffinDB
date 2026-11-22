# M4 security review: WP1 (attachment store) and WP2 (attachment links, link methods, trash)

Design-tier security review (orchestration section 2, rule 4) of the merged code at `135d3c9` on the M4 integration branch: merge `7817cf5` (WP1) and merge `135d3c9` (WP2). Checked against `docs/specs/M4-core.md` sections 3 and 4, ADR 0018, and `docs/build/M4-session-plan.md` sections 5.2, 5.3 and 8. The reviewer did not write the code. Each property was checked by reading the code and by running hostile cases (`tests/core/query/AttachmentHostileTest.php`, plus probes kept out of the suite that showed the gaps listed below). No data in this review or its tests is real.

Result: **no blocking or high findings.** WP1 and WP2 may stay merged. Five low findings and two hand-over notes for WP4 follow.

## Scope

- `src/Core/Storage`: `AttachmentStore`, `MediaType`, `StoredUpload`, `Fs::sha256`, `Fs::freeBytes`, and the `uploads` kind in `src/Core/Io/StagingArea.php`.
- `src/Core/Query`: `Attachments`, the `Records` link methods (`addLink`, `removeLink`, `applyLinks`, `requiredLinkProblems`, `linkDetails`), `Trash` (`impact`, `empty`, `restoreAll`), and `TrashImpact`.
- Not in scope, because WP4 to WP6 are not merged yet: permission checks (`Core\App`), the upload size cap and the zero-byte refusal, the serving route and its headers.

## Properties checked

| # | Property | Result | Run |
|---|---|---|---|
| 1 | Files never named from user text; no traversal through name, hash, key or extension | Holds. Every path comes from a hash checked against `^[0-9a-f]{64}\z` (also the `StoredUpload` constructor). Upper case, 63 characters, a trailing newline, `../`, `/` and NUL are refused. The original name is only stored as data. | yes |
| 1 | Symbolic links and swaps | Holds for links: a linked `attachments/` folder or `<2>` folder is refused on place, ignored by `path()`, and never followed by delete or sweep. A link at the staged path is refused by `inspect`, and a swap to a link after `inspect` is refused by `place`. A same-size content swap is not caught (L2). | yes |
| 1 | Temporary file placement | Holds. Staging is `<data>/staging/uploads/<key>/upload.bin`, outside the project folder, and the final move is a rename. | read |
| 2 | Type from bytes only | Holds. `MediaType` reads 16 bytes, compares exactly, and takes no browser type or extension. HTML, SVG with script, an HTML file with a BOM, an empty file, a lower-case `gif89a`, and an 11-byte RIFF header all give `application/octet-stream`. A GIF or PNG signature followed by HTML is typed as an image (L5). | yes |
| 2 | Hostile names | Holds. Invalid UTF-8 gives `file`. CR/LF, ESC, NUL, U+202E, U+200B, U+FEFF and U+2028 are removed; `/` and `\` become `_`; a 5000-character name is cut to 255 code points and stays valid UTF-8; empty and blank names give `file`. | yes |
| 2 | Zero-byte file, 64 MiB cap | Not enforced by the core layers (H1). Both pass `inspect` and `Attachments::add`. A 64 MiB + 1 byte file hashes by streaming. | yes |
| 3 | File before row on add; failed upload leaves no row | Holds. If the link insert fails after the file is placed, there is no row and no link, and the orphan file is removed by `sweep`. | yes |
| 3 | Row before file on removal | Holds. The row is deleted in the write. The file is deleted later in `deleteFiles`, in its own write, and only when no row has the hash. | yes |
| 3 | Replacing an orphan | Holds. The orphan is replaced only when it is a regular file of a different size and no row has the hash. A same-size file is trusted (L1). | yes |
| 3 | Sweep never removes a needed file | Holds for well-formed rows: files of trashed records and files shared by rows are kept. A row whose hash is in another letter case does not protect its file (L3). | yes |
| 3 | Two links to one file | Holds. Removing one link keeps the row and the file. After the trash is emptied, the freed hash is still kept while another row has it. | yes |
| 4 | Live record, field type, cross-record ids | Holds. Add and remove on a trashed record, a `replaceId` or a remove through another record, and a relation field used as an attachment field are all `NotFound`. `isServable` is false when the only link is from a trashed record. `addLink` to a trashed target or an absent target is `target_missing`; `addLink` from a trashed record is `NotFound`. | yes |
| 4 | Permission, project | No permission checks in these layers (they take no actor, as the spec says); see H2. Cross-project ids cannot be reached because each project is its own database. | read |
| 5 | Emptying the trash removes only unreferenced files | Holds when every attachment field is passed (as `TrashOperations::empty` does). Passing fewer fields leaves links to deleted records (L4). | yes |
| 6 | SQL identifiers and values | Holds. Names come only from `Identifier` and the definition objects (`TableDef::sql`, `FieldDef::sql`, the relation sides), and every value is bound. `SqlStringsTest` passes. | yes |
| 7 | Leaks in errors or logs | Holds. Exception messages and `error_log` lines contain no path, hash, or name (`ErrorTrap` keeps the PHP warning only as the previous exception). The stray-entry log line gives a count only. | yes |

## Findings

### L1 (low): a same-size file at the target is trusted as the content

`src/Core/Storage/AttachmentStore.php:98`. When a regular file of the upload's size is already at `attachments/<2>/<hash>`, `place` returns false and the new row names that file. The bytes are not compared. A probe put a same-size file of `X` bytes at the target, then uploaded the real content: the row was added and the stored bytes stayed `XXXX...`. This is what the spec says (section 3.2), and getting there needs write access to the data folder or disk corruption. A crash during a copy leaves a shorter file, which step 4 does handle. Suggested hardening (later, cheap): when `place` returns false, compare `Fs::sha256($target)` with the hash, and treat a mismatch like the different-size orphan case.

### L2 (low): `place` does not check the hash or type again; `StoredUpload` can be built by anyone

`src/Core/Storage/StoredUpload.php:19`, `src/Core/Storage/AttachmentStore.php:86`. The class comment says a `StoredUpload` is "made only by inspect", but its constructor is public. `place` checks only that the staged path is a regular file of the inspected size, then renames it. Two probes showed the effect:

- a staged file replaced with same-size HTML between `inspect` and `add` was stored under the PNG's hash and recorded as `image/png`;
- a hand-built `StoredUpload` naming an arbitrary file, with a made-up hash and `image/png`, was moved into the store.

Neither can be reached from a request: the staging folder holds only what the application writes, and only `Core\App` builds uploads. They are defence-in-depth gaps against a future caller misusing the API. Suggested hardening: give `StoredUpload` a private constructor with a factory used only by `inspect`; or, in `place`, open the staged file once, check its inode against the `inspect` stat, and hash it again.

### L3 (low): rows naming a hash in another letter case do not protect the file from the sweep

`src/Core/Query/Attachments.php:288`. `sweepFiles` matches row hashes to stored names exactly. A row with an upper-case `sha256` (possible only in a hand-edited project file; `ProjectRebuilder` drops such rows when an archive is imported) does not count as naming the lower-case file, so the sweep deleted that file in a probe. Such a row could not be served anyway (`AttachmentStore::path` refuses the hash), so no bytes are exposed. The effect is that the file stops being recoverable by hand. Suggested hardening: compare in lower case, or report rows with malformed hashes during the sweep instead of passing over them.

### L4 (low): `Trash::empty` with an incomplete list of attachment fields leaves links to deleted records

`src/Core/Query/Trash.php:235` and `:309`. Links are deleted only for the fields the caller passes. In a probe, emptying with no fields deleted the record but kept its link, so the attachment row and its file can never be swept. Record ids are not reused (`AUTOINCREMENT`), so the link does not attach the file to a new record. `TrashOperations::empty` passes every attachment field of each table, so the current caller is safe. Suggested hardening: have `emptyTable` delete every `_tiffin_attachment_links` row of the table's trashed records whatever the field (or check that the list is complete).

### L5 (low): polyglot files are typed as images

`src/Core/Storage/MediaType.php:43`. `GIF89a<html><script>...` gives `image/gif`, and a PNG signature followed by HTML gives `image/png`. Both will be served inline with that image type. This is what the spec intends (T-STO-9). It is safe only because of `X-Content-Type-Options: nosniff` and the `sandbox` response CSP, so the WP5 and WP6 serving headers must be checked with this case at M4-X.

## Hand-over notes for WP4 (not defects in WP1 or WP2)

- **H1.** `Attachments::add` and `AttachmentStore::inspect` accept a zero-byte file and a file over 64 MiB (both confirmed by running). Under spec section 10.2, `AttachmentOperations::add` must refuse both from `Fs::size` before hashing (`problem.upload.empty`, `problem.upload.too_large`). The exit audit should test both through the operation and the route.
- **H2.** `Attachments::add` and `remove` take a field key and a record id, but no table key: the field decides the table. `AttachmentOperations` must check that the field belongs to the table named in the URL, and must check the permission (`records.edit`; `records.view` for `serve` and `file`) before calling them. The same applies to `Records::addLink` and `removeLink`. `Records::linkDetails` does not filter out trashed records, so its callers should pass only ids they are allowed to show.

## Tests added

`tests/core/query/AttachmentHostileTest.php` (11 tests, all passing): the name corpus, type detection of hostile heads, refused hashes, a linked `<2>` folder, a linked `attachments/` folder, a linked staged file and a swap to a link after `inspect`, a failed upload with no row and a swept orphan, cross-record and trashed-record refusals for attachments and links, two links to one file through emptying the trash, and the sweep keeping files of trashed records. The L1 to L4 and H1 probes are not added, because they record current behaviour that the spec allows rather than a regression to guard.
