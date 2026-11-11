<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) TiffinDB contributors
-->

# Structure discovery tool

`discover-structure.php` looks inside a file, a directory or a zip archive and describes its **structure**: which files it holds, and the schema, row counts and per-column statistics of every SQLite database it finds. It never prints row values.

TiffinDB needs this to plan a clean-room importer for Bento files (`docs/plan.md`, section 6.4). The file format is unknown. The tool is therefore generic: it contains no knowledge of any product and works on any package that holds SQLite databases.

## Rules

1. Real files never enter this repository, an issue, a pull request, a commit message, or any hosted AI session. Only the reviewed report may be shared.
2. Run the tool on your own machine, outside the repository working tree, on files you legitimately own.
3. Work on a copy of the data, never the original.
4. Read the whole report before sharing it. You decide what leaves your machine.
5. Never commit a report, or any part of one, without that review. This applies most of all to `--value-shapes` output.
6. Findings are later written up, in our own words, in `docs/formats/bento.md`.

## Procedure

1. Quit the application that owns the data.
2. Make a work directory outside the repository, for example `~/discovery-work`.
3. Copy the script into it: `cp tools/discovery/discover-structure.php ~/discovery-work/`
4. Copy the data package into it (`cp -R` for a package directory). From here on, touch only the copy.
5. Run it. The report must go outside the package:

   ```sh
   cd ~/discovery-work
   php discover-structure.php --out report.txt package-copy
   ```

6. Read `report.txt` from top to bottom. Check the layout section, every table and column name, the `sql:` lines, and the list of flagged identifiers. If a name is personal, re-run with `--redact-identifiers` (or `--redact-identifiers=all`), or edit the line out.
7. Send back `report.txt` only. Do not send the package, the database, screenshots of the data, or the unreviewed report.
8. Delete the work directory when finished.

If the report shows that field definitions are stored as rows (for example a table whose row count matches the number of fields you created), structure alone will not show how fields map to columns. In that case, and only then, see `--value-shapes` below.

Requirements: PHP 8.1 or later with `pdo_sqlite`. The `zip` extension is optional; without it, zip archives are listed but not opened.

## Options

| Option | Effect |
|---|---|
| `--out <file>` | Write the report to a file instead of the screen. The file must not already exist and must not be inside the input (or, for a single input file, in its directory). |
| `--json` | JSON instead of plain text. Same content. |
| `--show-names` | Show real file and directory names, and SQLite's own error messages. Off by default. |
| `--redact-identifiers` | Replace flagged table, column, index, view and trigger names with a hash such as `id_3fa91c20be`, everywhere including the `sql:` lines. |
| `--redact-identifiers=all` | Replace every identifier. SQL keywords and common type and function names stay. Types, counts and relationships remain readable. |
| `--apply-wal` | If a database has a write-ahead log (`-wal` file) beside it, copy both to a private temporary directory, read them there, then delete the copy. Without this, the log is ignored and the report says so. |
| `--value-shapes <table>` | Opt-in. See below. May be repeated. |
| `--help`, `--version` | Print usage or the version. |

Exit codes: `0` success; `1` finished, but a problem was reported in the Notes section (damaged or unreadable database, table not found); `2` wrong usage; `3` input not readable or report not writable; `4` `pdo_sqlite` missing.

## What the report contains

- A header with the tool version, the options used, and a statement of what the report holds.
- The container layout: relative paths, sizes in bytes, and a kind detected from the first bytes of each file (`sqlite`, `zip`, `plist-xml`, `plist-binary`, `image-*`, `text`, `other`, and a few more). Entries inside zip archives appear as `archive.zip!/path`.
- For each SQLite database: page size, text encoding, `user_version`, `application_id`, and sidecar files.
- Every table, view, index and trigger with its `CREATE` statement. String literals and comments in the statement are masked (`'<text:14>'`).
- For each table: row count, columns (name, declared type, not-null, primary key, default), foreign keys.
- For each column: how many values are NULL and non-NULL, how many are stored as integer, real, text or blob, and the minimum, maximum and average byte length of the text and blob values.
- A list of identifiers that do not look like fixed product names.
- Notes: anything that could not be read. A damaged database is reported and the run continues.

Output is sorted and repeatable: the same input and options give the same report.

## What the report does not contain

- No row values, samples, distinct-value lists, or minimum/maximum values.
- No file contents. Plist, text and image files are only identified by kind.
- No text defaults. A numeric default is shown; any other is shown only as "present".
- No absolute paths, user names, host names, dates or times.

## Things that can still be personal

**File names.** Attachment and library names are often personal, so each path component is replaced by a stable placeholder: `<name-1>.jpg`, `<uuid-2>`, `<num-3>`, `<hex-4>`. The same name always gets the same placeholder within one report. A short list of generic words (`Contents`, `Resources`, `Info`, `Attachments`, `data` and similar) is shown as is. The extension, meaning the part after the last dot, is always shown, because it is the main structural clue. A name such as `J.Smith` would therefore show `.Smith`. Check the layout section.

