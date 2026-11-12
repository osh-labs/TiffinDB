# TiffinDB project file format, version 1

Status: format version 1, written by TiffinDB from milestone M1. Provisional until the maintainer confirms ADR 0010. This document is meant to let anyone read, check, or rescue a TiffinDB project with ordinary SQLite tools and no TiffinDB code.

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
| `PRAGMA user_version` | The format version: `1` for this document |
| `SELECT value FROM _tiffin_meta WHERE key = 'format'` | `tiffindb-project` |
| `SELECT value FROM _tiffin_meta WHERE key = 'format_version'` | `1` |

TiffinDB refuses to open a file whose `application_id` or `format` differs, or whose version is higher than it understands. It upgrades a file with a lower version in place, after saving a snapshot (section 11).

## 3. Keys and names

Every project, table, field, relation, and person has a **key**: 12 characters from `abcdefghijklmnopqrstuvwxyz234567`, for example `q7mzk2wbx4ae`. Keys are random, never change, and are never reused. The names people see ("Items", "Purchased") are stored separately and can change at any time without touching the database structure.

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

All metadata lives in tables whose names start with `_tiffin_`. Exact definitions for version 1:

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

The connection that TiffinDB uses turns on `PRAGMA foreign_keys`. A tool that does not will not enforce the `REFERENCES` clauses; that only matters when writing.

### 4.1 `_tiffin_meta`

| Key | Value |
|---|---|
| `format` | `tiffindb-project` |
| `format_version` | `1` |
| `project_key` | The project's key; equals the folder name |
| `project_name` | The project name shown in TiffinDB, for example `Home Inventory` |
| `created_at` | When the project was created (time format in section 5) |
| `created_by` | Key of the person who created it |
| `created_with` | TiffinDB version that created the file, for example `0.2.0-dev` |
| `updated_at` | When anything in the project last changed |
| `updated_by` | Key of the person who made that change |

Person keys refer to accounts in the installation's system database; a project file on its own does not say who they are.

### 4.2 `_tiffin_tables`

One row per user table. `name` is the plural display name ("Items"); `item_name` is what one record is called ("item"), used on buttons such as "Add item". `position` orders tables in the interface, from 1. `title_field` is the key of the field shown as each record's title, or null.

### 4.3 `_tiffin_fields`

One row per field. `sql_name` is the column name in the user table, or null for the two field types that have no column (relation and attachment). `type` is one of the identifiers in section 6. `options` is a JSON object whose members depend on the type (section 6). `required` is 0 or 1. `help` is the hint shown under the field, possibly empty. `position` orders fields within a table, from 1.

### 4.4 `_tiffin_relations`

One row per relation between two tables (section 7).

### 4.5 `_tiffin_attachments` and `_tiffin_attachment_links`

Files and which record field they belong to (section 8).

### 4.6 Reserved for later versions

`_tiffin_forms`, `_tiffin_collections`, and `_tiffin_import_log` are named in the plan and will be added in later format versions. Version 1 files do not contain them.

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
| `manual` | An Admin asked for one |

The 20 newest snapshots other than `manual` ones are kept; older ones are deleted when a new one is taken. Manual snapshots are kept until removed by hand.

**Restoring by hand.** TiffinDB does not yet restore snapshots itself. With nobody using TiffinDB: rename `project.tiffin` (to keep it), copy the chosen snapshot to `project.tiffin` in the same folder, and delete `project.tiffin-wal` and `project.tiffin-shm` if they exist (they belong to the old file). If the snapshot has an older format version, TiffinDB upgrades it the next time the project is opened.

## 11. Versions and upgrades

- `PRAGMA user_version` and `_tiffin_meta.format_version` always hold the same number.
- Each new format version is described in a new section of this document, with the exact SQL of its upgrade step. Upgrades only go forward and run inside one transaction, after a `format-upgrade` snapshot.
- Version history:

| Version | Introduced in | Changes |
|---|---|---|
| 1 | M1 | First version: metadata, tables, fields, relations, attachments, trash columns |
