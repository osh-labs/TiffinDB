# M2 components: classes added to `app.css`

Status: design for milestone M2, implemented by M2-WP5 (`docs/specs/M2-screens.md` section 13). Every M2 screen is built from these classes, the M1 classes of `docs/design/m1/components.md` (all still valid), and the partials of `docs/specs/M2-screens.md` section 4. Values are taken from the inline styles of `list.html`, `record.html`, and `settings.html`.

Values are **design px** at the Standard text size; `app.css` writes them in `rem` by the M0 rule (design px divided by 18, four decimals), except borders, the focus ring, the pill radius, and the two target floors. Colours are always tokens; no colour token is added or redefined, and no colour literal is written (the settings swatches reuse the theme and accent classes, section 8). Breakpoints, where any are needed, are in `em` (M0 rule R6); every layout below wraps with flexbox or grid without one.

## 1. Page structure

| Class | Properties | Used on |
|---|---|---|
| `.app-columns` | `flex: 1 1 auto; display: flex; flex-wrap: wrap; align-items: stretch` | Wraps the sidebar and `main` on list and search pages |
| `.page-list` | with `.page`: `flex: 999 1 560; min-width: 0; max-width: none; margin: 0; padding: 28 32 40; gap: 20` | `main` of the list view and search results |
| `.page-record` | with `.page`: `max-width: var(--width-wide)` (1120); `padding: 20 20 48; gap: 24` | Record screens |
| `.page-record-narrow` | with `.page-record`: `max-width: var(--width-form)` (800) | Record screens whose form has no `Details` grid (section 4, last row), and the trashed and conflict pages |
| `.page-settings` | with `.page`: `padding-top: 32` | Display settings |

The layout prints the skip link, top bar, then either `main#main` alone or `div.app-columns` holding `nav.sidebar` and then `main#main.page.page-list`. Reading order: sidebar before main, as in the mock-up; the skip link goes to `#main`.

## 2. Top bar additions

| Class | Properties |
|---|---|
| `.top-search` | the project search `form` (`role="search"`): `flex: 1 1 280; max-width: 520; display: flex; align-items: center; gap: 10; min-height: var(--control-height); margin: 0 0 0 12; padding: 0 8 0 16; border: 2px solid var(--ctl); border-radius: var(--radius-pill); background: var(--bg)` |
| `.top-search-icon` | the magnifier SVG: 20 × 20, `flex: none`, `stroke: currentColor`, `aria-hidden="true"` |
| `.top-search-input` | `flex: 1 1 auto; min-width: 0; min-height: 40; border: 0; border-radius: var(--radius-pill); background: transparent; color: var(--ink); font: inherit`. Focus: the standard focus ring (3 px accent, offset 2) |
| `.top-pill-icon` | the half-filled circle SVG in the Display link: 20 × 20, `flex: none`; the link gets `gap: 8` and bold text, as in the mock-up |

## 3. Sidebar (list view)

| Class | Properties |
|---|---|
| `.sidebar` | `nav`: `flex: 1 1 260; padding: 24 16 32; display: flex; flex-direction: column; gap: 26; background: var(--side); color: var(--side-ink)`. Links inside: `color: var(--side-ink)`. `:focus-visible` inside: outline colour `--side-ink` |
| `.sidebar-project` | `display: flex; flex-direction: column; gap: 2; padding: 0 12` |
| `.sidebar-label` | `font-size: 15; color: var(--side-ink2)` (the word "Project") |
| `.sidebar-project-name` | `font-size: 24; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere` |
| `.sidebar-switch` | `display: inline-flex; align-items: center; align-self: flex-start; min-height: var(--target-min)` |
| `.sidebar-group` | `display: flex; flex-direction: column; gap: 4` |
| `.sidebar-heading` | `h2`: `margin: 0 0 4 12; font-size: 15; font-weight: 700; color: var(--side-ink2)` |
| `.sidebar-link` | `display: flex; justify-content: space-between; align-items: center; gap: 12; min-height: var(--control-height); padding: 0 14; border-radius: 10; color: var(--side-ink); text-decoration: none`. Hover: underline. `[aria-current="page"]`: background `--side-on`, colour `--side-on-ink`, bold |
| `.sidebar-count` | `color: var(--side-ink2); font-variant-numeric: tabular-nums`; inside `[aria-current="page"]`: colour inherited |
| `.sidebar-foot` | `margin-top: auto; padding-top: 16; border-top: 1px solid var(--side-ink2); display: flex; flex-direction: column; gap: 4` |
| `.sidebar-item` | as `.sidebar-link` without hover or current state (the trash count, which is not a link in M2) |

At 390 px the sidebar takes the full width above `main` (flex wrap); nothing is hidden.

## 4. List view

