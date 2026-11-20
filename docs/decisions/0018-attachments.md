# ADR 0018: Attachments: types, limits, storage, serving, and removal

Status: Accepted (provisional, awaiting maintainer confirmation). Settles the open attachment points of ADR 0004 ("upload type and size limits, MIME checks, and storage quotas need handling"), plan section 7 (access-checked endpoint, safe content types, `Content-Disposition`), context brief section 12 item 17 (hash, limits, quotas, orphan cleanup), and the M4 session plan section 3 questions 1 to 3, which the maintainer has not answered (their defaults are applied). Storage format: `docs/formats/tiffin.md` section 8 (defined in format version 1; no format change). Implementation design: `docs/specs/M4-core.md` sections 3, 4, and 10, and `docs/specs/M4-screens.md`.

## Context

Attachments are the first feature that stores files people upload and sends them back to browsers in the application's own origin. A stored HTML or SVG file served inline would run script with the signed-in person's session (stored cross-site scripting). The storage layout is already fixed by format version 1 (`attachments/<2>/<64>`, `_tiffin_attachments`, `_tiffin_attachment_links`). Shared hosts differ in their PHP extensions (`fileinfo` is not guaranteed) and their upload limits. Household files are ordinary documents: receipts, manuals, photos.

## Decisions

### 1. Accepted types (session plan section 3 row 1, default)

Any file type may be attached. Only four image types are ever shown inside a TiffinDB page: PNG, JPEG, GIF, and WebP. Everything else, PDF and SVG included, is offered only as a download. An attachment field whose option `images_only` is true accepts only those four image types.

### 2. Type detection

TiffinDB decides the type from the file's leading bytes with its own check (`Core\Storage\MediaType`), never from the browser-supplied type, the file name's extension, or the `fileinfo` extension:

| Leading bytes | Stored `media_type` | Shown in a page |
|---|---|---|
| `89 50 4E 47 0D 0A 1A 0A` | `image/png` | yes |
| `FF D8 FF` | `image/jpeg` | yes |
| `47 49 46 38 37 61` or `47 49 46 38 39 61` (`GIF87a`, `GIF89a`) | `image/gif` | yes |
| `52 49 46 46`, any 4 bytes, `57 45 42 50` (`RIFF....WEBP`) | `image/webp` | yes |
| `25 50 44 46 2D` (`%PDF-`) | `application/pdf` | no (download only; the type is kept for the "PDF" label) |
| anything else | `application/octet-stream` | no |

