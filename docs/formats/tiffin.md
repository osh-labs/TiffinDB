# TiffinDB project file format, version 3

Status: format version 3, written by TiffinDB from milestone M3 (version 1 was written in M1 and version 2 in M2; section 11 says how an older file is upgraded). Everything marked "version 3" is absent from version 2 files; the rest describes both. Section 13 describes the project archive (a zip file for backup and transfer). Provisional until the maintainer confirms ADR 0010, ADR 0014, ADR 0015, and ADR 0017. This document is meant to let anyone read, check, or rescue a TiffinDB project with ordinary SQLite tools and no TiffinDB code.

Every example in this document is synthetic.

## 1. What a project is

A project is a folder. Inside the TiffinDB data directory it lives at `projects/<project key>/`:

```
projects/
  q7mzk2wbx4ae/                         project key (section 3)
    project.tiffin                      the database: a SQLite 3 file
    project.tiffin-wal                  present only while the project is in use (SQLite WAL mode)
    project.tiffin-shm                  present only while the project is in use
    attachments/
      9f/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08
    snapshots/
      20261112T031500Z-field-delete-k3m7.tiffin
```

- `project.tiffin` is a standard SQLite 3 database. Open it with any SQLite tool (for example `sqlite3 project.tiffin`). Open it read-only, or work on a copy, unless you know TiffinDB is not running.
- `attachments/` holds files added to records, named by the SHA-256 of their content (section 8).
- `snapshots/` holds complete, self-contained copies of `project.tiffin` taken before risky changes (section 10).
- Users, passwords, sharing, and the audit log are **not** in the project folder. They are in the installation's system database. A project folder can be copied, zipped, or handed to someone else without carrying anyone's account.

## 2. Identifying the file

| Check | Value |
|---|---|
| `PRAGMA application_id` | `1413891138` (hexadecimal `0x54464442`, the ASCII bytes `TFDB`) |
| `PRAGMA user_version` | The format version: `3` for this document (`2` in files written by M2) |
| `SELECT value FROM _tiffin_meta WHERE key = 'format'` | `tiffindb-project` |
| `SELECT value FROM _tiffin_meta WHERE key = 'format_version'` | `3` (`2` in files written by M2) |

TiffinDB refuses to open a file whose `application_id` or `format` differs, or whose version is higher than it understands. It upgrades a file with a lower version in place, after saving a snapshot (section 11).

## 3. Keys and names

Every project, table, field, relation, form, and person has a **key**: 12 characters from `abcdefghijklmnopqrstuvwxyz234567`, for example `q7mzk2wbx4ae`. Keys are random, never change, and are never reused. The names people see ("Items", "Purchased") are stored separately and can change at any time without touching the database structure.

SQL names are built from keys only:

| Object | SQL name | Example |
|---|---|---|
| User table | `t_` + table key | `t_h3kq7vn2xbma` |
| Column for a field | `f_` + field key | `f_m4pz7k2qsw3n` |
| Join table for a relation | `j_` + relation key | `j_c2vb6nxq4tkm` |
| Index | `x_` + SQL name of the indexed object + `_` + purpose | `x_t_h3kq7vn2xbma_trash` |

Index purposes: `trash` (the trash column of a user table), `u` (unique, on an auto-number column), `r` (the `right_id` column of a join table), `l1` and `r1` (unique, on a join table side limited to one link).

Names starting with `_tiffin_`, `t_`, `f_`, `j_`, `x_`, or `sqlite_` are reserved. Do not create your own tables with these prefixes in a project file.

## 4. Metadata tables

All metadata lives in tables whose names start with `_tiffin_`. Exact definitions of the tables version 1 introduced (unchanged in version 2):

