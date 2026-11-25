# M7 design spec: release, upgrade path, administration core, documentation: core and work packages

Status: design spec for milestone M7, part 1 of 2 (M7-D1), reviewed (M7-R1, rulings P237 to P241) and reconciled with the M5 as-built code (M7-D2, provisional decision P244). This document covers the release archive and build tool, the release rules, the version scheme, the upgrade procedure and its tests, the project lock, snapshot restore, project deletion, the audit listing, Admin recovery, the About facts, the carried-forward items, the documentation outline, the test plan, the provisional decisions, and **all M7 work packages**. The web layer (routes, controllers, templates, strings, keyboard and visual procedures) is `docs/specs/M7-screens.md`; screen designs are under `docs/design/m7/`.

Sections 18 and 19 (M7 part 2: release test, screen catalogue, visual pass, exit audit, final audit, the importer documentation) are an **outline only**. They are reconciled with the M6 as-built notes before part 2 starts (M7-D3).

Decisions this spec relies on: ADR 0001, ADR 0002, ADR 0008, ADR 0011, ADR 0012, ADR 0015, ADR 0016, ADR 0017, ADR 0018, and the new ADR 0021 (release packaging, versioning, upgrade path) and ADR 0022 (snapshot restore, project deletion, audit-log screen, Admin recovery). Everything in the M1 to M5 specs, with their as-built notes and provisional decisions, still applies unless a section below changes it. New provisional decisions are listed in section 20 as P213 to P241 and P244 (M5 took P189 to P212, P242, and P243; P237 to P241 are the spec review rulings; P244 is the M7-D2 reconciliation); the orchestrator records them in the ledger under these numbers.