The original file name is kept as data only (`original_name`), cleaned (control and format characters removed, `/` and `\` replaced by `_`, trimmed, cut to 255 characters, `file` when empty) and never used as a path.

### 3. Limits (session plan section 3 rows 2 and 3, defaults)

- Per file: the smaller of 64 MiB (the import cap, P116) and the PHP limits `upload_max_filesize` and `post_max_size`. The upload page states the effective limit before the upload.
- An empty (zero-byte) file is refused.
- At most 100 files per attachment field on one record.
- The upload is refused when free disk space would fall below the M3 reserve (100 MiB).
- No per-project quota. The project settings page shows the number and total size of the stored files.
- No thumbnails: images are sent as stored and sized by the stylesheet. Pixel size is not checked. No image is decoded on the server, so no image library or `gd` requirement is added.
- One file per request (P126 unchanged).

### 4. Storage

- The upload is first moved into a staging folder `<data>/staging/uploads/<key>/` (a new staging kind next to the M3 kinds, P127, swept after one hour), on the same file system as the projects, where it is hashed with SHA-256 and its leading bytes checked. The plan proposed a temporary file inside the project folder; the staging area keeps the project folder holding only documented content (format section 1), reuses the existing sweep, and is under the same data directory, so the final move is a rename.
- Inside the project's write transaction (which holds SQLite's write lock): the file is moved to `attachments/<first 2 hex>/<64 hex>` only when no file is there (identical content is stored once), then the `_tiffin_attachments` row and its `_tiffin_attachment_links` row are inserted. A file is never named from user text.
- Each upload gets its own `_tiffin_attachments` row (its own name and time) even when the content is already stored; rows with the same `sha256` share the file.
- An upload counts as a change to the record: the record's `_updated_at` and `_updated_by` and the project meta are updated in the same transaction (as links are, P106).

### 5. Serving (security-critical)

- One route, `GET /projects/{project}/files/{attachment}` (ADR 0012 decision 5), authorized in the core: the actor needs `records.view` on the project, and the attachment must be linked from a live record through an attachment field that still exists. Anything else, including an attachment of another project, one linked only from records in the trash, and a row whose file is missing on disk (also written to the PHP error log), is "not found", indistinguishable from an id that never existed.
- Headers: the four image types are sent with their exact type and `Content-Disposition: inline` (or `attachment` with `?download=1`); every other file is sent as `application/octet-stream` with `Content-Disposition: attachment`, whatever its stored type. Both carry the M3 safe file-name encoding (ASCII fallback plus `filename*`), `Content-Length`, `X-Content-Type-Options: nosniff`, and a response Content-Security-Policy of `default-src 'none'; frame-ancestors 'none'; sandbox` in place of the page policy, so a file opened directly can never run script or load anything. The other security headers stay as for every response, including `Cache-Control: no-store` (the plan's `private` is implied by `no-store`; caching with a validator can be added later without a format change).
- The `attachments/` folder is never reachable directly: the data folder is outside the web root or denied by `.htaccess`, and the installer's exposure probe refuses an exposed data folder (M1). On a real Apache server this is confirmed only by the maintainer's host check.

### 6. Removal (order of operations)

- Removing a file from a record removes its link. When no link to that `_tiffin_attachments` row remains, the row is deleted in the same transaction. After the transaction commits, the stored file is deleted if no row names its hash any more; that check and the deletion run inside a write transaction, so they cannot interleave with an upload of the same content (which places its file inside its own write).
- Emptying the trash removes the attachment links of the deleted records (as built in M1), deletes the rows nothing links to any more, and then the files as above. Deleting an attachment field or a table (whose links go by `ON DELETE CASCADE` or the editor's own delete) is followed by the same clean-up.
- A full sweep (rows with no link, then files under `attachments/<2>/` whose hash no row names) runs after emptying the trash and after deleting an attachment field or a table. A crash between commit and file deletion therefore leaves only an orphan file, never a row without its file; the next sweep removes the orphan.
- Removing or replacing a file cannot be undone (snapshots do not hold files), so both go through a confirmation page. "Replace" is one upload that adds the new file and removes the old link in one transaction.
- A form's static image (`img src="attachment:<id>"`, form format 1) does not keep a row alive; until the form editor (M5) can create such images, only an edited project file can hold one. M5 revisits this.

### 7. Required attachment fields

A required attachment field is enforced when an existing record is saved and the record's effective form shows the field: with no file the save is refused at that field. Creating a record and imports never enforce it (closes P105 together with ADR 0019 decision 8; P118 unchanged).

### 8. Audit

`attachment.added` and `attachment.removed` (target the table; detail: field key, record id, attachment id, size; never the file name or content) are added to the audit actions (amends ADR 0012 decision 4). `trash.emptied` gains the number of files removed.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Types | Allowlist of file types | Blocks ordinary household files; serving rules make any type safe to store |
| Detection | `fileinfo` / `mime_content_type` | Not guaranteed on shared hosts; its magic database differs between hosts |
| Detection | Trust the browser's type or the extension | Attacker-controlled |
| Serving | Serve PDF inline | Browser PDF viewers run in the origin and have had script bugs; download is safe and enough |
| Serving | SVG inline as an image | SVG can carry script when opened directly |
| Serving | Separate download domain | Needs a second host name; not possible on the reference shared host by upload only |
| Storage | Temporary file inside the project folder | Undocumented content in the project folder; a second sweep; the staging area already exists |
| Storage | One row per distinct content | Loses each upload's own name and time |
| Thumbnails | Generate with `gd` | New installer requirement and an image-decoding attack surface |
| Quota | Per-project quota | Needs a counter and new failure states; adding it later is cheap |
| Removal | Delete file in the same transaction as the row | A rollback after the file is gone would leave a row without a file |

## Consequences

- Large images are sent at full size; pages with many photos load slowly on slow links. Thumbnails can be added later as derived files without a format change.
- A PDF or a document cannot be previewed in the browser from TiffinDB; people open the downloaded file.
- Orphan files can exist for a while after a crash; they cost disk space only.

## Costly to reverse

The serving URL (bookmarkable) and the stored `media_type` values (they are in project files and exports). The removal order is cheap to change.