```sql
CREATE TABLE _tiffin_meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
) WITHOUT ROWID;

CREATE TABLE _tiffin_tables (
    key         TEXT PRIMARY KEY,
    sql_name    TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    item_name   TEXT NOT NULL,
    position    INTEGER NOT NULL,
    title_field TEXT,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
) WITHOUT ROWID;
CREATE UNIQUE INDEX _tiffin_tables_name ON _tiffin_tables (name COLLATE NOCASE);

CREATE TABLE _tiffin_fields (
    key        TEXT PRIMARY KEY,
    table_key  TEXT NOT NULL REFERENCES _tiffin_tables (key) ON DELETE CASCADE,
    sql_name   TEXT UNIQUE,
    name       TEXT NOT NULL,
    type       TEXT NOT NULL,
    options    TEXT NOT NULL DEFAULT '{}',
    required   INTEGER NOT NULL DEFAULT 0,
    help       TEXT NOT NULL DEFAULT '',
    position   INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) WITHOUT ROWID;
CREATE UNIQUE INDEX _tiffin_fields_name ON _tiffin_fields (table_key, name COLLATE NOCASE);
CREATE INDEX _tiffin_fields_order ON _tiffin_fields (table_key, position);

CREATE TABLE _tiffin_relations (
    key         TEXT PRIMARY KEY,
    join_table  TEXT NOT NULL UNIQUE,
    left_table  TEXT NOT NULL REFERENCES _tiffin_tables (key),
    left_field  TEXT NOT NULL UNIQUE REFERENCES _tiffin_fields (key),
    left_max    TEXT NOT NULL,
    right_table TEXT NOT NULL REFERENCES _tiffin_tables (key),
    right_field TEXT NOT NULL UNIQUE REFERENCES _tiffin_fields (key),
    right_max   TEXT NOT NULL,
    created_at  TEXT NOT NULL
) WITHOUT ROWID;

CREATE TABLE _tiffin_attachments (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    sha256        TEXT NOT NULL,
    size          INTEGER NOT NULL,
    media_type    TEXT NOT NULL,
    original_name TEXT NOT NULL,
    created_at    TEXT NOT NULL,
    created_by    TEXT
);
CREATE INDEX _tiffin_attachments_sha256 ON _tiffin_attachments (sha256);

CREATE TABLE _tiffin_attachment_links (
    field_key     TEXT NOT NULL REFERENCES _tiffin_fields (key) ON DELETE CASCADE,
    record_id     INTEGER NOT NULL,
    attachment_id INTEGER NOT NULL REFERENCES _tiffin_attachments (id),
    position      INTEGER NOT NULL,
    PRIMARY KEY (field_key, record_id, attachment_id)
) WITHOUT ROWID;
```

Version 2 adds one table and its two indexes:

```sql
CREATE TABLE _tiffin_forms (
    key        TEXT PRIMARY KEY,
    table_key  TEXT NOT NULL REFERENCES _tiffin_tables (key) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    format     INTEGER NOT NULL,
    html       TEXT NOT NULL,
    css        TEXT NOT NULL DEFAULT '',
    is_default INTEGER NOT NULL DEFAULT 0,
    generated  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) WITHOUT ROWID;
CREATE INDEX _tiffin_forms_table ON _tiffin_forms (table_key);
CREATE UNIQUE INDEX _tiffin_forms_default ON _tiffin_forms (table_key) WHERE is_default = 1;
```

Version 3 adds one table and its index:

```sql
CREATE TABLE _tiffin_import_log (
    key            TEXT PRIMARY KEY,
    imported_at    TEXT NOT NULL,
    imported_by    TEXT,
    source         TEXT NOT NULL,
    plugin         TEXT,
    file_name      TEXT NOT NULL,
    file_size      INTEGER NOT NULL,
    file_sha256    TEXT NOT NULL,
    table_key      TEXT,
    new_table      INTEGER NOT NULL DEFAULT 0,
    rows_read      INTEGER NOT NULL,
    rows_imported  INTEGER NOT NULL,
    rows_skipped   INTEGER NOT NULL,
    values_emptied INTEGER NOT NULL,
    links_added    INTEGER NOT NULL DEFAULT 0,
    snapshot       TEXT,
    report         TEXT NOT NULL DEFAULT '{}'
) WITHOUT ROWID;
CREATE INDEX _tiffin_import_log_at ON _tiffin_import_log (imported_at);
```

The connection that TiffinDB uses turns on `PRAGMA foreign_keys`. A tool that does not will not enforce the `REFERENCES` clauses; that only matters when writing.

### 4.1 `_tiffin_meta`

| Key | Value |
|---|---|
| `format` | `tiffindb-project` |
| `format_version` | `3` (`2` in files written by M2) |
| `project_key` | The project's key; equals the folder name |
| `project_name` | The project name shown in TiffinDB, for example `Home Inventory` |
| `created_at` | When the project was created (time format in section 5) |
| `created_by` | Key of the person who created it |
| `created_with` | TiffinDB version that created the file, for example `0.2.0-dev` |
| `updated_at` | When anything in the project last changed |
| `updated_by` | Key of the person who made that change |

Person keys refer to accounts in the installation's system database; a project file on its own does not say who they are.

A project made by importing a project archive (section 13) gets a new `project_key` and the name chosen at import; `created_at`, `created_by`, and `created_with` keep the archive's values, and `updated_at` and `updated_by` record the import. Its person keys belong to the installation the archive came from.

### 4.2 `_tiffin_tables`

