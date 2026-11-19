# ADR 0016: XLSX library and the pinning of shipped dependencies

Status: Accepted (provisional, awaiting maintainer confirmation). Settles M0 provisional decision P6 and ADR 0009 row B5 (XLSX library), and applies ADR 0008 decision 12 (no lock file; shipped packages pinned exactly) to the first shipped dependency, as the session plan's default for maintainer question 1 requires. Implementation: `docs/specs/M3-io.md` section 16 (M3-WP0) and section 9 (XLSX plugins).

## Context

Plan section 6.3 requires XLSX import and export "with a streaming library to stay within shared-host memory limits"; plan section 9 names OpenSpout as the candidate, licence and PHP floor to be confirmed. The phase 0 probe (ledger section 1) read Packagist metadata: releases from v5.4 require PHP 8.4 or later; v5.0.0, v5.1.1, v5.2.2 and v4.32.0 admit PHP 8.3; v5.3.0 and v5.4.0 were not checked, nor the licence file at a tag.

## Verification (M3-D1, 2026-11-19)

Read from the package metadata (Composer) and from a shallow clone of each tag in the session scratchpad; nothing was committed.

| Release | PHP constraint | Extensions required | Other packages required | Commit |
|---|---|---|---|---|
| v5.4.0 | `~8.4.0 || ~8.5.0` | as below | none | (not admissible) |
| v5.3.0 | `~8.3.0 || ~8.4.0 || ~8.5.0` | `dom`, `filter`, `libxml`, `xmlreader`, `zip` | none | `bc0d2cdefa3fa90c7caceb778192654b20f414f7` |
| **v5.2.2** | `~8.3.0 || ~8.4.0 || ~8.5.0` | `dom`, `filter`, `libxml`, `xmlreader`, `zip` | none | `95403917927ef252f7ae82d919c55286a642962a` |
| v4.32.0 | `~8.3.0 || ~8.4.0 || ~8.5.0` | the same plus `fileinfo` | none | `41f045c1f632e1474e15d4c7bc3abcb4a153563d` |