**Designed against the code as built at M4 close and reconciled (M7-D2) with the code as built at M5 close** (integration branch at `5bdab7d`; M5 exit report `docs/build/reviews/M5-exit-audit.md`; as-built notes `docs/specs/M5-forms.md` section 17 and `docs/specs/M5-screens.md` section 14). Signatures quoted from the tree are marked "(as built)". What M5 changed in files named here, and how this spec takes it into account (section 17 has the resulting ownership): `routes.php` holds nine form routes after the record routes (the M7 project routes go elsewhere, screens spec section 4); `layout.php` has two more layouts, `form` and `form-narrow` (M7 adds none); `app.css` gained the M5 classes (M7's are new names, screens spec section 8); `problems.php` and `MessageKeysTest.php` gained the M5 keys and an `m5Keys()` reader of `docs/specs/M5-forms.md` section 4.3 (WP1 adds an `m7Keys()` reader of section 11.3 here); `AuditAction.php` gained `form.saved` and `form.reset` (in `ALL`; `AuditTrailTest` writes both); `Operations.php` gained `forms()`; `Response.php` gained `attachmentText`, which names its download through `contentDisposition`, so the P187 extension rule of WP0 reaches form downloads too; `RouterTest`, `ScreenStructureTest`, and `KeyboardStructureTest` gained per-milestone M5 methods and controller lists, which M7 follows (section 17). No M7 decision depended on how M5 was built. Session plan: `docs/build/M5-M7-session-plan.md` ("plan N" below is a section of that document). The maintainer has not answered plan section 3; rows 1, 6, and 7 are applied as their defaults.

Audience: engineers holding this spec, `CLAUDE.md`, and the files a work package names. `CLAUDE.md` has the highest authority.

## 1. Goals, non-goals, exit criteria

### 1.1 Goals (part 1)

1. Snapshot restore, project deletion, and the audit-log screen for Admins (P49, P170; ADR 0022 decisions 1 to 4).
2. Admin recovery without shell access (plan 3 row 6; ADR 0022 decision 5).
3. About page, favicon, and app icon (ui 6.2, ui 11; ADR 0021 decision 8, ADR 0022 decision 6).
4. A release build tool that makes a reproducible archive whose contents pass the release rules (ADR 0021 decisions 1 to 5).
5. An upgrade path for the application, the system database, and every project format, with tests (ADR 0021 decision 6).
6. Host and user documentation (plan 8.2 row 9).
7. The carried-forward hardening items (section 15).

### 1.2 Non-goals for M7 part 1

- Anything that names or supports the legacy importer (ADR 0021 decision 10); M6 and M7-WP11 add it.
- A project trash; snapshot deletion in the browser; restoring a single table or record from a snapshot; attachment files kept alive by snapshots (section 21 item 4).
- A git tag, a hosted release, or any publication (plan 3 row 7).
- PNG or ICO icons (ADR 0021 decision 8).
- Screenshots in the user documentation (plan 8.2 row 9).
- Any project format step or system schema step (plan 9.1; none is needed: section 4.6, section 9.6).

### 1.3 Exit criteria

| Exit criterion (plan 2, part 1) | How M7 part 1 meets it |
|---|---|
| Each screen meets its design | `docs/design/m7/`; web tests T-WEB-80 to T-WEB-95; visual check V-35 to V-40 (screens spec section 11) |
| The build tool makes a reproducible archive whose contents pass the release rules | T-REL-1 (two builds byte-identical), T-REL-2 to T-REL-10 (one failing case per rule), T-REL-11 (the tree passes), CI step (section 3.8) |
| Installations made by earlier code open under the release code | T-UPG-1 to T-UPG-8 (section 12.3); M7-X upgrades an installation made by the code at M4 close |
| Security baseline for the new surface (plan 7) | Authorization in core (T-ADM-30 to T-ADM-34, T-REC-1 to T-REC-14); lock and failure injection (T-ADM-1 to T-ADM-20); CSRF and origin on every new POST (T-WEB-90); hostile audit values escaped (T-WEB-86); static-asset headers (T-WEB-93) |

Part 2 criteria (release test, visual pass, exit audit, final audit): section 18.

## 2. Code layout

New PHP follows ADR 0008 decision 6. New or changed files, with their owners (section 17):

```
src/Core/Storage/  ProjectLock, LocksUnavailable (new); SnapshotReason (pre-restore); Snapshots (find); Fs (copyIn,
                   replace)                                                              (WP1)
src/Core/Schema/   FormatMigrations (P183)                                               (WP1)
                   (none for WP0)
src/Core/App/      ProjectOpener (locks); SnapshotOperations (screen, restoreScreen, restore),
                   SnapshotScreen, RestoreScreen, RestoreResult (new); ProjectOperations
                   (deleteScreen, delete, sweep of deleting rows), ProjectDeleteScreen,
                   ProjectDeleted (new); AuditOperations (page), AuditPage, AuditRow,
                   AuditChoices (new)                                                    (WP1)
                   RecoveryOperations, RecoveryResult, AboutOperations, AboutFacts,
                   AboutComponent, AboutPlugin, LicenceText (new); Operations (recovery(),
                   about())                                                              (WP2)
src/Core/Io/       StagingArea (kinds restores, deleted)                                 (WP1)
src/Core/System/   ProjectRegistry (setState, deleting), ProjectRecord (STATE_DELETING),
                   AuditLog (search, count), AuditQuery (new), AuditAction                (WP1)
                   AuditAction (recovery actions)                                        (WP2, after WP1)
                   SystemSchema (newer-system reason only)                               (WP0)
src/Core/Install/  RecoveryCode (new)                                                    (WP2)
src/Core/Auth/     Authenticator (throttle clearing, section 15)                         (WP0)
src/Core/Query/    Attachments (cleanName keeps the extension, P187)                     (WP0)
src/Core/Error/    Unavailable (reason system-newer)                                     (WP0)
src/Web/           Kernel, Request (Origin refuse state, local keys), Response
                   (contentDisposition keeps the extension), Controller/SetupController
                   (probe port)                                                          (WP0)
                   routes, Shell, SecurityHeaders (manifest-src)                         (WP3)
                   Controller/SnapshotController (new), Controller/ProjectController     (WP4)
                   Controller/AuditController, AboutController, RecoveryController
                   (new), Controller/SignInController (recovered notice, link)           (WP5)
tools/release/     build.php, ReleaseRules.php, ArchiveBuilder.php (new)                 (WP6)
tools/host-check/, tools/discovery/  (section 15)                                        (WP0)
```

### 2.1 Dependency rule

Unchanged (ADR 0008, ADR 0012). `Core\Storage` gains `ProjectLock`, which depends on PHP only. `Core\App` uses Storage, System, Auth, Install, Schema, Query, Io, Error as built; `RecoveryOperations` uses `Core\Install\RecoveryCode` and `Core\Install\SetupCode` (the code alphabet and comparison). The web layer uses only `Core\App` result objects and `Core\Error` values; the new value classes of sections 5 to 10 are `Core\App` classes. `tests/repo/CoreLayerRulesTest.php` gains the rule of section 4.5 (WP1). `tools/release/` is not under `src/` and is not loaded by the application; it may use PHP's `ZipArchive` and `proc_open` (for `git` only) and nothing from `src/`: it reads files with plain PHP and must run from a checkout without `vendor/autoload.php`.

### 2.2 SQL rules

Every new statement is a literal with bound values: `AuditLog::search` and `count` build their `WHERE` clause from a fixed list of literal fragments chosen by which filters are set (no text from the request is joined into SQL). `tests/core/schema/SqlStringsTest.php` already covers `Core\System` literals (as built); WP1 adds a case for the four filter combinations.

## 3. Release archive and build tool (`tools/release/`, M7-WP6) (security-critical: what ships)

### 3.1 Contents (ADR 0021 decision 1)

The archive `tiffindb-<version>.zip` has one top folder `tiffindb-<version>/`. Below it:

| Included | From |
|---|---|
| `public/` (except anything a rule of 3.5 refuses), `src/`, `templates/`, `strings/`, `plugins/` | tracked files |
| `config/app.php`, `config/notices.json` | tracked files |
| `.htaccess`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md`, `THIRD-PARTY-NOTICES.md` | tracked files |
| `tools/admin/`, `tools/host-check/` | tracked files |
| `docs/host/`, `docs/user/`, `docs/formats/`, `docs/dependencies.md` | tracked files |
| `vendor/` | the prepared production `vendor/` (3.2), filtered by 3.2 |
| `MANIFEST.txt` | generated (3.4 step 7) |

Everything else is excluded, in particular `tests/`, `tools/dev/`, `tools/discovery/`, `tools/release/`, `docs/build/`, `docs/specs/`, `docs/decisions/`, `docs/design/`, `docs/brand/`, `.github/`, `CLAUDE.md`, `phpunit.xml.dist`, `.gitignore`, `config/local.php`. The include list is a constant `ReleaseRules::INCLUDE` of path prefixes (a prefix ending in `/` is a folder, anything else an exact file); it names no plugin and no admin tool.

### 3.2 The production `vendor/` (ADR 0021 decision 2)

The tool reads `<vendor>/composer/installed.php` (a PHP file returning an array; read by `include` inside the tool only, which runs on the maintainer's or CI's machine, never on a host) and refuses the build when:

- `installed.php` `root.dev` is true, or any package has `dev_requirement` true;
- a package (other than the root and `composer/*` metapackages Composer itself writes) is not a row of the shipped table of `docs/dependencies.md`, or its `pretty_version` or `reference` differs from the row's Version and Commit columns (parsed as T-DEP-1 parses them, as built);
- a row of that table is shipped but missing from `installed.php`;
- `autoload_files.php` (when present) names a file outside a shipped package folder.

Shipped from `vendor/`: `autoload.php`, `composer/` except `installed.json`, and each shipped package's folder, leaving out, at any depth inside the package, folders named `tests`, `test`, `Tests`, `examples`, `.github`, `docs` and files named `phpunit.xml`, `phpunit.xml.dist`, `.gitattributes`, `.gitignore`, `.editorconfig`, `composer.lock`, `Makefile`. A file named `LICENSE*`, `LICENCE*`, `NOTICE*`, or `COPYING*` is always shipped, wherever it is.

### 3.3 Preparing `vendor/`

Where Composer can download: in an empty folder holding only a copy of `composer.json`, `composer install --no-dev --prefer-dist --no-interaction --no-scripts`. In the build environment, where downloads are refused (orchestration guide section 9): start from the shared offline `vendor/` that holds OpenSpout (ledger note "M3-WP0 merged", scratchpad `vendor-m3`), copy only `openspout/openspout/` into a new folder, run `composer dump-autoload --no-dev` there against a copy of `composer.json`, and write `composer/installed.php` by hand from the dependency row (root `dev` false; one package entry). Neither command uses `--classmap-authoritative` or `--optimize-autoloader`: the folder holds no `src/`, so an authoritative class map would list no TiffinDB class and the release could load none; the plain PSR-4 map (`TiffinDB\\` to `src/`) is what ships. WP6 writes this procedure into `tools/release/README.md` with the exact commands it ran; `docs/host/` does not need it.

### 3.4 Build tool

```
php tools/release/build.php --vendor=<dir> --out=<dir> [--test-build] [--allow-dirty]
```

Exit 0 on success, 1 on a refusal (one plain line per problem on standard error, no absolute path), 2 when not run from the command line or on an unknown option. The steps:

1. Read `config/app.php` `version`. Refuse unless it matches `^[0-9]+\.[0-9]+\.[0-9]+(-rc\.[1-9][0-9]*)?\z`, or, with `--test-build`, also `-dev`.
2. List tracked files with `git ls-files -z` (run through `proc_open` with an argument array, no shell, from the repository root). Refuse when `git status --porcelain=v1 -z` reports a change to any included path, unless `--allow-dirty` (then a warning line says the archive is not reproducible from a commit).
3. Keep the files under `ReleaseRules::INCLUDE`; add the filtered `vendor/` files of 3.2.
4. Apply every rule of 3.5 to the full list; refuse on any violation.
5. Read every file as bytes; refuse symbolic links and anything that is not a regular file.
6. Sort the archive paths by byte value (`strcmp`).
7. Write `MANIFEST.txt`: one line per file, `<lower-case SHA-256 hex>  <path below the top folder>\n`, in the same order; it is added after the sorted files as the last entry and is not listed in itself.
8. Set `putenv('TZ=UTC')` before the first `ZipArchive` call: libzip converts the entry time to a zip (DOS) date with the C library's local time, so without it the bytes depend on the build machine's time zone, and west of UTC the 1980 time falls before the zip epoch (checked with libzip 1.7.3: different hashes under `TZ=UTC`, `America/New_York`, and `Asia/Tokyo`; equal once the tool sets `TZ=UTC`). Write the zip with `ZipArchive` to `<out>/tiffindb-<version>.zip.part`: first the folder entries (each folder that holds a file, sorted, with a trailing `/`), then the files; each entry gets `setMtimeName` 315532800 (1980-01-01 00:00:00 UTC), `setExternalAttributesName` with `ZipArchive::OPSYS_UNIX` and mode `0100644` (files) or `040755` (folders), shifted left 16 bits as the zip format stores it, and `setCompressionName` `ZipArchive::CM_DEFLATE` level 9. No archive comment, no extra fields beyond what these calls write.
9. Rename `.part` to `tiffindb-<version>.zip`, refusing to overwrite an existing file; write `tiffindb-<version>.zip.sha256` as `<hex>  tiffindb-<version>.zip\n`.
10. Print the SHA-256, the file count, the archive size, and the PHP, libzip (`ZipArchive::LIBZIP_VERSION`), and zlib (`ZLIB_VERSION`) versions.

The tool downloads nothing and never writes inside the repository. Its logic sits in `tools/release/ArchiveBuilder.php` (steps 3 and 5 to 9, given a file list and a source root) and `tools/release/ReleaseRules.php` (3.1, 3.2 filtering, 3.5), both plain PHP files with one `final class` each in namespace `TiffinDB\Tools\Release`, loaded by `build.php` and by the tests with `require_once`; `build.php` does steps 1, 2, 4, and 10. `tools/release/` carries SPDX headers like every source file.

### 3.5 Release rules (`ReleaseRules::violations(list<string> $paths, string $root): list<string>`)

Each rule gives a fixed message naming the rule and the path (relative). Paths are below the top folder.

| Rule | Fails when |
|---|---|
| R1 | A path starts with `tests/`, `tools/dev/`, `tools/discovery/`, `tools/release/`, `docs/build/`, `docs/specs/`, `docs/decisions/`, `docs/design/`, `docs/brand/`, `.github/`, or is `CLAUDE.md`, `phpunit.xml.dist`, `.gitignore`; or a path below `vendor/` holds a segment `tests`, `test`, `Tests`, `examples`, `.github` (3.2) |
| R2 | A path is `config/local.php`, or starts with `data/` or `local/` |
| R3 | A path's last segment matches `*.db`, `*.sqlite*`, `*.tiffin`, `*.tiffindb.zip`, or `.env*` (the data patterns of `.gitignore` as built) |
| R4 | An `.htaccess` other than `.htaccess` and `public/.htaccess` contains `Rewrite` (case-insensitive) in any non-comment line (ledger section 7) |
| R5 | A file outside `vendor/` fails the SPDX rule of `tests/repo/SpdxHeaderTest.php` (third-party files carry their own notices; `MANIFEST.txt` is generated after the rules run). As built that test holds the rule in public static methods (`ruleFor`, `exemptionFor`, `problem`) and the `EXEMPTIONS` register, but it extends PHPUnit's `TestCase`, which the build tool cannot load. WP6 moves those methods and the register, unchanged, into `ReleaseRules` (`ReleaseRules::spdxProblem(string $path, string $contents): ?string` and `ReleaseRules::SPDX_EXEMPTIONS`), and `SpdxHeaderTest` calls them (tests already load tool files, as `DiscoverStructureTest` does), so the two can never differ. The test's own assertions, fixtures, and the pinned exemption list stay in the test |
| R6 | A package under `vendor/` is not a shipped row of `docs/dependencies.md` with the same version and commit, or a shipped row has no package (3.2) |
| R7 | A component of `config/notices.json` names a licence file that is not in the list, or a shipped row of `docs/dependencies.md` (packages and font families) has no component in `config/notices.json` |
| R8 | A path holds a segment `.` or `..`, a NUL, a backslash, or a character outside printable ASCII (no shipped file has one; this keeps the zip free of path tricks) |

Checked against the tree at M5 close (`5bdab7d`, M7-D2): the include list of 3.1 selects 576 tracked files (544 PHP, 8 WOFF2, 6 JSON, 5 Markdown, 4 licence texts, 3 JavaScript, 3 CSS, 2 `.htaccess`, `LICENSE`), and none breaks R1 to R4 or R8. M5's new test material, the sanitizer corpus (`tests/core/forms/corpus/*.php`), `tests/Support/M5Fixture.php`, `AppearanceMatrix.php`, and `tests/web/fixtures/templates/pages/m5-components.php`, is under `tests/`, so the include list never selects it and R1 refuses it if it were added. The six JSON files are `composer.json` and the five `plugins/*/*/plugin.json` manifests, which `SpdxHeaderTest` already exempts (as built); `config/notices.json` and `public/assets/app.webmanifest` get exemptions from WP6 (section 17). `docs/dependencies.md` is unchanged since M4 close, so R6 and R7 read the shipped table as it stands (one package, four font families).

### 3.6 Third-party notices

- `config/notices.json` (new, WP6): a JSON array; each element `{"id": <[a-z0-9-]{1,40}>, "name": <text>, "version": <text or "">, "licence": <SPDX expression>, "files": [<shipped paths>]}`. Rows, from `docs/dependencies.md`: `tiffindb` (TiffinDB, the configured version, `AGPL-3.0-or-later`, `LICENSE`); `openspout` (`5.2.2`, `MIT AND Apache-2.0`, both licence files); `inter`, `atkinson-hyperlegible`, `lexend`, `source-serif-4` (the font version of the table, `OFL-1.1`, each `public/assets/fonts/<family>/LICENSE.txt`); `composer-autoloader` (the class loader Composer writes into `vendor/composer/`, version "", `MIT`, `vendor/composer/LICENSE`). The autoloader is third-party code that ships, so WP6 also adds it as a shipped row of `docs/dependencies.md` (licence confirmed from `vendor/composer/LICENSE` as shipped), which R7 then requires; R6 and 3.2 still skip `composer/*` as a package. Read by `AboutOperations` (section 10) and by R7.
- `THIRD-PARTY-NOTICES.md` (new, WP6): plain text for people who read the archive: one section per component of `config/notices.json` except `tiffindb`, with name, version, licence, use (one line, from the dependency table's Purpose column), and the path of each licence file. T-REL-9 checks that every component id appears.
- Neither file names a plugin. A plugin with a third-party dependency would add a row to `docs/dependencies.md`, `config/notices.json`, and `THIRD-PARTY-NOTICES.md`, as any dependency does (ADR 0008 decision 12).

### 3.7 Version and changelog

- `config/app.php` `version` stays `0.1.0-dev` through part 1 (WP6 does not change it; test builds use `--test-build`). It becomes `1.0.0-rc.1` in the hand-over build commit, M7-WP12 (P240), after every part 1 package is merged. Tests that compare a version with the configured one read it from `config/app.php`; tests that write `created_with` constants (`EndToEndTest`, `M4Fixture`, `FormatUpgradeTest`, as built) keep their own constants.
- `CHANGELOG.md` (new, WP6): "## 1.0.0 (not yet released)" with one bullet list of what v1 holds, by plan section 8 milestone, in plain words; no names, no links other than repository-relative ones. A release candidate adds no section of its own.
- The final build commit (part 2) sets `1.0.0` and dates the section; the tag is the maintainer's.

### 3.8 CI step

`.github/workflows/ci.yml` (WP6) gains, after the tests, a step that prepares a production `vendor/` in `build/release-vendor/` (`composer install --no-dev ...` in a copy of `composer.json`, network allowed on the hosted runner), runs `build.php --test-build --vendor=build/release-vendor --out=build/out-1` and again into `build/out-2`, and fails unless the two `.sha256` files are equal. The comment "The only step that uses the network" moves to say there are two. `.gitignore` already ignores `/build/` and `/dist/` (as built), so it needs no change.

## 4. Project lock (`Core\Storage\ProjectLock`, M7-WP1) (security-critical)

### 4.1 Class

```php
final class ProjectLock
{
    public const FILE = 'project.lock';
    public const SHARED_WAIT_MS = 2000;
    public const EXCLUSIVE_WAIT_MS = 10000;
    public const STEP_MS = 50;

    /** Shared lock on <folder>/project.lock. Busy when not got within SHARED_WAIT_MS; null when the file system cannot lock (logged once per request, by the opener). */
    public static function shared(string $projectFolder): ?self;
    /** Exclusive lock; Busy when not got within EXCLUSIVE_WAIT_MS; LocksUnavailable when the file system cannot lock. */
    public static function exclusive(string $projectFolder): self;
    public function release(): void; // idempotent; also on destruct
}
```

- The lock file is opened with `fopen($path, 'c')` (create if missing, never truncate), after checking with `lstat` that it is a regular file or absent (a symbolic link or anything else: `LocksUnavailable` for exclusive, null with a log line for shared). A new file gets mode `Fs::FILE_MODE`.
- `flock($handle, LOCK_SH | LOCK_NB, $wouldBlock)` (or `LOCK_EX`) is tried every `STEP_MS` until the wait runs out. A false result with `$wouldBlock` true is contention; false with `$wouldBlock` false is "cannot lock" (`flock` disabled, or a file system without locks).
- `LocksUnavailable` (new, `Core\Storage`, extends `\RuntimeException`) is never shown as such: the operations that need an exclusive lock turn it into `ValidationFailed([Problem(null, 'problem.lock.unavailable')])`, shown on their confirmation page (sections 5.3 and 6.2). No new `Unavailable` reason is needed for locks.
- A project folder that does not exist (deleted or moved meanwhile) is not "cannot lock": `shared` returns null without a log line and `ProjectFile::open` then reports the missing file (`Unavailable('missing')`, as built).
- Time is real time (`usleep`); tests use folders on the test machine's own file system and a second PHP process (`proc_open`) to hold the other lock.

### 4.2 Where locks are taken (`ProjectOpener`, changed)

- `open(ProjectRecord $project)`: before `ProjectFile::open`, `ProjectLock::shared(folder)`; the lock is kept with the connection and released by `forget` and `closeAll` after the connection is closed. A null lock (cannot lock) is kept as "none". `Busy` from the shared lock reaches the kernel's busy page (as built).
- `adopt(string $projectKey, Database $db)`: takes the shared lock as `open` does (new projects; an archive import moves a rebuilt folder in and is opened later through `open`, as built).
- New `openExclusive(ProjectRecord $project): array{Database, ProjectLock}`: forgets any open connection for the key (releasing this process's shared lock), takes `ProjectLock::exclusive`, then opens the file with `Sqlite::open` directly (no upgrade, no identity check beyond what the caller does) and returns both; the caller closes the database and releases the lock. Used only by restore (section 5.3).
- New `lockExclusive(string $projectKey): ProjectLock`: forgets the connection and takes the exclusive lock, opening no database. Used only by deletion (section 6.2).
- A process holds at most one lock, and one open descriptor of `project.lock`, per project: `flock` locks belong to the open file, so a second `fopen` of the same lock file in one process would wait for itself; and where Linux emulates `flock` with `fcntl` record locks (NFS), closing any descriptor of the file drops every lock the process holds on it. `ProjectOpener` keeps one lock per key, and `openExclusive` and `lockExclusive` release this process's shared lock before asking for the exclusive one.
- The places that remove a project folder's known files, `ProjectOperations::undoCreate` and `removeAbandonedFolder` (as built), also remove `project.lock` (after releasing it), because `adopt` now creates it in a new project's folder; otherwise the folder would not be empty and the removal would fail.

### 4.3 Request lifetime

The lock lives as long as the connection: in the web layer, until the response is sent and `CoreServices::close()` runs `closeAll` (as built), or the process ends. Streamed exports and file serving keep it for the transfer. An import chunk keeps it for its few seconds. Nothing waits on a shared lock except behind an exclusive one.

### 4.4 What the lock does not cover

`tools/admin/reset-password.php` and the installer open only the system database. The archive check (`UntrustedProject`) opens staging copies, never a project folder. Snapshots are taken through an open (locked) connection. M6's command-line driver must use `Core\App` operations, which use the opener (section 21 item 7 tells M6).

### 4.5 Repository rule (WP1)

`tests/repo/CoreLayerRulesTest.php` gains two token-scan rules: the only file under `src/` that calls `ProjectFile::open` is `src/Core/App/ProjectOpener.php` (as built it is already the only one); and the files that may call `Sqlite::open` at all are exactly `src/Core/Schema/ProjectFile.php`, `src/Core/System/SystemDatabase.php`, `src/Core/Io/ImportJob.php` (its job database, as built), and `src/Core/App/ProjectOpener.php` (`openExclusive`, new). A new caller fails the test and needs a reviewed allowlist entry.

### 4.6 Format document (WP1)

`docs/formats/tiffin.md` section 1 lists `project.lock` in the project folder ("an empty file TiffinDB uses to keep other requests out while it restores or deletes the project; safe to delete when nobody is using TiffinDB"). Section 10 gains the reason row `pre-restore` ("Restoring a snapshot (a copy of the project as it was just before)"), and its paragraph "Restoring by hand" begins "An Admin can restore a snapshot in TiffinDB (Project settings, Snapshots). To restore by hand instead, ...". No format version change: older TiffinDB versions ignore the lock file and skip snapshot names with an unknown reason (as built, `Snapshots::list`).

## 5. Snapshot restore (`Core\App\SnapshotOperations`, M7-WP1) (security-critical)

### 5.1 Values

```php
final class SnapshotScreen { /* ProjectRecord $project; list<SnapshotInfo> $snapshots; bool $canRestore (locks available: `screen()` opens the project through the opener, as every read does, and reads `ProjectOpener::locked($projectKey)` (new: true when its shared lock was got, false for "none"); no second descriptor of the lock file is opened, 4.2) */ }
final class RestoreScreen {
    // ProjectRecord $project; SnapshotInfo $snapshot;
    // int $formatVersion (the snapshot's user_version);
    // int $recordsNow; int $recordsThen (live records, all tables);
    // int $filesMissing (rows in the snapshot whose file is not stored);
    // int $filesRemoved (stored files no row of the snapshot names)
}
final class RestoreResult { /* SnapshotInfo $restored; SnapshotInfo $preRestore; int $filesMissing; int $filesRemoved; ?int $upgradedFrom */ }
```

### 5.2 Methods (after `Actor $actor, string $projectKey`)

| Method | Does |
|---|---|
| `screen()` | `SnapshotScreen` |
| `take()` | As built (manual snapshot, audited `snapshot.taken`) |
| `list()` | As built |
| `restoreScreen(string $snapshotId)` | `RestoreScreen`; reads the live project and the checked candidate (5.3 steps 1 to 3) without changing anything; the candidate folder is removed before returning |
| `restore(string $snapshotId)` | `RestoreResult` (5.3) |

Every method begins with `Context::project($actor, Permission::ManageSnapshots, $projectKey)` (Admins only, as built: a granted Member is `AccessDenied`, an ungranted person `NotFound`), then `Context::requireCurrentActor($actor)` (as built, outside a transaction) for `restoreScreen` and `restore`; `restore` also runs `Context::requireCurrentAdmin($actor)` in a short system `Database::write()` just before step 4 (it needs a transaction, as built). `$snapshotId` is a snapshot file name without `.tiffin`; anything for which `Snapshots::find($folder, $snapshotId . '.tiffin')` returns null is `NotFound`. `Snapshots::find` (new) returns the `SnapshotInfo` of that exact name when `list` would list it, else null.

### 5.3 `restore`, step by step

1. **Copy.** `StagingArea::create('restores')` (as built: returns the new key and folder) gives a job folder; the snapshot is copied to `<job>/candidate.tiffin` with `Fs::copyIn` (new: copies a regular file to a path that must not exist, mode `FILE_MODE`; refuses links). From here on only the copy is read.
2. **Check the copy** (`SnapshotOperations::checkCandidate`, private): first 16 bytes `SQLite format 3\0`; bytes 18 and 19 both 1 (a snapshot made by `VACUUM INTO` is never in WAL mode; anything else is refused); `Sqlite::openUntrusted` (read-only, as built); `PRAGMA application_id` = `ProjectFile::APPLICATION_ID`; meta `format` = `ProjectFile::FORMAT`; `PRAGMA user_version` from 1 to `ProjectFile::VERSION` and equal to meta `format_version`; meta `project_key` equal to the project's key; `PRAGMA integrity_check(1)` `ok`. Any failure or any `PDOException`: `ValidationFailed([Problem(null, 'problem.snapshot.unreadable')])`, the job folder is removed, nothing else happens. The snapshot comes from TiffinDB's own folder, which belongs to the trusted data folder; this check guards against damage and mix-ups, not against a hostile file, which is why the archive's hostile-file checks (`UntrustedProject`) are not reused (a hand-edited file in the data folder is as trusted as a hand-edited project file, ADR 0017).
3. **Counts** (for `restoreScreen` and the result): live records now (sum over `_tiffin_tables` of `COUNT(*) WHERE "_trashed_at" IS NULL`, through `Records` as the project settings totals do), records then (the same on the candidate, by literal SQL over the validated table keys read from its `_tiffin_tables` with `Identifier`), `filesMissing` (distinct `sha256` of the candidate's `_tiffin_attachments` for which `AttachmentStore::path` is null, as built), `filesRemoved` (hashes of `AttachmentStore::storedHashes()`, as built, that no candidate row names). The candidate's connection is closed after counting.
4. **Exclusive.** `[$db, $lock] = ProjectOpener::openExclusive($project)`. `Busy` (others still working) ends the restore here and reaches the kernel's busy page; `LocksUnavailable` becomes `ValidationFailed([Problem(null, 'problem.lock.unavailable')])`. Either way the job folder is removed and nothing has changed.
5. **Pre-restore snapshot.** `Snapshots::take($db, $folder, SnapshotReason::PreRestore)` (automatic, pruned with the others; the one just taken always stays, as built). Then `PRAGMA wal_checkpoint(TRUNCATE)` and `$db->close()`.
6. **Swap.** A `project.tiffin.restoring` left by an interrupted restore is removed first (`Fs::remove`; under the lock nobody else can be using it). `Fs::moveIn(<job>/candidate.tiffin, <folder>/project.tiffin.restoring)` (as built: rename, or copy then remove across devices), then `Fs::replace(<folder>/project.tiffin.restoring, <folder>/project.tiffin)` (new: `rename` over an existing regular file in the same folder; refuses when either path is a link or the target is not a regular file; `\RuntimeException` on failure, leaving the target as it was). Then `Fs::remove` on `project.tiffin-wal` and `project.tiffin-shm` when present (they belong to the old file; after step 5's checkpoint and close they are normally absent).
7. **Release** the lock. Remove the job folder.
8. **Reopen** with `ProjectOpener::open` (shared lock again): a snapshot of an older format is upgraded after its own `format-upgrade` snapshot (`upgradedFrom` set). In one `Database::write()`: `ProjectFile::setName($db, $project->name)` (the registry name wins), `ProjectFile::touch($db, $actor->userKey, clock)`.
9. **Files.** `Attachments::sweepFiles()` (as built, M4: removes stored files no row names), then count `filesMissing` again on the live file.
10. **Audit** after everything: `snapshot.restored` (section 11.2).

Failure handling: a throwable at steps 1 to 5 leaves the live file untouched (step 5's snapshot is the only write, outside the project). A throwable at step 6 before `replace` leaves the live file untouched and the `.restoring` file is removed by a `finally` block; `replace` is one `rename`. A throwable at steps 8 or 9 leaves the restored file in place (the restore happened) and is rethrown; the audit row is still written before rethrowing (with the counts known). Test seam: `Context::seam('restore.<step>')` (as built mechanism) at steps 2, 5, 6 (before and after `replace`), 8, 9.

Concurrent restores of one project: the second waits on the exclusive lock and then restores its own choice; the result is the second choice, and both are audited with their own pre-restore snapshots. Two Admins doing this at once is outside household use and is not guarded further.

### 5.4 What a restore does not do

It does not change the registry (name, state), grants, plugins, or anything in the system database. It does not restore attachment files (snapshots hold none); `filesMissing` are shown as missing, which serving already answers with "not found" (M4). Collections, forms, the import log, trash, and record ids come back exactly as they were in the snapshot.

## 6. Project deletion (`Core\App\ProjectOperations`, M7-WP1) (security-critical)

### 6.1 Values

```php
final class ProjectDeleteScreen {
    // ProjectRecord $project;
    // int $records (live); int $trashed; int $files; int $fileBytes; int $snapshots; int $members (granted Members, active or not);
    // bool $folderMissing (P239)
}
final class ProjectDeleted { /* string $name; int $records; int $trashed; int $files; int $snapshots */ }
```

### 6.2 Methods

| Method (after `Actor $actor, string $projectKey`) | Does |
|---|---|
| `deleteScreen()` | `ProjectDeleteScreen`. Counts as the project settings totals (`attachmentTotals`, as built) and `Snapshots::list`. When nothing at all is at `DataDirectory::projectFolder($key)` (`Fs::exists` false, lstat-based) the project is not opened: every count is 0 and `ProjectDeleteScreen::$folderMissing` (new, bool) is true (P239) |
| `delete(string $typedName, int $expected)` | `ProjectDeleted` (steps below) |

Both begin with `Context::project($actor, Permission::ManageProject, $projectKey)` (Admins only) and `Context::requireCurrentActor($actor)` (as built, outside a transaction); `delete` also runs `Context::requireCurrentAdmin($actor)` inside step 3's write. Steps of `delete`:

1. **Name.** `Text::trim($typedName)` compared with the registry name ignoring ASCII case (`strcasecmp` on both, after the trim; names are unique ignoring ASCII case, as built). Mismatch: `ValidationFailed([Problem('confirm-name', 'problem.project.delete_name')])`.
Missing folder (P239): when `Fs::exists(DataDirectory::projectFolder($key))` is false at step 2, the deletion skips the counts (they are 0), the lock (step 4), the move (step 5), and the removal and release (steps 7 and 8): steps 1, 3, 6, and 9 run, so the registry row, its grants, and the audit entry (`records`, `trashed`, `files`, `snapshots` all 0) are handled as for any project. The check is made again inside step 3's write; a folder that appeared meanwhile makes the request take the normal path from step 4. Anything at the path that is not a directory (a symbolic link, dangling or not, or a file) is not "missing" and is refused at step 5 as before.

2. **Counts.** `records + trashed` counted again; when greater than `$expected`: `ConfirmationRequired` (as built, the P82 pattern), nothing changed.
3. **Mark.** One system `Database::write()`: `requireCurrentAdmin`; re-read the row by key; unless its state is `active`, `NotFound`; `ProjectRegistry::setState($id, ProjectRecord::STATE_DELETING)`. From now on `Context::project` gives `NotFound` for it (as built for any state but `active`), and no list shows it.
4. **Lock.** `ProjectOpener::lockExclusive($projectKey)`. On `Busy` or `LocksUnavailable`: set the state back to `active` (system write), then rethrow `Busy`, or throw `ValidationFailed([Problem(null, 'problem.lock.unavailable')])`.
5. **Move.** The folder `DataDirectory::projectFolder($key)` must be a directory and not a link (`Fs::isDir`, lstat-based, as built); then `StagingArea::reserve('deleted', $key)` (new: returns `<data>/staging/deleted/<key>` without creating it; `\RuntimeException` when it exists) and `Fs::moveIn($folder, $target)` (as built: `rename` of a directory; no copy fallback for directories). On failure: state back to `active`, release the lock, `\RuntimeException` becomes `ValidationFailed([Problem(null, 'problem.project.delete_failed')])`.
6. **Forget.** One system write: `ProjectRegistry::remove($id)` (as built; grants cascade, audit `project_id` set null).
7. **Remove** with `Fs::removeTree($target, <data>/staging)` (as built, staging-only, never follows links), still holding the lock, whose file now lies inside the moved folder (removing an open, locked file is harmless on the file systems TiffinDB supports; on NFS the removed open file stays as a hidden `.nfs` entry until it is closed, so the folder removal fails there and is left to the sweep). A failure is logged without a path; the staging sweep finishes the removal later.
8. **Release** the lock.
9. **Audit** `project.deleted` (section 11.2) after step 6, whatever step 7 did.

Staging sweep for `deleted` (`StagingArea`, WP1): an entry whose `project.lock` exists is removed only when an exclusive `flock` on it can be had at once (the file is opened without creating it; an entry whose lock is held is skipped, because a deletion is still removing it); an entry with no `project.lock` (the removal has already taken it) is removed only when the entry's own modification time is over an hour old, since a running removal keeps changing it. The kinds' `MAX_AGE` map gains `deleted` 3600 (used for that second case) and `restores` 3600.

Recovery from a crash: `ProjectOperations::clearAbandoned` (as built for `creating` rows older than an hour, and run at step 0 of `create`) also handles every `deleting` row whatever its age, and is also run by the staging sweep (P238): `Context::sweepStaging` (as built: called by imports, exports, and file uploads) calls it first, outside any write, through `ProjectOperations::clearAbandoned` made `@internal` public; a failure is logged without a key or path and never stops the caller. A `deleting` row is therefore finished on the next import, export, or upload, not only at the next project creation: when `projects/<key>` still exists and is a directory, it is moved as in step 5 (skipped, and retried next time, when the exclusive lock cannot be had at once); then the row is removed and the staging folder removed as in step 7. No audit row is written by the sweep (the original request either wrote it or died before step 6).

### 6.3 What deletion does not do

It does not touch other projects, the people, or the audit log's rows other than the `project_id` the schema already nulls. It does not offer an undo. The project archive is offered on the confirmation page (screens spec), not made automatically.

## 7. Audit listing (`Core\App\AuditOperations`, `Core\System\AuditLog`, M7-WP1)

### 7.1 Values

```php
final class AuditQuery {
    public function __construct(public readonly ?int $actorUserId, public readonly ?int $projectId, public readonly ?string $action, public readonly bool $deletedProjects = false) {}
}
final class AuditRow {
    // int $id; string $at (Timestamp text); ?string $actorName (display name; null when no person acted);
    // ?string $actorKey; string $action; ?string $projectName (current name, or the deleted project's
    // name for project.deleted, else null); ?string $targetKind; ?string $targetKey;
    // array<string, string|int|bool|null> $detail
}
final class AuditChoices { /* list<array{key: string, name: string, active: bool}> $people; list<array{key: string, name: string}> $projects; list<string> $actions (AuditAction::ALL in its order) */ }
final class AuditPage { /* list<AuditRow> $rows; int $total; int $page; int $pages; AuditChoices $choices; ?string $person; ?string $project; ?string $action (the filters as applied, keys) */ }
```

### 7.2 `AuditLog` (changed)

- `search(AuditQuery $q, int $limit, int $offset): list<AuditEntry>` and `count(AuditQuery $q): int`. The `WHERE` clause is built from literal fragments only: `actor_user_id = ?`, `project_id = ?`, `action = ?`, each added when its filter is set, joined with ` AND `; order `at DESC, id DESC` (as `recent`). `deletedProjects` adds `project_id IS NULL AND action = 'project.deleted'` (used by the project filter value `deleted`, 7.3).
- `recent` stays (as built) for existing callers.

### 7.3 `AuditOperations::page(Actor $actor, array $query): AuditPage` (new)

- `Authorizer::require($actor, Permission::ViewAudit)` (instance-wide, Admins only, as built); then `Context::requireCurrentActor($actor)` (as built; the page writes nothing, so no transaction is opened).
- `$query` keys (strings, from the query string): `person` (a user key), `project` (a project key, or the literal `deleted`), `action` (a value of `AuditAction::ALL`), `page` (`^[1-9][0-9]{0,5}\z`). A value that is not a string, does not match, or names nothing that exists is ignored (that filter is off), never an error, so a stale bookmark still shows a page. The page is clamped to `1..pages` (P84 pattern).
- 50 rows per page (`AuditOperations::PAGE = 50`).
- Names: `actorName` from `Users::byId` (deactivated people included); `projectName` from the registry by id (any state); for `project.deleted`, `detail['name']` when it is a string. Values are returned as stored; escaping is the template's.
- `AuditChoices`: every person (by display name, then key), every active project (by name), and the action list.

## 8. Repeated failed upgrades (P183, `Core\Schema\FormatMigrations`, M7-WP1)

`FormatMigrations::upgrade` (as built: snapshot, then each step) changes its first step: when `Snapshots::list($projectFolder)` is not empty and its first (newest) entry has reason `format-upgrade`, that file is opened with `Sqlite::openUntrusted`; when its `PRAGMA user_version` equals `$from` and its meta `project_key` equals the file's own, no new snapshot is taken (the earlier attempt from this version already saved this state; a failed upgrade leaves the project unopenable, so nothing else has changed it). Any error while reading that snapshot means a new snapshot is taken as before. `upgrade` gains no parameter; the key is read from `$db`. This bounds `format-upgrade` snapshots to one per starting version, so repeated failures cannot prune older snapshots (ADR 0021 decision 6). Test T-ADM-21.

## 9. Admin recovery (`Core\App\RecoveryOperations`, `Core\Install\RecoveryCode`, M7-WP2) (security-critical)

### 9.1 `RecoveryCode`

```php
final class RecoveryCode
{
    public const FILE = 'recovery-code.txt';
    public const VALID_SECONDS = 1800;
    public const MAX_FILE_BYTES = 1024;

