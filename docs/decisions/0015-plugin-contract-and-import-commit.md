# ADR 0015: Import and export plugin contract, and the import commit model

Status: Accepted (provisional, awaiting maintainer confirmation). Settles ADR 0009 row E1 (single-transaction commit versus chunked import; context brief section 12 item 2), the plugin manifest, directory layout, namespace, loading, and enable and disable storage that plan section 6.2 sketches and ADR 0008 decision 1 reserved for the M3 spec, and amends the layer table of ADR 0012 decision 1 (as amended by ADR 0014 decision 5) for `Core\Io`. Implementation design: `docs/specs/M3-io.md` sections 3 to 7; screens in `docs/specs/M3-screens.md`. Provisional decision numbers are listed in `docs/specs/M3-io.md` section 17.

## Context

Plan section 6.1 commits an import "in a single transaction"; plan section 10 mitigates shared-host time limits with "chunked import with resumable progress". The accepted mock-up (`import.html`) promises "The rows are saved all together, or not at all." Plan section 6.2 sketches plugins as directories with a manifest and a class implementing `Importer` or `Exporter`, discovered by a directory scan and enabled or disabled by an Admin, running with full PHP privileges. Nothing fixes the manifest's file name or fields, the method signatures beyond `inspect()` and `read()`, where enabled state is kept, or when plugin code is loaded.

## Decisions

### 1. Import commit model (settles E1)

An import runs in four phases, each its own request or series of requests, against a **staging job** that lives outside the project file:

1. **Upload.** The file is stored in the job folder `<data directory>/staging/imports/<job key>/` under a name the application chooses.
2. **Read.** The importer plugin's rows are copied, as text, into the job's own SQLite file (`job.sqlite` in the job folder), in chunks of at most 2,000 rows or 5 seconds per request. Column types are inferred incrementally while reading.
3. **Review** (mock-up step 2). Nothing is written to the project.
4. **Import** (step 3). The staged rows are coerced to the chosen field types in chunks, written back into the job file; then **one final request takes the `import` snapshot and moves every prepared row into the project in a single transaction**, together with any new table, fields, relation, linked records it adds, and the import log row. A failure at any point before or inside that transaction leaves the project unchanged.

