# ADR 0017: Project archive format and import rules, and the JSON export format

Status: Accepted (provisional, awaiting maintainer confirmation). Settles the project archive of plan section 6.3 ("zip of the project folder; the native backup and transfer path"), context brief section 12 items 17 (WAL sidecars and the zip archive), 22 (JSON export schema), and 23 (how ids, grants, and conflicts are handled on archive import), and the reviewer-flagged rule that an imported edited form must not block record creation. Formats: `docs/formats/tiffin.md` section 13 (archive) and `docs/formats/json-export.md`. Implementation design: `docs/specs/M3-io.md` sections 10 and 11.

## Context

The archive is the first feature that opens a database file TiffinDB did not write. A hostile SQLite file can carry triggers, views, generated columns, check constraints, and schema text that runs when queried; a hostile zip can carry traversal names, links, and decompression bombs. Stored forms in such a file were written elsewhere (ADR 0014 decision 4). Plan section 6.3 also requires a JSON export of the full project, documented in `docs/formats/`, and the portability rule of `CLAUDE.md` requires that it be usable without TiffinDB.

## Decisions

### 1. Archive contents

A zip file holding exactly: `tiffindb-archive.json` (a manifest: format name, archive version 1, creation time, TiffinDB version, project format version, project name, SHA-256 and size of the database, attachment count), `project.tiffin` (a consistent copy made with `VACUUM INTO`, never the live file, so no `-wal` or `-shm` file is involved), and `attachments/<2 hex>/<64 hex>` for every attachment the database lists and the folder holds. Snapshots are not included. The archive is a core feature, not a plugin: it is the native path and must not depend on plugin state.

### 2. Archive import creates a new project

- Always a new project with a new project key, the archive's project name (or one the Admin types, when it is taken), and no grants. Nothing in an existing project is touched. Admin only (`project.create`).
- Table, field, relation, form, and attachment keys and record ids are kept, so links, forms, and attachment references stay valid and a JSON export of the imported project compares with one of the original.
- Person keys in the file (`_created_by` and so on) belong to the other installation; they are kept as data and show as unknown people.
- The source file's import log is not carried over; the new project's log gets one row for the archive import.

### 3. Archive import: verify, then rebuild (security-critical)

1. **Zip.** Entry names must match the allowlist above exactly (no other entry, no directory entries other than prefixes, no duplicates); no encrypted entries; compression stored or deflated only; caps on entry count and declared and actual sizes, checked by counting bytes while streaming each entry to a name the application chooses (never `extractTo`).
2. **Manifest.** Valid JSON with the documented members; the database's SHA-256 and size match.
3. **SQLite, read-only and distrusted.** The extracted copy is opened read-only with `trusted_schema` off and `query_only` on, after its header is checked (and the WAL flag in the header cleared on the copy). `integrity_check` must return `ok`; `application_id`, `user_version` 1 to 3, and the meta format must match. Any trigger, view, or virtual table refuses the archive; any table or index whose name is not a documented pattern refuses it; user and join tables must have exactly the documented columns, none hidden or generated.
4. **Rebuild.** The archive's file is never adopted as a project file. TiffinDB creates a fresh project file at the current format version and copies into it, after validating each value: metadata rows (keys, names, types, options through each type's own normalisation, relation consistency), then every record value through its field type's storage check (values that fail are emptied and counted), links whose both records exist and respect the cardinality, attachment rows, and forms. Only values cross from the archive; every SQL statement and every schema object is TiffinDB's own.
5. **Forms.** Every form is parsed with the M2 default-deny parser; a rejected form is dropped. Then every table's forms are regenerated with the M2 rules (ADR 0014 decision 3): generated forms are rewritten, and an edited form **gains a slot for each field it does not name**. This settles the reviewer-flagged case: an edited form that omits a required field gets that field appended, so the record form shows it.
6. **Attachments.** Each file's SHA-256 and size must equal its name and its row; files not listed are skipped; listed files that are missing are reported.

The report page lists everything emptied, dropped, or missing.

### 4. Required fields and forms (defence in depth)

A record is created from the fields the effective form shows. When a stored form does not name a required field (possible only for a file edited outside TiffinDB), creating a record through the form does not require that field: `RecordOperations::create` passes the bound form's editable keys as the required set. Imports (file to table) enforce no requiredness at all; required values left empty are counted in the report. Updates were already partial (ADR 0013 decision 7).

### 5. JSON export format 1

One UTF-8 JSON document per project: format name and version, export time, TiffinDB version, project facts, and for each table its fields (key, name, type, options, required, help) and its live records (id, times, person keys, values by field key). Values use one documented JSON form per field type; currency is a decimal string so no amount passes through floating point; relation and attachment values are arrays of record ids and attachment ids in link order. Relations and attachment descriptions are listed once at the top level. Records in the trash and links to them are left out (the archive is the complete backup). Written as a stream; documented in `docs/formats/json-export.md` for use without TiffinDB.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Archive | Zip the live project folder | Copies WAL sidecars and snapshots; inconsistent while in use (context brief 12.17) |
| Archive | Include snapshots | Multiplies the size; snapshots are local history |
| Import | Adopt the archive's file after checks | Safety would rest on the checks being complete; any missed object (a `CHECK` with a function, a collation, a generated column) runs inside TiffinDB later |
| Import | Merge into an existing project | Key and id conflicts; partial merges are hard to undo; not needed for backup and transfer |
| Import | New keys for everything | Breaks forms and links for no gain; keys are random and never SQL names on their own |
| Forms | Keep imported edited forms unchanged | A form without a required field would block record creation (reviewer finding) |
| JSON | One file per table | A project is one unit with relations between tables |
| JSON | Currency as a number | Floating point; a string keeps the exact amount |

## Consequences

- An archive import of a large project runs in one request; its time is measured by M3-WP11 and on the reference host by the maintainer.
- A file edited outside TiffinDB in ways the format does not allow cannot be imported; the refusal names the first reason.

## Costly to reverse

The archive layout and manifest, and the JSON format, once people hold archives and exports. Both carry a version number.
