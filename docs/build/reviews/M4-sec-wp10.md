# M4 security review: WP10 (format version 4, archive, JSON export)

Design-tier security review (orchestration section 2, rule 4) of merge `cc8ba1e` on the M4 integration branch (WP10). Checked against `docs/specs/M4-core.md` sections 7, 8 and 12.4, `docs/formats/tiffin.md` sections 4, 11.3 and 13, `docs/formats/json-export.md` sections 7 and 9, and `docs/build/M4-session-plan.md` sections 5.2 and 7. The reviewer did not write the code. Each property was checked by reading the code and by running hostile cases: `tests/core/io/archive/Wp10HostileReviewTest.php` (in the suite), plus scratch scripts kept out of the repository that built a real version 3 project and archive with the source tree of `cc8ba1e^1` and then opened, upgraded and imported them with the WP10 code. No data in this review or its tests is real.

Result: **no blocking or high findings.** WP10 may stay merged. Two low findings and two notes follow.

## Scope

- `src/Core/Schema/ProjectFile.php`, `src/Core/Schema/FormatMigrations.php` (version 4 block and step).
- `src/Core/Io/Archive/UntrustedProject.php` (version 4 objects, the new automatic-index rule, `readCollections`, `checkFormKeys`), `ProjectRebuilder.php` (step 6b `copyCollections`, `collectionRules`, `keyUsed`), `RebuildReport.php`.
- `src/Core/Io/Contract/ExportCollection.php`, `ExportRule.php`, `ExportSource.php`, `src/Core/Io/ProjectExportSource.php`, `plugins/export/json/src/JsonExporter.php`.
- The seven mechanically edited tests were read for weakened assertions; none was weakened (version numbers raised, the empty step 4 added to the failing-step seams, `collections` added to the fakes).
- The attachment parts of the archive (`ArchiveReader`, `ZipCheck`, `copyAttachments`, `copyFiles`) are M3 code that WP10 did not change; they were re-run with hostile cases because the brief asked for it.

## Properties checked