    /** Writes <data>/recovery-code.txt with a new code (SetupCode::generate) unless a valid one exists; true when written. */
    public static function start(string $dataPath, Random $random, \DateTimeImmutable $now): bool;
    /** The stored code when the file exists, is a regular file of at most MAX_FILE_BYTES, parses, and has not expired; else null. */
    public static function current(string $dataPath, \DateTimeImmutable $now): ?string;
    public static function remove(string $dataPath): void; // no error when absent
}
```

- File text, exactly: `TiffinDB Admin recovery code\n<code>\nCreated <Timestamp>\nThis code works once, for 30 minutes. TiffinDB deletes this file when it is used.\n`. Written with `Fs::writeNew` (never overwrites; mode `FILE_MODE`). An expired or unreadable file is removed first, then the new one written. A symbolic link at the path is never followed: `current` returns null and `start` refuses (logs, returns false).
- The code is read back only by `current`; it is never returned to the web layer.

### 9.2 Methods (`RecoveryOperations`; no `Actor`: the person is signed out)

| Method | Does |
|---|---|
| `start(bool $enabled, string $clientAddress): void` | `NotFound` when `$enabled` is false. Otherwise one system `Database::write()` (the throttle methods need one, as built): when `LoginThrottle::wait(['s:recoverystart'])` is over 0, nothing else happens; else `LoginThrottle::failure('s:recoverystart', 5)` (every start counts, so the throttle's own rules limit starts), then `RecoveryCode::start`. After the write, when not throttled, audit `auth.recovery_started` with `{written: bool}`. Returns nothing either way; `$clientAddress` is not used for this bucket (a global limit, as the setup code's, P76) |
| `recover(bool $enabled, string $username, string $code, string $password, string $again, string $clientAddress): RecoveryResult` | Section 9.3 |

`$code`, `$password`, `$again` carry `#[\SensitiveParameter]`. `RecoveryResult { string $outcome ('done', 'failed', 'wait'); int $waitSeconds }`.

