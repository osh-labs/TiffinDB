# ADR 0009: Provisional defaults for open questions

Status: Accepted (provisional, awaiting maintainer confirmation). Every row below is a working default adopted so that the build can proceed. None has been confirmed by the lead developer. Each is designed to be cheap to change; the "Cost to change" column says what a different answer would touch.

## Context

The plan, the UI design plan, and the ADRs leave several questions open (plan section 11 questions; ui-design-plan sections 9 and 12; context brief sections 9 and 12). Work on M0 to M5 would otherwise wait on each answer. This record adopts the resolution the source documents already propose wherever one exists, and records what is deferred. It does not amend `docs/plan.md` or the earlier ADRs; they are amended only after the maintainer confirms.

## Provisional resolutions

### A. Differences between the model UI and the plan (ui-design-plan section 9)

The "Proposed resolution" column of that section is adopted as written.

| # | Item | Provisional resolution | Takes effect | Cost to change |
|---|---|---|---|---|
| A1 | Record form at wide widths (ui 9.1) | The auto-generated form keeps a single-column source order. The default form CSS uses a reflowing grid, so fields flow into two or three columns (minimum 300 px each) on wide screens and one column on narrow screens. Plan section 4 is amended after confirmation | M2 | One block of default form CSS |
| A2 | Mark colour in the application (ui 9.2) | The mark follows the user's accent. Fixed brand colours are for use outside the interface | M0 | One CSS rule |
| A3 | Sidebar on record screens (ui 9.3) | Replaced by a breadcrumb on record, collection, and import screens | M2 | Layout template choice per screen |
| A4 | Light-theme warning contrast (ui 9.4) | The token is adjusted to reach at least 7:1 before M2 exit. Until then the measured 6.81:1 is recorded as a known shortfall and the M0 contrast test asserts the measured value, not the target | M2 | Two token values and one test expectation |
| A5 | Sign out placement (ui 9.5) | A visible link in the top bar | M1 | One template line |

### B. Plan questions

| # | Item | Provisional resolution | Takes effect | Cost to change |
|---|---|---|---|---|
| B1 | Trash retention (plan Q3) | Items stay in the trash until an Admin empties it. No automatic purge | M1 | Adding a purge job later is additive |
| B2 | Font payload (plan Q9) | Measured in M0 (section "Font payload measurement" below). Rule: if the four families exceed 2 MB in total as bundled (WOFF2, weights 400 and 700; Latin subsets except Source Serif 4, see "Font licences"), the release ships Inter and Atkinson Hyperlegible and documents the others as optional downloads. Result: the rule is not triggered; all four ship | M0 | Removing a family is one directory, two `@font-face` rules, one settings option |
| B3 | Desktop or local packaging (plan Q6) | Out of scope for v1 | - | None now |
| B4 | Non-Apache hosting (plan Q7) | Out of scope for v1. v1 assumes Apache with `.htaccess` and `mod_rewrite` | - | A second set of server rules; the application itself has one entry point |
| B5 | XLSX library (plan section 9) | OpenSpout is adopted if, when M3 starts, its own licence file confirms MIT and its package metadata confirms support for PHP 8.3. The decision is made in the M3 spec; this row only records the intent. Otherwise an alternative is selected by a new ADR | M3 | Confined to two plugins behind the importer and exporter interfaces |

A preliminary look during M0 design, recorded so the M3 check has a starting point and not as the decision: the licence file on the project's current development branch reads "MIT License", and that branch's Composer manifest declares PHP `~8.3.0 || ~8.4.0 || ~8.5.0`. Neither was checked against a tagged release.

### C. Design process

| # | Item | Provisional resolution | Takes effect | Cost to change |
|---|---|---|---|---|
| C1 | Undesigned screens (ui 6.2) | Each is designed from the existing tokens and shell before its milestone starts, and the design is added under `docs/design/` | M1 onward | None; this is a process rule |
| C2 | High-contrast theme colours (brief 12.6) | `docs/design/tokens.css` is the visual source of truth and its values are transcribed unchanged. Discrepancy recorded: ADR 0007 says the two high-contrast themes "stay black and white", but the tokens file gives them non-neutral accent, tint, and warning colours (for example high-contrast light `--accent #003B37`, `--soft #D5F0EC`; high-contrast dark `--accent #8FF5E8`). What does hold is that the high-contrast themes ignore the user's accent choice. The maintainer is asked to confirm which reading is intended | M0 | Six token values per theme |
| C3 | Default typeface (brief 12.5) | ADR 0007 supersedes the "default typeface" statement in ADR 0004: Inter is the default. Atkinson Hyperlegible is a user option and the wordmark typeface. No conflict remains; recorded for clarity | M0 | One token |

### D. Deferred to the maintainer