One row per user table. `name` is the plural display name ("Items"); `item_name` is what one record is called ("item"), used on buttons such as "Add item". `position` orders tables in the interface, from 1. `title_field` is the key of the field shown as each record's title, or null.

### 4.3 `_tiffin_fields`

One row per field. `sql_name` is the column name in the user table, or null for the two field types that have no column (relation and attachment). `type` is one of the identifiers in section 6. `options` is a JSON object whose members depend on the type (section 6). `required` is 0 or 1. `help` is the hint shown under the field, possibly empty. `position` orders fields within a table, from 1.

### 4.4 `_tiffin_relations`

One row per relation between two tables (section 7).

### 4.5 `_tiffin_attachments` and `_tiffin_attachment_links`

Files and which record field they belong to (section 8).

### 4.6 `_tiffin_forms` (version 2)

One row per stored form: the layout used to show and edit one table's records (section 12).

| Column | Meaning |
|---|---|
| `key` | The form's key. Forms share the key scope of tables, fields, and relations in the project |
| `table_key` | The table whose records the form shows. Deleting the table deletes its forms |
| `name` | The form's name, for example `Standard form` |
| `format` | The form format version of `html` (section 12): `1` |
| `html` | The form's layout, in the form format of section 12 |
| `css` | Reserved for a form's own styling. Always the empty string in files written by milestone M2; TiffinDB ignores any other value until a later version documents it |
| `is_default` | 1 for the form TiffinDB uses for the table; at most one per table (index `_tiffin_forms_default`) |
| `generated` | 1 while the form is exactly what TiffinDB generates from the table's fields; 0 once a person has edited it (a later milestone) |
| `created_at`, `updated_at` | UTC times (section 5) |

A table may have no row here, for example in a file just upgraded from version 1. TiffinDB then lays the table's records out as it would generate them (section 12.3), and stores that form the next time the table's fields change.

### 4.7 `_tiffin_import_log` (version 3)

One row per completed import into the project. An import that fails or is abandoned leaves no row (it changes nothing in the project).

| Column | Meaning |
|---|---|
| `key` | The import's key (12 characters from the key alphabet, section 3); unique within this table. Not used in SQL names |
| `imported_at`, `imported_by` | When, and the key of the person who imported (null when unknown) |
| `source` | `file` (a CSV, XLSX, or other file read by an import plugin into a table) or `archive` (the project itself was made from a project archive, section 13) |
| `plugin` | The import plugin as `<kind>/<id>`, for example `import/csv`; null for `archive` |
| `file_name` | The file's name as it was uploaded, for example `inventory.csv`. Shown only; never used as a path |
| `file_size`, `file_sha256` | Size in bytes and SHA-256 (lower-case hexadecimal) of the uploaded file |
| `table_key` | The table the rows went into; null for `archive`. Not a foreign key: the table may be deleted later |
| `new_table` | 1 when the import created that table, else 0 |
| `rows_read` | Data rows read from the file (heading row not counted) |
| `rows_imported` | Records added |
| `rows_skipped` | Rows left out (empty rows, rows over the row limit, rows with an error the import cannot recover from) |
| `values_emptied` | Values that could not be read as the field's type and were left empty |
| `links_added` | Records added to other tables because a linked title was not found there |
| `snapshot` | File name of the `import` snapshot taken just before (section 10), or null |
| `report` | A JSON object with the per-column details the report screen shows: counts and row numbers, never cell values (section 4.7.1) |

#### 4.7.1 The `report` object

```json
{"columns":[{"name":"Purchased","field":"f3kq7vn2xbma","emptied":3,"rows":[4,17,30]}],
 "skipped":{"empty":2,"over_limit":0},
 "links":[{"field":"r5vk3pn7qx2m","table":"p6wz2kq7nm3v","added":2}],
 "required_empty":[{"field":"m4pz7k2qsw3n","count":1}],
 "archive":{"values_emptied":0,"links_dropped":0,"forms_dropped":0,"forms_extended":1,"files_missing":0}}
```

`rows` holds at most the first 20 row numbers (1 is the first data row). Members are omitted when empty; `archive` appears only when `source` is `archive`. Readers must ignore members they do not know.

### 4.8 Reserved for later versions

`_tiffin_collections` is named in the plan and will be added in a later format version. Version 3 files do not contain it.

## 5. User tables

Each table a person creates is one SQLite table named `t_<table key>`:

```sql
CREATE TABLE "t_h3kq7vn2xbma" (
    "id"          INTEGER PRIMARY KEY AUTOINCREMENT,
    "_created_at" TEXT NOT NULL,
    "_created_by" TEXT,
    "_updated_at" TEXT NOT NULL,
    "_updated_by" TEXT,
    "_trashed_at" TEXT,
    "_trashed_by" TEXT
);
CREATE INDEX "x_t_h3kq7vn2xbma_trash" ON "t_h3kq7vn2xbma" ("_trashed_at");
```

followed by one column per field, added with `ALTER TABLE ... ADD COLUMN "f_<field key>" <type>` (section 6). Column order in the SQLite table has no meaning; use `_tiffin_fields.position`.

| Column | Meaning |
|---|---|
| `id` | Record number. Assigned by SQLite, never reused |
| `_created_at`, `_updated_at` | UTC times, `YYYY-MM-DDTHH:MM:SSZ`, for example `2026-11-12T03:15:00Z` |
| `_created_by`, `_updated_by` | Key of the person, or null when unknown |
| `_trashed_at`, `_trashed_by` | Null for a live record. Set when the record is moved to the trash |

**Trash.** A record whose `_trashed_at` is not null is in the trash. To see only live records, add `WHERE "_trashed_at" IS NULL`. Emptying the trash deletes those rows.

## 6. Field types and stored values

| `type` | Column definition | Stored value | `options` members (defaults) |
|---|---|---|---|
| `text` | `TEXT` | One line of text | `max_length` (500) |
| `long_text` | `TEXT` | Text; line breaks stored as `\n` | `max_length` (20000) |
| `number` | `NUMERIC` | Integer or real number | `decimals` (0), `min` (null), `max` (null) |
| `currency` | `INTEGER` | Amount in **minor units**: with `minor_digits` 2, `32900` means 329.00 | `code` (`"USD"`), `minor_digits` (2) |
| `date` | `TEXT` | `YYYY-MM-DD` | none |
| `time` | `TEXT` | `HH:MM:SS`, 24-hour | `seconds` (false): whether seconds are shown and entered |
| `date_time` | `TEXT` | `YYYY-MM-DDTHH:MM:SS`, local clock time, no time zone | `seconds` (false) |
| `duration` | `INTEGER` | Whole seconds, 0 or more: `5400` is 1 hour 30 minutes | `seconds` (false) |
| `checkbox` | `INTEGER NOT NULL DEFAULT 0` | `1` ticked, `0` not ticked | none |
| `choice` | `TEXT` | The chosen label, exactly as listed in `choices` | `choices`: list of `{"key": <key>, "label": <text>}` |
| `rating` | `INTEGER` | 1 to `max`; null means not rated | `max` (5) |
| `url` | `TEXT` | An `http` or `https` address | none |
| `email` | `TEXT` | An email address | none |
| `phone` | `TEXT` | A phone number as typed, for example `555-0100` | none |
| `auto_number` | `INTEGER`, with a unique index | A number assigned by TiffinDB, never edited by people | `start` (1), `next` (the next number to assign) |
| `relation` | none | Rows in the relation's join table (section 7) | `relation` (relation key), `side` (`"left"` or `"right"`) |
| `attachment` | none | Rows in `_tiffin_attachment_links` (section 8) | `images_only` (false) |

Rules that apply to every type:

- An empty value is stored as SQL `NULL` (except `checkbox`, which is always 0 or 1).
- Text is UTF-8. TiffinDB removes spaces at the start and end of text values and stores no control characters other than line breaks in `long_text`.
- `choice` labels are unique within a field, ignoring the case of ASCII letters. When a label is renamed in TiffinDB, the stored values are rewritten to the new label in the same transaction.
- `currency`: divide the stored integer by 10 to the power `minor_digits` to get the amount. `code` is an ISO 4217 code chosen by the person; TiffinDB does not convert between currencies.
- `number` with `decimals` 0 holds integers; with more decimals it may hold reals. `decimals` controls entry and display, not storage.

Example field rows (synthetic):

| key | table_key | sql_name | name | type | options |
|---|---|---|---|---|---|
| `m4pz7k2qsw3n` | `h3kq7vn2xbma` | `f_m4pz7k2qsw3n` | Name | `text` | `{"max_length":500}` |
| `b7xk2mq4nz3p` | `h3kq7vn2xbma` | `f_b7xk2mq4nz3p` | Value | `currency` | `{"code":"USD","minor_digits":2}` |
| `w2qn6kz7tb4x` | `h3kq7vn2xbma` | `f_w2qn6kz7tb4x` | Category | `choice` | `{"choices":[{"key":"a3kq7vn2xbmz","label":"Appliance"},{"key":"c6zt2mq4wn7k","label":"Tool"}]}` |
| `r5vk3pn7qx2m` | `h3kq7vn2xbma` | null | Room | `relation` | `{"relation":"c2vb6nxq4tkm","side":"left"}` |