Without JavaScript each chunk is a page with a "Continue" button; a small script presses it automatically. `meta` refresh stays forbidden. The staging data never enters the project file, so project format version 3 has no staging table (deviation from the session plan's "staging table if adopted": a separate file needs no format change, is never copied into snapshots or archives, and is removed as a whole folder).

The final transaction is bounded by the row cap (50,000 rows per import, the same limit M1 uses for type changes). Whether 50,000 prepared rows commit within a shared host's time limit is not measured; M3-WP11 measures the build machine and the maintainer's host check records the reference host.

### 2. Plugins: layout, manifest, namespace

- A plugin is a directory `plugins/<kind>/<id>/`, where `<kind>` is `import` or `export` and `<id>` matches `^[a-z][a-z0-9-]{1,31}\z`. A format offered in both directions is two plugins (for example `plugins/import/csv/` and `plugins/export/csv/`).
- The directory holds `plugin.json` (the manifest) and `src/`, whose PHP classes live under the namespace `TiffinDB\Plugin\<Kind>\<Id>\` (`<Kind>` and `<Id>` in StudlyCase, hyphens removed), mapped PSR-4 to `src/`.
- Manifest fields (JSON object, UTF-8, at most 16 KiB, no other members): `api` (integer, 1), `id`, `kind`, `name` (1 to 60 characters, shown to Admins), `version` (`MAJOR.MINOR.PATCH`), `class` (fully qualified class name inside the plugin's namespace), `licence` (an SPDX identifier from the `CLAUDE.md` allowlist), `extensions` (import: list of lower-case file name extensions it reads; export: the one extension it writes), `media_type` (export only), `scopes` (export only: a non-empty subset of `table` and `project`), `requires_extensions` (list of PHP extension names), `description` (0 to 200 characters).
- The five plugins TiffinDB ships are `import/csv`, `import/xlsx`, `export/csv`, `export/xlsx`, `export/json`. The CSV reader and writer themselves are core classes (`Core\Io\Csv`), so CSV import and export, the portability minimum, never depend on the shipped XLSX library or on plugin loading succeeding.

### 3. Loading

- Discovery reads `plugins/import/*/plugin.json` and `plugins/export/*/plugin.json` only, never following symbolic links, and validates each manifest; an invalid manifest makes that plugin "broken" (listed with the reason, never loaded). Listing plugins never executes plugin code.
- A plugin's PHP is loaded only when an enabled plugin is used for an import or export: the registry registers an autoloader for exactly that plugin's namespace prefix, mapped to its `src/` directory after a containment check, and instantiates the manifest's class, which must implement the contract interface for its kind. Composer's autoloader is not configured for `plugins/`.
- Plugins may use only `TiffinDB\Core\Io\Contract\` names (API version 1), their own namespace, PHP's standard library, and the shipped `OpenSpout\` package. A repository test enforces this for the plugins in the repository. As plan section 6.2 says, a plugin runs with full PHP privileges; no sandbox is possible on shared hosting. Plugins are installed by copying files onto the server, never through the application.
- A plugin whose `requires_extensions` are not all loaded is "unavailable": listed, not offered, and not loaded.

### 4. Enable and disable

- Stored in the system database, table `plugins` (system schema version 2): one row per plugin that an Admin has changed, with `kind`, `id`, `enabled`, `changed_at`, `changed_by`.
- With no row, the five shipped plugins are enabled and any other plugin is disabled. Enabling a plugin that does not ship with TiffinDB goes through a confirmation page saying that a plugin can read and change everything in the installation.
- Enabling and disabling are Admin-only (`plugins.manage`) and audited (`plugin.enabled`, `plugin.disabled`).

### 5. Layers (amends ADR 0012 decision 1 as amended by ADR 0014 decision 5)

| Namespace | Holds | May depend on |
|---|---|---|
| `TiffinDB\Core\Io` | Contract (interfaces and values for plugins), plugin discovery and loading, staging jobs, type inference, import pipeline, export source, CSV reader and writer, project archive | Schema, Query, Forms, Storage, Error |
| `TiffinDB\Core\System` | (as before) plus `PluginStates` | Storage, Error |
| `TiffinDB\Core\App` | (as before) | every Core namespace except Install, now including Io |

`Core\Io` takes no actor and holds no authorization; `Core\App` checks `io.use`, `project.create`, or `plugins.manage` and calls it.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Commit | Each chunk commits its own rows | Breaks "all together, or not at all"; a failure leaves half an import |
| Commit | Whole import in one request | Exceeds shared-host time limits on large files (plan section 10) |
| Commit | Staging table inside the project file | Changes the format, bloats the file and its snapshots, needs cleanup inside users' data |
| Continuation | `meta` refresh | Forbidden by the template rules; moves focus without warning |
| Plugins | One directory per format with both directions | ADR 0008 reserved `plugins/<kind>/<id>/`; keeps the manifest one-kind |
| Plugins | Composer autoload entries for plugins | Loads code of disabled plugins on demand from any class reference; third-party plugins could not be added without editing `composer.json` |
| Plugins | Manifest as a PHP file returning an array | Discovery would execute plugin code before an Admin enabled it |
| State | Enabled flag in the manifest | Plugin files are not writable by the application on many hosts and would be overwritten by an upgrade |

## Consequences

- An import needs several requests; each is short. Without JavaScript an Admin presses "Continue" once per chunk.
- Abandoned jobs leave folders in `staging/`, removed after 24 hours by a sweep at the start of later import operations.
- The plugin contract is a public interface: changing it needs a new `api` number.

## Costly to reverse

The manifest format, the directory layout, the plugin namespace pattern, and the contract interfaces, once third-party plugins exist. The commit model and the staging location are code only.
