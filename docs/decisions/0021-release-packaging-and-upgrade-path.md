# ADR 0021: Release packaging, versioning, and the upgrade path

Status: Accepted (provisional, awaiting maintainer confirmation). Settles the packaging, version, and upgrade points of `docs/build/M5-M7-session-plan.md` section 8.2 rows 1 to 5, 11, 12, and 14, the static-asset CSP item and the nested `.htaccess` release scan carried in `docs/build/ledger.md` section 7, and P80's deferred port question. Amends ADR 0008 decision 12 (what ships from `vendor/`) and ADR 0011 (a new optional `config/local.php` key). The implementation design is `docs/specs/M7-release.md`; screens are in `docs/specs/M7-screens.md` and `docs/design/m7/`.

## Context

TiffinDB has no build step: the release is the source tree plus the one shipped Composer package. Plan section 3.2 says `vendor/` ships in the release archive; plan section 8 makes "the release archive installs on the reference shared host by upload and setup page only" the M7 exit criterion. Until now only project-file upgrades were described (context brief 12.23); nothing said how the application itself, the system database, and the files of an older copy are replaced. Composer downloads are refused in the build environment (orchestration guide section 9), so the build tool cannot fetch anything. Static files are served by Apache directly and so never receive the application's Content-Security-Policy. The legacy importer (M6) is built after this decision, possibly by another developer, and must add one plugin and one admin tool without reopening the packaging.

## Decisions

### 1. What ships

The release archive holds, under one top folder `tiffindb-<version>/`: `public/`, `src/`, `templates/`, `strings/`, `plugins/`, `config/app.php`, `config/notices.php`, the root `.htaccess`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md`, `THIRD-PARTY-NOTICES.md`, `tools/admin/`, `tools/host-check/`, `docs/host/`, `docs/user/`, `docs/formats/`, `docs/dependencies.md`, the production `vendor/`, and a generated `MANIFEST.txt` (one `<sha256>  <path>` line per other file, sorted). Nothing else ships: not `tests/`, `tools/dev/`, `tools/discovery/`, `tools/release/`, `docs/build/`, `docs/specs/`, `docs/decisions/`, `docs/design/`, `docs/brand/`, `.github/`, `CLAUDE.md`, `phpunit.xml.dist`, `.gitignore`, or `config/local.php`.

`composer.json` is added to the session plan's list: it lets an operator rebuild `vendor/` with `composer install --no-dev`, and it states the one runtime dependency and its exact version. `MANIFEST.txt` is added so that an upgrade can say exactly which files a release owns (decision 6) and so that an operator can check an unpacked copy. The "Source code" link stays the AGPL section 13 offer (ADR 0008); the archive is source, not a substitute for the link.

Files are taken only from what git tracks (`git ls-files`), so ignored files (data, local configuration, scratch output) cannot enter the archive by accident. `plugins/` and `tools/admin/` ship as found, with no list of names (session plan section 8.2 row 14).

### 2. The production `vendor/`

The build tool takes a `vendor/` prepared without development packages (`composer install --no-dev`, or the build environment's equivalent recorded in `docs/specs/M7-release.md` section 3.3). It refuses a `vendor/` in which `composer/installed.php` names a development package or a package that `docs/dependencies.md` does not list as shipped with the same version and source reference (ADR 0016 decision 2). Inside each package it leaves out test, example, and continuous-integration folders by a fixed list; licence files always ship. `vendor/composer/installed.json` is not shipped: it carries package authors' names and email addresses and nothing reads it at run time (`installed.php` holds the versions). This amends ADR 0008 decision 12, which said only that `vendor/` ships.

### 3. Reproducible archive

`tools/release/build.php` writes `tiffindb-<version>.zip` and `tiffindb-<version>.zip.sha256`. Entries are sorted by byte value, every entry has the same fixed time (1980-01-01 00:00:00, the earliest a zip can hold), files have mode 0644 and folders 0755, and the compression level is fixed. Two runs from the same commit and the same prepared `vendor/` give the same bytes on the same machine. The tool downloads nothing, runs no shell, and needs `git` only to list tracked files. Byte equality across machines is expected but depends on the `zip` library and `zlib` versions, which the tool prints so a rebuild can be compared like with like.

### 4. Release rules

A release rules check fails the build, and a repository test fails the suite, when the file list holds: a file under a test or development path; `config/local.php`; any name matching a data pattern of `.gitignore` (`*.db`, `*.sqlite*`, `*.tiffin`, `*.tiffindb.zip`, `.env*`, `/data/`, `/local/`); a nested `.htaccess` that holds a rewrite directive anywhere other than the root and `public/`; a source file without the SPDX header where `tests/repo/SpdxHeaderTest.php` requires one; a shipped package missing from, or differing from, the shipped table of `docs/dependencies.md`; or a component of `config/notices.php` whose licence file is not shipped. The rules contain no plugin or tool names, so one more plugin or admin tool needs no change.

### 5. Versions

The application version is `config/app.php` `version`, already used in asset addresses (ADR 0008). It follows semantic versioning. The release candidate built at the end of M7 part 1 is `1.0.0-rc.1` (later candidates `-rc.2` and so on); the final build commit sets `1.0.0`. The build tool refuses a version ending in `-dev` unless told it is a test build. `CHANGELOG.md` starts at 1.0.0. Creating a git tag or a hosted release is the maintainer's alone (session plan section 3 row 7).

### 6. Upgrade path

- An installation is upgraded by replacing the application's files and keeping the data folder and `config/local.php`. Before copying the new files, the operator deletes the folders the release owns (`public/assets/`, `src/`, `templates/`, `strings/`, `vendor/`, `tools/`, `docs/`, and every plugin folder named in the new `MANIFEST.txt`), so that a file removed in the new version cannot linger and be loaded. Plugins the operator added and the data folder are never inside those folders unless the operator put them there; the host documentation says so.
- The system database and each project upgrade on first use, as built: the system database after a `system-before-v<n>` copy, a project after a `format-upgrade` snapshot (ADR 0012). A data folder or a project written by a newer version is refused with a plain page that names which one is newer; nothing is changed.
- A repeated failed project upgrade does not take a new snapshot each time: when the newest snapshot is a `format-upgrade` snapshot of the same starting version, it is kept as the copy for the retry (P183). This bounds format-upgrade snapshots to one per starting version, so automatic pruning cannot push out older snapshots through repeated failures.
- Tests build installations at every earlier project format version (1 to 4) and system schema version (1 and 2) from the documented SQL, open them under the release code, and run a simulated next version through the existing test seams. The exit audit also upgrades an installation made by the code at M4 close.

### 7. Static assets

`public/.htaccess` sets, for the static file types TiffinDB ships (CSS, JavaScript, WOFF2, SVG, the web manifest, plain-text licences), `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, and `Cache-Control: public, max-age=604800`. A policy on a stylesheet, script, or font response does not affect the page that loads it; it matters when a file such as an SVG is opened on its own, where it stops any script or outside load. Behaviour on Apache is reasoned, not run, until the host check (orchestration guide section 9).