| # | Item | Status |
|---|---|---|
| D1 | Legacy-product import (M6) | Deferred. It depends on a discovery run that only the maintainer can perform on legitimately owned files, outside the repository (plan section 6.4) |
| D2 | M0 exit criterion "`docs/formats/bento.md` first findings" | Recorded as "deferred, pending maintainer discovery run". The discovery script and its instructions are provided under `tools/discovery/`; the findings file is not written until the maintainer has run it and reviewed the output. M0 is otherwise complete without it |
| D3 | Reference-shared-host verification (plan Q2) | Deferred. A check script and instructions are provided under `tools/host-check/` for the maintainer to run on a real account |
| D4 | M7 exit criterion "release archive installs on the reference shared host" | Recorded as "pending maintainer verification" |
| D5 | Naming of the legacy importer: plugin directory, plugin id, and the label shown in the UI (brief 12.1) | Left open until M6. `CLAUDE.md` forbids the legacy product's name in the UI and in package metadata, while plan section 3.2 uses it as a directory name. No directory, id, or label is created before M6 |

### E. Noted, to be settled in a later spec

| # | Item | Where it is settled |
|---|---|---|
| E1 | Single-transaction import commit versus chunked, resumable import (brief 12.2) | M3 spec |
| E2 | Form sanitizer rule "no external URLs" versus static images and links in forms (brief 12.3) | M5 spec |

These are not decided here.

## Font payload measurement

Measured on the files the M0 spec tells implementers to commit (recomputed at review): WOFF2, weights 400 and 700, upright only. Inter, Atkinson Hyperlegible, and Lexend are Latin subsets; Source Serif 4 is the complete upstream file.

| Family | 400 (bytes) | 700 (bytes) | Family total (bytes) |
|---|---|---|---|
| Inter | 23,664 | 24,356 | 48,020 |
| Atkinson Hyperlegible | 17,208 | 17,524 | 34,732 |
| Lexend | 14,476 | 14,808 | 29,284 |
| Source Serif 4 | 76,260 | 81,540 | 157,800 |
| Total, eight font files | | | 269,836 |
| Four licence files | | | 17,660 |
| Total font directory | | | 287,496 |

269,836 bytes is about 264 KiB, roughly 13% of the 2 MB limit. All four families ship in the release. (As Latin subsets the Source Serif 4 files would have been 41,804 bytes; the difference is the price of the licence position below.)

## Font licences

Confirmed during M0 design by reading each family's licence file in its upstream project repository, and by reading the licence URL and copyright strings embedded in each of the eight font files: all four families are under the SIL Open Font License, Version 1.1 (`OFL-1.1`). File locations and checksums are in the M0 spec and, once the assets are added, in `docs/dependencies.md`.

Reserved Font Names were checked at review, from the fetched licence files and the embedded copyright strings. OFL-1.1 defines a Modified Version to include any derivative made by deleting components or by changing formats, and its condition 3 forbids a Modified Version from using a Reserved Font Name without the copyright holder's written permission; the restriction applies to the primary font name as presented to users. A Latin subset is therefore a Modified Version.

| Family | Reserved Font Name declared | Position |
|---|---|---|
| Inter | None | Latin subset bundled under the family name |
| Atkinson Hyperlegible | None | Latin subset bundled under the family name |
| Lexend | "RevReading Lexend" (licence file only; not in the font files) | Latin subset bundled. The family name in the files is "Lexend", which is not the reserved name. Whether sharing one word with a reserved name could matter was not determined; low residual risk, for the maintainer to note |
| Source Serif 4 | "Source" (licence file and every font file) | The family name contains the reserved name, and no written permission was found in the upstream licence or README files. The Latin subset is **not** bundled. The release ships the copyright holder's own WOFF2 files for Regular and Bold, unmodified and under their published file names, from a tagged upstream release, with that tag's licence file |

Options considered for Source Serif 4: ship the unmodified upstream files (chosen: no naming question, 116 KiB more payload, still far below the limit); rename the family in a subset (rejected: it requires the project to modify font files, contrary to ADR 0004, and shows the user an unfamiliar name); drop the family or make it an optional download (not needed; remains the fallback under row B2 if the maintainer prefers the smaller payload). ui-design-plan section 10 asks for Latin subsets; for this one family the licence takes precedence, and the difference is recorded here for the maintainer.

ADR 0004 says font files "ship unmodified". This record reads that as: the project does not alter the files it obtains. For the three subset families the Latin subsetting is done upstream of the project, by the distribution channel; for Source Serif 4 the files are the copyright holder's own.

## Consequences

- Work proceeds on M0 to M5 without waiting for answers.
- Each row above is listed in the build ledger as awaiting confirmation. When the maintainer confirms or changes a row, this ADR's status line is updated and, where the row says so, `docs/plan.md` is amended.
- M6 cannot start, and M0 and M7 cannot be declared fully complete, until the deferred maintainer tasks (D1 to D4) are done.
