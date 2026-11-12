# M1 components: classes for `app.css`

Status: design for milestone M1, implemented by M1-WP8 (`docs/specs/M1-screens.md` section 10). Every M1 screen is built from these classes and the partials of `M1-screens.md` section 4.4. The values are taken from the model UI (`docs/design/model-ui/`), mainly `projects.html`, `sign-in.html`, `settings.html`, `list.html`, and `import.html`; where a mock-up used an inline style, the value is written here as a class property instead.

Values are **design px** at the Standard text size. `app.css` writes them in `rem` by the M0 rule (design px divided by 18, four decimals; M0 spec section 8.1), except borders (`1px`, `2px`), the focus ring, the pill radius, and the two target floors (`var(--target-min)`, `var(--control-height)`). Colours are always tokens; no new colour tokens are introduced and none is redefined. No media queries are added: every layout below wraps with flexbox or grid `auto-fill`.

## 1. Page structure

| Class | Properties | Used on |
|---|---|---|
| `.page` (M0, extended) | add `display: flex; flex-direction: column; gap: 28`; children have no outer margin | Every page |
| `.page-plain` | with `.page`: `align-items: center; padding: 28 16 48` (the lockup bar supplies the top 48) | Sign-in |
| `.page-head` | `display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12 24` | Pages with a title and actions |
| `.page-actions` | `display: flex; flex-wrap: wrap; gap: 12` | Inside `.page-head` |
| `.lead` (M0) | unchanged: `--ink2`, margin-top 4 | Under every `h1` |
| `.section` | `display: flex; flex-direction: column; gap: 12` | A titled part of a page (`section` with an `h2`) |
| `.section-title` | `font-size: var(--text-h2)` (22), bold, line height 1.2, margin 0 | `h2` of a section |
| `.lockup-bar` | `display: flex; justify-content: center; padding: 48 16 0` | Plain shell `header` |
| `.brand-lockup` | with `.brand`: gap 14; `.brand-mark` inside is 64 × 64; `.brand-word` inside is 40, line height 1, letter spacing -0.025 em | Plain shell |
| `.site-footer-centered` | with `.site-footer`: `justify-content: center` | Plain shell footer |
| `.visually-hidden` | the usual clip pattern: `position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap` | Table captions |

## 2. Top bar (signed in)

| Class | Properties |
|---|---|
| `.top-nav` | `display: flex; flex-wrap: wrap; align-items: center; gap: 4; margin-left: 12` |
| `.account-nav` | `display: flex; flex-wrap: wrap; align-items: center; gap: 4 6; margin-left: auto` |
| `.top-pill` | `display: flex; align-items: center; min-height: var(--target-min); padding: 0 16; border: 0; border-radius: var(--radius-pill); background: none; color: var(--ink); font: inherit; text-decoration: none; cursor: pointer`. Hover: background `--soft`. `[aria-current="page"]`: background `--soft`, colour `--accent`, bold |
| `.sign-out` | `display: flex; margin: 0` (the form around the Sign out button); its `.top-pill` has padding 0 14 |
| `.user-chip` | `display: flex; align-items: center; gap: 10; min-height: var(--target-min); padding: 0 16 0 5; border: 1px solid var(--line); border-radius: var(--radius-pill); background: var(--bg); color: var(--ink); text-decoration: none`. Hover: border colour `--ctl`. `[aria-current="page"]`: border colour `--accent` |
| `.user-initials` | `display: flex; align-items: center; justify-content: center; width: 34; height: 34; border-radius: 50%; background: var(--accent); color: var(--on-accent); font-size: 15; font-weight: 700` |

## 3. Buttons and links