## 7. Relations

A relation links records in one table (the left table) to records in another (the right table), or in the same table. Each relation has:

- one row in `_tiffin_relations`;
- two fields of type `relation`, one in each table (`left_field`, `right_field`), so the link can be seen and changed from both sides;
- one join table:

```sql
CREATE TABLE "j_c2vb6nxq4tkm" (
    "left_id"  INTEGER NOT NULL REFERENCES "t_h3kq7vn2xbma" ("id") ON DELETE CASCADE,
    "right_id" INTEGER NOT NULL REFERENCES "t_p6wz2kq7nm3v" ("id") ON DELETE CASCADE,
    "position" INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY ("left_id", "right_id")
) WITHOUT ROWID;
CREATE INDEX "x_j_c2vb6nxq4tkm_r" ON "j_c2vb6nxq4tkm" ("right_id");
```

`left_max` says how many right records one left record may link to (`one` or `many`); `right_max` says the same the other way. When a side is `one`, a unique index enforces it: `x_j_<key>_l1` on `left_id` when `left_max` is `one`, `x_j_<key>_r1` on `right_id` when `right_max` is `one`. For example, "each Item is in one Room, each Room holds many Items" is `left_max = 'one'`, `right_max = 'many'`.

`position` orders the links shown on a record (lowest first; ties by the other record's id).

Example: every item with its room, live records only.

```sql
SELECT i."f_m4pz7k2qsw3n" AS item, r."f_k7n2qz4mxp3w" AS room
FROM "t_h3kq7vn2xbma" AS i
LEFT JOIN "j_c2vb6nxq4tkm" AS j ON j."left_id" = i."id"
LEFT JOIN "t_p6wz2kq7nm3v" AS r ON r."id" = j."right_id" AND r."_trashed_at" IS NULL
WHERE i."_trashed_at" IS NULL;
```

Links to records in the trash stay in the join table, so restoring a record restores its links. Deleting a record (emptying the trash) removes its links through `ON DELETE CASCADE`.

## 8. Attachments

A file added to a record is stored once, under `attachments/<first two characters of its SHA-256>/<all 64 hexadecimal characters>`, with no extension. `_tiffin_attachments` describes it:

| Column | Meaning |
|---|---|
| `id` | Attachment number |
| `sha256` | SHA-256 of the file content, lower-case hexadecimal; also the file name |
| `size` | Size in bytes |
| `media_type` | The media type TiffinDB determined when the file was added, for example `image/png` |
| `original_name` | The file name it had when added, for example `receipt.pdf` |
| `created_at`, `created_by` | When and by whom it was added |

`_tiffin_attachment_links` says which record field shows which file, in what order. `record_id` is the `id` in the user table that owns `field_key`. Two links may point to the same attachment. To get a file back by hand: find its `sha256`, open `attachments/<first two characters>/<sha256>`, and save it under `original_name`.

Version 1 defines the storage; adding and serving files arrives in milestone M4.

## 9. Concurrency and copying

TiffinDB opens the file in WAL mode when the file system allows it. While TiffinDB is using a project, recent changes may sit in `project.tiffin-wal`. Therefore:

- To copy a project safely, either copy it while nobody is using TiffinDB (the WAL file is then normally gone), copy all three files together, or use a snapshot (section 10), which is always self-contained.
- Never copy `project.tiffin` on its own while `project.tiffin-wal` exists and keep using the copy: it may lack the newest changes.

## 10. Snapshots

TiffinDB saves a complete copy of `project.tiffin` before risky changes, using SQLite's `VACUUM INTO`. Snapshots have no WAL file and can be opened on their own.

File name: `<UTC time as YYYYMMDDTHHMMSSZ>-<reason>-<4 random characters from the key alphabet>.tiffin`, for example `20261112T031500Z-field-delete-k3m7.tiffin`.

| Reason | Taken before |
|---|---|
| `format-upgrade` | Upgrading the file to a newer format version |
| `table-delete` | Deleting a table |
| `field-delete` | Deleting a field (including both fields of a relation) |
| `field-type-change` | Changing the type of a field |
| `field-options` | Changing field options in a way that clears or rewrites stored values |
| `empty-trash` | Emptying the trash |
| `import` | Saving the rows of an import into the project (version 3; the snapshot is taken by the application before the import transaction, so older versions of TiffinDB simply never write this reason) |
| `manual` | An Admin asked for one |

The 20 newest snapshots other than `manual` ones are kept; older ones are deleted when a new one is taken. Manual snapshots are kept until removed by hand.

**Restoring by hand.** TiffinDB does not yet restore snapshots itself. With nobody using TiffinDB: rename `project.tiffin` (to keep it), copy the chosen snapshot to `project.tiffin` in the same folder, and delete `project.tiffin-wal` and `project.tiffin-shm` if they exist (they belong to the old file). If the snapshot has an older format version, TiffinDB upgrades it the next time the project is opened.

## 11. Versions and upgrades

- `PRAGMA user_version` and `_tiffin_meta.format_version` always hold the same number.
- Each new format version is described in a new section of this document, with the exact SQL of its upgrade step. Upgrades only go forward. After a `format-upgrade` snapshot, each version step runs in its own transaction, which also sets `PRAGMA user_version` and `format_version` to that step's version, so an interrupted upgrade leaves the file at the last completed version.
- A file with a version older than 1 does not exist. A file with a version newer than the application knows is refused, never opened.
- Version history:

| Version | Introduced in | Changes |
|---|---|---|
| 1 | M1 | First version: metadata, tables, fields, relations, attachments, trash columns |
| 2 | M2 | Adds `_tiffin_forms` (sections 4.6 and 12) |
| 3 | M3 | Adds `_tiffin_import_log` (section 4.7) and the snapshot reason `import` (section 10) |

### 11.1 Upgrade from version 1 to version 2

After the `format-upgrade` snapshot, in one transaction, exactly:

```sql
CREATE TABLE _tiffin_forms (
    key        TEXT PRIMARY KEY,
    table_key  TEXT NOT NULL REFERENCES _tiffin_tables (key) ON DELETE CASCADE,
    name       TEXT NOT NULL,
    format     INTEGER NOT NULL,
    html       TEXT NOT NULL,
    css        TEXT NOT NULL DEFAULT '',
    is_default INTEGER NOT NULL DEFAULT 0,
    generated  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) WITHOUT ROWID;
CREATE INDEX _tiffin_forms_table ON _tiffin_forms (table_key);
CREATE UNIQUE INDEX _tiffin_forms_default ON _tiffin_forms (table_key) WHERE is_default = 1;
PRAGMA user_version = 2;
UPDATE _tiffin_meta SET value = '2' WHERE key = 'format_version';
```

No other table or row changes. In particular, no forms are written during the upgrade: each table has no row in `_tiffin_forms` until its fields next change (section 4.6). The upgrade can be done by hand with the statements above, on a copy, while TiffinDB is not running.

### 11.2 Upgrade from version 2 to version 3

After the `format-upgrade` snapshot, in one transaction, exactly:

```sql
CREATE TABLE _tiffin_import_log (
    key            TEXT PRIMARY KEY,
    imported_at    TEXT NOT NULL,
    imported_by    TEXT,
    source         TEXT NOT NULL,
    plugin         TEXT,
    file_name      TEXT NOT NULL,
    file_size      INTEGER NOT NULL,
    file_sha256    TEXT NOT NULL,
    table_key      TEXT,
    new_table      INTEGER NOT NULL DEFAULT 0,
    rows_read      INTEGER NOT NULL,
    rows_imported  INTEGER NOT NULL,
    rows_skipped   INTEGER NOT NULL,
    values_emptied INTEGER NOT NULL,
    links_added    INTEGER NOT NULL DEFAULT 0,
    snapshot       TEXT,
    report         TEXT NOT NULL DEFAULT '{}'
) WITHOUT ROWID;
CREATE INDEX _tiffin_import_log_at ON _tiffin_import_log (imported_at);
PRAGMA user_version = 3;
UPDATE _tiffin_meta SET value = '3' WHERE key = 'format_version';
```

No other table or row changes. The snapshot reason `import` needs no change to the file. A version 1 file is upgraded by section 11.1 and then this section, each step in its own transaction.

## 12. Forms (version 2)

A form says which fields of a table appear on the screen that shows one record, in what order and in what groups. It does not hold record values: TiffinDB fills those in when it shows a record. The form text is a small, fixed subset of HTML written as well-formed XML, so it can be read in any text editor, and TiffinDB reads it with an XML parser.

### 12.1 Form format 1

`_tiffin_forms.format` is `1`, and the outermost element repeats it. Everything below is the complete vocabulary of format 1; TiffinDB refuses a form that contains anything else (section 12.4).

**Document rules.** One root element. Well-formed XML 1.0 in UTF-8. No `<!DOCTYPE>`, no processing instructions, no CDATA sections. Comments are allowed and ignored. Character references (`&#233;`) and the five XML entities (`&amp;`, `&lt;`, `&gt;`, `&quot;`, `&apos;`) are the only references. White space between block elements has no meaning. At most 262,144 bytes, 5,000 elements, and 8 levels of nesting.

**Elements.**

| Element | Where | Attributes | Content |
|---|---|---|---|
| `div` (root) | Outermost element only | `data-tiffin-form="1"` (required, exactly this) | Blocks |
| `fieldset` | In the root | `data-tiffin-section` (optional): a role word of 1 to 32 characters from `a-z`, `0-9`, `-`, starting with a letter; TiffinDB writes `details`, `files`, and `links`. Placement attributes | An optional `legend` first, then blocks other than `fieldset` |
| `legend` | First child of a `fieldset` | none | Text only, 1 to 100 characters |
| `div` (field) | In the root or a `fieldset` | `data-tiffin-field` (required): a field key of the form's table. `data-tiffin-label` (optional): text shown instead of the field's name, 1 to 60 characters. Placement attributes | Empty |
| `h2`, `h3` | In the root or a `fieldset` | Placement attributes | Inline content |
| `p` | In the root or a `fieldset` | Placement attributes | Inline content |
| `hr` | In the root or a `fieldset` | Placement attributes | Empty |
| `img` | In the root or a `fieldset` | `src` (required): `attachment:` followed by an attachment `id` from `_tiffin_attachments`. `alt` (required): 0 to 200 characters. Placement attributes | Empty |
| `strong`, `em` | Inline content | none | Inline content |
| `br` | Inline content | none | Empty |
| `a` | Inline content | `href` (required): an `http` or `https` address following the rules of the `url` field type (section 6) | Inline content other than `a` |

Inline content is text and the inline elements `strong`, `em`, `br`, and `a`. No other element and no other attribute is allowed anywhere: in particular no `class`, `id`, `style`, event attributes, `script`, `style`, `iframe`, `form`, or `input`.

**Placement attributes** (optional, on any block element) place an element on a grid of 12 columns, for later form editors: `data-tiffin-col` (first column, 1 to 12), `data-tiffin-row` (row, 1 to 50), `data-tiffin-span` (columns covered, 1 to 12, or `full` for the whole width). Milestone M2 uses only `data-tiffin-span="full"`; it shows the other values in reading order without explicit placement.

### 12.2 Example

A generated form for an Items table (synthetic keys):

```html
<div data-tiffin-form="1">
  <fieldset data-tiffin-section="details">
    <legend>Details</legend>
    <div data-tiffin-field="m4pz7k2qsw3n"></div>
    <div data-tiffin-field="w2qn6kz7tb4x"></div>
    <div data-tiffin-field="r5vk3pn7qx2m"></div>
    <div data-tiffin-field="b7xk2mq4nz3p"></div>
    <div data-tiffin-field="t6mq2zk4wn7x" data-tiffin-span="full"></div>
  </fieldset>
  <fieldset data-tiffin-section="files">
    <legend>Files</legend>
    <div data-tiffin-field="p3zk7mq2xn4w"></div>
  </fieldset>
  <fieldset data-tiffin-section="links">
    <legend>Warranties</legend>
    <div data-tiffin-field="k2xq7vn4mz3t"></div>
  </fieldset>
</div>
```

Empty elements other than `hr`, `br`, and `img` are written with an end tag (`<div ...></div>`), so the text also reads correctly as HTML.

### 12.3 Generated forms

TiffinDB generates a table's form from its fields, in field order (`_tiffin_fields.position`):

1. A `fieldset` with role `details` and legend `Details` holding every field except attachment fields and relation fields that may link to several records. Long text and rating fields carry `data-tiffin-span="full"`.
2. When the table has attachment fields: a `fieldset` with role `files` and legend `Files` holding them.
3. For each relation field that may link to several records, in field order: a `fieldset` with role `links` whose legend is the field's name, holding that field.

The legends are written in English as data, like the `Name` field of a new table. The generated form is stored with `name` `Standard form`, `is_default` 1, and `generated` 1, and is rewritten whenever the table's fields change while `generated` is 1.

### 12.4 How TiffinDB uses a stored form

- A field element whose key is not a field of the table (for example after the field was deleted) shows nothing.
- A field of the table that no field element names is not shown on the record screen and is left unchanged when a record is saved.
- A form that breaks any rule of section 12.1, or whose `format` is not `1`, is not used: TiffinDB shows the generated layout instead (section 12.3) and leaves the stored text as it is.
- Stored form text is never sent to a browser as it is. TiffinDB reads it into the elements of section 12.1 and builds the page from those, escaping all text.

## 13. Project archive

A project archive is a zip file that holds one project for backup or for moving it to another TiffinDB installation. TiffinDB writes it from the project's Export page and reads it with "Import a project archive" on the project list, which always makes a **new** project. It can be unpacked with any zip tool; `project.tiffin` inside it is an ordinary project file (sections 1 to 12) and opens on its own.

### 13.1 Contents

```
tiffindb-archive.json                 the manifest (section 13.2)
project.tiffin                        a complete copy of the project database (made with VACUUM INTO; never a -wal or -shm file)
attachments/9f/9f86d0...0a08          one entry per attachment file listed in _tiffin_attachments (section 8)
```

No other entry is written, and TiffinDB refuses an archive that holds any other entry (for example `snapshots/`, a `project.tiffin-wal`, or a folder entry other than those below). Folder entries `attachments/` and `attachments/<first two characters>/`, which some zip tools add when an archive is re-packed, are accepted and ignored. Entry names use `/`, are case-sensitive, and are exactly `tiffindb-archive.json`, `project.tiffin`, or `attachments/` followed by the first two characters of a SHA-256, `/`, and all 64 lower-case hexadecimal characters. Entries are stored or compressed with deflate; none is encrypted. Snapshots are not included.

### 13.2 Manifest

`tiffindb-archive.json` is a UTF-8 JSON object with exactly these members:

| Member | Value |
|---|---|
| `format` | `"tiffindb-archive"` |
| `archive_version` | `1` |
| `created_at` | UTC time the archive was written, `YYYY-MM-DDTHH:MM:SSZ` |
| `created_with` | TiffinDB version, for example `"0.3.0-dev"` |
| `project_format_version` | The `user_version` of `project.tiffin`, for example `3` |
| `project_name` | The project's name |
| `project_size` | Size of `project.tiffin` in bytes |
| `project_sha256` | SHA-256 of `project.tiffin`, lower-case hexadecimal |
| `attachments` | Number of `attachments/` entries |

Example (synthetic):

```json
{"format":"tiffindb-archive","archive_version":1,"created_at":"2026-11-19T10:15:00Z","created_with":"0.3.0-dev","project_format_version":3,"project_name":"Home Inventory","project_size":204800,"project_sha256":"5f70bf18a086007016e948b04aed3b82103a36bea41755b6cddfaf10ace3c6ef","attachments":2}
```

### 13.3 What TiffinDB does when importing an archive

TiffinDB treats the archive as untrusted. It checks the zip and the manifest, opens `project.tiffin` read-only, and refuses it when `PRAGMA integrity_check` is not `ok`, when its identity (section 2) is wrong or its version is newer than TiffinDB knows, or when it holds a trigger, a view, a virtual table, an object whose name is not one of the documented patterns (sections 3 and 4), or a user or join table whose columns differ from sections 5 and 7. It then builds a **new** project file and copies the archive's metadata and values into it, checking each one against this document: values that do not fit their field type are left empty, links whose records are missing are dropped, and forms that break section 12 are dropped; every table's forms are then brought in line with its fields (section 12.3), so each table's edited default form gains a field element for any field it does not name. Keys and record ids are kept; the project gets a new key and no sharing. The import report lists everything that was emptied, dropped, or missing.

### 13.4 Restoring an archive by hand

TiffinDB accepts archive uploads up to 64 MiB, or less when the server's PHP upload limits are lower. A larger archive (or any archive, if you prefer) can be restored by hand by someone with access to the server's files. This skips the checks of section 13.3, so use it only for archives you made yourself.

1. In TiffinDB, create a new, empty project with the name you want (Projects, New project). Open it once, then note its key: the last part of its address, for example `/projects/q7mzk2wbx4ae`, is the folder `projects/q7mzk2wbx4ae/` in the data directory.
2. Make sure nobody is using TiffinDB. In that folder, delete `project.tiffin`, `project.tiffin-wal`, and `project.tiffin-shm` (the empty project), and empty `attachments/`.
3. Unpack the archive. Copy its `project.tiffin` into the folder and its `attachments/` entries into the folder's `attachments/`, keeping their two-character subfolders.
4. Open the copied `project.tiffin` with an SQLite tool and set the key to the folder's name: `UPDATE _tiffin_meta SET value = 'q7mzk2wbx4ae' WHERE key = 'project_key';` (TiffinDB refuses a file whose key differs from its folder). Optionally set `project_name` to the name chosen in step 1.
5. Open the project in TiffinDB. A file of an older format version is upgraded after a snapshot (section 11). The project keeps the sharing set in step 1 (none, for a new project).

The files and their names must follow sections 1 and 8. Forms are still read with the rules of section 12.4, so a form that breaks them is not used.