### 8. Icons

The favicon is `docs/brand/tiffindb-mark-small.svg` and the app icon `docs/brand/tiffindb-app-icon.svg`, copied unmodified to `public/assets/icons/`. The page head links the favicon as `image/svg+xml` and a web manifest (`public/assets/app.webmanifest`) that names the app icon. The page CSP gains `manifest-src 'self'`. No PNG or ICO is shipped in v1, because the repository has no rasteriser and binary icons would be made outside it; browsers that need a PNG home-screen icon show their default. The maintainer may supply PNG files later without a design change.

### 9. Probe port (closes P80's deferred point)

`config/local.php` may set `probe_port` (an integer 1 to 65535). When set, the installer's exposure probe connects to that port on the server's own address and ignores the port in `SERVER_PORT` and the `Host` header; when absent, the P80 rule stands. An operator behind a proxy or on a non-default port sets it so the probe can reach the site without trusting request headers.

### 10. Room for the legacy importer

Part 1 names no legacy product anywhere: not in the build tool, the release rules, the notices, the About page, or the documentation. `docs/host/` and `docs/user/` each keep a named, empty place for a chapter on importing a whole database from another application, written after M6 by M7-WP11. The About page lists plugins from the plugin registry, so a new plugin appears without a change.

## Consequences

- The archive is the tree as committed, minus what the release rules exclude; there is still no build step for the application itself.
- An operator can verify an unpacked copy against `MANIFEST.txt` and the archive against its SHA-256.
- Static files receive a restrictive policy on Apache; the PHP built-in server (development only) does not read `.htaccess` and so sends none.
- A CI step builds the archive twice from a no-development install and compares the hashes, so reproducibility is checked on every push.
- Deleting the release-owned folders before an upgrade is a manual step on a host without a shell. It is written as a short list in `docs/host/upgrade.md`; forgetting it leaves stale files that the new code does not load by name, so the risk is limited to a removed file that something still includes, which the release rules and the autoloader make unlikely.