| Class | Properties |
|---|---|
| `.button` | `display: inline-flex; align-items: center; justify-content: center; gap: 8; min-height: var(--target-min); padding: 0 20; border: 2px solid var(--ctl); border-radius: var(--radius-control); background: var(--surface); color: var(--ink); font: inherit; font-weight: 700; text-decoration: none; cursor: pointer`. Hover: `text-decoration: underline` |
| `.button-primary` | border colour and background `--accent`; colour `--on-accent` |
| `.button-danger` | border colour `--warn`; background `--warn-soft`; colour `--warn` |
| `.button-large` | `min-height: var(--control-height); padding: 0 28` (form submit buttons, mock-up "Save settings") |
| `.button-block` | `width: 100%` (sign-in) |
| `.button:disabled`, `.button[aria-disabled="true"]` | `border-style: dashed; background: transparent; color: var(--ink2); font-weight: 400; cursor: not-allowed` |
| `.button-icon` | an inline SVG inside a button: 18 × 18, `flex: none`, `stroke: currentColor`, `aria-hidden="true"` in the markup (the "+" of "New project", from the mock-up) |
| `.link-row` | `display: flex; flex-wrap: wrap; gap: 4 20` for a row of plain links; each `a` inside: `display: inline-flex; align-items: center; min-height: var(--target-min)` |
| `.link-strong` | bold (the "Open" link) |

Only one `.button-primary` appears on a screen.

## 4. Forms

| Class | Properties |
|---|---|
| `.form` | `display: flex; flex-direction: column; gap: 20; margin: 0` |
| `.form-card` | with `.form`: background `--surface`; border `1px solid var(--line)`; radius `--radius-card`; padding 32 |
| `.form-narrow` | `width: 100%; max-width: 440` (sign-in, own password) |
| `.form-wide` | `width: 100%; max-width: var(--width-form)` (800; every other form) |
| `.field` | `display: flex; flex-direction: column; gap: 6` |
| `.label` | bold |
| `.label-optional` | inside a label: weight 400, colour `--ink2` (the "(optional)" text) |
| `.help` | `margin: 0; font-size: var(--text-small); color: var(--ink2)` |
| `.error-text` | `margin: 0; color: var(--warn); font-weight: 700` |
| `.input` | for `input` (text, password, number), `select`, `textarea`: `width: 100%; min-height: var(--control-height); padding: 0 14; border: 2px solid var(--ctl); border-radius: var(--radius-control); background: var(--surface); color: var(--ink); font: inherit`. `select.input`: padding 0 12. `textarea.input`: padding 10 14, min height 120, `resize: vertical`. `[aria-invalid="true"]`: border colour `--warn` |
| `.input-short` | `max-width: 216` (codes, small numbers) |
| `.input-medium` | `max-width: 440` (setup code, usernames) |
| `.check` | `display: flex; align-items: center; gap: 10; min-height: var(--target-min)`; its `input[type=checkbox]` and `input[type=radio]`: 24 × 24, `margin: 0; flex: none; accent-color: var(--accent)` |
| `.check-list` | `display: flex; flex-direction: column; gap: 4; margin: 0; padding: 0; list-style: none` (lists of `.check` rows) |
| `.check-hint` | inside a `.check` label: on its own line, `font-size: var(--text-small); color: var(--ink2)` |
| `.fieldset-card` | `fieldset`: `margin: 0; padding: 20 24; display: flex; flex-direction: column; gap: 12; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card); min-width: 0` |
| `.legend` | `padding: 0 8; font-size: var(--text-h2); font-weight: 700` |
| `.legend-plain` | a `legend` inside a `.field`-like group: padding 0, bold, base size (the mock-up "Condition" legend) |
| `.radio-grid` | `display: grid; grid-template-columns: repeat(auto-fit, minmax(250, 1fr)); gap: 12` |
| `.radio-card` | a `label`: `display: flex; align-items: center; gap: 12; min-height: 64; padding: 8 14; border: 2px solid var(--ctl); border-radius: var(--radius-control)`; `:has(input:checked)`: border colour `--accent`, background `--soft` (an enhancement; the native radio shows the state everywhere) |
| `.radio-card-title` | `display: block; font-weight: 700` |
| `.radio-card-hint` | `display: block; font-size: var(--text-small); color: var(--ink2)` |
| `.form-actions` | `display: flex; flex-wrap: wrap; align-items: center; gap: 12` |
| `.form-note` | `margin: 0; color: var(--ink2)` (the sign-in reset note) |
| `.show-password` | the `.check` row; it carries `hidden` until the script removes it |

## 5. Messages