**Identifiers.** Table and column names are structure, but a product may create tables or columns named after things the user typed. The tool flags identifiers that contain spaces or punctuation, non-ASCII characters, or a run of two or more digits. This is a heuristic. It cannot recognise a user-chosen name that is a plain word. Read every name; if in doubt use `--redact-identifiers=all`. Hashes are keyed from the input, so they are stable for the same input but cannot be compared across different packages.

**`--value-shapes <table>`.** For the one named table, this adds masked patterns of its TEXT values: letters become `a` or `A`, digits become `9`, ASCII punctuation and spaces are kept, anything else becomes `?`. Patterns are cut at 40 characters, and at most the 20 most frequent patterns per column are listed, each with a count. A type code such as `date:v2` appears as `aaaa:a9`. Integer, real and blob values are not shown. Use this only on a table that the structure report suggests holds field or type definitions, never on a table of records. A pattern still reveals lengths, punctuation and word breaks. Review that output line by line before sharing it, and never commit it without your review.

## Read-only guarantees

- Databases are opened with `mode=ro` and `immutable=1`, and `PRAGMA query_only` is set. Nothing is created beside the input, not even lock or `-shm` files.
- Views are listed but never queried. Triggers do not run.
- Temporary copies are made only for databases inside zip archives and for `--apply-wal`. They go to a private directory under the system temporary directory and are deleted before the tool exits. If the tool is killed, delete any `tiffindb-discovery-*` directory left there.

## Fallback without PHP: the `sqlite3` shell

If PHP is not available, the `sqlite3` command-line shell (version 3.16 or later) gives equivalent structure-only output for one database. Work in the same work directory, on the copy. Replace the path; if it contains `?`, `#` or `%`, rename the copy first.

```sh
DB='file:/full/path/to/copy/database-file?immutable=1'

# File kinds and extensions in the package, without names:
find package-copy -type f -exec file -b {} \; | sort | uniq -c
find package-copy -type f | sed -n 's/.*\.\([A-Za-z0-9-]*\)$/\1/p' | sort | uniq -c

# Header values, object names, columns, foreign keys:
sqlite3 -readonly "$DB" > structure.txt <<'SQL'
PRAGMA user_version;
PRAGMA application_id;
PRAGMA page_size;
PRAGMA encoding;
SELECT type, name, tbl_name FROM sqlite_master ORDER BY type, name;
SELECT m.name, p.cid, p.name, p.type, p."notnull", p.pk, p.dflt_value IS NOT NULL
FROM sqlite_master AS m, pragma_table_info(m.name) AS p
WHERE m.type = 'table' ORDER BY m.name, p.cid;
SELECT m.name, f.id, f.seq, f."from", f."table", f."to", f.on_update, f.on_delete
FROM sqlite_master AS m, pragma_foreign_key_list(m.name) AS f
WHERE m.type = 'table' ORDER BY m.name, f.id, f.seq;
SQL

# Row counts and per-column storage classes and byte lengths.
# Step 1 writes the counting queries (they contain names only); step 2 runs them.
sqlite3 -readonly "$DB" > count-queries.sql <<'SQL'
SELECT 'SELECT ' || quote(tname) || ', count(*) FROM ' || t || ';' FROM (
  SELECT name AS tname, '"' || replace(name, '"', '""') || '"' AS t
  FROM sqlite_master WHERE type = 'table' ORDER BY name);
SELECT 'SELECT ' || quote(tname) || ', ' || quote(cname) || ', typeof(' || q || '), count(*), '
    || 'min(' || len || '), max(' || len || '), round(avg(' || len || '), 1) FROM ' || t || ' GROUP BY 3;'
FROM (
  SELECT tname, cname, cid, t, q,
         'CASE WHEN typeof(' || q || ') IN (''text'', ''blob'') THEN length(CAST(' || q || ' AS BLOB)) END' AS len
  FROM (
    SELECT m.name AS tname, p.name AS cname, p.cid AS cid,
           '"' || replace(m.name, '"', '""') || '"' AS t,
           '"' || replace(p.name, '"', '""') || '"' AS q
    FROM sqlite_master AS m, pragma_table_info(m.name) AS p
    WHERE m.type = 'table'))
ORDER BY tname, cid;
SQL
sqlite3 -readonly "$DB" < count-queries.sql >> structure.txt
```

The statistics lines read: table, column, storage class, count, then minimum, maximum and average byte length (text and blob only).

The fallback does not redact anything and does not print the `CREATE` statements, because the shell cannot mask the literals in them. If the statements are needed, run `sqlite3 -readonly "$DB" .schema` and remove every quoted literal by hand before sharing. Review `structure.txt` under the same rules as the report.

## Known limits

- An encrypted database has no SQLite header. It is listed as `other`, with a note if its extension looks like a database.
- A database damaged beyond its header is reported with an error code and skipped.
- Virtual tables that need a module the local SQLite lacks are listed, but their statistics may be missing.
- Zip archives nested more than two levels deep, and archive entries over 1 GiB, are listed but not opened.
- Symbolic links are listed but not followed.