| Class | Properties |
|---|---|
| `.list-controls` | `form` (GET): `display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12 16; margin: 0; padding: 16; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card)` |
| `.list-find` | `flex: 2 1 260; display: flex; flex-direction: column; gap: 4` |
| `.list-sort` | `flex: 1 1 200; display: flex; flex-direction: column; gap: 4` |
| `.label-small` | with `.label`: `font-size: var(--text-small)` |
| `.input-compact` | with `.input`: `min-height: var(--target-min)` (the find box and sort menu are 44 high in the mock-up) |
| `.data-table-plain` | with `.data-table`: body rows have no zebra background (`tbody tr:nth-child(even)` transparent); `tbody tr:hover`: background `--soft`; every `th` and `td`: `white-space: nowrap` |
| `.cell-title` | the first column's link: bold |
| `.cell-none` | colour `--ink2` (the words "None" for no files or links) |
| `.pager` | `nav`: `display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12` |
| `.pager-status` | colour `--ink2` |
| `.pager-links` | `display: flex; gap: 12` |
| `.pager-link` | with `.button`: `padding: 0 18` |
| `.filter-bar` | with `.notice`: `display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8 16; padding: 12 16` (the active-filter line) |

The record table's existing `.num` class right-aligns numbers and money (mock-up "Value"). The first column is a `th scope="row"` holding the title link (`.cell-title`), as in M1 tables; it keeps body size and weight 700.

## 5. Search results

| Class | Properties |
|---|---|
| `.result-group` | `section`: `display: flex; flex-direction: column; gap: 12` |
| `.result-list` | `ol`: `list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10` |
| `.result` | `li`: `padding: 12 16; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-control)` |
| `.result-title` | the title link: bold; `display: inline-flex; align-items: center; min-height: var(--target-min); overflow-wrap: anywhere` |
| `.result-match` | `margin: 0; color: var(--ink2); overflow-wrap: anywhere` |
| `.result-more` | `display: inline-flex; align-items: center; min-height: var(--target-min); font-weight: 700` |

## 6. Record form

