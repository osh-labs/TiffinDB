# M3 design spec: import, export, plugins, archive, and work packages

Status: design spec for milestone M3, part 1 of 2 (M3-D1), awaiting spec review (M3-R1) and reconciliation with the M2 as-built notes (M3-D2). This part covers the core: the plugin contract and registry, staging jobs, the import pipeline, CSV, XLSX, and JSON formats, the project archive, project format version 3, system schema version 2, the new core operations, the test plan, the provisional decisions, and **all M3 work packages**. Part 2, `docs/specs/M3-screens.md`, covers uploads and downloads in the request pipeline, routes, controllers, templates, strings, and the keyboard procedure. Screen designs are under `docs/design/m3/`.

Decisions this spec relies on: ADR 0008, ADR 0009, ADR 0010, ADR 0012, ADR 0013, ADR 0014 (M2), and the new ADR 0015 (plugin contract, import commit model), ADR 0016 (XLSX library, pinning), ADR 0017 (project archive, JSON export). Formats: `docs/formats/tiffin.md` (version 3, archive in section 13) and `docs/formats/json-export.md`; where this spec and those documents differ, stop and report. Everything in the M1 and M2 specs, with their as-built notes and provisional decisions P1 to P109, still applies unless a section below changes it.

**Designed against M2 as specified, not as built.** M2 was being implemented while this spec was written. Every statement about an M2 class (names, signatures, behaviour) comes from `docs/specs/M2-records.md` and `docs/specs/M2-screens.md` after review M2-R1 and is marked "(M2 assumption)" where M3 depends on a detail. Section 18 lists them for M3-D2.

Audience: engineers holding this spec, `CLAUDE.md`, and the files a work package names. `CLAUDE.md` has the highest authority.

## 1. Goals, non-goals, exit criteria

### 1.1 Goals

1. A plugin contract (`Core\Io\Contract`, API 1), plugin discovery from `plugins/<kind>/<id>/plugin.json`, lazy loading of enabled plugins only, and Admin enable and disable stored in the system database (ADR 0015).
2. An import pipeline from an uploaded file into a new or an existing table: read in chunks into a staging job, infer types, review, prepare in chunks, commit in **one transaction** after an `import` snapshot, report, and log (`_tiffin_import_log`, project format version 3).
3. CSV import and export with TiffinDB's own reader and writer (no library); XLSX import and export through the shipped library (ADR 0016); JSON export of a whole project (`docs/formats/json-export.md`).
4. Project archive export and import (ADR 0017): verify, then rebuild into a new project.
5. Core operations with authorization in the core: import, export, and archive export need `io.use`; archive import needs `project.create`; plugins need `plugins.manage`. All Admin-only; a granted Member gets "access denied", an ungranted Member "not found".
6. The screens of part 2: import steps 1 to 4 (step 2 from `import.html`), reading options, export, archive import, plugins.

### 1.2 Non-goals for M3

- The legacy-product importer (M6, deferred); relation picker, attachment upload and serving, trash screen, collections (M4); form editor and sanitizer (M5).
- Importing attachments from CSV or XLSX; importing into more than one table at once (except an archive); creating fields when appending to an existing table; updating existing records from a file ("merge" or "upsert").
- Restoring a snapshot; scheduling exports; exporting records in the trash (the archive carries them).
- Installing or removing plugins through the application (they are copied onto the server).

### 1.3 Exit criteria

| Exit criterion (session plan section 2; plan 8; ui 11) | How M3 meets it |
|---|---|
| Round-trip tests on seeded synthetic fixtures | T-RT-1 (CSV to project to CSV, byte for byte), T-RT-2 (XLSX to project to XLSX, cell for cell, plus a hand-assembled workbook read independently), T-RT-3 (project to JSON checked against `docs/formats/json-export.md` and the database), T-RT-4 (archive export to archive import, table by table, keys, ids, links, forms, files), T-RT-5 (JSON of the original equals JSON of the archive-imported copy apart from project key, name, and export time). Fixtures come from `tests/Support/IoFixture.php` with documented seeds; no binary fixture is committed |
| Import flow usable by keyboard only | Structure: T-KBD-2 (part 2 section 9.1). Scripted headless-browser run with key presses only: K-2 (part 2 section 9.2), evidence recorded by the exit audit |
| Scope: framework and plugin contract; CSV and XLSX import; CSV, XLSX, JSON export; archive export and import; import steps 1 to 4; export screen; plugins screen | Sections 3 to 13 and part 2; T-IO, T-PLG, T-CSV, T-XLSX, T-JSON, T-ARC, T-APP-90 to T-APP-97, T-WEB-40 to T-WEB-47, T-E2E-8 to T-E2E-10 |
| An import that fails part-way leaves the target unchanged (exit audit, session plan 7.3) | T-IO-12 (failure injected at every step of the commit transaction, file compared before and after), T-E2E-9 |
| Hostile-input corpus for CSV, XLSX, archive | T-CSV-9, T-XLSX-6, T-ARC-3 to T-ARC-8, T-WEB-41 (uploads) |
| A Member is refused every import, export, and plugin operation in core | T-APP-90 (data provider over every method of `ImportOperations`, `ExportOperations`, `ArchiveOperations`, `PluginOperations`) |
| Shipped packages match `docs/dependencies.md` exactly; licence allowlist passes | T-DEP-1, T-DEP-2 (M3-WP0) |
| No network call in application code | Existing repository rule (M0), extended to `plugins/` (T-PLG-8) |
| Data portability | Version 3 file matches `docs/formats/tiffin.md` (T-FMT-4); a version 2 file upgrades after a snapshot (T-FMT-5); exports are documented formats |

## 2. Code layout

New PHP follows ADR 0008 decision 6. New or changed directories:

```
src/Core/Io/Contract/   Importer, Exporter, SourceFile, Setting, SettingChoice, Settings, Inspection, SourceTable,
                        ExportSource, ExportTable, ExportField, ExportRecord, ExportValue, ExportRelation,
                        ExportAttachment, ExportProject, PluginFailure
src/Core/Io/Plugin/     Manifest, ManifestReader, PluginScanner, PluginInfo, PluginStatus, PluginLoader
src/Core/Io/            StagingArea, ImportJob, JobState, ColumnStats, TypeGuess, Inference, Mapping, ColumnMapping,
                        MappingCheck, Reader (staging read loop), Preparer, Committer, ImportReport, ImportLog,
                        ExportSourceBuilder, IoLimits
src/Core/Io/Csv/        CsvDialect, CsvDetector, Charset, CsvReader, CsvWriter
src/Core/Io/Archive/    ArchiveManifest, ArchiveWriter, ZipCheck, ArchiveReader, UntrustedProject, ProjectRebuilder,
                        RebuildReport
src/Core/Schema/        Ddl (new), SchemaEditor (joined mode), ProjectFile (version 3), FormatMigrations (step 3)
src/Core/Query/         Records (joined mode, required keys)
src/Core/Storage/       Fs (removeTree, moveIn, entries reuse), Sqlite (openUntrusted), SnapshotReason (import)
src/Core/System/        PluginStates (new), SystemSchema (version 2), AuditAction (new actions)
src/Core/App/           ImportOperations, ExportOperations, ArchiveOperations, PluginOperations and result objects
plugins/import/csv/  plugins/import/xlsx/  plugins/export/csv/  plugins/export/xlsx/  plugins/export/json/
tests/core/io/, tests/core/io/csv/, tests/core/io/archive/, tests/plugins/, tests/formats/   new tests
```

### 2.1 Dependency rule

ADR 0015 decision 5 amends the layer table; `tests/repo/CoreLayerRulesTest.php` (T-REPO-9) gains the rows:

| Area | May use |
|---|---|
| Io (including Contract, Plugin, Csv, Archive) | Schema, Query, Forms, Storage, Error |
| System | Storage, Error (unchanged; `PluginStates` is a repository) |
| App | Error, Storage, System, Auth, Schema, Query, Forms, Io |

`Core\Io\Contract` uses nothing but PHP and `Core\Io\Contract` itself, so plugins see one small namespace. Files under `plugins/` may name only `TiffinDB\Core\Io\Contract\`, their own namespace (`TiffinDB\Plugin\<Kind>\<Id>\`), PHP built-in classes, and `OpenSpout\` (T-PLG-8, new `tests/repo/PluginLayerRulesTest.php`). The web layer may use the result objects of the new `Core\App` operations and `Core\Error` values only (T-WEB-23 unchanged).

### 2.2 SQL rules

`tests/core/schema/SqlStringsTest.php` (M2 T-REC-14 version) is extended to every file under `src/Core/Io/` with the same rules as `src/Core/Query/` (literals, `Identifier` calls, `Database::placeholders()`, `Sql` fragments). Its `variable-new` rule allows exactly one `new $class()` in `src/Core/Io/Plugin/PluginLoader.php` (section 4.3); WP1 adds that exemption by path, since the file is WP2's. `src/Core/Schema/Ddl.php` falls under the existing `src/Core/Schema/` rules. No SQL is allowed under `plugins/` (plugins receive values, never a database).

## 3. The plugin contract (`TiffinDB\Core\Io\Contract`, API 1)

All classes `final` with `readonly` properties unless they are interfaces. Text a plugin supplies for people (names, setting labels, descriptions) is plain text data, not a message key; the web layer escapes it and shows it as given (P133).

### 3.1 Shared values

```php
final class SourceFile
{
    public function __construct(
        public readonly string $path,        // absolute path inside the job folder; readable; the plugin must not write it
        public readonly string $extension,   // lower-case extension of the uploaded name, '' when none
        public readonly int $size,
        public readonly string $tempFolder,  // absolute path of a folder the plugin may write temporary files into
    ) {}
}

final class SettingChoice { public function __construct(public readonly string $value, public readonly string $label) {} }

final class Setting
{
    /** @param list<SettingChoice> $choices  kind 'choice' only */
    public function __construct(
        public readonly string $name,       // ^[a-z][a-z0-9_]{0,31}\z
        public readonly string $label,      // 1 to 80 characters
        public readonly string $kind,       // 'choice' | 'boolean'
        public readonly string $value,      // current value: a choice value, or '1' / '0'
        public readonly array $choices = [],
        public readonly string $help = '',  // 0 to 200 characters
    ) {}
}

final class Settings
{
    /** @param list<Setting> $items */
    public function __construct(public readonly array $items) {}
    public function value(string $name): ?string;
    /** A copy with the given values applied; unknown names and values not allowed by a setting are ignored. */
    public function with(array $values): self;
    /** @return array<string, string> name => value */
    public function values(): array;
}

final class PluginFailure extends \RuntimeException
{
    /** $reason: one of the REASON_* constants; $detail: plain text for the server log only, never shown. */
    public function __construct(public readonly string $reason, string $detail = '') {}
    public const REASON_UNREADABLE = 'unreadable';   // not a file of this format, or damaged
    public const REASON_TOO_LARGE = 'too-large';     // a limit of section 6.8 or of the format was passed
    public const REASON_UNSUPPORTED = 'unsupported'; // a valid file using a feature the plugin does not read
    public const REASON_FAILED = 'failed';           // anything else
}
```

### 3.2 `Importer`

```php
interface Importer
{
    /** Reading options with values detected from the file (for CSV: encoding, delimiter, heading row). */
    public function detect(SourceFile $file): Settings;

    /** What the file holds when read with $settings. Must read no more than needed (sheet names, headings, a row count when cheap). */
    public function inspect(SourceFile $file, Settings $settings): Inspection;

    /**
     * Data rows of table $table (0-based index into Inspection::$tables), starting at data row $from (0-based,
     * heading row not counted), at most $limit rows. Each row is a list of exactly count(columns) cells;
     * a cell is a string (the value as text, section 6.3) or null (empty). Rows past the end are not returned.
     * @return iterable<int, list<?string>>
     */
    public function rows(SourceFile $file, Settings $settings, int $table, int $from, int $limit): iterable;
}

final class SourceTable
{
    /** @param list<string> $columns headings (generated "Column n" when the file has none), 1 to 200 */
    public function __construct(public readonly string $name, public readonly array $columns,
                                public readonly ?int $rowCount, public readonly bool $hidden = false) {}
}

final class Inspection
{
    /** @param list<SourceTable> $tables  at least one; CSV has exactly one */
    public function __construct(public readonly array $tables, public readonly string $readAs) {}  // readAs: "CSV, commas, UTF-8"
}
```

Plugins throw `PluginFailure` for every expected failure; any other `Throwable` from plugin code is caught by the pipeline, logged with the plugin id, and treated as `REASON_FAILED`.

### 3.3 `Exporter`

```php
interface Exporter
{
    /** Options shown on the export page for this scope ('table' or 'project'). */
    public function settings(string $scope): Settings;

    /** Writes the export of $source to $targetPath (a new file in the job folder). */
    public function write(ExportSource $source, Settings $settings, string $targetPath): void;
}
```

`ExportSource` (built by the core, section 10.1) is read-only:

```php
final class ExportProject { string $key; string $name; string $createdAt; string $updatedAt; int $fileFormatVersion; string $exportedAt; string $exportedWith; }
final class ExportField { string $key; string $name; string $type; array $options; bool $required; string $help; ?string $linkedTable /* relation */; }
final class ExportTable { string $key; string $name; string $itemName; ?string $titleField; /** @var list<ExportField> */ array $fields; int $recordCount; }
final class ExportValue
{
    public function __construct(
        public readonly mixed $value,   // canonical PHP value (M1 types; currency as int minor units), null when empty
        public readonly string $text,   // FieldType::toText(value), '' when empty; relation: linked titles joined by '; '
                                        // ("{item} {id}" for an untitled record); attachment: original names joined by '; '
        public readonly array $ids,     // relation: linked live record ids; attachment: attachment ids; else []
    ) {}
}
final class ExportRecord { int $id; string $createdAt; ?string $createdBy; string $updatedAt; ?string $updatedBy; /** @var array<string, ExportValue> */ array $values; }
final class ExportRelation { string $key; string $leftTable; string $leftField; string $leftMax; string $rightTable; string $rightField; string $rightMax; }
final class ExportAttachment { int $id; string $sha256; int $size; string $mediaType; string $originalName; string $createdAt; }