Licence at v5.2.2 (and identically at v5.3.0): the repository holds **two** licence files, both shipped in the Composer archive (neither is excluded by the tag's `.gitattributes`):

- `LICENSE`: "MIT License", SHA-256 `d88ea20fec9e7f33400cbba4b363dbe592d330bc0abcd51607c4388ed75bc21b`.
- `LICENSE-for-cc42c1d`: the Apache License, Version 2.0, SHA-256 `c666b2511719a979ae3311021b97e0e8e0a16000946024e7262670dee0615144`. The README states that code up to and directly descending from upstream commit `cc42c1d` (the project it was forked from) is under Apache-2.0.

The package metadata declares only `MIT`. The effective licence is therefore `MIT AND Apache-2.0`. Both are on the `CLAUDE.md` allowlist, and Apache-2.0 is compatible with distribution under the AGPL version 3. No `NOTICE` file exists at the tag, so Apache-2.0 section 4(d) adds no obligation beyond keeping both licence files, which ship inside `vendor/openspout/openspout/`.

## Decisions

### 1. Library and release

`openspout/openspout` **v5.2.2**, the last release of the newest line that admits PHP 8.3 with no feature change after it: v5.3.0 also admits PHP 8.3 but its only change to `src/` is a new rich-text cell type that widens `Cell::getValue()` to return arrays, which TiffinDB's import code would then have to handle; the 5.2 line already carries every fix v5.3.0 has. Used only by the two XLSX plugins (`plugins/import/xlsx/`, `plugins/export/xlsx/`); nothing in `src/` names an `OpenSpout\` class. Only its XLSX reader and writer are used (not its CSV or ODS parts).

### 2. Pinning (applies ADR 0008 decision 12)

- No `composer.lock`; `"lock": false` stays. `composer.json` `require` gains `"openspout/openspout": "5.2.2"` (exact). It has no transitive packages.
- `docs/dependencies.md` lists it under "Runtime dependencies (shipped)" with version, source commit, licence `MIT AND Apache-2.0`, both licence files with their SHA-256, and the extensions it needs.
- A repository test (`DependencyLicenceTest`, extended) fails when an installed non-development package is not listed in that table with exactly the installed version and source reference, or when its declared licence is not on the allowlist. The M7 release build installs with `--no-dev` and runs the same check.
- When the maintainer exempts `composer.lock` as a named file (ADR 0008 decision 12, P8), the lock replaces this rule and the table becomes documentation only.

### 3. Extensions

`zip` and `xmlreader` are already installer requirements. `dom`, `filter`, and `libxml` are added to the installer's requirement check as **warnings**, not failures: without them TiffinDB installs and works, and the two XLSX plugins report themselves unavailable (their manifests list all five extensions in `requires_extensions`). `tools/host-check/` reports the three extensions too. In practice `libxml` is needed by `xmlreader`, and `dom` and `filter` are enabled in default PHP builds; this is not verified on the reference host (deferred maintainer task).

### 4. Safe use

Amended by P144 (M3-WP5 and its security review): OpenSpout is used for export only; the importer reads workbooks with PHP's `XMLReader` itself.

- The library's temporary folder is always set to the job's own folder under `<data directory>/staging/` (its options default to the system temporary directory, which on shared hosts may be shared with other accounts).
- Export builds every text cell explicitly as a string cell. The library's convenience constructor turns any string starting with `=` into a formula cell (`Cell::fromValue`), so it is never used for user values. Text is scrubbed to valid UTF-8 first (invalid sequences become U+FFFD; the library's escaping would otherwise write an empty cell); numbers the writer would print inexactly (14 significant digits) and whole numbers of 16 or more digits are written as text.
- Import (P144) does not use OpenSpout's `Reader`. At v5.2.2 it reports a formula without a cached value as 0, turns a text cell starting with `=` into a formula cell, aborts the whole read on one out-of-range date, fills every row to the width a hostile `spans` or `<dimension>` declares, rebuilds a row per cell (quadratic in width), and repeats an 8-second pass over 50,000 x 20 rows in every chunk. The plugin's own reader takes the cached result of a formula cell, never its formula text, and treats error cells as empty values. The plugin contract cannot carry issues, so those cells come back as null **with no issue** (`formula-no-value` and `error-cell` are not recorded).
- Before any XML of an uploaded workbook is parsed, the plugin checks the zip directory (entry count, declared sizes, ratio, encryption, methods, names including names equal ignoring case, macro parts by name) and then, on every call, reads every entry to its end counting its **real** bytes: an entry holding more bytes than it declares is refused (libzip streams past a lying size header), so the declared-size caps hold for what is parsed. The same pass refuses any prolog with a DOCTYPE or entity declaration and any macro content type (parsed, so character references are decoded) (`docs/specs/M3-io.md` section 9.1).
- `XMLReader::readString()`, `readInnerXml()`, `readOuterXml()`, and `expand()` are never called on untrusted subtrees: they build the subtree in libxml's memory, outside PHP's `memory_limit` (a 3.4 MB workbook reached 809 MB). Text is collected node by node up to a cap, and unexpected child elements are skipped with `next()`.
- Every read of the importer counts against a per-call step budget (structure, styles, shared strings, column scans, rows), so the parsing work of one call is bounded whatever the file holds. Derived data that is costly to recompute (column headings, cell format kinds, shared strings) is kept in the job's temporary folder and validated on read; the outcome of a safety check is never cached, so a cache forged by anyone who can write the job folder can change the values read from that workbook but cannot make a call skip a check. The plugin's own caps are in `plugins/import/xlsx/src/Limits.php` (255 worksheets; 2,000,000 shared strings and 32 MiB of them; 65,536 cell formats; 1,024 number formats of at most 255 characters).

## Alternatives considered

| Option | Reason not chosen |
|---|---|
| v5.3.0 | Admits PHP 8.3, but only adds a feature that changes a return type TiffinDB reads |
| v4.32.0 | Older major line; needs `fileinfo` as well |
| PhpSpreadsheet | Loads whole workbooks into memory; far larger; shared-host memory limits (plan section 6.3) |
| Own XLSX reader and writer | XLSX's shared strings, styles, dates, and inline strings are a large, error-prone surface; a maintained library is the plan's choice |
| Committed, stripped `composer.lock` | Rejected in ADR 0008 decision 12; a maintainer exemption remains the recommended alternative |

## Consequences

- The release archive's `vendor/` gains one package (about 1 MB of PHP, 149 files at this tag).
- Raising the PHP floor to 8.4 later would allow newer releases; that is a separate decision.
- The orchestrator's trimmed offline `vendor/` (orchestration guide section 9) must include this package at v5.2.2 before M3-WP5 starts.

## Costly to reverse

Little: the library is confined to two plugins behind the contract interfaces of ADR 0015. The pinning rule is replaced wholesale if the maintainer exempts the lock file.
