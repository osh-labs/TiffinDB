# M3 components: classes added to `app.css`

Status: design for milestone M3, implemented by M3-WP3 (`docs/specs/M3-screens.md` section 11). M3 screens use these classes, the M1 classes (`docs/design/m1/components.md`), and the M2 classes (`docs/design/m2/components.md`). Values are taken from the inline styles of `import.html` where it shows them.

Values are **design px** at the Standard text size, written in `rem` in `app.css` by the M0 rule, except borders and the focus ring. Colours are tokens only; no token is added or redefined.

## 1. Page structure

| Class | Properties | Used on |
|---|---|---|
| `.page-io` | with `.page`: `max-width: var(--width-wide)` (1120); `padding: 20 24 48; gap: 24` (mock-up `main`) | Every import, export, archive, and plugins page |
| `.panel` | `margin: 0; padding: 20 24; display: flex; flex-direction: column; gap: 10; background: var(--surface); border: 1px solid var(--line); border-radius: 12` | "What was found", report summary, export sections, plugin details |
| `.panel h2`, `.panel legend` | `margin: 0; font-size: 22; font-weight: 700; line-height: 1.3` (legend: `padding: 0 8`) | |

`fieldset.panel` is used for groups of radios (target table, export format), with the legend in the panel's heading style (mock-up "Where should the rows go?").

## 2. Steps

| Class | Properties |
|---|---|
| `.steps` | `ol`: `list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 12` |
| `.step` | `flex: 1 1 200; padding: 12 16; border: 1px solid var(--line); border-radius: 8; background: var(--surface); color: var(--ink2)` |
| `.step-label` | `display: block; color: var(--ink)` (the step's name on the second line) |
| `.step-now` | `padding: 11 15; border: 2px solid var(--accent); background: var(--soft); color: var(--ink); font-weight: 700` |
| `.step-done`, `.step-next` | no change from `.step` (the words "done" and "now" carry the state, not colour alone) |

## 3. Facts list

| Class | Properties |
|---|---|
| `.facts` | `dl`: `margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200, 1fr)); gap: 12 24` |
| `.facts dt` | `color: var(--ink2)` |
| `.facts dd` | `margin: 0; overflow-wrap: anywhere` |

## 4. Warning panel

| Class | Properties |
|---|---|
| `.warning-panel` | `padding: 18 22; display: flex; flex-direction: column; gap: 6; background: var(--warn-soft); border: 2px solid var(--warn); border-radius: 12; color: var(--ink)` |
| `.warning-panel h2` | `margin: 0; font-size: 20; color: var(--warn)` |
| `.warning-panel ul` | `margin: 0; padding-left: 22` |

Contrast: `--warn` on `--warn-soft` is 7.61:1 in the light theme after P99; text in `--ink` on `--warn-soft` is covered by the ui 4.5 table (warning tint row).

## 5. Mapping table

| Class | Properties |
|---|---|
| `.table-scroll` | on a `div`, around the mapping table and the report table: `max-width: 100%; min-width: 0; overflow-x: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 12` (focusable: `tabindex` is not used; the table's controls receive focus and scroll it into view) |
| `.mapping-table` | `width: 100%; min-width: 860; border-collapse: collapse`; cells `padding: 12 16; border-bottom: 1px solid var(--line); vertical-align: middle; text-align: left`; `thead th` as the M2 `record-table` header |
| `.mapping-table th[scope="row"]` | `background: transparent; color: var(--ink); font-size: 18; font-weight: 700; overflow-wrap: anywhere` |
| `.mapping-left-out` | on a row whose "Bring in" is unticked when the page is drawn: `th[scope="row"]` colour `var(--ink2)` |
| `.mapping-first` | `color: var(--ink2); overflow-wrap: anywhere` (First values) |
| `.mapping-table .input` | `width: 100%; min-width: 0` (the field name input) |
| `.mapping-table select` | `width: auto` (the Type and Field menus take the width of their longest choice, as the mock-up; never clipped) |
| `.mapping-table input[type="checkbox"]` | `width: 24; height: 24; margin: 0; accent-color: var(--accent)` (mock-up "Bring in") |

## 6. Inline choice row (target table)

| Class | Properties |
|---|---|
| `.choice-inline` | `display: flex; flex-wrap: wrap; align-items: center; gap: 8 16` |
| `.choice-inline .check` | `min-height: 48` |
| `.choice-inline .input`, `.choice-inline select` | `flex: 0 1 260; min-width: 0` |

## 7. Actions row with note

| Class | Properties |
|---|---|
| `.io-actions` | `display: flex; flex-wrap: wrap; align-items: center; gap: 12 20` |
| `.io-note` | `flex: 1 1 320; color: var(--ink2)` (mock-up "Nothing is saved until you choose Import...") |
| `.io-actions .button-large` (not primary) | `padding: 0 20` ("Start again", "Cancel", "Import another file"; section 10) |
| `.inline-form` | `display: inline-flex; margin: 0`: a form holding one button inside a row of actions (the "Start again" forms) |

## 8. Progress

| Class | Properties |
|---|---|
| `.progress` | `display: flex; flex-direction: column; gap: 8` |
| `.progress progress` | `width: 100%; height: 16; appearance: none; border: 1px solid var(--ctl); border-radius: var(--radius-pill); background: var(--sunk); overflow: hidden`; bar (`::-webkit-progress-value`, `::-moz-progress-bar`): `background: var(--accent)`; with `prefers-reduced-motion` or `.reduce-motion` no transition (none is set anyway) |
| `.progress-status` | `margin: 0; font-weight: 700` |
| `.continue-form` | `margin: 0; display: flex; flex-wrap: wrap; gap: 12 20; align-items: center` |

## 9. Report and plugin lists

| Class | Properties |
|---|---|
| `.report-table` | the M2 `record-table` look without links: `width: 100%; border-collapse: collapse`, cells `padding: 12 16`; the number cells (every `td`) and their column headings right-aligned with `font-variant-numeric: tabular-nums`; inside `div.table-scroll` |
| `.plugin-list` | `ul`: `list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12` |
| `.plugin-row` | `.panel` layout with `flex-direction: row; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12 24` |
| `.plugin-status` | `display: inline-flex; align-items: center; min-height: 32; padding: 0 12; border: 1px solid var(--line); border-radius: var(--radius-pill); font-weight: 700` |
| `.plugin-status-enabled` | `background: var(--soft); border-color: var(--accent)` |
| `.plugin-status-broken`, `.plugin-status-unavailable` | `background: var(--warn-soft); border-color: var(--warn); color: var(--ink)` |

The status pill always carries its word ("On", "Off", "Cannot be used", "Damaged"), never colour alone.

## 10. Measurements at 1360 px (for V-15)

| Element | Value |
|---|---|
| `main` | max 1120 wide, padding 20 24 48, gap 24 |
| `h1` | 34 |
| Steps | 4 boxes, at least 200 wide, padding 12 16 (current 11 15 with 2 px accent border), radius 8 |
| Panels | padding 20 24, radius 12, border 1; headings 22 |
| Mapping table | at least 860 wide inside a scrolling box, radius 12 |
| Warning panel | padding 18 22, border 2, radius 12, heading 20 |
| Buttons | 48 high ("Import 52 rows" padding 0 28; "Back"/"Start again" 0 20) |

At 390 px: steps wrap two by two then one per line; the facts list becomes one column; the mapping table scrolls inside its box; the actions row wraps with the note below the buttons; no page-level horizontal scroll.
