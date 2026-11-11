# ADR 0007: UI appearance, typefaces, and the v1 visual target

Status: accepted by the lead developer (design session)

## Context

The plan set accessibility and legibility requirements (plan section 5) but no visual design. A model UI was produced in a design session and reviewed by the lead developer, who accepted it as the target for v1 and made three decisions that differ from, or add to, earlier records.

## Decision

- The model UI in `docs/design/model-ui/` is the visual target for v1. `docs/ui-design-plan.md` describes how it maps onto the build plan.
- The default typeface is Inter. This supersedes the "default typeface" statement in the Fonts section of ADR 0004. Atkinson Hyperlegible remains bundled as a user option and is the typeface of the wordmark.
- Users can choose their typeface from: Inter (default), Atkinson Hyperlegible, Lexend, Source Serif 4, the device sans-serif face, and the device serif face.
- Users can choose an accent colour from: Teal (default), Indigo, Plum, Graphite. The two high-contrast themes ignore the accent and stay black and white.
- Typeface and accent join the per-user appearance settings already planned (theme, text size, line spacing, reduced motion). All are stored server-side per user.
- The list view uses a colour-blocked sidebar in the accent colour and a single slim top bar.
- The logo and wordmark in `docs/brand/` are the project identity. Usage rules are in `docs/brand/README.md`.

## Fonts and licences

All bundled fonts are self-hosted; the application loads no fonts from the network. Bundling follows the ADR 0004 rule that `OFL-1.1` is approved for font assets only.

| Family | Role | Licence (to confirm) |
|---|---|---|
| Inter | Default UI typeface | OFL-1.1 |
| Atkinson Hyperlegible | User option; wordmark | OFL-1.1 |
| Lexend | User option | OFL-1.1 |
| Source Serif 4 | User option | OFL-1.1 |

The licence column reflects the licence declared by the fontsource npm packages for each family, checked during the design session. Each must be confirmed against the upstream licence file when the font assets are added, and recorded in `docs/dependencies.md`.

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Default typeface | Atkinson Hyperlegible (ADR 0004) | The lead developer preferred Inter as the default; Atkinson stays one choice away |
| Typeface choice | Single bundled face plus system fallback | Users differ in what they find easy to read; a short list costs little |
| Accent colour | Fixed teal | The lead developer wanted colour to be a user preference |
| Accent colour | Free colour picker | Arbitrary colours cannot be guaranteed to meet the contrast target in every theme |

## Consequences

- Four font families ship in the release archive. The size cost is not yet measured and must be checked against shared-host upload limits (plan section 11, question 2). Subsetting to the Latin ranges and to regular and bold weights is the first mitigation.
- The contrast tests planned in section 5 must cover every theme and accent combination: light and dark with each of four accents, plus two high-contrast themes (10 combinations).
- The user settings table needs `typeface` and `accent` columns in addition to those already planned.
- Per-form styling (ADR 0006 proposed defaults) draws its accent from the user's chosen accent, not a fixed palette value.
- The mock-ups load fonts from Google Fonts for convenience. That is a property of the mock-ups only and must not be carried into the application.
