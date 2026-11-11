# TiffinDB brand reference

Status: accepted by the lead developer (design session, ADR 0007).

This is the working reference for anyone, human or agent, who places the TiffinDB name or logo in the application, documentation, or packaging.

## Name

- The name is "TiffinDB": one word, capital T, D, B. Never "Tiffin", "Tiffin DB", or "tiffindb" in prose.
- Lower-case `tiffindb` is correct only where a technical identifier requires it (file names, package names, URLs).
- Do not use the name of the discontinued product that inspired the project in the product name, UI, or package metadata (see `CLAUDE.md`, Licensing).

## Concept

The mark is a tiffin carrier: three stacked food tiers held in a frame with a carrying handle. The stacked tiers also read as the conventional "database" symbol. The middle tier is picked out in a second colour.

## Files

All files are in this folder. They are plain SVG with no embedded fonts, scripts, or external references. The wordmark letters are outlines, so the files render the same with or without the typeface installed.

| File | Use |
|---|---|
| `tiffindb-logo.svg` | Primary horizontal lockup, for light backgrounds |
| `tiffindb-logo-reversed.svg` | Horizontal lockup for dark or brand-teal backgrounds |
| `tiffindb-logo-stacked.svg` | Mark above wordmark, for square or narrow spaces |
| `tiffindb-logo-black.svg`, `tiffindb-logo-white.svg` | One-colour lockups (print, embossing, single-ink use) |
| `tiffindb-mark.svg` | Mark alone, for light backgrounds |
| `tiffindb-mark-reversed.svg` | Mark alone, for dark or brand-teal backgrounds |
| `tiffindb-mark-black.svg`, `tiffindb-mark-white.svg` | One-colour marks |
| `tiffindb-mark-small.svg` | Simplified mark for 32 px and smaller (favicon, tab icon) |
| `tiffindb-app-icon.svg` | Rounded-square app icon (home-screen icon, social card) |

The reversed and white files have transparent backgrounds and are invisible on white. Place them on a dark ground.

## Colours

| Name | Hex | Use |
|---|---|---|
| Teal | `#084F4A` | Primary brand colour; mark and "DB" on light grounds |
| Turmeric | `#C27F00` | Middle tier on light grounds |
| Turmeric, on dark | `#F2B441` | Middle tier and "DB" on dark or teal grounds |
| Ink | `#111A18` | "Tiffin" on light grounds |
| Paper | `#F3F5F4` | Default light page ground |

Measured contrast (WCAG 2 relative luminance): Teal on white is 9.43:1; Turmeric `#C27F00` on white is 3.32:1, which is enough for a graphic element but not for text. Do not set text in Turmeric on a light ground.

## Typeface

- The wordmark is set in Atkinson Hyperlegible Bold with tight tracking (-0.025 em). "Tiffin" takes the ink colour and "DB" the brand colour.
- The wordmark keeps this typeface even when a user picks a different interface typeface.
- The interface default is Inter (ADR 0007). Do not re-set the wordmark in Inter.

## Geometry

- The mark is drawn on a 64 by 64 unit grid. Frame stroke 3.5 units with round ends; tiers 30 units wide with 3-unit corner radii.
- Horizontal lockup: the wordmark is centred vertically on the mark; the gap between mark and wordmark is about one sixth of the mark height.
- Clear space: keep at least the height of one tier (about one sixth of the mark height) free on every side. The SVG files already include a 4-unit margin.
- Minimum sizes: full mark 24 px high; use `tiffindb-mark-small.svg` below 32 px; horizontal lockup 24 px high.

## Use in the application

- Top bar: mark plus live-text wordmark, linking to the project list. The mark is drawn inline so it can follow the theme.
- In the interface the mark takes the current accent colour of the theme (light teal in the dark theme, black or white in the high-contrast themes, and the user's chosen accent). Outside the interface, use the fixed brand colours above.
- Sign-in screen: larger horizontal lockup above the form.
- Favicon: `tiffindb-mark-small.svg`.

## Do not

- Stretch, rotate, outline, or add effects (shadows, gradients, glows) to the mark.
- Recolour individual tiers other than as supplied.
- Place the colour lockup on a mid-tone or photographic background; use a one-colour version.
- Rebuild the wordmark from a different typeface or change its letter spacing.
- Combine the mark with another project's or organisation's logo as a joint lockup.

## Provenance

The mark and lockups were drawn for this project in the design session recorded in ADR 0007. The wordmark outlines were generated from Atkinson Hyperlegible Bold, which its distributor declares as OFL-1.1; confirm against the upstream licence file before redistributing the font itself. No assets were copied from any other product.