| Class | Properties |
|---|---|
| `.notice` | `margin: 0; padding: 16 20; background: var(--sunk); border: 1px solid var(--line); border-radius: var(--radius-card); color: var(--ink)` |
| `.notice-muted` | with `.notice`: colour `--ink2` (the Admin note on the project list) |
| `.notice-warn` | with `.notice`: `padding: 18 22; background: var(--warn-soft); border: 2px solid var(--warn)` |
| `.notice-title` | `margin: 0 0 4; font-size: 20; font-weight: 700; line-height: 1.2`; inside `.notice-warn` colour `--warn` |
| `.notice p`, `.notice ol`, `.notice ul` | margins 0, gap 6 between them (`.notice` is `display: flex; flex-direction: column; gap: 6`) |
| `.error-summary` | as `.notice-warn`; its heading `.error-summary-title` as `.notice-title`; its list `margin: 0; padding-left: 24`; links inside: colour `--ink`, underlined, `min-height: var(--target-min)` via `display: inline-flex; align-items: center` |

## 6. Cards and lists

| Class | Properties |
|---|---|
| `.card` | `background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card); padding: 24` |
| `.project-grid` | `list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(300, 1fr)); gap: 20` |
| `.project-card` | with `.card` on an `li`: `display: flex; flex-direction: column; gap: 14` |
| `.project-name` | `h2`: `margin: 0; font-size: 24; line-height: 1.25` |
| `.facts` | `dl`: `margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 4 16`; `dt` colour `--ink2`; `dd` margin 0 |
| `.card-links` | with `.link-row`: `margin-top: auto; padding-top: 10; border-top: 1px solid var(--line)` |

## 7. Tables

| Class | Properties |
|---|---|
| `.table-wrap` | `overflow-x: auto; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-card)` |
| `.data-table` | `width: 100%; border-collapse: collapse` |
| `.data-table th`, `.data-table td` | `padding: 14 16; text-align: left; border-bottom: 1px solid var(--line); vertical-align: middle` |
| `.data-table thead th` | `font-size: var(--text-small); color: var(--ink2); background: var(--sunk); white-space: nowrap` |
| `.data-table tbody th` | row headers: weight 700, colour `--ink`, base size, background transparent |
| `.data-table tbody tr:nth-child(even)` | background `--sunk` |
| `.num` | `text-align: right; font-variant-numeric: tabular-nums` (on `th` and `td`) |
| `.cell-actions` | `display: flex; flex-wrap: wrap; gap: 8`; inside, `.button` rows (move buttons) |
| `.status-word` | bold; the result words of the setup check ("Ready", "Works, with a note", "Needs fixing"); `.status-failed` adds colour `--warn`. Status is never shown by colour alone |

## 8. Steps and breadcrumb

| Class | Properties |
|---|---|
| `.steps` | `ol`: `list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 12` |
| `.step` | `li`: `flex: 1 1 200; padding: 12 16; border: 1px solid var(--line); border-radius: var(--radius-control); background: var(--surface); color: var(--ink2)`; its second line (`.step-name`) is a `span` with `display: block; color: var(--ink)`. `[aria-current="step"]`: `padding: 11 15; border: 2px solid var(--accent); background: var(--soft); color: var(--ink); font-weight: 700` (from `import.html`) |
| `.breadcrumb` | `nav`: `display: flex; flex-wrap: wrap; align-items: center; gap: 4 10`; links `display: inline-flex; align-items: center; min-height: var(--target-min)`; separators colour `--ink2` (from `record.html`) |

## 9. Measurements the visual check uses

These follow from the classes above; `M1-screens.md` section 8 checks them with a tolerance of 1 px at the Standard text size.

| Element | Size |
|---|---|
| Top bar | at least 68 high; items at least 44 |
| Primary and secondary buttons | at least 44 high; form submit buttons (`.button-large`) at least 48 |
| Inputs and selects | at least 48 high, border 2, radius 8 |
| Checkbox and radio rows | at least 44 high; control 24 × 24 |
| Cards | radius 12, border 1, padding 24 (form cards 32) |
| Page | content at most 1120 wide, padding 36 24 48 (the M0 `.page` padding is 36 24 48) |
| `h1` | 34 (sign-in `h1` 30: `.form-card h1` sets `font-size: 30`) |
| Section titles and legends | 22 |