interface ExportSource
{
    public function project(): ExportProject;
    /** @return list<ExportTable> the tables in scope, in table order */
    public function tables(): array;
    /** @return iterable<ExportRecord> live records of the table by id; streamed in pages of 500 */
    public function records(string $tableKey): iterable;
    /** @return list<ExportRelation> relations whose both tables are in scope */
    public function relations(): array;
    /** @return iterable<ExportAttachment> attachments linked from records in scope */
    public function attachments(): iterable;
}
```

(Properties above are written in short form; each class has a constructor with those `public readonly` properties, in that order.)

## 4. Plugins (`TiffinDB\Core\Io\Plugin`) (security-critical)

### 4.1 Manifest

`ManifestReader::read(string $pluginDir, string $kind, string $dirName): Manifest` throws `ManifestInvalid(reason)` (a `\RuntimeException` with a reason code) for any violation:

1. `plugin.json` is a regular file (not a link: `is_link` false, checked with `Fs::isFile`), at most 16,384 bytes, valid UTF-8, decoded with `json_decode(..., true, 8, JSON_THROW_ON_ERROR)` to an array with string keys.
2. Members exactly as ADR 0015 decision 2 (unknown member: `unknown-member`; missing: `missing-member`), with these checks: `api` the integer 1; `id` equals `$dirName` and matches `^[a-z][a-z0-9-]{1,31}\z`; `kind` equals `$kind`; `name` 1 to 60 characters and `description` 0 to 200 after `Text::trim`, no control characters; `version` `^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\z`; `class` equals the plugin namespace (ADR 0015 decision 2) followed by one class name `[A-Z][A-Za-z0-9]{0,63}` (so it can only name a class under `src/`); `licence` one of `MIT`, `BSD-2-Clause`, `BSD-3-Clause`, `Apache-2.0`, `ISC`, `LGPL-2.1-or-later`, `LGPL-3.0-or-later`, `MPL-2.0`, `GPL-3.0-or-later`, `AGPL-3.0-or-later`, `AGPL-3.0-only`, `GPL-3.0-only`; `extensions` a list of 1 to 10 strings `^[a-z0-9]{1,10}\z` (export: exactly one); `media_type` (export only, required) `^[a-z]+/[a-z0-9.+-]{1,100}\z`; `scopes` (export only, required) non-empty, subset of `table`, `project`, no duplicates; `requires_extensions` a list of 0 to 20 strings `^[a-z0-9_]{1,32}\z`.
3. `src/` exists as a directory inside the plugin directory (`Fs::isInside` with both resolved), and the class file `src/<ClassName>.php` is a regular file inside `src/`.

```php
final class Manifest { int $api; string $id; string $kind; string $name; string $version; string $class; string $licence;
                       array $extensions; ?string $mediaType; array $scopes; array $requiresExtensions; string $description;
                       public function ref(): string; /* '<kind>/<id>' */ }
```

### 4.2 Discovery

```php
enum PluginStatus: string { case Enabled = 'enabled'; case Disabled = 'disabled'; case Unavailable = 'unavailable'; case Broken = 'broken'; }

final class PluginInfo
{
    public function __construct(
        public readonly string $kind, public readonly string $id, public readonly ?Manifest $manifest,
        public readonly PluginStatus $status, public readonly bool $builtIn,
        public readonly ?string $brokenReason,          // reason code when Broken
        public readonly array $missingExtensions,       // when Unavailable
    ) {}
}

