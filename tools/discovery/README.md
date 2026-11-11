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
2. Make a work directory outside the repository, with one subdirectory for the copy and one for the report:

   ```sh
   mkdir -p ~/discovery-work/data ~/discovery-work/out
   cp tools/discovery/discover-structure.php ~/discovery-work/
   ```

3. Copy the data package (or single file) into `data/`. Use `cp -R` for a package directory. From here on, touch only the copy.
4. Run it. The report goes to `out/`, never into `data/`:

   ```sh
   cd ~/discovery-work
   php discover-structure.php --out out/report.txt data/package-copy
   ```

   The tool refuses to write inside the input directory, or in the same directory as a single input file. That is why the report has its own directory.

5. Read `out/report.txt` from top to bottom. Check the layout section, every `sql:` line, and above all the "REVIEW LIST" under each database: it names every table and column that is printed as stored. If any name is personal, re-run with `--redact-identifiers=all`, or edit the line out.
6. Send back `report.txt` only. Do not send the package, the database, screenshots of the data, or an unreviewed report.
7. Delete the work directory when finished.

If the report shows that field definitions are stored as rows (for example a table whose row count matches the number of fields you created), structure alone will not show how fields map to columns. In that case, and only then, see `--value-shapes` below.

Requirements: PHP 8.1 or later with `pdo_sqlite`. The `zip` extension is optional; without it, zip archives are listed but not opened.

## Options

| Option | Effect |
|---|---|
| `--out <file>` | Write the report to a new file readable only by you (mode 0600) instead of the screen. Refused if the file exists, if the path is a symbolic link, or if it is inside the input directory or beside a single input file. |
| `--json` | JSON instead of plain text. Same content. |
| `--show-names` | Show real file and directory names. Off by default. |
| `--redact-identifiers=all` | Replace every table, column, index, view and trigger name with a hash such as `id_3fa91c20be`, everywhere including the `sql:` lines. SQL keywords and common type and function names stay. Types, counts and relationships remain readable. |
| `--show-identifiers` | Show every identifier as stored, including those the tool would hash by default. **Warning:** names may be personal. Use only for a report you will read in full before sharing. |
| `--apply-wal` | If a database has a write-ahead log (`-wal` file) beside it, copy both to a private temporary directory, read them there, then delete the copy. Without this, the log is ignored and the report says so. |
| `--value-shapes <table>` | Opt-in. See below. May be repeated. |
| `--value-shapes-singletons` | With `--value-shapes`: also list patterns that occur only once. |
| `--help`, `--version` | Print usage or the version. |

Exit codes: `0` success; `1` finished, but a problem was reported in the Notes section (damaged or unreadable database, table not found); `2` wrong usage; `3` input not readable, report not writable, or the run was stopped; `4` `pdo_sqlite` missing.

## What the report contains

- A header with the tool version, the options used, and a statement of what the report holds.
- The container layout: relative paths, sizes in bytes, and a kind detected from the first bytes of each file (`sqlite`, `zip`, `plist-xml`, `plist-binary`, `image-*`, `text`, `other`, and a few more). Entries inside zip archives appear as `archive.zip!/path`.
- For each SQLite database: page size, text encoding, `user_version`, `application_id`, and sidecar files (`-wal`, `-journal`).
- Every table, view, index and trigger with its `CREATE` statement, with all literals masked (see below).
- For each table: row count, columns (name, declared type, not-null, primary key, whether a default exists and of what kind, hidden or generated), foreign keys.
- For each column: how many values are NULL and non-NULL, how many are stored as integer, real, text or blob, and the minimum, maximum and average byte length of the text and blob values.
- Per database: the identifiers that were hashed, and a review list of every identifier printed as stored.
- Notes: anything that could not be read. A damaged database is reported and the run continues.

Output is sorted and repeatable: the same input and options give the same report.

## What the report does not contain

- No row values, samples, distinct-value lists, or minimum/maximum values.
- No file contents. Plist, text and image files are only identified by kind.
- No literals from `CREATE` statements. Text becomes `<text>`, numbers become `<num>`, comments become `<comment>`. Only `0`, `1` and type sizes such as `VARCHAR(255)` stay. SQLite accepts `"double-quoted"` text as a string, so a quoted word is kept only when it is the name of a table, column, index, view or trigger in that database.
- No default values. A default is reported as `yes (numeric)`, `yes (text-or-blob)`, `yes (keyword)`, `yes (expression)` or `no`.
- No absolute paths, user names, host names, dates, times, or SQLite error messages (only error codes).

## Things that can still be personal