### 9.3 `recover`, step by step

1. `NotFound` when `$enabled` is false.
2. Password problems: `$password !== $again` gives `Problem('password-again', 'problem.password.mismatch')` (new key, section 11.3; the change-password page checks this in its controller with `account.password.mismatch`, as built, but recovery has no signed-in controller logic to lean on); then `PasswordPolicy::problems($password, $username)` (as built). Any problem: `ValidationFailed` (these depend only on what was typed and disclose nothing).
3. Throttle, in a short system `Database::write()` (`wait` needs a transaction, as built): `LoginThrottle::wait([LoginThrottle::addressBucket($s, $clientAddress), 's:recovery'])`; over 0: `RecoveryResult('wait', $seconds)`; nothing else runs.
4. `$hash = PasswordHasher::hash($password)` always, before any lookup, so every outcome costs the same.
5. In one system `Database::write()`: the same `wait` again (another request may have blocked the buckets while this one hashed); over 0: `RecoveryResult('wait', $seconds)`, nothing changed. Then `$expected = RecoveryCode::current(...)`; `$person = Users::byUsername($username)` (as built: it normalises the name itself); `$ok = $expected !== null && SetupCode::matches($expected, $code) && $person !== null && $person->active && $person->role === Role::Admin`. When `$ok`: `RecoveryCode::remove` first (inside the write, so a second request with the same code, which waits for this write, finds no code; when the file cannot be removed the write still goes on and step 6 logs it), then `Users::setPassword($person->id, $hash, false)`, `Sessions::endAllFor($person->id)`, `LoginThrottle::clear` of the person's user bucket (`LoginThrottle::userBucket`, as built), `clear('s:recovery')`. When not: `LoginThrottle::failure('s:recovery', 5)` and `failure(addressBucket, LoginThrottle::ADDRESS_LIMIT)`; when the `s:recovery` bucket has now reached 5 failures, `RecoveryCode::remove`.
6. After the write, when `$ok`: when the code file could not be removed in step 5, the line `TiffinDB: the recovery code file could not be removed; delete it by hand.` is logged (no path, no code); the code then stays usable until it expires. Then audit `auth.recovered` (actor null, target `user`, key the person's key). Result `done`.
7. Otherwise result `failed`.

Note on step 6: a code whose file could not be removed stays usable until it expires. This is recorded as an accepted residual risk (P227): anyone who could use it can read the file, and so already has file access to the data folder.

Note on the global bucket (P237): `s:recovery` and `s:recoverystart` count every visitor together, so a visitor without file access can use up the five attempts, which deletes the code file, and keep the bucket blocked, holding up recovery for as long as they keep at it. This is accepted: a limit per address would need TiffinDB to trust proxy headers to tell visitors apart, and the visitor gains nothing but the delay. `docs/host/accounts-and-recovery.md` says so plainly and names the ways that still work (a second Admin; `tools/admin/reset-password.php` with a shell; turning `admin_recovery` off).

### 9.4 What recovery never does

It never signs anyone in, never names whether a username exists or is an Admin, never shows or logs the code, never writes outside the data folder, and never works for a Member or an inactive account. It does not reactivate accounts.

### 9.5 Configuration

`config/local.php` key `admin_recovery` (bool, default true; WP0 adds it to `Kernel::LOCAL_KEYS`, section 15). The web layer passes it as `$enabled` (screens spec section 4). Plan section 3 row 6's default is applied: built, enabled unless the operator turns it off.

### 9.6 No schema step

The code lives in the file; attempts use `login_throttle` rows with buckets `s:recovery` and `s:recoverystart`, which the bucket pattern accepts as built (`s:[a-z]+`). System schema stays at version 2 (plan 9.1).

## 10. About facts (`Core\App\AboutOperations`, M7-WP2)

```php
final class AboutFacts { /* string $version; list<AboutComponent> $components; list<AboutPlugin> $plugins; ?array{php: string, sqlite: string} $server */ }
final class AboutComponent { /* string $id; string $name; string $version; string $licence */ }
final class AboutPlugin { /* string $name; string $version; string $kind ('import'|'export'); string $licence; bool $enabled */ }
final class LicenceText { /* string $name; list<array{file: string, text: string, cut: bool}> $files */ }
```

| Method | Does |
|---|---|
| `about(Actor $actor): AboutFacts` | `Context::requireCurrentActor($actor)` (as built, outside a transaction; the page writes nothing). Components from `config/notices.json` (10.1). Plugins from `Context::plugins()` (as built): every plugin whose manifest was read (not broken), with its manifest name, version, kind, and licence; for a Member only enabled ones. `server` for Admins only: `PHP_VERSION` and `SELECT sqlite_version()` on the system database |
| `licence(Actor $actor, string $id): LicenceText` | `requireCurrentActor`; `NotFound` for an id not in the notices; each listed file read (10.1) |

### 10.1 Reading the notices and licence files

- `config/notices.json` is read from the application root (`dirname(__DIR__, 3)` of `src/Core/App`, as `Context` finds `plugins/`), at most 64 KiB, with `json_decode` (`JSON_THROW_ON_ERROR`); a malformed file or element is a `\RuntimeException` (a broken release, 500 page). Elements are validated against section 3.6's shape.
- A licence path must match `^(LICENSE|vendor/[A-Za-z0-9._/-]+|public/assets/fonts/[a-z0-9-]+/[A-Za-z0-9._-]+)\z`, hold no `..` segment, and name a regular file that is not a link (`Fs::isFile`, as built). Each file is read at most 256 KiB (larger: cut, and `cut` is true, so the page says the rest is in the file), invalid UTF-8 replaced with U+FFFD. The text is returned as data; the template escapes it inside `pre`.
- The test fixture for `AboutOperations` points the application root at a temporary folder through a constructor default (`AboutOperations::__construct(Context $c, ?string $root = null)`), as `Context::usePluginsRoot` does for plugins.

## 11. Core operations: permissions, audit actions, message keys

### 11.1 Permissions

| Operation | Admin | Granted Member | Ungranted / signed out |
|---|---|---|---|
| `SnapshotOperations::*` | yes | access denied | not found |
| `ProjectOperations::deleteScreen`, `delete` | yes | access denied | not found |
| `AuditOperations::page` | yes | access denied | access denied (instance-wide, as built) |
| `AboutOperations::about`, `licence` | yes (with server facts) | yes (no server facts, enabled plugins only) | (route needs sign-in) |
| `RecoveryOperations::*` | (signed out only; route `guest`) | | |

A Member is refused before anything is read. Every new method then checks that the acting person is still who the session says (`requireCurrentActor`, outside a transaction); the methods that change something re-check the current Admin (`requireCurrentAdmin`) inside a system write before the change.

### 11.2 Audit actions (amends ADR 0012 decision 4)

`AuditAction` gains (WP1): `SNAPSHOT_RESTORED = 'snapshot.restored'`, `PROJECT_DELETED = 'project.deleted'`; (WP2): `RECOVERY_STARTED = 'auth.recovery_started'`, `RECOVERED = 'auth.recovered'`. Each is in `ALL`, so `AuditTrailTest` requires a test writing it (each package adds its own).

| Action | Actor | Project | Target | Detail |
|---|---|---|---|---|
| `snapshot.restored` | the Admin | the project | `snapshot`, the restored file name | `{pre_restore: <file name>, files_missing: int, files_removed: int, from_version: int}` |
| `project.deleted` | the Admin | null (the row is gone) | `project`, its key | `{name: <the project's name>, records: int, trashed: int, files: int, snapshots: int}` |
| `auth.recovery_started` | null | null | none | `{written: bool}` |
| `auth.recovered` | null | null | `user`, the person's key | `{}` |

`project.deleted` is the one action whose detail holds a name: a project name is an Admin-chosen label already shown to every granted person, not a field value, and without it the audit screen could not say which project was deleted (ADR 0022 decision 3). No entry holds a code, a password, a typed name, or a path.

### 11.3 Message keys produced by the core (new, `strings/en/problems.php`)

| Key | Package | English | Params |
|---|---|---|---|
| `problem.snapshot.unreadable` | WP1 | This snapshot cannot be used. It is damaged, or it belongs to another project. | |
| `problem.project.delete_name` | WP1 | Type the project's name exactly as it is shown above. | |
| `problem.project.delete_failed` | WP1 | TiffinDB could not move the project folder, so nothing was deleted. The person who looks after this TiffinDB can check the data folder. | |
| `problem.lock.unavailable` | WP1 | This server cannot lock files, so TiffinDB cannot do this safely here. Nothing was changed. The host documentation describes how to do it by hand. | |
| `problem.password.mismatch` | WP2 | The two new passwords are different. | |
| `problem.recovery.failed` | WP2 | That did not work. Check the username and the code, or start again for a new code. | |

`problem.password.*` keys are reused (as built). `tests/core/app/MessageKeysTest.php` reads this table as it reads the M4 and M5 tables: WP1 adds a private `m7Keys()` that takes the text of this file from `\n### 11.3 Message keys produced by the core` to `\n## 12.`, collects the keys of the first table cells with the same pattern `m5Keys()` uses (as built), asserts at least 4 and that `problem.snapshot.unreadable` is among them, and adds them to the listed keys of the M1 to M5 check (WP1 adds its rows; WP2 its rows). The error page for `Unavailable('system-newer')` is WP0's (`strings/en/errors.php`, section 12.2).

## 12. Upgrade path (ADR 0021 decision 6; procedure by WP8, tests by WP7)

### 12.1 Procedure (written into `docs/host/upgrade.md`)

1. Make a backup: a project archive of each project (Export, project archive), or a copy of the whole data folder made while nobody uses TiffinDB.
2. Download the new release archive and check its SHA-256 against the published `.sha256` file.
3. On the host, delete these folders of the installed copy: `public/assets/`, `src/`, `templates/`, `strings/`, `vendor/`, `tools/`, `docs/`, and each plugin folder listed under `plugins/` in the installed copy's own `MANIFEST.txt` (the old one, so that a plugin the new release no longer ships does not stay behind and keep loading) and in the new one (`plugins/import/csv/` and so on). Keep `config/local.php`, the data folder, and plugins you added yourself.
4. Upload the new files over the installed copy (the archive's top folder's contents into the installation folder).
5. Open TiffinDB. The system database upgrades on the first request after a copy named `system-before-v<n>-<time>.sqlite` beside it; each project upgrades when first opened after a `format-upgrade` snapshot.
6. If a page says a newer TiffinDB wrote the data, the copy uploaded is older than the data: upload the newer release again.

### 12.2 Newer data refused (WP0)

`SystemSchema::migrate` (as built) throws `Unavailable(Unavailable::NEWER)` for a newer system database, which the kernel shows with the project wording "This project needs a newer TiffinDB". WP0 adds `Unavailable::SYSTEM_NEWER = 'system-newer'`, thrown by `SystemSchema::migrate` instead, shown by the kernel as 503 with `error.unavailable.system-newer.title` "This TiffinDB needs a newer version" and `.body` "Its data folder was written by a newer version of TiffinDB. Nothing was changed. Upload that newer version, or the one you upgraded from, to open it again." The project case (`newer`) stays.

### 12.3 Tests (WP7)

`tests/Support/HistoricFixture.php` builds data folders from the documented SQL only (as `FormatUpgradeTest::buildVersionOne`, as built, does for projects):

- system schema version 1 (`SystemSchema::STEPS[1]`) and version 2 (as built);
- a project file at each format version 1, 2, 3, 4: version 1 from the SQL of `docs/formats/tiffin.md` section 4 as it stood for version 1 (the "before" state of section 11.1, which `FormatUpgradeTest` already reads), then the documented steps of sections 11.1 to 11.3 up to the wanted version; synthetic records ("Home Inventory", Items and Rooms, a relation, one attachment row with its file);
- each combination wired into a registry row and a grant.

| Test | Checks |
|---|---|
| T-UPG-1 | Every system version (1, 2) with every project version (1 to 4) opens under the release code through `Operations` (a Member lists records): system at version 2 with one `system-before-v2-*` copy when it started at 1; the project at version 4 with exactly one `format-upgrade` snapshot holding the old version; records, links, attachment rows unchanged |
| T-UPG-2 | Opening again changes nothing and takes no snapshot |
| T-UPG-3 | A system database at version 3 (fixture adds a meta row) gives 503 with `system-newer` through the kernel; the database file's bytes are unchanged |
| T-UPG-4 | A project at version 5 gives the project `newer` page (as built); unchanged bytes |
| T-UPG-5 | Simulated next project step through `FormatMigrations`' seam (as built): a version 4 file goes to 5 with one snapshot |
| T-UPG-6 | Simulated next system step through `SystemSchema::migrate($db, $steps)` (as built): a third step runs once, after one copy |
| T-UPG-7 | A failing simulated step, opened three times: exactly one `format-upgrade` snapshot (P183, section 8); then a working step succeeds and the data is the original |
| T-UPG-8 | Through the kernel with the built-in server fixture (as `EndToEndTest`): a version 1 installation signs in and shows the project list and a record |

## 13. Static assets, icons, probe port

Designs: `docs/specs/M7-screens.md` sections 3 and 8 (WP3) and section 15 item CF7-2 (WP0). Decisions: ADR 0021 decisions 7 to 9.

## 14. Documentation (M7-WP8, WP9)

### 14.1 `docs/host/` (WP8)

| File | Content |
|---|---|
| `README.md` | Who this is for; the chapter list; the reserved place for a later chapter "Importing a whole database from another application on the command line" (an HTML comment only, `<!-- reserved for a later chapter; see docs/specs/M7-release.md section 14.3 -->`, no file) |
| `requirements.md` | PHP 8.3 or later; extensions `pdo_sqlite`, `zip`, `xmlreader` (required), `dom`, `filter`, `libxml` (XLSX); SQLite 3.35.5 or later (P55); Apache with `mod_rewrite` (and `mod_headers` for the static-file headers); file locks (`flock`) for restore and deletion; what the host check reports (`tools/host-check/README.md`) |
| `install.md` | The two layouts (web root at `public/`, or the whole folder under the web root with the root `.htaccess`); upload and unpack; the setup page and the setup code file; where the data folder goes and how the installer tests it (M1); `config/local.php` |
| `configuration.md` | Every `config/local.php` key: `data_dir`, `timezone`, `secure_cookies`, `probe_port`, `admin_recovery`, with an example file using synthetic values |
| `https-and-proxies.md` | HTTPS and `secure_cookies`; reverse proxies: the `Origin` check compares the browser's origin with the request's own scheme and host, so a proxy must pass the original `Host` and tell PHP about HTTPS, or sign-in and every form are refused (ledger, M2-WP0 review); `probe_port` behind a proxy |
| `upgrade.md` | Section 12.1 |
| `backup-and-restore.md` | Project archives (and their 64 MiB upload cap, P136); snapshots: what they hold (the database, not files), restoring in TiffinDB, restoring by hand (format sections 10 and 13.4); backing up a large project by copying its folder while nobody uses TiffinDB (format section 9); the system database |
| `accounts-and-recovery.md` | Keep two Admins; resetting a Member's password (People); `tools/admin/reset-password.php` with a shell; Admin recovery without a shell, step by step, including where the code file appears and that it is deleted after use; that the attempt limit is shared by every visitor, so someone else can delay recovery (P237), and what to do then; turning recovery off |
| `plugins.md` | What plugins are; that they run with the full privileges of PHP and of the web server account, with no sandbox, and can read every project and the system database; only add plugins you trust; how to turn one on and off (plan 6.2, stated plainly) |
| `limits.md` | Upload and import limits (P116, P139, P142, P147), archive limits, paging; what the host's PHP limits change; the known slow cases recorded in the ledger (XLSX late chunks, large archive rebuilds) as "may take longer on a shared host" |
| `host-check.md` | When to run the host check and how to read its lines, linking `tools/host-check/README.md` for the procedure |

`README.md` (repository root, WP8): what TiffinDB is (inspired by, and a replacement for, a discontinued personal database application, in prose without the product's name, as the current README does), the licence, links to `docs/host/README.md` and `docs/user/README.md`, the "Source code" offer, and for developers `composer test` and `composer lint`. No names, no employer, no email.

### 14.2 `docs/user/` (WP9)

| File | Content |
|---|---|
| `README.md` | Chapter list; the reserved place for "Bringing in a whole database from another application" (HTML comment only, as 14.1) |
| `first-steps.md` | Signing in, the project list, opening a project, the sidebar, the top bar, signing out |
| `records.md` | Adding, editing, saving, the conflict page, moving to the trash and Undo, linked records, required fields |
| `lists-and-search.md` | Finding, sorting, filtering, paging, searching all tables |
| `collections.md` | Making, editing, and deleting a collection; rules; "all" and "any" |
| `files.md` | Adding, opening, replacing, removing files; which files show as images |
| `trash.md` | Restoring; emptying (Admins) |
| `forms.md` | For Admins: the form editor, the source view, download and upload, back to the standard form (from the M5 screens) |
| `import-and-export.md` | Importing CSV and XLSX; exporting CSV, XLSX, JSON; project archives |
| `display-settings.md` | Every appearance setting |
| `for-admins.md` | People and sharing, tables and fields, plugins, snapshots and restore, deleting a project, the activity log (the screens of M1, M3, and M7) |

`for-admins.md` is added to the session plan's list because the Admin screens of M1 and M7 are used in the browser, not on the host.

### 14.3 Rules and tests for both

- Examples use synthetic data only ("Home Inventory", "Stand mixer", "Example Admin", `user@example.com`, `192.0.2.10`), no screenshots (plan 8.2 row 9), no names of real people, no employer, no product name of the legacy application.
- Button labels and screen names are written in bold (`**Move to trash**`) and in no other way; bold is used for nothing else. `tests/repo/UserDocsTest.php` (WP9) collects every bold span in `docs/user/*.md` and requires each to equal, character for character, the English value of some key in `strings/en/*.php` that holds no placeholder (or the text with placeholders matching `{...}` as any text).
- `tests/repo/HostDocsTest.php` (WP8) collects every backtick span in `docs/host/*.md` and `README.md` that starts with a shipped top folder (`public/`, `src/`, `tools/`, `config/`, `docs/`, `plugins/`, `vendor/`) or is a `php tools/...` command, and requires the path (or the command's script) to exist in the tree (or, for `config/local.php` and the data folder names, to be in a fixed allowlist of paths that exist only on an installation). Every `config/local.php` key named must be in `Kernel::LOCAL_KEYS`.
- The reserved places for the importer chapters are named in 14.1 and 14.2; M7-WP11 replaces each comment with a link to the new chapter. Nothing else in part 1 refers to it.

## 15. Carried-forward items (plan 9.3; confirmed against the tree at `0c3ad18`)

### 15.1 Decided and assigned to WP0

| ID | Item (source) | Confirmed | Change |
|---|---|---|---|
| CF7-1 | `Origin` over 2048 characters, with a tab, or with non-ASCII bytes is read as absent (M2-WP0 review) | `Request::origin` returns null for those (as built) | `Request` gains `public readonly bool $originMalformed`: true when an `Origin` header was sent and failed the check. `Kernel` refuses an unsafe-method request with `originMalformed` true with the 403 CSRF page, before the CSRF check, whatever `Sec-Fetch-Site` says. Test T-CF7-1 (one case per malformed kind, and a well-formed foreign origin still refused as built) |
| CF7-2 | Operator-configured probe port (P80) | Port rule in `SetupController` (as built) | `Kernel::LOCAL_KEYS` gains `probe_port` (int 1 to 65535; anything else refuses the configuration as built for other keys) and `admin_recovery` (bool; the kernel passes it to the web `Context` as `adminRecovery`, default true). `SetupController` builds the probe target with `probe_port` as the server port when set (Host port and `SERVER_PORT` ignored) and keeps the P80 rule otherwise. T-CF7-2 |
| CF7-3 | Overlong file name loses its extension (P187) | `Attachments::cleanName` cuts at 255 code points (as built); `Response::contentDisposition` cuts the same way | Both cut the stem, not the extension: when the cleaned name is over 255 code points and its last `.` is followed by 1 to 16 code points that are letters or digits, the result is the first `255 - (1 + extension length)` code points of the stem, trimmed, then `.` and the extension; otherwise the present rule. T-CF7-3 (both functions, a 1,000-character name with `.pdf`, a name whose "extension" is 20 characters, a name of only an extension) |
| CF7-4 | Error-suppression operators in `tools/host-check/` (21 uses) and `tools/discovery/` (12 uses) (ledger section 7, M3 exit) | Counted at `0c3ad18` | Each `@` replaced by the file's existing error trap (`set_error_handler` around the call, restored after) or by a check before the call. `tests/repo/NoErrorSuppressionTest.php` extends its scope to `tools/` (all of it). The host check must still parse on PHP 7.4: no syntax newer than 7.4 (no `match`, no named arguments, no nullsafe operator, no `mixed`), checked by reading (no 7.4 binary here), and by a token scan in `HostCheckTest` for those constructs |
| CF7-5 | Host check reports no free disk space or quota (M6 plan section 3 row 3) | No `disk_free_space` call (as built) | New line `fs.free` (INFO): free space of `<docroot>/..` (or `<script-dir>/..` from the CLI) rounded down to a whole MiB, or "not available" when `disk_free_space` is disabled or fails; WARN when under 1 GiB. Quota cannot be read portably: the line says "Quotas set by the host are not shown." The JSON block gains `free_bytes` (int or null). README updated |
| CF7-6 | No plain page for a newer system database (the project wording is shown) | Section 12.2 | `Unavailable::SYSTEM_NEWER`; `SystemSchema::migrate` throws it for a newer system database; the kernel's list of shown reasons gains it; strings `error.unavailable.system-newer.*` (section 12.2). T-CF7-6 |
| CF7-7 | Sign-in clears the user bucket outright, erasing failures counted on it while the password was being checked (M1-WP2 round 3) | `Authenticator.php` near line 128 (as built: `$this->throttle->clear($userBucket)`) | Step (a) keeps the user bucket's state it read (as it already does for the address bucket); step (c) clears the user bucket only when its row is unchanged since then (same `failures` and `last_failure_at`), else takes back the one failure step (a) counted, with `LoginThrottle::takeBack` (as built). T-CF7-7 (an interleaved wrong attempt during a right one stays counted) |
| CF7-8 | Discovery tool: replace the `sqlite_` prefix exemption with an allowlist; make `NameRedactor::extension()` fail closed; README wording of the digit-run rule (M1-WP0) | `IdentifierRedactor` near line 253, `extension()` near line 154 (as built) | Allowlist `sqlite_sequence`, `sqlite_stat1` to `sqlite_stat4`, `sqlite_master`, `sqlite_schema`, `sqlite_temp_master`, `sqlite_temp_schema`, and `sqlite_autoindex_` by its existing recursion; any other `sqlite_` name is treated as user-chosen. `extension()` returns a placeholder instead of `printable($ext)` when the pattern does not match (unless `showNames`). README states the four-digit cap on type-like names. T-CF7-8 |

### 15.2 Decided, assigned elsewhere

| Item (source) | Decision | Owner |
|---|---|---|
| Reverse-proxy risk of the `Origin` check, for the deployment documentation (M2-WP0) | Documented | WP8 (`https-and-proxies.md`) |
| Repeated failed format upgrades each take a snapshot (P183) | Fixed (section 8) | WP1 |
| Static assets served without a CSP; nested `.htaccess` scan of release contents (ledger section 7) | Fixed (ADR 0021 decision 7; rule R4) | WP3; WP6 |
| Snapshot restore, project deletion, audit-log screen (P49, P170) | Built | WP1, WP4, WP5 |
| Admin recovery without shell access (P27, P49) | Built | WP2, WP5 |
| Words that break mid-word at 320 px with largest text (M1-WP8d, M2 exit) | `app.css` uses `overflow-wrap: anywhere` in nine rules (as built); WP3 changes those on labels, legends, headings, buttons, sidebar and top-bar links, and table headers to `overflow-wrap: break-word` with `min-width: 0` on their flex or grid items, so only a word longer than its line breaks; values and file names keep `anywhere` (a long single token must break). Accepted if a case remains | WP3 |
| Stale design documents (`docs/design/m1/installer.md`, `docs/design/m3/export.md`) | Fixed in the part 2 consistency pass, if still stale then | WP11 |
| XLSX late-chunk time; commit and rebuild times; upload limits; direct access to `attachments/` (M3, M4 exits) | Stay on the maintainer's host check; `limits.md` states them | maintainer (host check); WP8 text |

### 15.3 Decided: accepted with a note (no change)

| Item (source) | Reason |
|---|---|
| Throttle takeback after a window reset can lose one failure when verification stalls over 900 s (M1-WP2 round 3) | At most one failure per stalled sign-in; needs a 15-minute stall in password verification; the address bucket still limits. Recorded as P232 |
| Import "Check again" loses a typed table name; over-200-column CSV message; no test for the XLSX early stop (M3 exit) | Closed by M4-WP0 (CF-1, CF-3, CF-4 of `docs/specs/M4-core.md` section 13, merged); nothing left |
| Import commit does not re-read the actor inside the write (M3-WP8 review) | Same one-request window as every Admin operation (P78); the actor is re-checked before the snapshot (as built). Recorded as P233 |
| Bidirectional control characters kept in shown file names and headings (M2-WP5, M3-WP9, M3-WP10) | File names: removed at storage since M4 (`cleanName` removes category Cf, as built); archive file names: removed (`ArchiveController::fileName`, as built). Import review headings: shown escaped on the review page only; they become field names only through the name rules, which refuse Cf (P61). Values: escaped, not isolated. Isolation with `bdi` across every template is a wide change for a visual spoofing risk only an Admin's own file can cause. Recorded as P234 |
| First-Admin race gives 500 (M1-WP9 round 2) | Already caught: `SetupController` catches the installer's `LogicException` (as built); closed |
| Delete-table page matches incoming links by table name; `rename`/`activate` races; add-relation all-problems (M1 reviews) | Table names are unique in a project, so the match is exact; the races need two Admins acting in the same millisecond; the problems case is cosmetic. Accepted |
| URL rule and `0x` labels (M1-WP3) | Already narrowed (T-CF-2, as built: only `0x` plus hex digits); closed |

## 16. Test plan

All tests offline and deterministic with `TempDir`, `FixedClock`, `SeededRandom`; synthetic data only (`M4Fixture` "Home Inventory"). Lock tests start a second PHP process with `proc_open` (argument array, no shell) running a small script from `tests/Support/LockHolder.php` (WP1) that takes a lock on a given folder, prints a line, and waits for standard input to close.

### 16.1 Lock, restore, deletion, audit (`tests/core/`, WP1)

| Test | Checks |
|---|---|
| T-ADM-1 | `ProjectLock::shared` twice in two processes succeeds; `exclusive` while another process holds shared gives `Busy` after about 10 s (the test uses a short wait through a constant override seam: `ProjectLock::$waitScaleForTests`, `@internal`, default 1.0) |
| T-ADM-2 | `shared` while another holds exclusive gives `Busy`; after release, succeeds |
| T-ADM-3 | A symbolic link at `project.lock`: `exclusive` gives `LocksUnavailable` (restore and deletion then show `problem.lock.unavailable`), `shared` returns null and logs; nothing is created through the link |
| T-ADM-4 | `ProjectOpener::open` takes shared; `forget` and `closeAll` release (a second process can then take exclusive at once) |
| T-ADM-5 | Restore: a project with 3 records, a manual snapshot, then 2 more records and one changed: after restore, the records equal the snapshot's; a `pre-restore` snapshot exists and holds the 5-record state; the registry name is in the meta; `updated_by` is the Admin |
| T-ADM-6 | Restore of a version 3 snapshot (built by `HistoricFixture`-like SQL in the test): upgraded to 4 after one `format-upgrade` snapshot; `upgradedFrom` 3 |
| T-ADM-7 | Files: a snapshot naming file A (still stored) and file B (removed since); the live project names A and C: after restore, C's file is deleted, B counts as missing, A stays; the screen's counts before equal the result's after |
| T-ADM-8 | Failure injection at each seam point of 5.3: before `replace`, the live file's bytes are unchanged and no `.restoring` file remains; after `replace`, the restored file is in place and the audit row is written |
| T-ADM-9 | Candidate checks: wrong project key, `user_version` 0 and 5, WAL header bytes, a truncated file, a non-SQLite file: each `problem.snapshot.unreadable`, live file unchanged, staging folder removed |
| T-ADM-10 | Snapshot ids: `../x`, an existing file with an unknown reason, a name with `.tiffin`, an absolute path: `NotFound` |
| T-ADM-11 | Restore while another process holds a shared lock: `Busy`, nothing changed, no pre-restore snapshot |
| T-ADM-12 | Restore with a stale `-wal` file planted beside the live file after the checkpoint (seam): removed; the restored file opens and equals the snapshot |
| T-ADM-13 | Deletion: folder gone, registry row gone, grants gone, audit rows of the project have `project_id` null, `project.deleted` detail exact; other projects' folders and rows untouched |
| T-ADM-14 | Deletion refuses a wrong typed name (`confirm-name`), accepts different ASCII case and surrounding spaces; refuses when records grew (`ConfirmationRequired`) |
| T-ADM-15 | Deletion when `projects/<key>` is a symbolic link to a folder outside: refused, the outside folder and its files untouched, state back to `active` |
| T-ADM-16 | Deletion with a symbolic link inside the project folder pointing outside: the link is removed, its target untouched |
| T-ADM-17 | Deletion while another process holds a shared lock: `Busy`, state back to `active`, folder untouched |
| T-ADM-18 | A `deleting` row left by an interrupted deletion (seam after step 3, and after step 5): the next `clearAbandoned` finishes it; a `staging/deleted/` entry whose `project.lock` another process holds is skipped by the staging sweep, and removed once released |
| T-ADM-19 | After step 3 and before step 6, `Context::project` for that key is `NotFound` for the Admin and the project is in no list |
| T-ADM-20 | Audit search: each filter alone and in combination, `deleted`, paging and clamping, ignored bad values, names of deactivated people and deleted projects |
| T-ADM-22 | P239: deletion of a project whose folder is absent: 303 path in core succeeds, registry row and grants gone, `project.deleted` with zero counts, no lock file or staging entry made; `deleteScreen` shows `folderMissing` without opening the project; a dangling symbolic link at the folder path is refused and the state goes back to `active` |
| T-ADM-23 | P238: a `deleting` row left by an interrupted deletion is finished by `Context::sweepStaging` (an export start), not only by `create`; a sweep failure is logged and the export goes on |
| T-ADM-21 | P183: a failing step run three times leaves one `format-upgrade` snapshot; an older manual and 19 automatic snapshots all survive |
| T-ADM-30 | A granted Member is refused (`AccessDenied`) on every new `SnapshotOperations`, `ProjectOperations`, and `AuditOperations` method before anything is read (spy on the opener) |
| T-ADM-31 | An ungranted person and an unknown key: `NotFound`, the same for both |
| T-ADM-32 | An Admin made a Member after the session was resumed: refused by `requireCurrentActor` before anything is read, and by `requireCurrentAdmin` when the role changes between that check and the write (seam) |
| T-ADM-33 | `CoreLayerRulesTest`: only `ProjectOpener` calls `ProjectFile::open` (section 4.5); a planted call elsewhere fails it |
| T-ADM-34 | `AuditTrailTest` covers `snapshot.restored` and `project.deleted` |

### 16.2 Recovery and About (`tests/core/`, WP2)

| Test | Checks |
|---|---|
| T-REC-1 | `start` writes the file with the exact text; a second `start` within 30 minutes writes nothing and keeps the code; after 30 minutes writes a new one |
| T-REC-2 | `recover` with the right code, an active Admin, and good passwords: `done`; password changed, `must_change` 0, every session ended, user bucket cleared, file gone, `auth.recovered` written, no session started |
| T-REC-3 | Wrong code, unknown username, a Member's username, an inactive Admin, an expired code, no file: each `failed`, nothing changed, the same result object |
| T-REC-4 | Timing equality is not measured; instead a spy hasher records that `hash` was called once in every outcome of T-REC-3 |
| T-REC-5 | Five failures delete the code file; the sixth try is `wait` |
| T-REC-6 | Address throttling shared with sign-in: failures on one count on the other (as built buckets) |
| T-REC-7 | Password mismatch and policy problems are `ValidationFailed` and count no failure |
| T-REC-8 | `$enabled` false: `NotFound` on both methods, no file written |
| T-REC-9 | A symbolic link at the code path: `start` writes nothing through it; `recover` fails |
| T-REC-10 | No log line, exception message, or audit detail produced in T-REC-1 to T-REC-9 holds the code (captured `error_log`, exception messages, audit rows searched for the code text) |
| T-REC-11 | `start` throttle: the sixth start in a window writes nothing |
| T-REC-12 | `SetupCode::matches` is the comparison (hash_equals, as built); codes typed with spaces, dashes, lower case match |
| T-REC-13 | `AuditTrailTest` covers `auth.recovery_started` and `auth.recovered` |
| T-REC-14 | A file larger than 1 KiB or not UTF-8 at the code path: `current` is null, `start` replaces it |
| T-ABT-1 | `about` for an Admin: version from config, components from a fixture `notices.json`, every readable plugin with its enabled flag, server facts; for a Member: no server facts, enabled plugins only |
| T-ABT-2 | `licence`: unknown id `NotFound`; a path with `..`, an absolute path, a path outside the three allowed roots, a link: refused (`\RuntimeException`, broken release) |
| T-ABT-3 | A malformed `notices.json` is a `\RuntimeException` |

### 16.3 Release (`tests/tools/ReleaseBuildTest.php`, `tests/repo/ReleaseContentsTest.php`, WP6)

| Test | Checks |
|---|---|
| T-REL-1 | Two builds of a fixture tree (temporary git repository made by the test with `git init`, synthetic files, a fake prepared vendor with one package) give byte-identical zips and equal `.sha256` files, also when the second run has `TZ=America/New_York` in its environment (3.4 step 8); folder entries, then files sorted by byte value, then `MANIFEST.txt` last; times and modes fixed |
| T-REL-2 to T-REL-9 | One failing fixture per rule R1 to R8, each giving exactly that rule's message |
| T-REL-10 | Vendor refusals of 3.2: a dev package, an unlisted package, a version or commit mismatch, a listed package missing, `autoload_files.php` naming an outside file; `installed.json` and package `tests/` folders not shipped, licence files shipped |
| T-REL-11 | `ReleaseContentsTest`: the current tree's include list passes R1 to R5, R7, R8 (R6 needs a prepared vendor and runs in T-REL-10 and the CI step) |
| T-REL-12 | `--test-build` needed for a `-dev` version; a malformed version refused |
| T-REL-13 | `MANIFEST.txt` lists every other entry with its correct SHA-256, sorted, and not itself |
| T-REL-14 | The tool refuses to overwrite an existing archive and leaves no `.part` file after a failure |

### 16.4 Carried-forward (WP0)

T-CF7-1 to T-CF7-8 as named in section 15.1, in `tests/web/RequestTest.php`, `KernelTest.php`, `SetupScreenTest.php`, `ResponseTest.php`, `tests/core/query/AttachmentsTest.php`, `tests/core/auth/AuthenticatorTest.php`, `tests/core/system/SystemSchemaTest.php`, `tests/tools/HostCheckTest.php`, `DiscoverStructureTest.php`, `tests/repo/NoErrorSuppressionTest.php`. Each test fails before its change (the WP0 report states this per item).

### 16.5 Upgrade (WP7)

T-UPG-1 to T-UPG-8, section 12.3.

### 16.6 Documentation (WP8, WP9)

`HostDocsTest`, `UserDocsTest` (section 14.3).

### 16.7 Web tests

`docs/specs/M7-screens.md` section 11.

## 17. Work packages

Tiers per orchestration guide section 2. Owned files may be created or changed only by that package; packages that can run at the same time own disjoint files (check at the end). A file owned by two packages is listed in both with the part each changes, and the second starts after the first is merged. Every package starts with `git merge --ff-only <assigned branch>`, runs `composer test` and `composer lint` before committing and before reporting, and may not weaken an existing test. A package may add private helpers inside its own files and must report them.

Changes from plan 8.3, with reasons: WP2 also holds the About facts (`AboutOperations`), because the About page needs a core read of the notices and the plugin list and WP2 is the smaller core package; WP2 starts after WP1 and WP6 (it shares `AuditAction.php`, `problems.php`, `MessageKeysTest.php`, and `AuditTrailTest.php` with WP1, and reads WP6's `config/notices.json`); `Response.php`, `Request.php`, `SetupController.php`, `Authenticator.php`, `Unavailable.php`, `SystemSchema.php`, and `errors.php` join WP0 (one carried item each); `Fs.php`, `ProjectOpener.php`, `StagingArea.php`, `FormatMigrations.php`, `SnapshotReason.php`, `ProjectRecord.php`, and `docs/formats/tiffin.md` join WP1 (the lock, restore, deletion, P183); `SignInController.php` and the sign-in page join WP5 (the recovery link and notice); `SecurityHeaders.php` joins WP3 (`manifest-src`). WP5 depends on WP2 and WP3 only through files, as planned. WP7 depends on WP0 as well (the newer-system page).

| Wave | Packages | Starts when |
|---|---|---|
| 1a | WP0, WP1, WP6 | M7-D2 merged and M5 CLOSED (G-close) |
| 1b | WP2, WP3 (and WP9 in any free slot) | WP2: WP1 and WP6 merged. WP3: WP6 merged |
| 2 | WP4, WP5, then WP7, WP8, WP9 as slots free | WP4: WP1, WP3 merged. WP5: WP1, WP2, WP3 merged. WP7: WP0, WP1 merged. WP8: WP0, WP1, WP2, WP6 merged. WP9: M5 CLOSED |
| hand-over | WP12 | WP0 to WP9 merged |
| 3 (part 2) | WP10, WP11 | Part 1 merged and G7-release open; WP11 also needs M6 CLOSED |

### M7-WP0: Carried-forward hardening

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (request pipeline, sign-in throttle) |
| Owned files | `src/Web/Request.php`, `src/Web/Kernel.php` (origin refuse state; `LOCAL_KEYS`; shown `Unavailable` reasons; passing `admin_recovery`); `src/Web/Context.php` (`adminRecovery` argument and accessor only); `src/Web/Controller/SetupController.php` (probe port only); `src/Web/Response.php` (`contentDisposition` extension only); `src/Core/Query/Attachments.php` (`cleanName` only); `src/Core/Auth/Authenticator.php` (CF7-7 only); `src/Core/Error/Unavailable.php`; `src/Core/System/SystemSchema.php` (the reason only); `strings/en/errors.php`; `tools/host-check/host-check.php`, `README.md`; `tools/discovery/discover-structure.php`, `README.md`; `tests/web/RequestTest.php`, `KernelTest.php`, `SetupScreenTest.php`, `ResponseTest.php`; `tests/core/query/AttachmentsTest.php`; `tests/core/auth/AuthenticatorTest.php`; `tests/core/system/SystemSchemaTest.php`; `tests/tools/HostCheckTest.php`, `DiscoverStructureTest.php`; `tests/repo/NoErrorSuppressionTest.php` |
| Must not touch | Files of WP1, WP2, WP3, WP6 |
| Scope | Sections 12.2, 15.1 |
| Acceptance | T-CF7-1 to T-CF7-8 pass, each shown failing before its change; the host check parses on its PHP floor by inspection and by the T-CF7-4 token scan; no `@` left under `tools/`; design-tier security review (request pipeline, throttle) |

### M7-WP1: Core administration

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (restore, deletion, locks, authorization) |
| Owned files | New `src/Core/Storage/ProjectLock.php`, `LocksUnavailable.php`; `src/Core/Storage/Snapshots.php` (`find`), `SnapshotReason.php`, `Fs.php` (`copyIn`, `replace`); `src/Core/Schema/FormatMigrations.php` (section 8 only); `src/Core/App/ProjectOpener.php`, `SnapshotOperations.php`, `ProjectOperations.php`, `AuditOperations.php`; `src/Core/App/Context.php` (`sweepStaging` only, P238); new `src/Core/App/SnapshotScreen.php`, `RestoreScreen.php`, `RestoreResult.php`, `ProjectDeleteScreen.php`, `ProjectDeleted.php`, `AuditPage.php`, `AuditRow.php`, `AuditChoices.php`; `src/Core/Io/StagingArea.php` (kinds `restores`, `deleted`; `reserve`); `src/Core/System/ProjectRegistry.php`, `ProjectRecord.php`, `AuditLog.php`, `AuditAction.php`; new `src/Core/System/AuditQuery.php`; `strings/en/problems.php` (WP1 rows); `docs/formats/tiffin.md` (sections 1 and 10 only); tests: new `tests/core/storage/ProjectLockTest.php`, `tests/Support/LockHolder.php`, `tests/core/app/SnapshotRestoreTest.php`, `ProjectDeletionTest.php`, `AuditPageTest.php`, `M7PermissionsTest.php`; `tests/core/storage/SnapshotsTest.php`, `FsTest.php`; `tests/core/schema/FormatUpgradeTest.php` (T-ADM-21 case), `SqlStringsTest.php` (audit filters); `tests/core/system/AuditLogTest.php`, `ProjectRegistryTest.php`; `tests/core/io/StagingAreaTest.php`; `tests/core/app/MessageKeysTest.php` (`m7Keys()`, section 11.3), `AuditTrailTest.php`, `TrashAndSnapshotOperationsTest.php`; `tests/repo/CoreLayerRulesTest.php` |
| Must not touch | `src/Web`, `templates`, WP0 files |
| Scope | Sections 4 to 8, 11 (WP1 parts) |
| Acceptance | T-ADM-1 to T-ADM-23 and T-ADM-30 to T-ADM-34 pass; a Member is refused; restore under injected failure leaves the current file intact; deletion removes exactly the project folder and never follows a link; a second failed upgrade does not push out an older snapshot; design-tier security review |

### M7-WP2: Core Admin recovery and About facts

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1, WP6 (shared files; the notices file) |
| Security-critical | Yes (account recovery) |
| Owned files | New `src/Core/Install/RecoveryCode.php`; new `src/Core/App/RecoveryOperations.php`, `RecoveryResult.php`, `AboutOperations.php`, `AboutFacts.php`, `AboutComponent.php`, `AboutPlugin.php`, `LicenceText.php`; `src/Core/App/Operations.php` (`recovery()`, `about()`); `src/Core/System/AuditAction.php` (WP2 actions); `strings/en/problems.php` (WP2 row); new `tests/core/install/RecoveryCodeTest.php`, `tests/core/app/RecoveryOperationsTest.php`, `AboutOperationsTest.php`; `tests/core/app/MessageKeysTest.php`, `AuditTrailTest.php` (WP2 actions) |
| Must not touch | WP1 files other than the four shared ones named; `src/Web`; `LoginThrottle.php` (buckets fit as built) |
| Scope | Sections 9, 10, 11 (WP2 parts) |
| Acceptance | T-REC-1 to T-REC-14, T-ABT-1 to T-ABT-3 pass; the code is one-time, expires, is throttled, is compared in constant time, and is never echoed; recovery ends every session of the account and is audited; nothing is disclosed to a visitor who has no file access; design-tier security review |

### M7-WP3: Web components

| | |
|---|---|
| Tier | implementation |
| Depends on | WP6 (the manifest's SPDX exemption is in WP6's `SpdxHeaderTest.php`) |
| Security-critical | Yes (headers, templates) |
| Owned files | `templates/layout.php` (head icons and manifest, footer `about` flag); `templates/partials/footer.php` (About link), `top-bar.php` (Activity tab); `src/Web/Shell.php` (nav value `activity`); `src/Web/routes.php`; `src/Web/SecurityHeaders.php` (`manifest-src 'self'`); `public/assets/css/app.css`; `public/.htaccess`; new `public/assets/icons/favicon.svg`, `app-icon.svg`, `public/assets/app.webmanifest`; `strings/en/shell.php`; `tests/web/TemplateRulesTest.php` (the `link` rule, runtime prefixes), `ShellTest.php`, `SecurityHeadersTest.php`, `RouterTest.php` (pending controllers; a new `testM7RoutesAreTheSpecification` with the ten rows WP3 adds, as `testM5RoutesAreTheSpecification` is built), `StringsTest.php`, `KeyboardStructureTest.php` (only its `ADMIN_TOP` list, which gains `Activity` after `Plugins`, and its signed-in `FOOTER` list, which gains `About TiffinDB` before `Source code`); any other existing test that lists the Admin top bar or the signed-in footer in full, changed only for those two entries (the P178 precedent; WP3 reports each); `tests/repo/HtaccessTest.php`; new `tests/design/IconFilesTest.php` |
| Must not touch | Controllers, page templates, `src/Core` |
| Scope | Screens spec sections 2, 3, 5, 6.1, 7 (shared strings), 8; section 15.2 (mid-word breaks) |
| Acceptance | T-WEB-80 to T-WEB-84, T-WEB-93 pass; icons load under the CSP; no inline code; every M7 route except the two `ProjectController` delete routes (WP4's) exists with its pending controller; every M7 page's layout is one that `layout.php` has (screens spec section 6); `.htaccess` rules covered by `HtaccessTest`; design-tier security review |

### M7-WP4: Snapshot and project-deletion screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1, WP3 |
| Security-critical | Yes (destructive confirmations) |
| Owned files | New `src/Web/Controller/SnapshotController.php`; `src/Web/Controller/ProjectController.php` (delete actions; settings page data for its new links); `src/Web/routes.php` (only the two `ProjectController` delete routes, after WP3: `ProjectController` exists as built, so `RouterTest::testRealRouteTableHandlersExist` requires its methods the moment a route names them) and `tests/web/RouterTest.php` (those two rows in `testM7RoutesAreTheSpecification`); new `templates/pages/snapshots/index.php`, `restore.php`; new `templates/pages/projects/delete.php`; `templates/pages/projects/settings.php` (the two links); new `strings/en/snapshots.php`; `strings/en/projects.php` (delete strings, notices); new `tests/web/SnapshotScreenTest.php`, `ProjectDeleteScreenTest.php`; `tests/web/ScreenStructureTest.php` and `KeyboardStructureTest.php` (WP4's own methods only, P244) |
| Must not touch | `app.css`, shared partials, `routes.php` except the two lines named, `layout.php`, WP5 files |
| Scope | Screens spec sections 4 (WP4 rows), 6.2; `docs/design/m7/snapshots.md`, `project-delete.md` |
| Acceptance | T-WEB-85, T-WEB-87, T-WEB-88, T-WEB-90 (WP4 routes), T-SCR-6 and T-KBD-5 (WP4 screens) pass; confirmation pages re-validated on the second post; typed-name check in core; CSRF refusals; a Member gets 403 and an ungranted person 404; design-tier security review together with WP5 |

### M7-WP5: Audit, About, recovery screens

| | |
|---|---|
| Tier | implementation |
| Depends on | WP1, WP2, WP3 |
| Security-critical | Yes (signed-out POSTs, hostile values) |
| Owned files | New `src/Web/Controller/AuditController.php`, `AboutController.php`, `RecoveryController.php`; `src/Web/Controller/SignInController.php` (`recovered` notice, recovery link data); new `templates/pages/audit/index.php`, `templates/pages/about/index.php`, `licence.php`, `templates/pages/recover/index.php`; `templates/pages/sign-in.php` (the link and notice); new `strings/en/audit.php`, `about.php`, `recovery.php`; `strings/en/account.php` (sign-in link and notice); new `tests/web/AuditScreenTest.php`, `AboutScreenTest.php`, `RecoveryScreenTest.php`; `tests/web/SignInScreenTest.php` (new cases); `tests/web/ScreenStructureTest.php` and `KeyboardStructureTest.php` (WP5's own methods only, after WP4 is merged, P244) |
| Must not touch | As WP4, and WP4 files |
| Scope | Screens spec sections 4 (WP5 rows), 6.3, 6.4; `docs/design/m7/audit.md`, `about.md`, `recovery.md` |
| Acceptance | T-WEB-86, T-WEB-89, T-WEB-90 (WP5 routes), T-WEB-91, T-WEB-92, T-SCR-6 and T-KBD-5 (WP5 screens) pass; hostile audit values escaped; server facts absent for a Member; recovery pages give the same response whether or not the account exists; design-tier security review together with WP4 |

### M7-WP6: Release build tool

| | |
|---|---|
| Tier | implementation |
| Depends on | none |
| Security-critical | Yes (what ships) |
| Owned files | New `tools/release/build.php`, `ReleaseRules.php`, `ArchiveBuilder.php`, `README.md`; new `tests/tools/ReleaseBuildTest.php`, `tests/repo/ReleaseContentsTest.php`; `tests/repo/SpdxHeaderTest.php` (calls `ReleaseRules::spdxProblem`, no rule change; two new `EXEMPTIONS` entries, `config/notices.json` and `public/assets/app.webmanifest`, JSON having no comments); new `config/notices.json`; `.github/workflows/ci.yml`; `docs/dependencies.md` (a sentence on what ships from `vendor/`); new `THIRD-PARTY-NOTICES.md`, `CHANGELOG.md`; `.gitignore` (`/build/`); `tests/repo/GitignoreTest.php` (if it pins the entry list) |
| Must not touch | `src/`, `public/`, `templates/` |
| Scope | Section 3 |
| Acceptance | T-REL-1 to T-REL-14 pass; two builds are byte-identical; each rule of 3.5 has a failing case; the release-candidate archive is built from the merged tree by the orchestrator at the hand-over, after M7-WP12 (the WP6 author records the command and a test-build hash in its report); design-tier security review |

### M7-WP7: Upgrade-path tests

| | |
|---|---|
| Tier | implementation |
| Depends on | WP0, WP1 |
| Security-critical | No |
| Owned files | New `tests/roundtrip/UpgradePathTest.php`, `tests/Support/HistoricFixture.php`, `tests/web/NewerDataTest.php` |
| Must not touch | Product code; a defect found goes to the file's owner |
| Scope | Section 12.3 |
| Acceptance | T-UPG-1 to T-UPG-8 pass; every earlier format opens with one snapshot per upgrade and unchanged data; newer-version refusal shown; implementation-tier review |

### M7-WP8: Host documentation

| | |
|---|---|
| Tier | implementation |
| Depends on | WP0, WP1, WP2, WP6 |
| Security-critical | No (its security statements are reviewed by the design tier) |
| Owned files | New `docs/host/*.md` (section 14.1); `README.md`; new `tests/repo/HostDocsTest.php` |
| Must not touch | `docs/user/` |
| Scope | Sections 12.1, 14.1, 14.3 |
| Acceptance | `HostDocsTest` passes; every command and path in the text exists; no personal data; plugin privileges stated plainly; the reserved place for the importer chapter present and naming nothing; design-tier review of `https-and-proxies.md`, `plugins.md`, `accounts-and-recovery.md`, `backup-and-restore.md` |

### M7-WP9: User documentation

| | |
|---|---|
| Tier | implementation |
| Depends on | M5 CLOSED (the forms chapter); the screen chapters of M7 (`for-admins.md` sections on snapshots, deletion, activity) are written from `docs/design/m7/` and checked against strings when WP4 and WP5 are merged: WP9 runs after them, or leaves those three sections to a short follow-up the orchestrator assigns |
| Security-critical | No |
| Owned files | New `docs/user/*.md` (section 14.2); new `tests/repo/UserDocsTest.php` |
| Must not touch | `docs/host/` |
| Scope | Sections 14.2, 14.3 |
| Acceptance | `UserDocsTest` passes: every quoted label matches `strings/en/`; the reserved place present and naming nothing; implementation-tier review |

### M7-WP12: Release-candidate version commit (P240)

| | |
|---|---|
| Tier | mechanical |
| Depends on | WP0 to WP9 merged (part 1 complete) |
| Security-critical | No |
| Owned files | `config/app.php` (`version` only) |
| Must not touch | Everything else |
| Scope | Section 3.7 |
| Acceptance | `version` is `1.0.0-rc.1`; `composer test` and `composer lint` pass (tests read the version from `config/app.php`); then the orchestrator builds the archive without `--test-build` and records its SHA-256; reviewed by an implementation-tier agent |

### M7-WP10 and M7-WP11 (part 2)

Outline in section 18. Their owned files and acceptance are fixed by M7-D3.

### Summary

| ID | Title | Tier | Depends on | Security-critical | Wave |
|---|---|---|---|---|---|
| M7-WP0 | Carried-forward hardening: Origin, probe port, file-name extension, throttle, newer-system page, tools | implementation | none | Yes | 1a |
| M7-WP1 | Core administration: lock, restore, deletion, audit listing, P183 | implementation | none | Yes | 1a |
| M7-WP6 | Release build tool, rules, notices, CI step | implementation | none | Yes | 1a |
| M7-WP2 | Core Admin recovery and About facts | implementation | WP1, WP6 | Yes | 1b |
| M7-WP3 | Web components: routes, shell links, icons, static headers, stylesheet | implementation | WP6 (file order) | Yes | 1b |
| M7-WP4 | Snapshot and deletion screens | implementation | WP1, WP3 | Yes | 2 |
| M7-WP5 | Audit, About, recovery screens | implementation | WP1, WP2, WP3 | Yes | 2 |
| M7-WP7 | Upgrade-path tests | implementation | WP0, WP1 | No | 2 |
| M7-WP8 | Host documentation, README | implementation | WP0, WP1, WP2, WP6 | No (statements reviewed) | 2 |
| M7-WP9 | User documentation | implementation | M5 CLOSED (and WP4, WP5 for three sections) | No | 1b or 2 |
| M7-WP12 | Release-candidate version commit | mechanical | WP0 to WP9 | No | hand-over |
| M7-WP10 | Release test, visual pass, keyboard run (part 2) | implementation | part 1; G7-release | No | 3 |
| M7-WP11 | Importer documentation; consistency pass (part 2) | implementation | M6 CLOSED | No | 3 |

Design-tier security reviews (orch 2 rule 4; plan 8.5): WP0 (request pipeline, throttle), WP1 (lock, restore, deletion), WP2 (account recovery), WP3 (headers, templates), WP4 with WP5 together, WP6 (what ships), the security statements in WP8. Implementation-tier review: WP7, WP9, WP10, WP11.

Ownership check. Wave 1a: WP0 holds the request pipeline files, `Attachments.php`, `Authenticator.php`, `Unavailable.php`, `SystemSchema.php`, `errors.php`, the two tools and their tests; WP1 holds storage, `FormatMigrations.php`, the opener and the three operation classes, the registry and audit classes, `StagingArea.php`, `problems.php`, `MessageKeysTest.php`, `AuditTrailTest.php`, `CoreLayerRulesTest.php`, `tiffin.md`; WP6 holds `tools/release/`, `config/notices.json`, `ci.yml`, `dependencies.md`, the two new root files, `.gitignore`, `SpdxHeaderTest.php`. No file is in two of them. Wave 1b: WP2 holds its new files and `Operations.php`, and takes `AuditAction.php`, `problems.php`, `MessageKeysTest.php`, `AuditTrailTest.php` after WP1 is merged; WP3 holds web shell files, `routes.php`, `SecurityHeaders.php`, `app.css`, `.htaccess`, icons, `shell.php`, and its tests; WP2 and WP3 share nothing. Wave 2: WP4 and WP5 each hold their controllers, page templates, strings files, and screen tests (WP4 `ProjectController.php`, `projects.php`, the two delete lines of `routes.php` and their `RouterTest` rows, both after WP3; WP5 `SignInController.php`, `sign-in.php`, `account.php`, `SignInScreenTest.php`); WP7 tests only; WP8 `docs/host/`, `README.md`; WP9 `docs/user/`. One exception, by the M5 precedent (P244): `tests/web/ScreenStructureTest.php` and `tests/web/KeyboardStructureTest.php` are changed by both WP4 and WP5. ScreenStructureTest's main test requires every GET route of an existing controller to be visited (as built), so each screen package must visit its own routes when its controller lands, as M5-WP5 and M5-WP6 did (`817987f`, `28503e2`). Each package adds only its own private visit method, its own `M7_*_CONTROLLERS` constant and per-milestone test method (as `M5_CONTROLLERS` and `testEveryM5ScreenInEveryState`, as built), its own `testM7*` keyboard methods, and one call line in the main test; neither changes a line the other writes. The orchestrator merges WP4 first; WP5 merges the integration branch before it reports. WP3 changes only the two shell lists of `KeyboardStructureTest.php` in wave 1b, before either.

## 18. M7 part 2 (outline only; reconciled by M7-D3 with the M6 as-built notes)

### 18.1 Exit criteria (plan 2)

| Criterion | Planned evidence |
|---|---|
| The release archive installs by upload and setup page only (verified on the PHP built-in server here; the reference shared host is pending maintainer verification) | WP10 release install test in both layouts from the built archive |
| Visual pass of every screen in every theme | WP10 screen catalogue run at 1360 px and 390 px in four themes, and 320 px with Largest text |

### 18.2 M7-WP10: release test, visual pass, keyboard run

- Rebuild the archive after M6 with the build tool; unpack it into a scratch folder (never the working tree).
- Layout A: built-in server with `public/` as the root (`php -S ... -t public public/index.php`). Layout B: the whole tree under a document root served by a small router that imitates the root `.htaccess` rewrite (the built-in server does not read `.htaccess`; this imitation is stated as such, and the Apache behaviour stays pending).
- From the setup page to a first record with no step outside the browser except reading the setup code file (the documented step); then sign in as a Member, import a CSV, export, take and restore a snapshot.
- Screen catalogue: a list of every route and state (from `routes.php` and the design documents of M1 to M7 and M6), each with how to reach it from a seeded installation. Expected size about 200 states. The catalogue is a test-support file (`tests/Support/ScreenCatalogue.php`, indicative) used by a scripted headless run; screenshots stay in the scratchpad.
- Checks per screenshot: no sideways scroll, no overlap or clipping (the M5 V-30 checks), focus ring visible when tabbing, no request off the host, no console message.
- Whole-application keyboard structure run (`KeyboardStructureTest` over the catalogue).
- Indicative owned files: `tests/web/ReleaseInstallTest.php`, `tests/Support/ScreenCatalogue.php`; assigned residue.

### 18.3 M7-WP11: importer documentation and consistency pass

- Replace the two reserved comments (section 14.3) with chapters written from the M6 hand-back (M6 plan section 11): the screens, the command-line usage text, what is not imported, the stated limits on very large projects.
- Consistency pass of `docs/host/` and `docs/user/` against the as-built screens; the stale design documents of section 15.2.
- Indicative owned files: the two new chapters; named sections of the index files.

### 18.4 Exit audit (M7-X) and final audit (M7-A)

As plan 8.5: the auditor rebuilds the archive and compares the SHA-256; installs from the archive in both layouts; upgrades an installation made by the code at M4 close; attacks restore, deletion, and recovery as a Member and across projects; checks shipped packages against `docs/dependencies.md`; runs the application under a system-call trace for outbound connections (none except the installer's loopback probe); runs the visual pass. Recorded as pending maintainer verification: the reference shared host, `.htaccess` on Apache (including the static-file headers), the manual screen-reader pass (section 19). The final audit (orch 10) follows.

### 18.5 Open for M7-D3

The M6 hand-back list; whether WP10 needs M6's new screens in the catalogue (yes, if M6 closed); the final version commit; the screen-reader pass result if the maintainer has done it.

## 19. Manual screen-reader pass (plan 8.2 row 13; for the maintainer)

Not verified by the build in any milestone. The procedure, for one person with a screen reader they already use (any of: VoiceOver on macOS or iOS, NVDA or Narrator on Windows, TalkBack on Android, Orca on Linux), on a TiffinDB installation holding the synthetic "Home Inventory" data (or the person's own test data):

1. Sign in by keyboard only. Expect: the page title is read first; each field's label is read with it; a wrong password reads the error and moves focus to the summary.
2. On the project list, move by headings and by landmarks. Expect: a banner, a navigation named "Main", a main region, and a footer; one heading level 1.
3. Open a project and a table's list. Expect: the sidebar is a navigation named "Project"; the table is announced with its column headers; the find box and the sort menu have labels; Apply is a button.
4. Open a record. Expect: each field's label, help, and "(required)" are read; relation and file sections are groups with their legend; Save is the one primary button.
5. Make an error (clear a required field, Save). Expect: the error summary is read, its links move to the fields, each field reads its error.
6. Move a record to the trash and use Undo from the notice. Expect: the notice is announced (status) and Undo is reachable.
7. Open the display settings, change the text size, Save. Expect: the change is announced through the page reload and the setting reads as selected.
8. As an Admin: open the form editor (M5), move a field, Save; open Snapshots, open a restore confirmation, cancel. Expect: each row's buttons name their field ("Move Shelf up"); the confirmation states what will happen before its button.
9. Note for each step: worked, worked with difficulty (what), or did not work (what). Report the list as structure only; it needs no project data.

The result goes into the ledger as "manual screen-reader pass: done on <date> with <screen reader family>, <n> of 9 steps worked", with the failures as issues for the owning package.

## 20. Provisional decisions (for the ledger)

Each is the option cheapest to reverse (orchestration guide section 5) and is recorded in ADR 0021 or ADR 0022 where it is a decision rather than a constant. Numbers continue after M5's P189 to P212.

| # | Item | Provisional resolution | Recorded in |
|---|---|---|---|
| P213 | Archive contents (plan 8.2 row 1) | As the plan, plus `composer.json`, `config/notices.json`, `CHANGELOG.md`, generated `MANIFEST.txt`; minus `vendor/composer/installed.json` and packages' test folders; files from `git ls-files` only | ADR 0021 d1, d2 |
| P214 | Build tool (row 2) | `tools/release/build.php`; prepared `vendor/`; sorted entries, time 1980-01-01, modes 0644/0755, deflate 9; `.sha256`; byte-identical on the same machine | ADR 0021 d3 |
| P215 | Release rules (row 3) | R1 to R8 of section 3.5 | ADR 0021 d4 |
| P216 | Version (row 4) | `1.0.0-rc.1` set in the hand-over commit (P240); `1.0.0` in the final build commit; `-dev` only for test builds; `CHANGELOG.md` from 1.0.0 | ADR 0021 d5 |
| P217 | Upgrade procedure (row 5) | Delete the release-owned folders, upload, open; data and `config/local.php` kept | ADR 0021 d6 |
| P218 | Newer system database page | New reason `system-newer` with its own wording | ADR 0021 d6; section 12.2 |
| P219 | Repeated failed upgrades (P183) | No new snapshot when the newest is a `format-upgrade` snapshot of the same starting version | ADR 0021 d6; section 8 |
| P220 | Project lock | `project.lock` with `flock`, shared on every open, exclusive for restore and deletion; 2 s and 10 s waits; destructive operations refused without lock support | ADR 0022 d1 |
| P221 | Snapshot restore (row 6) | File swap under the exclusive lock after a `pre-restore` snapshot; candidate checked in staging; unreferenced files swept, missing files counted and shown before and after | ADR 0022 d2 |
| P222 | Project deletion (row 7) | State `deleting`, folder moved to `staging/deleted/` and removed there (no new recursive-removal rule; amends the plan's P64/P127 amendment); typed name ignoring ASCII case; archive offered; `project.deleted` keeps the name | ADR 0022 d3 |
| P223 | Audit screen (row 8) | `/admin/activity`; 50 per page; person, project (including deleted), action filters; bad filter values ignored | ADR 0022 d4 |
| P224 | Admin recovery (plan 3 row 6) | Built; code file started from the browser; 30 minutes; once; 5 attempts; `admin_recovery` switch, on by default | ADR 0022 d5 |
| P225 | Recovery start by a visitor | Allowed (writes only an unreadable file), throttled, audited; never replaces a valid code | ADR 0022 d5 |
| P226 | Recovery outcome | The person signs in afterwards; no automatic sign-in | section 9.3 |
| P227 | Recovery code file that cannot be removed | Logged; stays usable until it expires (residual risk accepted) | section 9.3 |
| P228 | About page (row 10) | `/about` for signed-in people; components from `config/notices.json`; licence texts on their own pages; plugins from the registry (Members: enabled only); server facts for Admins | ADR 0022 d6 |
| P229 | Icons (row 10) | SVG favicon and an app icon through a web manifest; no PNG or ICO in v1; `manifest-src 'self'` | ADR 0021 d8 |
| P230 | Static assets (row 11) | `public/.htaccess` sets the sandbox CSP, `nosniff`, `DENY`, `no-referrer`, and one week of caching for static types | ADR 0021 d7 |
| P231 | Probe port (row 12) | `probe_port` in `config/local.php`; also `admin_recovery` | ADR 0021 d9 |
| P232 | Throttle takeback after a window reset | Accepted (at most one failure per stalled sign-in) | section 15.3 |
| P233 | Import commit does not re-read the actor inside the write | Accepted (P78 window) | section 15.3 |
| P234 | Bidirectional characters in shown values and import headings | Accepted (stripped from file names and names; values escaped, not isolated) | section 15.3 |
| P235 | Documentation (row 9) | Chapters of section 14, plus `for-admins.md`; bold only for UI labels, checked against strings; no screenshots | section 14 |
| P236 | Work-package changes from the session plan | Section 17 list (process; ledger only) | section 17 |
| P237 | Global recovery throttle buckets (review ruling a) | Accepted: a visitor can delay recovery; no limit per address (it would need trusted proxy headers); stated in `docs/host/accounts-and-recovery.md` | section 9.3; ADR 0022 d5 |
| P238 | Interrupted deletions (review ruling b) | `clearAbandoned` also runs from `Context::sweepStaging`, as well as at `create` step 0 | section 6.2; ADR 0022 d3 |
| P239 | Deleting a project whose folder is missing (review ruling c) | Succeeds: no lock, no move, no removal; registry row, grants, and audit as usual | section 6.2; ADR 0022 d3 |
| P240 | When the version becomes `1.0.0-rc.1` (review ruling d) | In the hand-over build commit, M7-WP12, not in WP6 | section 3.7; ADR 0021 d5; ADR 0022 |
| P241 | Restore sweeps files the pre-restore snapshot names (review ruling e) | Accepted as designed; stays on the maintainer list (section 21 item 4) | ADR 0022 d2 |
| P244 | M7-D2 reconciliation with the M5 code (work-package process) | WP4 adds the two `ProjectController` delete routes and their `RouterTest` rows after WP3; WP4 and WP5 both change `ScreenStructureTest.php` and `KeyboardStructureTest.php`, own methods only, WP4 merged first (M5 precedent); WP3 updates the Admin top-bar and signed-in footer lists of existing tests; `MessageKeysTest` gains `m7Keys()`; confirmations use the `form-actions` partial (the only printer of `form.cancel`, as built) | section 17; screens spec sections 4, 10, 13 |

## 21. Assumptions and unknowns

1. `flock` works on the reference shared host's file system for files in the data folder. The host check already tests `flock` in a folder above the web root (as built); the import job relies on it (P112). Unknown until the maintainer runs it; without it, restore and deletion are refused there (ADR 0022 decision 1). Shared hosts often keep files on NFS, where Linux emulates `flock` with `fcntl` record locks: locks then work across machines, but closing any descriptor of the lock file drops the process's locks on it, which is why the design keeps one descriptor per project per process (section 4.2).
2. `ZipArchive::setMtimeName` and `setExternalAttributesName` exist (PHP 8.0 and later with libzip 1.0 and later; PHP 8.3 is the floor). Assumed; T-REL-1 shows it on the build machine. The zip date depends on the time zone unless the tool sets `TZ=UTC` (3.4 step 8, checked with libzip 1.7.3). Whether two machines with different libzip or zlib versions give identical bytes is not known; the tool prints the versions so a comparison is like with like.
3. The PHP built-in server's MIME type for `.webmanifest` is not known; browsers accept a manifest served as `application/json` or `text/plain` in practice but may log a warning. `public/.htaccess` sets `application/manifest+json` for Apache. Checked in V-39.
4. Attachment files swept by a restore are lost even if an older snapshot names them; a design where snapshots keep files alive would read every snapshot on each sweep. Recorded for the maintainer (not blocking): the confirmation page states the number of files a restore deletes.
5. Snapshot pruning keeps 20 automatic snapshots; a `pre-restore` snapshot is automatic, so after 20 later risky operations it is pruned like the others. An Admin who wants to keep it takes a manual snapshot. For the maintainer to confirm.
6. The design was written before M5 was built and reconciled with the M5 code by M7-D2 (the paragraph after the status line). Files M5 added that M7 does not touch: the form controllers, templates, partials, and strings; `FormOperations`; the form tests.
7. M6 must open projects only through `Core\App` operations (section 4.4) so that its command-line driver takes the project lock. To be stated in the M6 spec; `docs/build/M6-handoff-plan.md` section 2 lists the M7 items M6 relies on.
8. Missing information for the maintainer (not blocking): plan section 3 rows 1, 6, 7 (defaults applied: release waits for M6; recovery built; publication is the maintainer's); whether PNG icons are wanted; whether restore should keep files that older snapshots name (item 4); whether `pre-restore` snapshots should be kept like manual ones (item 5); whether the user documentation should gain screenshots later (needs a seeded screenshot generator).

## 22. As-built notes

To be appended by the M7 exit audit (M7-X). Where the merged code differs from the text above, the code, these notes, and the provisional decisions in `docs/build/ledger.md` govern.

- M7-WP1 (audit search, section 2.2 and 7.2): `AuditLog::search` and `count` are each one literal statement, not a `WHERE` clause joined from literal fragments. Each filter is a fixed part `(? IS NULL OR column = ?)`, and `deletedProjects` is `(? = 0 OR (project_id IS NULL AND action = ?))`, all with bound values. A filter is off when its value is null. The property the section asks for holds: no text from a request reaches SQL. The file passes the `SqlStringsTest` scan (`testTheAuditSearchIsBuiltFromLiteralsOnly`).
- M7-WP1 review fixes: where `flock` is disabled, `ProjectLock::shared` returns null (the project opens without a lock, logged once) and `exclusive` throws `LocksUnavailable`, so restore and deletion refuse with `problem.lock.unavailable` (section 4.1). A deletion that finds its registry row already removed by a concurrent `clearAbandoned` reports success and writes the one `project.deleted` row; the sweep writes none (section 6.2).
