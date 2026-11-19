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

- The library's temporary folder is always set to the job's own folder under `<data directory>/staging/` (its options default to the system temporary directory, which on shared hosts may be shared with other accounts).
- Export builds every text cell explicitly as a string cell. The library's convenience constructor turns any string starting with `=` into a formula cell (`Cell::fromValue`), so it is never used for user values.
- Import takes the cached result of a formula cell, never its formula text, and treats error cells as empty values that are reported.
- Before the library opens an uploaded workbook, TiffinDB checks the zip directory itself (entry count, declared sizes, ratio, encryption), so a zip bomb is refused before the library reads it (`docs/specs/M3-io.md` section 9.1).

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