**File names.** Attachment and library names are often personal, so each path component is replaced by a stable placeholder: `<name-1>.jpg`, `<uuid-2>`, `<num-3>`, `<hex-4>`. The same name always gets the same placeholder within one report. A short list of generic words (`Contents`, `Resources`, `Info`, `Attachments`, `data` and similar) is shown as is. Extensions are shown only if they are on a list of common ones (`db`, `sqlite`, `zip`, `plist`, `jpg`, `txt` and so on); any other extension becomes `.<ext-1>`. So a product-specific extension is hidden by default. To learn it, look at the file name yourself, or run with `--show-names` and review the layout section.

**Identifiers.** Table and column names are structure, but a product may create tables or columns named after things the user typed. By default the tool replaces, with a hash, every identifier that contains spaces, punctuation or non-ASCII characters, is longer than 63 characters, or contains a run of two or more digits. This is a heuristic, and it has a hard limit: **a user-chosen name that is a plain word (a table called `Recipes`, a column called `nickname`) looks exactly like a product name and is shown.** The tool cannot tell them apart. That residual risk is why each database ends with a review list. Read it. If in doubt use `--redact-identifiers=all`. Hashes are keyed from the input, so they are stable for the same input but cannot be compared across different packages or reversed by guessing.

**`--value-shapes <table>`.** This adds masked patterns of the TEXT values of the named table: letters become `a` or `A`, digits become `9`, ASCII punctuation and spaces are kept, anything else becomes `?`. Patterns are cut at 40 characters. Only patterns that occur at least twice are listed, at most the 20 most frequent per column, each with a count; patterns that occur once are only counted, because a one-off pattern is close to identifying (`--value-shapes-singletons` lists them too). A type code such as `date:v2` appears as `aaaa:a9`. Integer, real and blob values are not shown.

The table is matched by its real name in every database that has it. To pick one database, use the number from its `== Database <n> ==` heading in a previous run: `--value-shapes 2:tablename`.

Use this only on a table that the structure report suggests holds field or type definitions, never on a table of records. A pattern still reveals lengths, punctuation and word breaks. Review that output line by line before sharing it, and never commit it without your review.

## Read-only guarantees

- Databases are opened with `mode=ro` and `immutable=1`, and `PRAGMA query_only` is set. Nothing is created beside the input, not even lock or `-shm` files.
- Because of `immutable=1`, a `-wal` or `-journal` file beside a database is ignored. The report warns when one is present. Quitting the application first normally leaves neither.
- Views are listed but never queried. Triggers do not run.
- Temporary copies are made only for databases inside zip archives and for `--apply-wal`. They go to a private directory under the system temporary directory and are deleted when the tool exits, including after an error. If the tool is killed, delete any `tiffindb-discovery-*` directory left there.

## Fallback without PHP: the `sqlite3` shell

If PHP is not available, the `sqlite3` command-line shell (version 3.16 or later) gives equivalent structure-only output for one database. Use the same `data/` and `out/` layout. Replace the paths; if the database path contains `?`, `#` or `%`, rename the copy first.

```sh
cd ~/discovery-work
DB='file:/full/path/to/discovery-work/data/database-file?immutable=1'

# File kinds and extensions in the package, without names:
find data -type f -exec file -b --mime-type {} \; | sort | uniq -c > out/structure.txt
find data -type f | sed -n 's/.*\.\([A-Za-z0-9-]*\)$/\1/p' | sort | uniq -c >> out/structure.txt

# Header values, object names, columns, foreign keys:
sqlite3 -readonly "$DB" >> out/structure.txt <<'SQL'
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
sqlite3 -readonly "$DB" > out/count-queries.sql <<'SQL'
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
sqlite3 -readonly "$DB" < out/count-queries.sql >> out/structure.txt
```

The statistics lines read: table, column, storage class, count, then minimum, maximum and average byte length (text and blob only).

The fallback redacts nothing: every table and column name, and every unusual file extension, appears as stored. It does not print the `CREATE` statements, because the shell cannot mask the literals in them; do not add them. Review `out/structure.txt` under the same rules as the report, and send only that file.

## Known limits

- The identifier heuristic cannot detect plain-word user-chosen names (see above).
- An encrypted database has no SQLite header. It is listed as `other`, with a note if its extension looks like a database.
- A database damaged beyond its header is reported with an error code and skipped.
- Virtual tables that need a module the local SQLite lacks are listed, but their statistics may be missing.
- Zip archives nested more than two levels deep, and archive entries over 1 GiB, are listed but not opened.
- Symbolic links are listed but not followed.
- `--value-shapes` covers TEXT values only, so integer type codes cannot be characterised with it.