| Class | Properties |
|---|---|
| `.record-form` | `form`: `display: flex; flex-direction: column; gap: 24; margin: 0` |
| `.record-section` | with `.fieldset-card`: `padding: 24; gap: 20` (the mock-up's fieldsets) |
| `.record-grid` | on the `Details` section (role `details`) only: `display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300), 1fr)); gap: 20; align-items: start` (ADR 0014 decision 6). The legend is not a grid item |
| `.span-full` | `grid-column: 1 / -1` (slots with `data-tiffin-span="full"`) |
| `.field-group` | `fieldset` wrapping a radio group inside a section: `margin: 0; padding: 0; border: 0; min-width: 0; display: flex; flex-direction: column; gap: 6` (the mock-up's "Condition"); its legend uses the M1 `.legend-plain` |
| `.choice-row` | the radio group of a rating: `display: flex; flex-wrap: wrap; gap: 4 20` |
| `.static-value` | `margin: 0; min-height: var(--control-height); display: flex; align-items: center` (auto-number and other values shown without an input) |
| `.link-list` | `ul`: `list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10` |
| `.link-row` (M2) | `li`: `display: flex; flex-wrap: wrap; align-items: center; gap: 8 16; padding: 8 8 8 14; border: 1px solid var(--line); border-radius: var(--radius-control)`; its link bold, `min-height: var(--target-min)`. The M1 `.link-row` of plain links is a different element (`div`); the selector is `li.link-row` |
| `.file-row` | `div`: `display: flex; flex-wrap: wrap; align-items: center; gap: 8 16; padding: 8 8 8 14; border: 1px solid var(--line); border-radius: var(--radius-control); background: var(--sunk)`; the name `span` `flex: 1 1 200; min-width: 0; overflow-wrap: anywhere`; the type and size in `span.help` |
| `.record-actions` | `div`: `display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12 24` |
| `.record-actions-main` | `div`: `display: flex; flex-wrap: wrap; gap: 12` |
| `.record-meta` | `margin: 0; font-size: var(--text-small); color: var(--ink2)` |
| `.record-nav` | `div`: `display: flex; flex-wrap: wrap; gap: 12` (Previous and Next on the record screen) |
| `.trash-form` | `form`: `margin: 0; display: flex` (the separate form around "Move to trash", so the button is not part of the save form) |

## 7. Notices and comparison

| Class | Properties |
|---|---|
| `.notice-row` | with `.notice`: `flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8 16` (the flash notice with an Undo button) |
| `.notice-form` | `form`: `margin: 0; display: flex` |
| `.compare-table` | with `.data-table`: `th` and `td` `vertical-align: top; white-space: normal; overflow-wrap: anywhere` (the conflict page) |
| `.empty-state` | `div`: `display: flex; flex-direction: column; align-items: flex-start; gap: 12; padding: 32 24; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card)` |
| `.empty-state-title` | `margin: 0; font-size: var(--text-h2); font-weight: 700; line-height: 1.2` |

## 8. Display settings

| Class | Properties |
|---|---|
| `.settings-layout` | `display: flex; flex-wrap: wrap; align-items: flex-start; gap: 24` |
| `.settings-form` | with `.form`: `flex: 3 1 480; min-width: 0; gap: 24` |
| `.settings-section` | with `.fieldset-card`: `padding: 20 24; gap: 12` (text size, spacing, lettering: `gap: 4`, via `.settings-section-tight`) |
| `.radio-grid-210`, `.radio-grid-150` | as `.radio-grid` with column minimum 210 (colours) and 150 (accents); `.radio-grid` (250) is used for lettering |
| `.swatch` | `span` (`aria-hidden="true"`): `display: flex; align-items: center; justify-content: center; flex: none; width: 44; height: 44; border: 1px solid var(--ctl); border-radius: 6; background: var(--bg); color: var(--ink); font-weight: 700`. The theme radio card puts the theme's own class on the swatch (`span.swatch` alone for light, `span.swatch.t-dark`, `span.swatch.t-hcl`, `span.swatch.t-hcd`), so the swatch shows that theme's tokens whatever theme the page uses. High-contrast swatches: `border-width: 2px` |
| `.swatch-dot` | `span` (`aria-hidden="true"`): `flex: none; width: 36; height: 36; border-radius: 50%; background: var(--accent); border: 2px solid var(--surface); outline: 1px solid var(--ctl)`; carries the accent class (`span.swatch-dot` for teal, `span.swatch-dot.a-indigo`, `.a-plum`, `.a-graphite`). It always shows the light-theme accent, as in the mock-up |
| `.size-row` | `div`: `display: flex; flex-wrap: wrap; align-items: baseline; gap: 4 28` |
| `.size-option` | with `.check`: `min-height: 52`; the five labels carry `.size-small`, `.size-standard`, `.size-large`, `.size-larger`, `.size-largest` with `font-size` 16, 18, 21, 24, 28 design px (in `rem`) |
| `.face-option` | with `.radio-card`: `min-height: 76; font-family: var(--font-ui)`; each label carries the typeface class (`f-atk`, `f-lexend`, none for Inter, `f-serif4`, `f-sans`, `f-serif`), which sets `--font-ui` for that label only |
| `.face-name` | `display: block; font-size: var(--text-h2); font-weight: 700` |
| `.face-hint` | `display: block; color: var(--ink2)` |
| `.settings-divider` | the reduced-movement row: `margin-top: 12; padding-top: 12; border-top: 1px solid var(--line)` |
| `.settings-preview` | `section`: `flex: 2 1 340; min-width: 0; padding: 20 24; display: flex; flex-direction: column; gap: 14; background: var(--surface); color: var(--ink); border: 2px solid var(--accent); border-radius: var(--radius-card)` |
| `.preview-heading` | `h2`: `margin: 0; font-size: var(--text-small); color: var(--ink2)` |
| `.preview-body` | `div`: `display: flex; flex-direction: column; gap: 0.8em; font-family: var(--font-ui); line-height: var(--leading); background: var(--surface); color: var(--ink)`. Carries the preview classes below |
| `.preview-title` | `font-size: 1.5em; font-weight: 700; line-height: 1.2` |
| `.preview-label` | bold |
| `.preview-button` | `display: flex; align-items: center; align-self: flex-start; min-height: var(--target-min); padding: 0 1em; border: 2px solid var(--accent); border-radius: var(--radius-control); background: var(--accent); color: var(--on-accent); font-weight: 700` (looks like a button; it is a `div`, not focusable) |
| `.pv-s-small`, `.pv-s-standard`, `.pv-s-large`, `.pv-s-larger`, `.pv-s-largest` | on `.preview-body`: the text size the preview shows, independent of the page's own size step. Because the page root already carries a size class (none for Standard, `s-small`, `s-large`, `s-larger`, `s-largest`), each preview class has one rule per root size: `font-size` = preview size ÷ root size, in `em` to four decimals, with sizes 16, 18, 21, 24, 28 px (for example `:root.s-large .pv-s-small { font-size: 0.7619em }`, `.pv-s-larger { font-size: 1.3333em }` for the Standard root). 25 rules in all. Exactly one class is present |
| `.pv-l-standard`, `.pv-l-roomy`, `.pv-l-very-roomy` | on `.preview-body`: `--leading` 1.5, 1.75, 2 |

The preview body also carries the theme class (`t-dark`, `t-hcl`, `t-hcd`, or none), the accent class (none in high-contrast themes), and the typeface class of the settings it shows, so it takes those tokens locally. Because `.t-dark.a-indigo` and the other compound accent rules need both classes on one element, the preview body carries both.

## 9. Measurements the visual check uses

From the mock-ups, at the Standard text size, tolerance 1 px (`docs/specs/M2-screens.md` section 11):

| Element | Size |
|---|---|
| Sidebar | at least 260 wide when beside `main`; padding 24 16 32; links at least 48 high, radius 10 |
| List `main` | padding 28 32 40; gap 20 |
| Find box, sort menu, Filter button, Apply button | at least 44 high |
| Record table cells | padding 14 16; header text 16 |
| Pager buttons | at least 44 high, padding 0 18 |
| Record `main` | at most 1120 wide with a `Details` grid; padding 20 20 48 |
| Record fieldsets | padding 24, radius 12, gap 20, legend 22 |
| Record inputs | at least 48 high, border 2, radius 8 |
| Save, Back to list, Move to trash | at least 48 high |
| Settings `main` | at most 1120 wide; padding 32 24 48 |
| Radio cards | colours and accents at least 64 high; lettering at least 76 high |