final class PluginScanner
{
    public const BUILT_IN = ['import/csv', 'import/xlsx', 'export/csv', 'export/xlsx', 'export/json'];
    public const MAX_PLUGINS = 100;
    /** $extensionLoaded: \Closure(string): bool (default extension_loaded), a test seam. */
    public function __construct(string $pluginsRoot, ?\Closure $extensionLoaded = null) {}
    /** @param array<string, bool> $enabledOverrides '<kind>/<id>' => enabled, from PluginStates
        @return list<PluginInfo> import plugins then export plugins, each by id */
    public function scan(array $enabledOverrides): array;
}
```

`scan` lists `plugins/import/` and `plugins/export/` with `Fs::entries` (M2-WP0; never follows links). Each entry that is a real directory (not a link) whose name matches the id pattern is read with `ManifestReader`; entries that are links, files, or other names are ignored (not listed). More than 100 directories: the first 100 by name are read, the rest listed as Broken with `too-many`. Status: Broken when the manifest is invalid; else Unavailable when a required extension is not loaded; else Enabled when the override says so, or when there is no override and the plugin is built in; else Disabled. `builtIn` is true only for the five refs **and** when the directory is inside the application's own `plugins/` (always true in v1, since `pluginsRoot` is fixed). Scanning executes no plugin PHP.

### 4.3 Loading

```php
final class PluginLoader
{
    public function __construct(string $pluginsRoot) {}
    /** Loads and instantiates an Enabled plugin; \LogicException for any other status. */
    public function importer(PluginInfo $plugin): Importer;
    public function exporter(PluginInfo $plugin): Exporter;
}
```

On first use of a plugin in a request, `PluginLoader` calls `spl_autoload_register` with a closure that handles only class names starting with that plugin's namespace prefix, maps the rest of the name to `src/<path>.php` (each segment `[A-Z][A-Za-z0-9]{0,63}`; anything else is not loaded), checks the resolved file is inside the plugin's `src/` directory with `Fs::isInside`, and `require`s it. It then checks that the manifest's class exists, is final, and implements the interface for its kind, and instantiates it with no arguments (`new $class()`: the second allowed dynamic `new` in the code base after the kernel's controller dispatch; `tests/repo/CompositionSeamTest.php` (which refuses every computed `new` except the one in `CompositionSeamTest::DISPATCHER`) and `SqlStringsTest`'s `variable-new` rule (section 2.2) are told of it by path, in `PluginLoader.php` only). Any failure throws `PluginFailure(REASON_FAILED)` with the detail logged. A plugin is loaded at most once per request. Every job operation (read, reading options, prepare, export) rescans before loading; when the job's plugin is no longer Enabled (an Admin turned it off, or it became Broken or Unavailable), the import job goes to `Failed` with reason `plugin-unavailable` and the export gives `problem.export.failed`; the `\LogicException` of `PluginLoader` is never reached from a request.

### 4.4 Enabled state (`TiffinDB\Core\System\PluginStates`)

System schema version 2 (section 12.2) adds `plugins`. Repository (P51: no transaction of its own; refuses to change rows outside one):

```php
final class PluginStates
{
    public function __construct(SystemDatabase $system) {}
    /** @return array<string, bool> '<kind>/<id>' => enabled */
    public function all(): array;
    public function set(string $kind, string $id, bool $enabled, int $userId, string $now): void;   // INSERT ... ON CONFLICT DO UPDATE
}
```

## 5. Staging area and jobs

### 5.1 `StagingArea`

```php
final class StagingArea
{
    public const FOLDER = 'staging';
    public const KINDS = ['imports', 'exports', 'archives'];
    public const MAX_AGE = ['imports' => 86400, 'exports' => 3600, 'archives' => 3600];   // seconds
    public function __construct(DataDirectory $data, Clock $clock, Keys $keys) {}
    /** Creates <data>/staging/<kind>/<new key>/ (mode 0750) and returns [key, path]. */
    public function create(string $kind): array;
    /** The folder of an existing job; null when absent or not a real directory. Key checked with Keys::isValid. */
    public function folder(string $kind, string $key): ?string;
    public function remove(string $kind, string $key): void;
    /** Removes job folders of every kind older than MAX_AGE (by the folder's mtime); errors are logged and skipped. */
    public function sweep(): void;
}
```

`<data>/staging/` is inside the data directory, so the installer's exposure test and deny rules cover it. Every file in a job folder is named by the application: `upload.bin`, `job.sqlite` (plus its journal), `export.<extension>`, `archive.zip`, `source.tiffin`, `rebuilt/`, `tmp/`, `job.lock` (section 5.2), and `owner.json` (archive jobs, section 11.6) (the plugin's and the library's temporary folder). The uploaded file's own name is never used as a path.

`Fs` gains (M3-WP1; amends P64 for this folder only, P127):

- `Fs::removeTree(string $path, string $root): void`: `$path` must be inside `$root` and `$root` must end in `/staging` (else `\LogicException`); walks with `lstat`, never follows links (a link is removed as a link), removes files, then directories bottom-up.
- `Fs::moveIn(string $from, string $to): void`: `rename`, falling back to copy then remove when `rename` fails across devices; `$to` must not exist; result mode 0640.

### 5.2 `ImportJob` and `job.sqlite`

A job file is created by `ImportJob::create` with `Sqlite::create` (journal mode as the connection gives) and this schema (the job file is TiffinDB's own scratch file, not a documented format):

```sql
CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID;
CREATE TABLE rows (n INTEGER PRIMARY KEY, cells TEXT NOT NULL);            -- JSON list of ?string; n = data row number in the file, from 1 (gaps where empty rows were skipped)
CREATE TABLE prepared (n INTEGER PRIMARY KEY, record TEXT, skip TEXT);     -- record: JSON field key => storage value; skip: reason
CREATE TABLE issues (n INTEGER NOT NULL, col INTEGER NOT NULL, kind TEXT NOT NULL, PRIMARY KEY (n, col)) WITHOUT ROWID;
```

`meta` keys (JSON-encoded values where noted): `project` (project key), `user` (user id, integer), `plugin` (`<kind>/<id>`), `file_name`, `file_size`, `file_sha256`, `extension`, `settings` (JSON), `inspection` (JSON), `table_index`, `state` (`JobState` value), `read_rows`, `read_done`, `stats` (JSON list of `ColumnStats`), `mapping` (JSON, after review), `schema_hash` (section 6.6), `prepared_rows`, `created_at`, `report` (after commit).

```php
enum JobState: string { case Reading = 'reading'; case Review = 'review'; case Preparing = 'preparing'; case Ready = 'ready'; case Done = 'done'; case Failed = 'failed'; }
```

Transitions: `Reading` to `Review` (read done) to `Preparing` (mapping accepted) to `Ready` (all rows prepared) to `Done` (committed; folder removed after the log row is written) or back to `Review` (mapping saved again, or the target changed, section 6.6). `Failed` holds a reason code; the only way on is to start again. Changing the reading settings (part 2, reading options) resets to `Reading` with `rows`, `prepared`, and `issues` emptied.

Ownership (P134): every operation on a job checks that `meta.project` equals the project in the URL and `meta.user` equals the actor's user id; otherwise `NotFound`.

Concurrency (job lock): two requests on one job can arrive together (a double press, the auto-continue script and a manual press, two tabs). Every operation that reads or changes a job first takes an exclusive, non-blocking `flock` on `job.lock` in the job folder (created with the folder) through `ImportJob::lock()`, and reads `state` and `read_rows`/`prepared_rows` only after holding it; when the lock is held by another request the operation gives `Busy` (the M1 busy page; the Admin goes back to the import address). The lock is released at the end of the operation (and by the operating system if the request dies). `commit` additionally checks, as the first statement inside its project transaction, that `_tiffin_import_log` has no row with the job key; when it has one (an earlier commit finished but its clean-up did not), it rolls back and returns the log key, so the report is shown and nothing is imported twice.

## 6. Import pipeline (`TiffinDB\Core\Io`)

### 6.1 Upload (step 1)

`ImportOperations::start` (section 13) receives a file already on disk (the web layer passes the PHP upload's temporary path, part 2 section 2.1). The core: sweeps the staging area; creates an import job folder; `Fs::moveIn` the file to `upload.bin`; computes size and SHA-256; picks the plugin (the one the Admin chose, else the enabled import plugin whose `extensions` include the file's extension, else problem `problem.import.format_unknown`); calls `detect`, then `inspect`; refuses an inspection with no table or with a table of more than 200 columns (`problem.import.too_many_columns`); stores meta; and runs the first read chunk (section 6.2). For a CSV of at most 2,000 rows the whole read completes in this request and the next page is the review.

### 6.2 Read (chunked)

`Reader::chunk(ImportJob, Importer, IoLimits): bool` (true when done): reads rows from `read_rows` (the number of data rows the plugin has returned so far, empty rows included, so that `from` always counts in the plugin's own numbering) with `rows(file, settings, tableIndex, from, limit)` where `limit` is the rows left before the cap plus one, stopping after 2,000 rows or 5 seconds (`IoLimits::CHUNK_ROWS`, `CHUNK_SECONDS`, measured with the injected clock's monotonic source: `hrtime` through a `\Closure(): int` seam), and in one job-file transaction appends each row with `n` = its 1-based data row number in the file (`read_rows` + 1 at the time it is read; so row numbers in issues and in the report are the file's data row numbers, with gaps where empty rows were) (rows whose cells are all null or `''` are counted as `skipped.empty` and not stored) and updates `ColumnStats` (section 6.3). Each cell longer than 20,000 characters (`IoLimits::MAX_CELL_CHARS`) is stored as null and recorded as issue `too-long`. Reaching row 50,001 (`IoLimits::MAX_ROWS`) stops reading: the rows over the limit are counted (`skipped.over_limit`, only the first extra row is read, so the count is "at least one" and the report says "more than 50,000 rows; the rest were left out") and the review shows a warning.

### 6.3 Cells and inference

A cell is the text of the value as the source holds it: for CSV the field text; for XLSX the conversions of section 9.2. Inference never sees anything else.

`ColumnStats` per column, updated per non-empty cell (after `Text::trim`): `count`, `distinct` (exact up to 201 distinct values, then "more"), `firstValues` (first two distinct, at most 60 characters each, for the "First values" column), `maxLength`, `multiline` (any `\n`), and one counter per candidate type of how many cells its **lenient** parse (M1 `parse($text, $options, true)`) accepts: `checkbox` (only the words `yes`, `no`, `true`, `false`, case-insensitive: the M1 parser also accepts `1`, `0`, `on`, `off`, and `''`, which would make every 0/1 number column a checkbox), `number` (with `decimals` the largest seen, up to 6), `currency` (section 6.3.1), `date`, `date_time`, `time`, `duration` (only cells containing `:`), `url`, `email`, and `integer1to5`, `intDistinctIncreasing` (for the id rule).

`Inference::guess(string $heading, ColumnStats $s, array $existingTables): TypeGuess` returns the proposed field name, type, options, the ordered list of offered types (the type menu), and whether "Bring in" starts ticked. First rule that holds, over non-empty cells:

| # | Condition | Proposed type |
|---|---|---|
| 1 | No non-empty cell | `text` |
| 2 | The heading, normalised (trim, `_` and `-` to space, ASCII lower case), equals an existing table's name or item name (ASCII case-insensitive) and that table's title field is `text`, `choice`, `url`, `email`, or `phone` | Link to that table (relation) |
| 3 | Every cell parses as `checkbox` (words above) | `checkbox` |
| 4 | Every cell parses as `date` | `date` |
| 5 | Every cell parses as `date_time` | `date_time` |
| 6 | Every cell parses as `time` and contains `:` | `time` |
| 7 | Every cell parses as `currency` (6.3.1) | `currency` |
| 8 | Every cell parses as `number` | `number` with `decimals` = the largest seen |
| 9 | Every cell parses as `duration` (with `:`) | `duration` |
| 10 | Every cell parses as `email` | `email` |
| 11 | Every cell parses as `url` | `url` |
| 12 | `multiline` or `maxLength` over 500 | `long_text` |
| 13 | `distinct` at most 20, `distinct × 2` at most `count`, `maxLength` at most 60 | `choice` with the distinct values as labels, in first-seen order |
| 14 | otherwise | `text` |

The type menu always offers the proposed type first, then every other type whose counter equals `count` (every cell would be read), then `text` and `long_text`; `rating` (max 5) is offered when `integer1to5` equals `count`, but never proposed (P117: a 1 to 5 number column is as likely a quantity); Link to `<table>` is offered for each existing table with a text-like title field. Field name: the heading with `_` and `-` replaced by spaces, runs of spaces collapsed, the first letter upper-cased (`row_id` becomes "Row id"), cut to 60 characters; empty headings give "Column {n}"; duplicates (ASCII case-insensitive) get " 2", " 3". "Bring in" starts unticked only for an id-like column: normalised heading `id` or ending in ` id`, and `intDistinctIncreasing` equal to `count`.

#### 6.3.1 Currency rule

A cell is currency-like when it matches `^-?([$€£¥])?\d{1,3}(,\d{3})*(\.\d{1,3})?\z` or `^-?([$€£¥])?\d+(\.\d{1,3})?\z`, and the column has at least one symbol or every cell has exactly two decimals. The code is from the symbol (`$` USD, `€` EUR, `£` GBP, `¥` JPY; mixed symbols fall back to `number`), else `USD`; `minor_digits` is the largest number of decimals seen (2 when none, 0 for JPY). Before parsing, the symbol and thousands commas are removed; the result goes to the `currency` type's lenient parse.

### 6.4 Review (step 2) and the mapping

```php
final class ColumnMapping
{
    public function __construct(
        public readonly int $column,             // 0-based
        public readonly bool $include,
        public readonly ?string $fieldName,      // new table: the new field's name
        public readonly ?string $type,           // new table: a type id, or 'link:<table key>'
        public readonly ?string $fieldKey,       // existing table: the target field's key, or null to leave out
    ) {}
}
final class Mapping
{
    public function __construct(
        public readonly string $target,          // 'new' | 'existing'
        public readonly ?string $newTableName,   // 1 to 60 characters
        public readonly ?string $tableKey,       // existing table
        public readonly array $columns,          // list<ColumnMapping>, one per source column, in order
    ) {}
}
```

`MappingCheck::check(Mapping, Schema, ColumnStats[], TypeGuess[]): list<Problem>` collects every problem (field = `col-<n>` or `table`): new table name valid and not taken (`Text::nameProblems`, `problem.name.taken`); at least one column included (`problem.import.nothing_included`); new field names valid, unique among included columns (`problem.name.taken`), and not more than 500; types are field types that can hold imported values (every editable type except `auto_number`) or a link to an existing table with a text-like title field (`problem.import.type_invalid`); for an existing table: each `fieldKey` is an editable field of that table other than `auto_number`, or a relation field whose linked table has a text-like title field, and no field is chosen twice (`problem.import.field_twice`). The default mapping is built from the `TypeGuess`es with target `new` and the new table named after the file name without its extension (cut to 60 characters, "Imported rows" when empty) or, for XLSX, the sheet name; for target `existing` each column maps to the field whose name equals the column's proposed field name (ASCII case-insensitive), else left out.

`Inference` builds options for new fields: `choice` labels from the distinct values when at most 200 (`ChoiceType::MAX_CHOICES`), else the type falls back to `text` with a problem `problem.import.too_many_choices`; `number` decimals; `currency` code and digits; `text` and `long_text` `max_length` at least the column's longest value (capped by the type's maximum).

**Warnings on the review page** (P120): `Preparer::preview(Mapping, n = 5000)` runs the preparation of section 6.5 over the first 5,000 stored rows without writing, and returns per included column the number of values that will be left empty, and per link column the number of distinct titles not found (which will be added). When the job has more than 5,000 rows, the page says the counts are for the first 5,000 rows (part 2). The step-4 report has exact counts.

### 6.5 Prepare (step 3, chunked)

`Preparer::chunk(ImportJob, Mapping, Schema): bool` converts stored rows in `n` order after the last prepared one (`WHERE n > COALESCE((SELECT MAX(n) FROM prepared), 0)`; `prepared_rows` is a count for the progress bar, not an offset) in chunks (`IoLimits::CHUNK_ROWS`, `CHUNK_SECONDS`) and writes, per row, into `prepared` one JSON object of field key (for a new table: `col-<n>`, resolved at commit) to **storage** value (`toStorage(value)`), and into `issues` one row per value left empty. Per included column:

1. Empty cell (null or `''` after `Text::trim`): empty value (checkbox: false).
2. Plain field: the field type's lenient parse (`InputMode::Import`, M2) with the field's options (for a new field, the options of 6.4; for currency columns the cell is first cleaned as 6.3.1). Failure: empty value, issue `unreadable`. A `choice` value not among the labels: empty, issue `not-a-choice` (existing fields are never changed by an import, P119).
3. Link column: the cell is split on `;` when the link may hold several records (an existing relation field whose this-side max is `many`; for a new link column, always), else taken whole; each part trimmed, empties dropped; each part is a title to find. The JSON value is `{"titles": [...]}`; matching happens at commit, because matches must see the records the same import adds.

A row whose included cells are all empty after conversion is stored with `skip` = `empty`. No row is skipped for missing required values (P118).

### 6.6 Commit (step 3, final request) (security-critical: atomicity)

`Committer::commit(ImportJob, Mapping, Database $db, Schema $schema, SchemaEditor $editor, Records $records, Snapshots $snapshots, string $projectFolder, string $userKey): ImportReport`, called by `ImportOperations::commit` when the job is `Ready`:

1. Recompute `schema_hash` (SHA-256 of the JSON of the target table's field rows `[key, type, options, position]` and its relations, or of the list of table names for a new table) and compare with the hash stored when the mapping was accepted. Different: the job goes back to `Review` with `problem.import.target_changed`; nothing written.
2. `Snapshots::take($db, $projectFolder, SnapshotReason::Import)` (outside any transaction).
3. One `Database::write()`:
   0. Check `_tiffin_import_log` has no row with the job key (section 5.2), then recompute `schema_hash` inside the transaction; a difference throws `ValidationFailed(problem.import.target_changed)` and rolls back (the schema may change between step 1 and the write lock; step 1 only avoids a needless snapshot).
   1. Target `new`: `$editor->within()->createTable(name, itemName, withNameField: false)` (item name: the table name in lower case cut to 40 characters, singular not attempted, P117); for each included column in order, `addField` with the mapped name, type, options, `required` false, help `''`; for each link column, `addRelation(newTable, otherTable, thisMax 'many', otherMax 'many', names)` with this side named as mapped and the other side named after the new table (made unique); the title field is set to the first included column of type `text` (else none). The schema hook regenerates forms in the same transaction (M2 section 5.6).
   2. Build a fresh `Schema` and `Records` for the open transaction (`$records->within()`).
   3. For each link column: load the target table's live titles once (`Records::pickList` is limited to 500, so the committer reads `id`, title with one query per target table: `SELECT "id", <title> FROM <t> WHERE "_trashed_at" IS NULL`), keyed by `strtolower(Text::trim(title))` (first id wins on duplicates; a warning counts duplicates). For each title not found, in first-seen order: first parse the title with the title field's lenient parse and options (a title longer than its `max_length`, not a choice label, or not an address for a `url` or `email` title field is refused); a refused title creates no record, its links are dropped and counted as issue `link-unreadable` (and in the review warnings); otherwise `create(targetTable, [titleField => title], InputMode::Import, userKey, requiredKeys: [])` and add it to the map (`links_added`). A `ValidationFailed` from a link-target `create` therefore cannot occur for a reason the committer could have seen; if one does, the whole commit fails as below.
   4. For each prepared row by `n` (skipped rows excepted): `create(tableKey, values, InputMode::Canonical, userKey, requiredKeys: [])` with the stored values turned back with `fromStorage`; then `setLinks` for each link column with the matched ids. The committer checks before calling `setLinks` so that it never throws: a target that may link to one record only and is already taken (by an earlier record or an earlier row of this import) is dropped and counted as issue `link-taken`; ids past `Records::MAX_LINK_IDS` (1,000) in one cell are dropped and counted as issue `link-too-many`. Rows are inserted in file order, so record ids follow it.
   5. Insert the `_tiffin_import_log` row (section 6.7) with the job key as its key.
   6. `ProjectFile::touch` (also done by each `create`; harmless).
4. After the commit: audit `import.completed` (section 13.6), write `report` to the job meta, remove the job folder.

Any exception inside step 3 rolls everything back (one transaction); the snapshot stays, the job goes to `Failed` (reason `commit-failed`) or, for `Busy`, stays `Ready` so the Admin can retry. Required-field counts for the report (`required_empty`) are computed from the prepared values before the transaction.

**Joined mode (M2 amendment, M3-WP1).** M2's `SchemaEditor` and `Records` each run every public method in their own `Database::write()`, which must not nest. Both gain `within(): self`, returning a copy that runs each method inside the caller's open transaction (`\LogicException` when `$db->inTransaction()` is false) and otherwise behaves the same, including validation, the after-change hook, and `ProjectFile::touch`. Methods that take a snapshot (`deleteTable`, `deleteField`, `changeType`, `updateField` when it clears values, `Trash::empty`) throw `\LogicException` in joined mode. `Records::create` gains a last parameter `?array $requiredKeys = null`: null enforces every required field of the table (M2 behaviour); a list enforces only those keys (P118), in both of M2's checks (a required key missing from `$values`, and a required key given an empty value).

**Auto-number values (M2 defect, M3-WP1).** As built, M2's `Records::create` stores a caller's value for an `auto_number` field as given, so a duplicate reaches the field's UNIQUE index and escapes as a raw `PDOException`. M3 closes this in `Records` (WP1): in `Form` and `Import` mode a value for an `auto_number` field is refused with `problem.value.unsupported`; in `Canonical` mode (core callers only) a value already stored in that column is refused with the same key, checked inside the write before the `INSERT`, so the index is never the first line of defence. No M3 caller passes an auto-number value: `MappingCheck` never maps a column to one, and the archive rebuild (section 11.5) writes with its own statements and removes duplicates first. T-IO-13 covers both refusals.

### 6.7 Import log and report

`ImportLog::insert(Database, ImportReport)` writes one row of `docs/formats/tiffin.md` section 4.7 (literal SQL, bound values). `ImportReport` holds every column of that row plus the `report` object (section 4.7.1 of the format): per column the field key, the field name, the count of emptied values, and the first 20 row numbers; skipped counts; links added per relation field; required-empty counts. It never holds cell values. `ImportLog::get(Database, string $key): ?ImportReport` reads it back for the report page.

### 6.8 Limits (`IoLimits`)

| Constant | Value | Applies to |
|---|---|---|
| `MAX_UPLOAD_BYTES` | 67,108,864 (64 MiB), lowered to PHP's `upload_max_filesize` and `post_max_size` when those are smaller | Every upload; the screens show the effective figure |
| `MAX_ROWS` | 50,000 | Data rows per import (also the M1 type-change limit) |
| `MAX_COLUMNS` | 200 | Columns per source table |
| `MAX_CELL_CHARS` | 20,000 | Characters per cell (the `long_text` maximum) |
| `CHUNK_ROWS`, `CHUNK_SECONDS` | 2,000, 5 | Read and prepare chunks |
| `PREVIEW_ROWS` | 5,000 | Review warnings |
| `MAX_CSV_RECORD_BYTES` | 1,048,576 | One CSV record (quoted newlines included) |
| `XLSX_MAX_ENTRIES`, `XLSX_MAX_ENTRY_BYTES`, `XLSX_MAX_TOTAL_BYTES`, `XLSX_MAX_RATIO` | 10,000; 268,435,456; 536,870,912; 200 | Section 9.1 |
| `ARCHIVE_MAX_ENTRIES`, `ARCHIVE_MAX_PROJECT_BYTES`, `ARCHIVE_MAX_TOTAL_BYTES` | 100,000; 1,073,741,824; 4,294,967,296 | Section 11.3 |

All are constants (cheapest to change); the 64 MiB upload cap is also capped by free disk space at upload time (`disk_free_space` of the data directory minus 104,857,600 bytes; less gives `problem.upload.no_space`).

## 7. Changes to M2 classes (M2 assumptions)

| M2 class | Change | Package |
|---|---|---|
| `Core\Schema\SchemaEditor` | `within()` joined mode (6.6); DDL statements built through the new `Core\Schema\Ddl` (`createTable`, `addColumn`, `createJoinTable`, `indexes`), so the archive rebuild uses the same text | WP1 |
| `Core\Query\Records` | `within()`; `create(..., ?array $requiredKeys = null)` | WP1 |
| `Core\App\RecordOperations::create` | Binds the effective form itself (its private `bind()`, as `newScreen` does; as built, `create` holds no screen and the controller only filters the input) and passes `BoundForm::editableKeys()` intersected with the table's required fields as `$requiredKeys` (ADR 0017 decision 4) | WP8 |
| `Core\App\RecordListing`, `SearchResults` | New `bool $canImportExport` (`Authorizer::can(io.use)`) for the sidebar | WP8 |
| `Core\Forms\FormStore::regenerate` | Used unchanged by the archive rebuild (requires the M2 behaviour: edited forms gain slots for unnamed fields) | none |
| `Web\Sidebar::build` | New last parameter `bool $canImportExport = false` | WP3 |

`Ddl` (literal SQL plus `Identifier` only, under the existing Schema scanner rules): `table(string $tableKey): list<string>` (the `CREATE TABLE` and trash index of `docs/formats/tiffin.md` section 5), `column(FieldDef): ?string` (the `ALTER TABLE ... ADD COLUMN` with the type's `columnDefinition()`), `uniqueIndex(FieldDef): ?string` (auto-number), `joinTable(RelationDef): list<string>` (section 7, with the `l1`/`r1` indexes). T-SCH-5 (M1) runs against both `SchemaEditor` and `Ddl`.

## 8. CSV (`TiffinDB\Core\Io\Csv`, plugins `import/csv`, `export/csv`) (security-critical)

### 8.1 Reading

- `Charset::detect(string $head): string` on the first 65,536 bytes: `utf-8-bom` (EF BB BF), `utf-16le` (FF FE), `utf-16be` (FE FF), else `utf-8` when the bytes are valid UTF-8 (a multi-byte sequence cut at the end of the sample is allowed), else `windows-1252`. `Charset::decoder(string $charset): \Closure(string $bytes, bool $final): string` converts streamed bytes to UTF-8 with no extension: UTF-16 by code units (surrogate pairs joined; unpaired surrogates become U+FFFD), Windows-1252 by a 256-entry table (undefined bytes 0x81, 0x8D, 0x8F, 0x90, 0x9D become U+FFFD); UTF-8 input that turns out invalid later in the file has each invalid sequence replaced by U+FFFD and the replacements counted (a warning on the review page: "{count} characters could not be read and were replaced"). No `mbstring` or `iconv` is needed.
- `CsvDetector::delimiter(string $sample): string` over the first 50 records of the decoded sample: for each of `,`, `;`, tab, `|`, the count per record outside quotes; the delimiter with the largest number of records sharing the most common non-zero count wins, ties in that order; none: `,`.
- `CsvReader` (RFC 4180 with common variations): records end at LF, CRLF, or CR outside quotes; a field starting with `"` runs to the next `"` not followed by `"`, with `""` as one `"`, line breaks kept (CRLF and CR inside a field become LF); text after a closing quote up to the delimiter is appended as is; a quote inside an unquoted field is literal. A record over `MAX_CSV_RECORD_BYTES` stops reading with `PluginFailure(REASON_TOO_LARGE)`. NUL bytes become U+FFFD. The BOM is removed. Resumption: `rows(..., $from, ...)` restarts from the byte offset of data row `$from` kept in a small per-file offset cache inside the plugin's temp folder (`offsets.json`, every 2,000th record); without it the reader skips records from the start.
- Settings (`detect`): `encoding` (choice: UTF-8, UTF-16 little-endian, UTF-16 big-endian, Windows Latin 1), `delimiter` (comma, semicolon, tab, vertical bar), `heading` (boolean, default on). `readAs` text: "CSV, {delimiter name}, {encoding name}" in English, as the mock-up's "CSV, commas, UTF-8" (P133).
- Columns: the heading row's cells (empty headings become "Column n"), or "Column 1" to "Column n" for the widest of the first 1,000 records when `heading` is off. Shorter rows are padded with null; cells beyond the column count are dropped and counted (`issues` kind `extra-cells`, one per row).

### 8.2 Writing (`CsvWriter`, plugin `export/csv`)

- UTF-8 with BOM, delimiter `,`, records ended by CRLF, a heading row of field names, then one row per record in id order. A field is quoted when it contains `,`, `"`, CR, LF, or starts or ends with a space or tab; `"` doubled.
- Cells: `ExportValue::$text` (so dates are ISO, numbers plain with `.` decimals, currency with its minor digits and no symbol, checkbox `yes`/`no`, durations `H:MM` or `H:MM:SS`; relation and attachment cells joined with `; `).
- Settings: `record_ids` (boolean, default off: "Add a column with each record's number", written first, heading `id`), `spreadsheet_safe` (boolean, default **off**: "Protect against spreadsheet formulas"). With `spreadsheet_safe`, a cell of a text-like field (`text`, `long_text`, `url`, `email`, `phone`, `choice`, relation and attachment cells) whose text starts with `=`, `+`, `-`, `@`, tab, or CR gets a leading `'` (P122; numbers and dates are never changed, so `-5` stays a number). The default keeps exact values so that round trips hold; this default is a judgement call for the reviewer.
- Scope: `table` only (one table per file). File name: the table name with characters other than letters, digits, space, `-`, `_` removed, spaces to `-`, ASCII lower case, cut to 60, plus `.csv` (`export.csv` when empty).