| # | Property | Result | Run |
|---|---|---|---|
| 1 | v3 to v4 upgrade on a real v3 file (made by `ProjectFile::create` and `SchemaEditor` of `cc8ba1e^1`, with a relation, a record, an attachment row and file) | Holds. One `format-upgrade` snapshot taken first; the snapshot is still version 3; the file becomes `user_version` 4 and meta `4`; user tables and indexes are byte-for-byte unchanged in `sqlite_master`; exactly three objects are added and no `sqlite_autoindex__tiffin_collections_*`. | yes |
| 2 | Atomic | Holds. A step 4 that runs the three statements and then throws leaves `user_version` 3, meta `3`, and no `_tiffin_collections*` object (`PRAGMA user_version` is rolled back with the transaction). | yes |
| 3 | Idempotent where claimed | Holds. A second open takes no snapshot and changes nothing. The section 11.3 statements themselves are not idempotent (re-running them on a v4 file fails with "table already exists" and rolls back); the format document does not claim otherwise. See L1 for a file that already has the table. | yes |
| 4 | Opens with a plain SQLite driver | Holds. A plain PDO connection with no TiffinDB settings gives `integrity_check` `ok` and an empty `foreign_key_check`. The `sqlite3` command-line tool is not installed in the review container, so the plain-driver check stands in for it. | yes |
| 5 | v3 archive (M3 writer layout, made by the old code) imports | Holds. Rebuilt as version 4, clean report, attachment bytes kept with equal SHA-256 and size. An archive of the upgraded file has manifest version 4 and imports clean. | yes |
| 6 | Untrusted SQL objects next to `_tiffin_collections` | Holds. Refused: a trigger, a view, an extra index, an expression index under a user-index name, the table rebuilt with a CHECK, the table as a rowid table, the table missing, and a version 3 file that has the table. A forged `sqlite_autoindex__tiffin_collections_1` row is refused by SQLite itself when it reads the schema (`damaged`); the new automatic-index rule (`UntrustedProject.php:350`) is a second line behind it and cannot be reached with a file SQLite opens, because a table whose SQL equals the documented `WITHOUT ROWID` text never has an automatic index. | yes |
| 7 | Rebuild never copies untrusted SQL, triggers, views or automatic indexes | Holds. The new file is made by `ProjectFile::create` and `Ddl`; collection rows are inserted with a literal statement and bound values (`ProjectRebuilder.php:811`). | read |
| 8 | Key collisions | Holds. A collection key equal to a table, field, relation or form key is `bad-metadata`; a collection on a field key, a relation key or a `t_` table name is dropped; `keyUsed` also covers keys generated during the rebuild (regenerated default forms). | yes |
| 9 | Bounds | Holds. 1,001 rows and just over 1 MiB of rules text (as text or as a blob, counted in bytes) are `bad-metadata`. 20,000 rules in one row inside the bound decode, keep ten and drop 19,990 in well under a second. Per-column length guards turn over-long values into null before PHP reads them. | yes |
| 10 | Rules JSON that PHP decodes unusually | Holds. A NUL-led property name, an empty property name, duplicate members, `values` as an object, nested operands, `1e999999`, a 30-digit integer field, a top-level string, a byte order mark, and a lone surrogate escape all give a stored list of valid rules (or `[]`); never `rebuild-failed`. | yes |
| 11 | Name uniqueness matches the unique index | Holds. `strtolower` (ASCII only in PHP 8.2+) and `COLLATE NOCASE` fold the same letters: `Été` and `été` are both kept, `  Kite<NBSP>` and `KITE` are one (the second dropped). No unique-index failure. | yes |
| 12 | Attachment rows and entries that do not match | Holds. A hash with `../`, a hash in capitals: rows dropped. Two rows for one hash with different sizes: file not kept (`files_missing`). An entry no row names: not extracted (`files_skipped`). Nothing is written outside `attachments/<2>/<64>` and `project.tiffin`. | yes |
| 13 | Entry names, local-header names, zip bombs | Holds. `attachments/../x`, `attachments/<2>/../../x`, `../x`, a leading `/`, backslashes, a NUL inside or before the name (written byte by byte, since `ZipArchive::addFromString` cuts names at NUL), `attachments/zz/...`, and capitals are refused. A local header naming `../../../escaped-...` under a good central name writes nothing outside the folder. A 4 MiB entry declared as 10 bytes, named by a row with size 10, is cut off after 10 bytes and not kept. | yes |
| 14 | Attachment bytes round trip | Holds. A 70,000-byte binary file and the fixture's two files come back with equal SHA-256 and size through `ArchiveWriter` and the rebuild. | yes |
| 15 | JSON export is additive | Holds. `collections` is the last member; `format_version` stays 1, as section 9 of the format document allows for an added member. Members are exactly `key, table, name, match, rules, sort, created_at, updated_at` (no person keys). A one-table export lists only that table's collections. `U+2028` and `</script>` in a rule value survive as JSON text. | yes |
| 16 | No attachment bytes or paths in the export | Holds. The output holds no file bytes (raw or base64), no `attachments/` path, and no project folder path; attachments are described only by id, hash, size, type, original name and time, as before. | yes |

## Findings

### L1 (low): a file that cannot be upgraded takes a new snapshot on every open

`src/Core/Schema/FormatMigrations.php:149`. When step 4 fails (for example, a version 3 file that already has some `_tiffin_collections` table, as a hand-edited or partly restored file may), `upgrade` takes a `format-upgrade` snapshot, the step rolls back, and the open fails. Every later open does the same: three opens left three identical snapshots. Pruning keeps the 20 newest automatic snapshots, so about 20 failed opens push out the older automatic snapshots (for example the one taken before a table was deleted), which are the ones a user would want in that situation. This is the M1 mechanism, not new in WP10, but WP10 adds a step that can fail. Suggested later hardening: skip the snapshot when the newest snapshot is a `format-upgrade` snapshot of the same file version and the file has not changed since, or stop retrying after a failure until the maintainer acts.

### L2 (low, documentation): spec section 8.1 and the code differ on invalid collection keys

`docs/specs/M4-core.md` section 8.1 lists "keys valid and unique ... (`bad-metadata`)", but `UntrustedProject::readCollections` (`src/Core/Io/Archive/UntrustedProject.php:670`) skips a key that is not a key, and the rebuild drops the row (section 8.2, T-ARC-11). The code follows 8.2 and is the safer reading (the row is dropped, never copied). Suggested: reword 8.1 to "keys that are valid keys must be unique ...".

## Notes

- N1: the automatic-index rule added at `UntrustedProject.php:350` is defence in depth only (property 6). Keep it: it costs nothing and guards against a future SQLite that loads such a schema.
- N2: the session plan (section 5.2, "Archive and export") recommended validating every rule against the rebuilt schema; the spec (section 8.2) settled on keeping rules that do not fit, as in a live project. That is safe because `CollectionRules::resolve` checks each rule and sort token against the table before any SQL is built (WP3), and the rebuild still enforces the stored shape.