## 9. XLSX (plugins `import/xlsx`, `export/xlsx`) (security-critical)

### 9.1 Zip check before the library

`TiffinDB\Plugin\Import\Xlsx\WorkbookCheck` (plugin code, using only PHP's `ZipArchive`): opens with `ZipArchive::RDONLY | ZipArchive::CHECKCONS`; refuses (`REASON_UNREADABLE`) when it cannot open or `[Content_Types].xml` or `xl/workbook.xml` is absent; refuses (`REASON_TOO_LARGE`) more than 10,000 entries, any entry declaring more than 256 MiB uncompressed, more than 512 MiB in total, or a ratio of declared uncompressed to compressed size over 200 for any entry larger than 1 MiB; refuses (`REASON_UNSUPPORTED`) encrypted entries or compression methods other than stored and deflated. Names are not used as paths (the library reads through the `zip://` wrapper), but entries whose names contain `..`, a leading `/`, `\`, or NUL are refused as unreadable. Every `.xml` and `.rels` entry the library will read is checked by streaming its prolog (the bytes before the first element start tag, at most 4,096 bytes): a `<!DOCTYPE` or `<!ENTITY` there is refused as `REASON_UNSUPPORTED` (workbook parts never declare one). The library's `expand()` calls decode entities, so this removes any reliance on the host's libxml version for internal entity expansion (section 18 item 12).

### 9.2 Reading

OpenSpout's XLSX `Reader` with `Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true, SHOULD_LOAD_MERGE_CELLS: false, tempFolder: <job tmp>)`. One source table per worksheet, in workbook order, named by the sheet name; hidden sheets are listed with `hidden` true and not chosen by default. The first row is the heading row (setting `heading`, default on); the column count is the widest of the heading row and the first 1,000 rows, capped at 200. Setting `sheet` (choice of sheet names). Cell to text:

| Library cell | Text |
|---|---|
| String, rich text | the string |
| Numeric (int) | decimal digits |
| Numeric (float) | the shortest text that reads back to the same float (`var_export`-style with `serialize_precision` -1), with any exponent expanded; `-0` becomes `0` |
| Boolean | `true` or `false` |
| Date-time | `YYYY-MM-DD` when the time is 00:00:00, else `YYYY-MM-DDTHH:MM:SS` (seconds dropped when 0) |
| Date interval | `H:MM:SS` |
| Formula | the cached value converted by the rows above; no cached value: empty, issue `formula-no-value` |
| Error | empty, issue `error-cell` |
| Empty | null |

Rows past the end of the sheet are not produced; `rows(..., $from, ...)` iterates from the first row and skips `$from` rows (the library cannot seek; acceptable at 50,000 rows, measured by WP11).

### 9.3 Writing

OpenSpout's XLSX `Writer` with `tempFolder` set to the job's `tmp/`. One sheet per table in scope (`table` or `project`), named by the table name with `[ ] : * ? / \` removed, cut to 31 characters, made unique with " 2", " 3"; a bold heading row of field names; then records in id order. Every cell is constructed explicitly; `Cell::fromValue` and `Row::fromValues` are never called (they turn a leading `=` into a formula):

| Field type | Cell |
|---|---|
| text, long text, url, email, phone, choice, relation, attachment | `StringCell` with `ExportValue::$text` |
| number, rating, auto-number | `NumericCell` (int or float) |
| currency | `NumericCell` with `minor / 10^digits` as float and the number format `#,##0.00` (zeros per `minor_digits`) |
| date | `DateTimeCell` (midnight) with format `yyyy-mm-dd` |
| date and time | `DateTimeCell` with format `yyyy-mm-dd hh:mm:ss` (or `hh:mm`) |
| time, duration | `StringCell` with the text (`HH:MM[:SS]`, `H:MM[:SS]`), so that reading back gives the same text |
| checkbox | `BooleanCell` |
| empty | `EmptyCell` |

Settings: `record_ids` (as CSV). No `spreadsheet_safe` setting: text is never a formula in XLSX. File name: as CSV with `.xlsx` (project scope: the project name).

## 10. Export (`TiffinDB\Core\Io`)

### 10.1 `ExportSourceBuilder`

`build(Database $db, Schema $schema, ProjectRecord-like facts, ?string $tableKey, Clock, string $appVersion): ExportSource` reads the open project: tables in scope (one table, or every table), fields by position, records by id in pages of 500 through `Records::page`-like reads (`SELECT r.* FROM <t> WHERE "_trashed_at" IS NULL AND "id" > ? ORDER BY "id" LIMIT 500`), links per relation field for the page's ids (live targets only, in position order), attachment ids per attachment field, linked titles per page with one query per target table. Values: `fromStorage`, `toText`. No write; no transaction needed (one read connection; `BEGIN DEFERRED` is not used, so a long export may see records added during it: acceptable).

### 10.2 Writing and download

`ExportOperations::export` (section 13) creates an `exports` job folder, asks the plugin to write `export.<extension>` there, and returns an `ExportFile { string $jobKey; string $path; string $fileName; string $mediaType; int $size }` that the web layer streams and then removes (part 2 section 2.2). A plugin failure removes the folder and gives `problem.export.failed`.

### 10.3 JSON (plugin `export/json`)

Scope `project` only; no settings. Writes `docs/formats/json-export.md` exactly, streamed with `fwrite` per record (`json_encode` with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION`, and `serialize_precision` -1 for numbers). Values per type as section 4 of that document: currency from minor units as a decimal string with exactly `minor_digits` decimals, computed with integer arithmetic; `number` values written as a raw JSON number token equal to `ExportValue::$text` (the M1 type's `toText`: plain decimal, `.` separator, never an exponent), not through `json_encode` of the float, which writes `1.0e-6` and, with `JSON_PRESERVE_ZERO_FRACTION`, `12.0`. File name: the project's file name base plus `.json`.

## 11. Project archive (`TiffinDB\Core\Io\Archive`) (security-critical)

### 11.1 Export

`ArchiveWriter::write(Database $db, string $projectFolder, string $target, ArchiveManifest $facts): void`:

1. `VACUUM INTO ?` with the bound path `<job>/project.tiffin` (outside a transaction; the same mechanism as snapshots).
2. Read `_tiffin_attachments` `sha256` values from the copy; for each whose file `attachments/<2>/<64>` is a regular file inside the project folder, add it.
3. `ZipArchive` to `<job>/archive.zip`: `tiffindb-archive.json` first (deflated), `project.tiffin` (deflated), attachment entries (stored: they are usually compressed formats). Entry names are built only from the validated SHA-256 strings.
4. The manifest's `project_sha256` and `project_size` are computed from the copy before zipping.

### 11.2 Import overview

`ArchiveOperations::import` (section 13.4): sweep; create an `archives` job; move the upload to `upload.bin`; then `ArchiveReader::open` (11.3), `UntrustedProject::check` (11.4), `ProjectRebuilder::rebuild` (11.5), registration (11.6). Any refusal removes the job folder and reports `problem.archive.<reason>` with `-` in the reason code replaced by `_` (section 14; keys follow the existing `snake_case` key style, which `MessageKeysTest` and `StringsTest` read); nothing is registered. The core maps each reason to its key with a literal table (whole string literals, so `MessageKeysTest` sees every key).

### 11.3 Zip

`ArchiveReader` with `ZipArchive::RDONLY | ZipArchive::CHECKCONS`:

1. Entry count at most 100,000. For every entry (`statIndex`): name must match `^(tiffindb-archive\.json|project\.tiffin|attachments/([0-9a-f]{2})/\2[0-9a-f]{62})\z` (the folder must equal the first two characters), or be exactly `attachments/` or `attachments/<2 hex>/` (directory entries, ignored); no duplicate names; `encryption_method` 0; `comp_method` 0 or 8; declared size of `project.tiffin` at most 1 GiB; declared total at most 4 GiB and at most free space minus 100 MiB. Any violation: refuse (`entry-name`, `duplicate`, `encrypted`, `compression`, `too-large`).
2. `tiffindb-archive.json` and `project.tiffin` must be present (`missing-entry`).
3. Every extraction streams `getStream($name)` into an application-named file (`manifest.json`, `source.tiffin`, `files/<sha256>`) with `fopen(..., 'xb')`, counting bytes and stopping with `too-large` when the count passes the declared size or the cap (a lying header cannot make the copy larger than its declared size).
4. The manifest (at most 16 KiB) must decode to exactly the members of `docs/formats/tiffin.md` section 13.2 with valid values; `project_format_version` 1 to 3; `project_size` and `project_sha256` must equal the extracted copy (`manifest`).

### 11.4 `UntrustedProject::check(string $path): UntrustedProject`

Any `PDOException` raised while opening or reading the copy (for example a schema naming an unknown collation, which SQLite reports when it first parses the schema) refuses the archive as `damaged`; it never reaches the request as an error page.

1. The first 16 bytes are `SQLite format 3\0`; bytes 18 and 19 are 1 or 2; when 2 (WAL), both are rewritten to 1 in the copy (rollback journal), so it opens read-only without a `-wal` file (`not-sqlite`).
2. Open with `Sqlite::openUntrusted($path)` (M3-WP1): PDO with `SQLITE_OPEN_READONLY`, exceptions on, then `PRAGMA trusted_schema = OFF`, `PRAGMA query_only = ON`, `PRAGMA cell_size_check = ON`, `PRAGMA mmap_size = 0`; no `journal_mode`, `foreign_keys`, or other writes. (`Sqlite::open` sets WAL mode, which writes, and is never used on an untrusted file.)
3. `PRAGMA integrity_check(1)` returns exactly `ok` (`damaged`).
4. `PRAGMA application_id` = 1413891138, `PRAGMA user_version` 1 to 3 and equal to the manifest's, `_tiffin_meta` `format` = `tiffindb-project` and `format_version` = `user_version` (`not-project`, `newer`).
5. `sqlite_master`: no row of type `trigger` or `view`; no `sql` starting with `CREATE VIRTUAL TABLE` (ASCII case-insensitive); every `table` name is one of the `_tiffin_*` names of that version, `sqlite_sequence`, `sqlite_stat1`, or `t_<key>` / `j_<key>` with a valid key; every `index` name is a `_tiffin_*` index of that version, `sqlite_autoindex_<table>_<n>` of an allowed table, or `x_<allowed object>_<purpose>` (`unexpected-object`).
6. Metadata read with explicit column lists (`_tiffin_tables`, `_tiffin_fields`, `_tiffin_relations`, `_tiffin_attachments`, `_tiffin_attachment_links`, `_tiffin_forms` from version 2) and validated: keys valid and unique across tables, fields, relations, and forms; `sql_name` equal to the prefix plus key (null for relation and attachment fields); names by `Text::nameProblems` limits and unique as the M1 indexes require; types known; `FieldType::normaliseOptions` accepts the decoded options (problems refuse); relation rows consistent with their two relation fields (`relation` and `side` options, tables, `left_max`/`right_max` in `one`, `many`); `title_field` a field of the table whose type `canBeTitle()`; positions positive; at most 200 tables and 500 fields per table; every `t_<key>` and `j_<key>` named by metadata exists and no other does (`bad-metadata` with the first failing rule logged).
7. `PRAGMA table_xinfo` of each user table: exactly `id`, the six system columns, and one `f_<key>` per field with a column, every `hidden` 0 (no generated or hidden columns); each join table exactly `left_id`, `right_id`, `position` (`bad-table`).

### 11.5 `ProjectRebuilder::rebuild(UntrustedProject $src, string $folder, string $newKey, string $name, string $userKey): RebuildReport`

In `<job>/rebuilt/` (later moved to `projects/<newKey>/`): make `attachments/` and `snapshots/`; `ProjectFile::create` at the current version (writes meta with `$newKey` and `$name`); then one `Database::write()` on the new file:

1. Insert `_tiffin_tables`, `_tiffin_fields`, `_tiffin_relations` rows from the validated metadata (literal `INSERT` statements, bound values; options re-encoded from the normalised array; times passed through only when they match the timestamp pattern, else now).
2. Create each user table, column, unique index, and join table with `Ddl` (section 7).
3. Copy records table by table in pages of 1,000 ordered by `id` (`SELECT <explicit quoted columns> FROM <t> WHERE "id" > ? ORDER BY "id" LIMIT 1000` on the source): `id` must be an integer from 1 to 2^63 - 1; system times kept when they match the pattern, else now (counted); person keys kept when valid keys, else null; `_trashed_at`/`_trashed_by` kept as a pair (one without the other: both null). Each field value: `fromStorage`, then `check` with the field's options; failure: empty (checkbox 0), counted as `values_emptied` per field. Auto-number duplicates within a column: later ones emptied and counted. Insert with explicit `id`. Then set each auto-number field's `options.next` to `max(next, MAX + 1)`; set `sqlite_sequence` for the table to the largest id (so new ids continue after it).
4. Join rows in pages: both ids must exist in their tables (live or trashed); duplicate pairs dropped; a side limited to one link keeps the row with the lowest `position`, then lowest other id, and drops the rest (`links_dropped`); `position` an integer, else 0.
5. `_tiffin_attachments` rows: `id` positive integer, `sha256` 64 lower-case hex, `size` 0 or more, `media_type` matching `^[a-z0-9.+-]+/[a-z0-9.+-]+\z` (else `application/octet-stream`), `original_name` 1 to 255 characters with no control characters and no `/` or `\` (else `file`), `created_at` as above, `created_by` valid key or null. `_tiffin_attachment_links` rows whose field is an attachment field, record exists in that field's table, and attachment exists; duplicates dropped.
6. Forms (source version 2 and 3): each row with a valid key, a table key that exists, `format` 1, and `html` that `FormParser::parse` accepts is inserted with `css` `''` and its `is_default` and `generated` flags (at most one default per table: the first by key wins); any other row is dropped (`forms_dropped`). Then `FormStore::regenerate($schema, every table key)` (M2 section 5.5): generated forms are rewritten, missing default forms inserted, and **each edited form gains a slot for every field it does not name** (`forms_extended` counts forms whose HTML changed).
7. One `_tiffin_import_log` row: `source` `archive`, `file_name` the upload's name, size, SHA-256, `rows_read` and `rows_imported` the total records copied, `values_emptied`, `report.archive` with the rebuild counts.
8. Meta: `created_at`, `created_by`, `created_with` from the source when valid; `updated_at`, `updated_by` now and the importer.
9. After the transaction: `PRAGMA integrity_check` and `PRAGMA foreign_key_check` on the new file return no problems (else `rebuild-failed`).

Attachment files: for each attachment row, the entry `attachments/<2>/<sha256>` when present is streamed to `rebuilt/attachments/<2>/<sha256>` through a hashing stream; the SHA-256 and size must equal the name and the row (else the file is not kept and counted as missing); entries no row lists are skipped. The source's import log is not copied (ADR 0017 decision 2).

### 11.6 Registration

As M1 project creation (M1 spec 9.4.1, `ProjectOperations::create`): registry row in state `creating` with the new key and the name (name checked against the registry inside the write; taken: `problem.name.taken`, the job kept so the Admin can choose another name), `Fs::moveIn` of the `rebuilt` folder to `projects/<key>/` (same file system, a `rename`), `activate`. Failure after the registry row: undo as M1 does. No grants are created. The `archives` job folder holds `owner.json` (`{"user": <id>}`, written when the job is created); `importWithName` gives `NotFound` unless it names the actor (as P134 for import jobs), and takes the job lock of section 5.2. Audit `project.created` and `archive.imported`.

## 12. Project format version 3 and system schema version 2 in code

### 12.1 Project format

- `ProjectFile::VERSION` = 3; `create` runs the three SQL blocks of `docs/formats/tiffin.md` section 4 in order and writes `format_version` 3 and `PRAGMA user_version = 3`.
- `FormatMigrations::STEPS[3]` holds the two statements of section 11.2 (`CREATE TABLE _tiffin_import_log ...`, `CREATE INDEX ...`), `VERSION_PRAGMAS[3]` = `'PRAGMA user_version = 3'`. A version 1 file upgrades through steps 2 and 3, each in its own transaction, after one `format-upgrade` snapshot (M1 mechanism).
- `SnapshotReason::Import = 'import'` (automatic, pruned with the others).

### 12.2 System schema version 2

`SystemSchema::STEPS[2]` (the M1 mechanism copies the database before migrating):

```sql
CREATE TABLE plugins (
    kind       TEXT NOT NULL,
    id         TEXT NOT NULL,
    enabled    INTEGER NOT NULL,
    changed_at TEXT NOT NULL,
    changed_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
    PRIMARY KEY (kind, id)
) WITHOUT ROWID
```

`SystemSchema::VERSION` = 2. Values are validated in code (ADR 0011 decision 2): `kind` `import` or `export`, `id` the plugin id pattern, `enabled` 0 or 1.

## 13. Core operations (`TiffinDB\Core\App`)

`Operations` gains `imports(): ImportOperations`, `exports(): ExportOperations`, `archives(): ArchiveOperations`, `plugins(): PluginOperations`; the constructor keeps six required arguments (P78 seam test). `Context` gains `staging(): StagingArea`, `pluginScanner(): PluginScanner`, `pluginLoader(): PluginLoader`, `pluginsRoot` (the application's `plugins/` directory, passed by `CoreServices`). Every method takes `Actor $actor` first and starts with the permission check. A granted Member gets `AccessDenied` from every method of this section (`io.use` and `project.create` are Admin-only; M1 permission table), an ungranted Member `NotFound` for project-scoped methods. Job keys that are not valid keys, jobs of another project or person, and absent jobs give `NotFound`.

### 13.1 `ImportOperations` (permission `io.use` on the project)

| Method (after `Actor $actor, string $projectKey`) | Returns or does |
|---|---|
| `startScreen()` | `ImportStart { ProjectRecord $project; list<PluginChoice> $formats (enabled import plugins: ref, name, extensions); int $maxBytes; list<TableSummary> $tables }` |
| `start(string $uploadPath, string $clientName, ?string $pluginRef)` | `string` job key. `$clientName` trimmed, control characters removed, cut to 255 characters, `file` when empty; `ValidationFailed` with `problem.upload.*` / `problem.import.*` (section 14) |
| `job(string $jobKey)` | `ImportJobView { state; ?int readRows; bool readDone; ?string failure; ... }` used to route the screens |
| `readChunk(string $jobKey)` | `ImportProgress { int $done; ?int $total; bool $finished }` |
| `readingOptions(string $jobKey)` / `setReadingOptions(string $jobKey, array $values)` | `ReadingScreen { list<Setting> $settings; list<SourceTable> $tables; int $tableIndex; string $readAs }` / void (resets to Reading) |
| `review(string $jobKey, ?array $input, array $problems)` | `ImportReview` (below); `$input` re-shows posted values |
| `saveMapping(string $jobKey, array $input)` | void; `ValidationFailed` with every `MappingCheck` problem; sets `Preparing` and stores `schema_hash` |
| `prepareChunk(string $jobKey)` | `ImportProgress` |
| `commit(string $jobKey)` | `string` the log key (= job key); `ValidationFailed(problem.import.target_changed)` returns the job to Review |
| `report(string $logKey)` | `ImportReportView { ProjectRecord $project; TableDef? $table (null when deleted since); ImportReport $report; list<ReportColumn> $columns }` from `_tiffin_import_log` |
| `cancel(string $jobKey)` | void; removes the job folder |

`ImportReview`: `project`, `fileName`, `readAs`, `rows` (data rows stored), `headingRow` (bool), `columnCount`, `overLimit` (bool), `replacedCharacters`, `target` (`new`|`existing`), `newTableName`, `tableKey`, `tables` (`list<TableSummary>` for the existing-table menu), `columns` (`list<ReviewColumn { int $index; string $heading; bool $include; string $fieldName; string $type; list<TypeChoice> $typeChoices; ?string $fieldKey; list<FieldChoice> $fieldChoices; list<string> $firstValues; bool $leftOutByDefault }>`), `warnings` (`list<ReviewWarning { string $kind; array $params }>`: `unreadable` with column and count, `links_added` with table and count, `over_limit`, `replaced`, `preview_partial` with 5,000), `problems`, `importCount` (rows that will be imported), `previewPartial` (bool).

### 13.2 `ExportOperations` (permission `io.use`)

| Method | Returns |
|---|---|
| `screen(Actor, string $projectKey)` | `ExportScreen { ProjectRecord $project; list<TableSummary> $tables; list<ExportFormat { string $ref; string $name; array $scopes; list<Setting> $tableSettings; list<Setting> $projectSettings }> $formats; bool $archive (always true) }` |
| `export(Actor, string $projectKey, string $formatRef, ?string $tableKey, array $settings)` | `ExportFile`; `ValidationFailed` for an unknown or disabled format, a scope the format does not offer, or an unknown table; audit `export.created` (detail: format ref, table key or `project`, record count) |
| `archive(Actor, string $projectKey)` | `ExportFile` (`application/zip`, file name base plus `.tiffindb.zip`); audit `archive.exported` |
| `finish(Actor, string $projectKey, string $jobKey)` | void; removes the export job folder after the download was sent (called by the web layer's after-send callback); unknown or absent jobs are ignored |

### 13.3 `PluginOperations` (permission `plugins.manage`, instance-wide)

| Method | Returns or does |
|---|---|
| `list(Actor)` | `list<PluginInfo>` (scan with the stored overrides) |
| `get(Actor, string $kind, string $id)` | `PluginInfo`; `NotFound` when not discovered |
| `enable(Actor, string $kind, string $id)` / `disable(...)` | void; refuses Broken and Unavailable plugins (`problem.plugin.not_available`); a no-op when already in that state (not audited); otherwise `PluginStates::set` inside a system write with `requireCurrentAdmin`, then audit |

### 13.4 `ArchiveOperations`

| Method | Permission | Returns |
|---|---|---|
| `importScreen(Actor)` | `project.create` | `ArchiveImportScreen { int $maxBytes }` |
| `import(Actor, string $uploadPath, string $clientName, ?string $name)` | `project.create` | `ArchiveImportResult { ProjectRecord $project; RebuildReport $report }`; `ValidationFailed` with `problem.archive.*`, or `problem.name.taken` together with a kept job key so the screen can ask for another name |
| `importWithName(Actor, string $jobKey, string $name)` | `project.create` | the same, reusing the checked job |

`RebuildReport`: `tables`, `records`, `valuesEmptied` (per field name), `linksDropped`, `formsDropped`, `formsExtended`, `filesMissing`, `filesSkipped`, `timesReplaced`.

### 13.5 Snapshots and locking

`commit` takes the `import` snapshot through `Snapshots::take` and records it like other automatic snapshots (`Context::recordSnapshot`, audit `snapshot.taken`). Read and prepare chunks never open the project for writing; the commit is the only project write.

### 13.6 Audit actions (amends ADR 0012 decision 4, P128)

`AuditAction` gains `IMPORT_COMPLETED = 'import.completed'` (target `table`, detail: plugin ref, rows imported, new table flag), `EXPORT_CREATED = 'export.created'`, `ARCHIVE_EXPORTED = 'archive.exported'`, `ARCHIVE_IMPORTED = 'archive.imported'` (target `project`), `PLUGIN_ENABLED = 'plugin.enabled'`, `PLUGIN_DISABLED = 'plugin.disabled'` (target kind `plugin`, target key `<kind>/<id>`). No entry holds a cell value or a file name.

## 14. Message keys produced by the core (new)

English text in `strings/en/problems.php` (owned by M3-WP3), exactly:

| Key | Text |
|---|---|
| `problem.upload.missing` | Choose a file. |
| `problem.upload.too_large` | That file is larger than {max}, the largest this installation accepts. |
| `problem.upload.failed` | The file did not arrive completely. Try again. |
| `problem.upload.no_space` | There is not enough free disk space for that file. |
| `problem.import.format_unknown` | TiffinDB cannot tell how to read this file. Choose a format. |
| `problem.import.unreadable` | This file could not be read as {format}. |
| `problem.import.unsupported` | This file uses something TiffinDB cannot read yet. |
| `problem.import.failed` | The file could not be read. |
| `problem.import.too_many_columns` | The file has more than {max} columns. |
| `problem.import.nothing_included` | Choose at least one column to bring in. |
| `problem.import.type_invalid` | Choose a type for this column. |
| `problem.import.field_twice` | Each field can take only one column. |
| `problem.import.too_many_choices` | This column has more than {max} different values, so it cannot be a choice. |
| `problem.import.target_changed` | The table changed while you were importing. Check the fields again. |
| `problem.export.failed` | The export could not be made. |
| `problem.archive.entry_name` | This archive holds a file that does not belong in a TiffinDB archive. |
| `problem.archive.duplicate` | This archive holds the same file twice. |
| `problem.archive.encrypted` | This archive is encrypted. TiffinDB cannot open encrypted archives. |
| `problem.archive.compression` | This archive uses a kind of compression TiffinDB cannot read. |
| `problem.archive.too_large` | This archive is too large to import. |
| `problem.archive.missing_entry` | This is not a TiffinDB archive: the project file or its description is missing. |
| `problem.archive.manifest` | This archive's description does not match its project file. |
| `problem.archive.not_sqlite` | The project file in this archive is not a database. |
| `problem.archive.damaged` | The project file in this archive is damaged. |
| `problem.archive.not_project` | The file in this archive is not a TiffinDB project. |
| `problem.archive.newer` | This archive was made by a newer version of TiffinDB. |
| `problem.archive.unexpected_object` | The project file in this archive contains something TiffinDB does not allow. |
| `problem.archive.bad_metadata` | The project file in this archive has a table or field TiffinDB cannot read. |
| `problem.archive.bad_table` | The project file in this archive has a table that does not match its fields. |
| `problem.archive.rebuild_failed` | The project could not be rebuilt from this archive. |
| `problem.plugin.not_available` | This plugin cannot be turned on. |

M1 keys reused: `problem.name.*`, `problem.text.*`, `problem.value.unsupported` (section 6.6). T-WEB-19 (`StringsTest`) is extended with this table, and `tests/core/app/MessageKeysTest.php` (WP1) reads this section as it reads M1 section 12 and M2 section 8.

## 15. Test plan

All tests offline and deterministic with `TempDir`, `FixedClock`, `SeededRandom`; synthetic data only. Files for import tests are generated in the test (CSV text, XLSX workbooks written with the shipped library **and** workbooks assembled by hand from XML strings with `ZipArchive`, archives made by `ArchiveWriter` and by hand); no binary fixture is committed. `tests/Support/IoFixture.php` (WP11, with a smaller seed-only version in WP1) generates a "Home Inventory" project and matching CSV text from a documented seed (`IoFixture::SEED = 20261119`).

### 15.1 Core pipeline (`tests/core/io/`, WP1)

| ID | Asserts |
|---|---|
| T-IO-1 | Contract values: `Setting` and `Settings::with` refuse invalid names, kinds, and values; `SourceTable` column limits |
| T-IO-2 | `StagingArea`: create, folder (invalid and absent keys null), remove, sweep by age per kind; `Fs::removeTree` refuses a root not ending in `/staging` and a path outside it, removes links as links (a link to a file outside is removed, the target survives) |
| T-IO-3 | `ImportJob`: schema, meta round trip, state transitions (invalid ones throw), ownership check |
| T-IO-4 | Read loop with a fake importer: chunks of 2,000 and the time limit (seam), empty rows skipped and counted, cell length cap, row cap with the over-limit flag, `ColumnStats` exact after resumption equals stats of one pass |
| T-IO-5 | Inference table (section 6.3) row by row with seeded columns, including the checkbox word set, currency symbols and two-decimal rule, the id rule, choice thresholds, link by heading, field name normalisation and de-duplication, rating offered but not proposed |
| T-IO-6 | `MappingCheck`: every problem at once; defaults for new and existing targets |
| T-IO-7 | `Preparer`: every editable type in Import mode, unreadable values emptied with issues, choice labels outside the list, link splitting, empty rows; `preview` over the first 5,000 rows equals `chunk` counts on those rows |
| T-IO-8 | Commit to a new table: table, fields, relation, title field, forms regenerated, records in file order, link targets created once per title (case-insensitive), log row exact (format document 4.7), snapshot `import` exists before the transaction |
| T-IO-9 | Commit to an existing table: values into the mapped fields, unmapped fields empty, required fields not enforced and counted, links to a one-side target already taken dropped and counted, link titles the target's title field refuses (too long, not a choice) dropped and counted without failing the commit, more than 1,000 titles in a cell capped |
| T-IO-10 | Target changed between review and commit (field added on a second connection): back to Review with the problem; nothing written; no snapshot |
| T-IO-11 | `Busy` during commit leaves the job Ready and the file unchanged; with the job lock held by another connection every job operation gives `Busy`; a second commit of a job whose log row exists returns the log key and imports nothing; a commit whose schema changes between step 1 and the write lock rolls back with `target_changed` |
| T-IO-12 | Atomicity: a failure injected (test seam) after each sub-step of 6.6 step 3 (table created, fields added, link targets created, half the rows inserted, log row) leaves every table, row, form, and `_tiffin_*` row byte-identical to before (compared table by table) |
| T-IO-13 | Joined mode: `SchemaEditor::within()` and `Records::within()` refuse outside a transaction, refuse snapshot-taking methods, and run the after-change hook; `Records::create` with `requiredKeys` null, a subset, and `[]` (missing key and empty value); an `auto_number` value refused in `Import` mode and a duplicate refused in `Canonical` mode with `problem.value.unsupported`, never a `PDOException` |
| T-IO-14 | `ExportSourceBuilder`: scope, record order, paging over 1,234 records, live links only, titles and untitled records, attachment names, values and texts per type |
| T-FMT-4 | `ProjectFile::create` writes version 3; `sqlite_master` SQL of every `_tiffin_*` object equals the three SQL blocks of section 4 |
| T-FMT-5 | A version 2 file (built from blocks 1 and 2) opens: one snapshot, version 3, `_tiffin_import_log` empty, data unchanged, `integrity_check` and `foreign_key_check` pass; a version 1 file passes through 2 to 3; section 11.2 statements equal `STEPS[3]` and `VERSION_PRAGMAS[3]` |
| T-SCH-20 | `Ddl` output equals what M2's `SchemaEditor` wrote before the refactor for every type, relation cardinality, and self-relation (sqlite_master compared) |

### 15.2 Plugins (`tests/core/io/plugin/`, `tests/repo/`, WP2)

| ID | Asserts |
|---|---|
| T-PLG-1 | `ManifestReader` accepts the five shipped manifests (read from `plugins/`) and a synthetic third-party one |
| T-PLG-2 | Rejection corpus with reason codes: not JSON, too large, depth over 8, unknown member, missing member, wrong api, id not the directory name, bad id pattern, kind mismatch, class outside the namespace, class with `\..`, licence off the list, export without media type or scopes, bad extension, manifest a symbolic link, `src` a link out of the plugin directory, class file missing |
| T-PLG-3 | `PluginScanner`: statuses (enabled built-in, disabled third-party, override both ways, unavailable with a missing extension through the seam, broken), ordering, links and files in `plugins/import/` ignored, more than 100 entries |
| T-PLG-4 | Scanning executes no PHP: a third-party plugin whose class file has a top-level side effect (writes a marker file) leaves no marker after `scan`, and leaves one only after `importer()` on the enabled plugin |
| T-PLG-5 | `PluginLoader`: refuses non-Enabled plugins; loads only classes under the plugin's prefix; a class name with path tricks is not loaded; class not final or not implementing the interface fails; loaded once per request |
| T-PLG-6 | `PluginStates`: set, all, refuses outside a transaction; system schema version 2 migration from version 1 (copy taken, table created, `SystemSchema::VERSION` 2) |
| T-PLG-7 | `CoreLayerRulesTest` rows of section 2.1 |
| T-PLG-8 | `PluginLayerRulesTest`: files under `plugins/` name only the allowed namespaces; no network functions (the M0 rule's list), no SQL, no superglobals, no `header()` |

### 15.3 CSV (`tests/core/io/csv/`, `tests/plugins/csv/`, WP4)

| ID | Asserts |
|---|---|
| T-CSV-1 | Charset detection and decoding: BOMs, UTF-16 surrogate pairs and unpaired surrogates, every Windows-1252 byte, invalid UTF-8 replaced and counted, sequences split across chunk boundaries |
| T-CSV-2 | Delimiter detection on seeded samples for each delimiter, quoted delimiters ignored, ties |
| T-CSV-3 | Reader: RFC 4180 corpus (quotes, doubled quotes, embedded CR, LF, CRLF, trailing delimiter, empty fields, no final newline, CR-only files), padding and extra cells, NUL, record size cap |
| T-CSV-4 | Resumption: rows from any offset equal the same rows of one pass, with and without the offset cache |
| T-CSV-5 | Writer: quoting rules, BOM, CRLF, heading, record ids option |
| T-CSV-6 | Spreadsheet-safe option on every text-like type and its absence on numbers and dates; default off writes exact values |
| T-CSV-7 | Importer plugin through the contract: detect, inspect (`readAs` text), rows |
| T-CSV-8 | Exporter plugin through the contract with an `ExportSource` from `IoFixture` |
| T-CSV-9 | Hostile corpus: a 10 MB single quoted field, a million delimiters on one line, deeply alternating quotes, binary data, a file of only BOM, a UTF-16 file with odd length; each ends with a `PluginFailure` or a correct read, within 5 seconds and 64 MB memory |

### 15.4 XLSX (`tests/plugins/xlsx/`, WP5)

| ID | Asserts |
|---|---|
| T-XLSX-1 | `WorkbookCheck` corpus: not a zip, missing parts, too many entries, oversized and high-ratio entries (declared), encrypted entry, unsupported method, `..` names |
| T-XLSX-2 | Reading a hand-assembled workbook (XML strings, shared and inline strings, numbers including floats like 0.1 and 1e-7, booleans, a date and a date-time with 1900 and 1904 systems, a formula with and without cached value, an error cell, empty cells, a hidden sheet, rich text) gives exactly the texts of section 9.2 |
| T-XLSX-3 | Writer: every type's cell class and format (read back by parsing the written XML directly, not with the library); a text value `=1+1` is a string cell, never a formula |
| T-XLSX-4 | Temporary files go only to the job's `tmp/` |
| T-XLSX-5 | Plugins report Unavailable without `dom` (seam) |
| T-XLSX-6 | Hostile corpus: a zip bomb with honest headers refused by the check; a worksheet, shared-strings part, or workbook part with a DOCTYPE (an external entity, and separately a nested internal-entity expansion) refused by the check before the library opens it (no file read, no network, no expansion); 50,000 rows by 20 columns read within the chunk limits and memory 128 MB |

### 15.5 JSON and archive (WP6, WP7)

| ID | Asserts |
|---|---|
| T-JSON-1 | Export of `IoFixture` validates against `docs/formats/json-export.md`: member names and order read from the document's example, value form per type (table of section 4 read from the document), every record and link present, trashed records and links to them absent |
| T-JSON-2 | Currency strings exact for large and negative amounts and `minor_digits` 0 to 3; numbers with 15 significant digits; streaming (memory flat over 20,000 records) |
| T-ARC-1 | `ArchiveWriter`: entries exactly as the format, manifest values, consistent copy while another connection writes (WAL), no `-wal` entry, missing attachment files skipped |
| T-ARC-2 | Import of a written archive: new key, no grants, keys and ids kept, counts zero, `_tiffin_import_log` one `archive` row, project opens through `ProjectOpener` |
| T-ARC-3 | Zip corpus: traversal names (`../x`, `/x`, `attachments/../../x`, backslashes, NUL), an extra entry, a duplicate, an encrypted entry, bzip2 method, a lying size header (actual bytes larger), entry count over the cap, a symlink entry (external attributes), missing manifest |
| T-ARC-4 | Manifest corpus: extra member, wrong version, size or hash mismatch, version 4 |
| T-ARC-5 | SQLite corpus (each built by plain SQL in the test): not SQLite, an index using an unknown collation (refused as `damaged`, no error page), WAL header (accepted after the rewrite), integrity failure, wrong `application_id`, version 4, a trigger, a view, a virtual table, an extra table, an index with a foreign name, a generated column, an extra column, a missing column, a `CHECK` constraint calling a function on a user table (refused by the column rule or, if the columns match, never executed because the rebuild uses `Ddl`), a field with an unknown type, bad options, a relation pointing at a missing field, a title field of a non-title type |
| T-ARC-6 | Values: a text value with control characters, a number over the limit, an invalid date, a currency real, a choice label not in the list, an auto-number duplicate, a bad timestamp, a bad person key: each emptied or replaced and counted |
| T-ARC-7 | Forms: a stored form with `<script>` dropped and replaced by the generated layout; an edited form (`generated` 0) missing a required field gains the slot; the bound form then shows the field and `RecordOperations::create` succeeds with the field filled; a second default form dropped |
| T-ARC-8 | Attachments: hash or size mismatch not kept and counted; unlisted entry skipped; listed but absent counted |
| T-ARC-9 | Name taken: job kept, `importWithName` succeeds; a failure after the registry row leaves no project folder and no registry row |

### 15.6 Core operations (`tests/core/app/`, WP8)

| ID | Asserts |
|---|---|
| T-APP-90 | Every method of `ImportOperations`, `ExportOperations`, `ArchiveOperations`, `PluginOperations`: Admin allowed; granted Member `AccessDenied`; ungranted Member `NotFound` (project-scoped); invalid project and job keys, another person's job, another project's job `NotFound` (data provider) |
| T-APP-91 | Import end to end through the operations with a CSV from `IoFixture`: start, read chunks, review defaults, save mapping, prepare chunks, commit, report |
| T-APP-92 | Export through the operations for each format and scope; disabled plugin refused; audit entries without values or file names |
| T-APP-93 | Archive export then import through the operations; audit entries |
| T-APP-94 | Plugins: enable and disable with audit, no-op not audited, Broken and Unavailable refused, deactivated actor refused |
| T-APP-95 | `RecordOperations::create` with a stored edited form that omits a required field: the record is created (ADR 0017 decision 4); with the field on the form and empty, `problem.value.required` |
| T-APP-96 | `RecordListing::$canImportExport` true for Admin, false for Member |
| T-APP-97 | Import snapshot recorded (`snapshot.taken`) and listed by `SnapshotOperations::list` with reason `import` |

### 15.7 Round trips and end to end (WP11)

| ID | Asserts |
|---|---|
| T-RT-1 | CSV text from `IoFixture` (canonical forms, all importable types, a link column to Rooms) imported into a new table with the default mapping, exported as CSV: byte-identical |
| T-RT-2 | XLSX written by the export plugin from the fixture project, imported into a new project table by table, exported again: every cell equal (read with the hand parser); and a hand-assembled workbook imported gives the same records as the equivalent CSV |
| T-RT-3 | JSON export of the fixture project equals values read from the database by plain SQL and satisfies T-JSON-1 |
| T-RT-4 | Archive export then import: every `_tiffin_*` row (except meta keys `project_key`, `project_name`, `updated_*`, and the import log), every user table row, every join row, every attachment file equal |
| T-RT-5 | JSON export of the original and of the archive-imported project equal apart from `project.key`, `project.name`, `project.updated_at`, `exported_at` |
| T-RT-6 | Timing (reported, not asserted): 50,000-row CSV import read, prepare, and commit times; 50,000-row XLSX read; archive import of the fixture scaled to 50,000 records |

Web tests (T-WEB-40 to T-WEB-47, T-SCR-3, T-KBD-2, T-E2E-8 to T-E2E-10) are in part 2 section 10. Dependency tests T-DEP-1 and T-DEP-2 are in M3-WP0.

## 16. Work packages

Tiers per orchestration guide section 2. Owned files may be created or changed only by that package; packages that can run at the same time own disjoint files (check at the end). Every package starts with `git merge --ff-only <assigned branch>`, runs `composer test` and `composer lint` before committing and before reporting, and may not weaken an existing test. A package may add private helpers inside its own directories and must report them.

Waves (at most three agents at once):

| Wave | Packages | Starts when |
|---|---|---|
| 1 | WP1, WP3, WP0 | M2 CLOSED and M3-D2 merged; for WP0, the shared offline `vendor/` already holds OpenSpout v5.2.2 (T-DEP-1 checks that listed packages are installed, so every later package's suite needs it) |
| 1b | WP2, then WP7 | WP1 merged (WP2 implements `Core\Io\Contract` interfaces and lives in the `Io` layer row, both WP1's) |
| 2 | WP4, WP6, then WP5 | WP1 and WP2 merged (WP5 also WP0) |
| 3 | WP8 | WP2, WP4, WP6, WP7 merged (WP5 may still run) |
| 4 | WP9, WP10 | WP3 and WP8 merged |
| 5 | WP11 | WP5, WP9, WP10 merged |

### M3-WP0: Shipped dependency

| | |
|---|---|
| Tier | implementation |
| Depends on | probe (done; ADR 0016) |
| Security-critical | Yes (supply chain) |
| Owned files | `composer.json`; `docs/dependencies.md`; `tests/repo/ComposerManifestTest.php`, `tests/repo/DependencyLicenceTest.php`; `src/Core/Install/Installer.php` (requirement rows only); `strings/en/setup.php` (requirement strings only); `tests/core/install/RequirementsTest.php`; `tools/host-check/host-check.php`, `tools/host-check/README.md`, `tests/tools/HostCheckTest.php`; `.github/workflows/ci.yml` (only if `composer validate` or install needs a change) |
| Scope | ADR 0016 decisions 2 and 3: pin `openspout/openspout` `5.2.2`; dependencies table row (version, commit `95403917927ef252f7ae82d919c55286a642962a`, licence `MIT AND Apache-2.0`, both licence files with SHA-256, extensions); T-DEP-1 (every installed non-dev package is listed with the exact installed version and source reference read from `vendor/composer/installed.json`; listed packages are installed), T-DEP-2 (declared licences on the allowlist; the dependencies table licence column for OpenSpout names both licences); installer warnings for `dom`, `filter`, `libxml`; host-check rows |
| Acceptance | T-DEP-1, T-DEP-2, updated manifest and requirement tests pass with the orchestrator's vendor; design-tier review |

### M3-WP1: `Core\Io` foundation and import pipeline; format version 3

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (atomic commit, SQL from identifiers, file handling in the staging area) |
| Owned files | `src/Core/Io/Contract/*`; `src/Core/Io/*.php` (top level, section 2); `src/Core/Schema/Ddl.php` (new), `src/Core/Schema/SchemaEditor.php`, `src/Core/Schema/ProjectFile.php`, `src/Core/Schema/FormatMigrations.php`; `src/Core/Query/Records.php`; `src/Core/Storage/Fs.php`, `src/Core/Storage/Sqlite.php`, `src/Core/Storage/SnapshotReason.php`; `tests/core/io/*.php` (top level); `tests/core/schema/ProjectFileTest.php`, `tests/core/schema/FormatUpgradeTest.php`, `tests/core/schema/DdlTest.php` (new), `tests/core/schema/SqlStringsTest.php`; `tests/core/query/RecordsJoinedTest.php` (new); `tests/core/storage/FsTreeTest.php` (new), `tests/core/storage/SqliteUntrustedTest.php` (new), `tests/core/storage/SnapshotsTest.php` (the reason list only); `tests/repo/CoreLayerRulesTest.php`; `tests/core/app/MessageKeysTest.php` (reading part 1 section 14 only); `tests/Support/IoFixture.php` (seed-only first version; WP11 extends it) |
| Scope | Sections 2.1 (Io row), 2.2, 3, 5, 6, 7 (except the App rows), 10.1, 12.1; `Sqlite::openUntrusted` (11.4 step 2) |
| Acceptance | T-IO-1 to T-IO-14, T-PLG-7, T-FMT-4, T-FMT-5, T-SCH-20 pass; M1 and M2 schema, record, and form tests pass unchanged except the version expectations; design-tier review of `Committer`, `Ddl`, `Fs::removeTree`, and the scanner changes |

### M3-WP2: Plugin registry and system schema version 2

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1 (`Core\Io\Contract`, the `Io` layer row) |
| Security-critical | Yes (plugin discovery and loading) |
| Owned files | `src/Core/Io/Plugin/*`; `src/Core/System/PluginStates.php` (new), `src/Core/System/SystemSchema.php`; the five `plugins/*/*/plugin.json` manifests (manifests only; classes come with WP4 to WP6); `tests/core/io/plugin/*`; `tests/core/system/PluginStatesTest.php` (new), `tests/core/system/SystemSchemaTest.php`, `tests/core/system/SystemDatabaseTest.php` (the `schema_version` expectation only); `tests/repo/PluginLayerRulesTest.php` (new); the exemption for the dynamic `new` in `PluginLoader.php` in `tests/repo/CompositionSeamTest.php` (that one change only) |
| Scope | Sections 4, 12.2 |
| Acceptance | T-PLG-1 to T-PLG-6 and T-PLG-8 pass; T-PLG-4 shows no plugin PHP runs during a scan; design-tier security review |

### M3-WP3: Web plumbing and shared components

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (uploads, downloads, headers, templates) |
| Owned files | Part 2 section 11, WP3 row |
| Scope | Part 2 sections 2 to 7 (shared parts) |
| Acceptance | Part 2 section 11, WP3 row |

### M3-WP4: CSV reader, writer, and plugins

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1, WP2 |
| Security-critical | Yes (parses user files) |
| Owned files | `src/Core/Io/Csv/*`; `plugins/import/csv/src/*`, `plugins/export/csv/src/*`; `tests/core/io/csv/*`; `tests/plugins/csv/*` |
| Scope | Section 8 |
| Acceptance | T-CSV-1 to T-CSV-9 pass; design-tier security review |

### M3-WP5: XLSX plugins

| | |
|---|---|
| Tier | implementation |
| Depends on | WP0, WP1, WP2 |
| Security-critical | Yes (parses user files; library use) |
| Owned files | `plugins/import/xlsx/src/*`, `plugins/export/xlsx/src/*`; `tests/plugins/xlsx/*` (including the hand-assembled workbook builder `tests/plugins/xlsx/WorkbookBuilder.php`) |
| Scope | Section 9 and ADR 0016 decision 4 |
| Acceptance | T-XLSX-1 to T-XLSX-6 pass; design-tier security review |

### M3-WP6: JSON export plugin and format document tests

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1, WP2 |
| Security-critical | No |
| Owned files | `plugins/export/json/src/*`; `tests/plugins/json/*`; `tests/formats/JsonExportFormatTest.php` (new) |
| Scope | Section 10.3; `docs/formats/json-export.md` (read-only; report any defect) |
| Acceptance | T-JSON-1, T-JSON-2 pass; implementation-tier review |

### M3-WP7: Project archive

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1 |
| Security-critical | Yes (opens untrusted zip and SQLite files) |
| Owned files | `src/Core/Io/Archive/*`; `tests/core/io/archive/*` |
| Scope | Section 11.1 to 11.5 (registration, 11.6, is WP8) |
| Acceptance | T-ARC-1 to T-ARC-8 pass; design-tier security review that confirms the rebuild never executes source schema text |

### M3-WP8: Core operations

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1, WP2, WP4, WP6, WP7 |
| Security-critical | Yes (authorization boundary) |
| Owned files | `src/Core/App/` new files of section 13, `src/Core/App/Operations.php`, `src/Core/App/Context.php`, `src/Core/App/RecordOperations.php`, `src/Core/App/RecordListing.php`, `src/Core/App/SearchResults.php`; `src/Core/System/AuditAction.php` (section 13.6: adding the actions to `AuditAction::ALL` makes `AuditTrailTest` require each to be written, so the constants land with the operations that write them); `src/Web/CoreServices.php` (passing the plugins root only; WP3 changed it in wave 1 for `uploadLimits()`); `tests/core/app/Import*Test.php`, `Export*Test.php`, `Archive*Test.php`, `Plugin*Test.php`, `IoPermissionsTest.php` (new), `tests/core/app/AuditTrailTest.php`; `tests/Support/CoreFixture.php` |
| Scope | Sections 7 (App rows), 11.6, 13 (including the 13.6 constants) |
| Acceptance | T-APP-90 to T-APP-97 pass; M1 and M2 T-APP tests pass; design-tier security review |

### M3-WP9: Import screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP3, WP8 (and at least WP4 merged) |
| Security-critical | Yes (upload path, templates) |
| Owned files | Part 2 section 11, WP9 row |
| Scope | `docs/design/m3/import.md` |
| Acceptance | Part 2 section 11, WP9 row |

### M3-WP10: Export, archive, and plugins screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP3, WP8 |
| Security-critical | Yes (downloads, uploads, plugin enabling) |
| Owned files | Part 2 section 11, WP10 row |
| Scope | `docs/design/m3/export.md`, `archive.md`, `plugins.md` |
| Acceptance | Part 2 section 11, WP10 row |

### M3-WP11: Fixtures, round trips, end to end, keyboard, visual

| | |
|---|---|
| Tier | implementation |
| Depends on | WP4 to WP10 |
| Security-critical | No |
| Owned files | `tests/Support/IoFixture.php`; `tests/roundtrip/*` (new: T-RT-1 to T-RT-6); part 2 section 11, WP11 row |
| Scope | Section 15.7; part 2 sections 9 and 10 (T-E2E-8 to T-E2E-10, T-SCR-3, T-KBD-2, K-2, V-15 to V-19) |
| Acceptance | Tests pass; a report with K-2 steps and results, visual deviations per screen and width, and the T-RT-6 timings |

### Summary

| ID | Title | Tier | Depends on | Security-critical | Wave |
|---|---|---|---|---|---|
| M3-WP0 | Shipped dependency | implementation | probe | Yes | 1 |
| M3-WP1 | `Core\Io` foundation, import pipeline, format version 3 | implementation | none | Yes | 1 |
| M3-WP2 | Plugin registry, system schema version 2 | implementation | WP1 | Yes | 1b |
| M3-WP3 | Web plumbing and shared components | implementation | none | Yes | 1 |
| M3-WP4 | CSV | implementation | WP1, WP2 | Yes | 2 |
| M3-WP5 | XLSX | implementation | WP0, WP1, WP2 | Yes | 2 |
| M3-WP6 | JSON export | implementation | WP1, WP2 | No | 2 |
| M3-WP7 | Project archive | implementation | WP1 | Yes | 1b |
| M3-WP8 | Core operations | implementation | WP1, WP2, WP4, WP6, WP7 | Yes | 3 |
| M3-WP9 | Import screens | implementation | WP3, WP8 | Yes | 4 |
| M3-WP10 | Export, archive, plugins screens | implementation | WP3, WP8 | Yes | 4 |
| M3-WP11 | Fixtures, round trips, end to end, keyboard, visual | implementation | WP4 to WP10 | No | 5 |

Ownership check (corrected by review M3-R1). Wave 1: WP0 holds `composer.json`, `docs/dependencies.md`, two repository tests, the installer requirement rows and their strings, host-check files; WP1 holds `src/Core/Io/` top level and `Contract/`, the four Schema files, `Records.php`, three Storage files, `CoreLayerRulesTest.php`, `SqlStringsTest.php`, `SnapshotsTest.php`, `MessageKeysTest.php`, the first `IoFixture.php`; WP3 holds the web files of part 2 section 11. No file is shared. Wave 1b (after WP1): WP2 holds `src/Core/Io/Plugin/`, two System files, the manifests, `PluginLayerRulesTest.php`, the System tests, one exemption in `CompositionSeamTest.php`; WP7 holds `Io/Archive/`; disjoint from each other and from WP0 and WP3. Wave 2: WP4 (`Io/Csv/`, CSV plugins), WP5 (XLSX plugins), WP6 (JSON plugin, `tests/formats/`) are disjoint; the `plugin.json` files stay WP2's (merged). Wave 4: WP9 and WP10 own their controllers, templates, strings files, and tests; `routes.php`, `app.css`, `layout.php`, shared partials, and shared strings belong to WP3 (merged). Files touched by both milestones (session plan 8.3) each have one owner here: `routes.php`, `Request.php`, `Response.php`, `Kernel.php`, `app.css` (WP3); `Operations.php` (WP8); `FormatMigrations.php`, `docs/dependencies.md` (WP1, WP0); `docs/formats/tiffin.md` is changed by this design only.

## 17. Provisional decisions (for the ledger, from P110)

Each is the option cheapest to reverse (orchestration guide section 5) and is recorded in ADR 0015, 0016, or 0017 where it is a decision rather than a constant.

| # | Item | Provisional resolution | Recorded in |
|---|---|---|---|
| P110 | XLSX library (settles P6, ADR 0009 B5) | `openspout/openspout` 5.2.2, pinned exactly, no lock file; licence `MIT AND Apache-2.0` (two licence files at the tag; package metadata says MIT only); v5.3.0 also admits PHP 8.3 but was not chosen; v5.4 and later need PHP 8.4 | ADR 0016 d1, d2 |
| P111 | Extensions for XLSX | `dom`, `filter`, `libxml` reported as installer warnings; XLSX plugins unavailable without them; host check extended | ADR 0016 d3 |
| P112 | Import commit model (settles E1) | Read and prepare in chunks (2,000 rows or 5 s) into a per-job SQLite file under `<data>/staging/imports/`; one final transaction after an `import` snapshot; Continue button with an auto-submit script; no staging table in the project format | ADR 0015 d1 |
| P113 | Plugin layout and loading | `plugins/<kind>/<id>/plugin.json` plus `src/` under `TiffinDB\Plugin\<Kind>\<Id>\`; manifests never execute; enabled plugins loaded by a per-plugin autoloader on use; contract `Core\Io\Contract` API 1; CSV reader and writer in core | ADR 0015 d2, d3 |
| P114 | Plugin state | System schema version 2 table `plugins`; the five shipped plugins enabled by default, others disabled until an Admin enables them through a confirmation page | ADR 0015 d4 |
| P115 | Project format version 3 | `_tiffin_import_log` with a JSON `report` (counts and row numbers, never values); snapshot reason `import` | ADR 0015 d1; `docs/formats/tiffin.md` 4.7, 11.2 |
| P116 | Import limits | 50,000 rows, 200 columns, 20,000 characters per cell, uploads at most 64 MiB or the PHP limits, whichever is smaller | this spec 6.8 |
| P117 | Type inference | Rules of section 6.3: ISO dates only (as the M1 types), checkbox only for yes/no/true/false, currency by symbol or two decimals, choice for at most 20 repeated short values, link by a heading naming an existing table; rating offered, never proposed (deviation from the mock-up); new table item name is the lower-cased table name | this spec 6.3, 6.6 |
| P118 | Requiredness | Imports enforce no required fields (counted in the report); `Records::create` gains `requiredKeys`; record creation from a form requires only the required fields that form shows (reviewer-flagged point) | ADR 0017 d4 |
| P119 | Appending to an existing table | Columns map to existing fields only; no new fields; choice values not in the list emptied and reported; fields not mapped stay empty | this spec 6.4, 6.5 |
| P120 | Review warnings | Counted over the first 5,000 rows, labelled as such; the report has exact counts | this spec 6.4 |
| P121 | CSV dialects | Export: UTF-8 with BOM, comma, CRLF, heading row of field names, optional record-number column. Import: UTF-8, UTF-16 with BOM, Windows-1252 by TiffinDB's own decoder; delimiters comma, semicolon, tab, bar | this spec 8 |
| P122 | Formula-like cells | CSV keeps exact values by default; "Protect against spreadsheet formulas" prefixes `'` on text-like cells starting `= + - @`, tab, CR. XLSX always writes text as string cells and reads cached formula results | ADR 0016 d4; this spec 8.2 |
| P123 | Exports | Streamed from a staged file in the POST response; live records only; relation and attachment cells as `; `-joined titles and names; CSV one table, XLSX one table or all, JSON whole project | this spec 10 |
| P124 | Project archive | Zip with manifest, `VACUUM INTO` copy, attachments; no snapshots; import verifies, then rebuilds into a new project (keys and ids kept, new project key, no grants, source import log dropped, invalid values emptied, invalid forms dropped, forms regenerated so edited forms gain missing slots) | ADR 0017 d1 to d3 |
| P125 | JSON export format 1 | Whole project, live records, values per type with currency as a decimal string, relation and attachment values as id arrays | ADR 0017 d5 |
| P126 | Uploads | `Request::$files` from PHP's upload array, single files only, checked with `is_uploaded_file`; a POST larger than `post_max_size` gets a 413 page before the CSRF check | part 2 section 2 |
| P127 | Staging area | `<data>/staging/{imports,exports,archives}/<key>/`; recursive removal allowed only there (amends P64); swept after 24 h (imports) or 1 h | this spec 5.1 |
| P128 | Audit actions | `import.completed`, `export.created`, `archive.exported`, `archive.imported`, `plugin.enabled`, `plugin.disabled` (amends ADR 0012 d4) | this spec 13.6 |
| P129 | M3 routes | Part 2 section 3 | part 2 |
| P130 | Joined mode and DDL | `SchemaEditor::within()`, `Records::within()`; DDL text in one class `Core\Schema\Ddl` shared by the editor and the archive rebuild | this spec 6.6, 7 |
| P131 | Layers | `Core\Io` may use Schema, Query, Forms, Storage, Error; plugins only `Core\Io\Contract`, their namespace, PHP, `OpenSpout\` | ADR 0015 d5 |
| P132 | Navigation | Sidebar Import and Export for people with `io.use`; Plugins tab for Admins; "Import a project archive" on the project list | part 2 section 4 |
| P133 | Plugin text | Names, setting labels, `readAs`, descriptions supplied by plugins are escaped plain text, not message keys (built-in plugins write English) | this spec 3 |
| P134 | Import jobs | Bound to the project and the Admin who started them; anyone else gets "not found" | this spec 5.2 |

## 18. Assumptions and open points

Assumptions about M2 (for M3-D2; each is a statement of the reviewed M2 spec that M3 depends on):

1. `Records::create(tableKey, values, InputMode, userKey)` and `setLinks` exist as M2-records 3.2 says, with `InputMode::Import` (lenient parse) and `InputMode::Canonical`; `Records::pickList` limit 500.
2. `SchemaEditor`'s after-change hook regenerates forms inside the schema transaction (M2-records 5.6) and runs in joined mode unchanged.
3. `FormStore::regenerate` appends a slot for each unnamed field to a `generated` 0 form and leaves rejected forms alone (M2-records 5.5); `FormParser::parse` is the default-deny parser of 5.2.
4. `RecordOperations::create` obtains the screen first (M2-screens 2.1), so `BoundForm::editableKeys()` is available to it.
5. `Fs::entries` and the stat helpers exist (M2-WP0, T-CF-4).
6. `Sidebar::build` takes strings (M2-screens 3.2), the sidebar omits Import and Export (P103), and `RecordListing`/`SearchResults` are plain result objects that can gain a property.
7. Project format version 2 is exactly `docs/formats/tiffin.md` sections 4 (first two blocks) and 11.1; `ProjectFile::VERSION` is 2 when M3 starts.
8. The template rules allow scripts only from a fixed list in `layout.php` (M2-screens 3.3), which WP3 extends.
9. `Strings` supports the plural placeholder (P97).

Checked against the M2 code by review M3-R1 (tree at `d79c3fa`, M2-WP9 not yet merged), for M3-D2:

- Items 1, 2, 3, 5, 7, 8, 9 hold. `InputMode` has `Form`, `Import`, `Canonical`; `Records::PICKER_LIMIT` is 500; `SchemaEditor` runs each method in its own `write()` through a private `change()` and calls the `\Closure(Database, list<string>)` after-change hook inside it; `FormStore::regenerate` requires an open transaction and extends only each table's **default** form (non-default forms are not touched, so `forms_extended` counts default forms); `ProjectFile::VERSION` is 2 and `ProjectFile::create` sets `PRAGMA user_version = 2` before its transaction; the 11.2 statements run on a version 2 file made by `ProjectFile::create` in one transaction and leave `integrity_check` `ok`.
- Item 4 does not hold: `RecordOperations::create(Actor, $projectKey, $tableKey, array $input)` passes the input straight to `Records::create` in `Form` mode; the controller builds the input from `blank()`'s `editableKeys()`. Section 7 is corrected.
- Item 6 holds except the signature: `Sidebar::build(string $projectKey, string $projectName, array $tables, int $trashCount, ?string $currentTableKey)`, called only by `RecordListController` and `SearchController`.
- `Records` takes its `Schema` in the constructor (`Records(Database, Schema, FieldTypes, Clock)`), so `within()` cannot see a table created earlier in the same transaction: step 6.6 3.2 must construct a new `Records` over a new `Schema` (the committer needs `FieldTypes` and `Clock`, or `Records` gains `withSchema()`).
- `SchemaEditor::addRelation(string $tableKey, string $name, string $targetTableKey, Cardinality $thisMax, Cardinality $otherMax, string $reverseName, bool $required, string $help)`; setting the title field of a new table is `updateTable(...)`, a separate method in joined mode.
- The dynamic-`new` scanner is `tests/repo/CompositionSeamTest.php` (`DISPATCHER`), not `PhpSourceRulesTest.php`; `SqlStringsTest` also refuses `new $class` in the directories it scans. `AuditTrailTest` requires every action in `AuditAction::ALL` to be written; `SnapshotsTest` lists every `SnapshotReason`; `SystemDatabaseTest` expects `schema_version` `1`; `ScreenStructureTest` requires every GET route with an existing controller to be visited; `MessageKeysTest` reads M1 section 12 and M2 section 8 only and matches keys `[a-z_.]` (no hyphens). Section 16 and part 2 section 11 are corrected for each.
- `Installer` already checks `zip` and `xmlreader` as failures; `Requirement::WARNING` exists for the three new rows; the requirement test is `tests/core/install/RequirementsTest.php`. `tools/host-check/host-check.php` lists `ext.zip` and `ext.xmlreader` only.
- `tests/web/KeyboardStructureTest.php` and `tests/Support/M2Fixture.php` are M2-WP9's and not yet in the tree.

Other assumptions and open points:

10. Household scale: imports up to 50,000 rows; archive imports of projects of that size. Commit and rebuild time on shared hosts not measured (T-RT-6 measures the build machine).
11. `ZipArchive::CHECKCONS` and libzip's own size checks are relied on in addition to the byte counting of section 11.3; behaviour may vary with the host's libzip version (host check).
12. OpenSpout's XLSX reader does not expand external entities (it uses `XMLReader` with `LIBXML_NONET` and no DTD loading at this tag); T-XLSX-6 verifies on the build machine only.
13. Non-ISO dates (for example `3/14/2025`) are not read as dates in v1; such columns import as text unless the Admin changes the type, in which case values are reported as unreadable.
14. Missing information: whether the maintainer wants the spreadsheet-safe CSV option on by default (P122); whether appending to an existing table should be able to add fields (P119); whether Members should be able to export (currently Admin-only, M1 table).
