# M2 screen design: reading and display settings

Status: design for milestone M2, following `docs/design/model-ui/settings.html`. Logic: `docs/specs/M2-records.md` section 7.3 (`SettingsOperations`); values: `docs/specs/M1-foundation.md` section 5.5. Implemented by M2-WP8 with the components and the preview script of M2-WP5. Strings: `strings/en/settings.php` (WP8). Example data is synthetic.

## 1. Overview

| URL | Behaviour | Template |
|---|---|---|
| `GET /settings` | The form with the person's saved settings | `templates/pages/settings.php` |
| `POST /settings` | Save; 303 to `/settings` with a notice; 422 with errors | |
| `POST /settings/reset` | Back to the standard settings; 303 to `/settings` with a notice | |

Every signed-in person, Admin or Member, has their own settings; there is no permission beyond being signed in. Shell: signed-in shell, no sidebar, `nav` = `settings`: the top bar's Display link (`shell.nav.display` "Display", now shown on every signed-in page, reversing that part of M1 provisional decision P41) has `aria-current="page"`. Page title `settings.title` "Reading and display".

## 2. Elements

`main#main.page.page-settings`, in reading order:

1. Flash notice; error summary at 422.
2. `div`: `h1` `settings.title` "Reading and display"; `p.lead` `settings.lead` "These settings are yours. They follow you to any device you sign in on, and they apply to every project and form."
3. `div.settings-layout`:
   1. `form#settings-form.form.settings-form` (`method="post"`, `action` `/settings`): `csrf`, then five fieldsets:

| Fieldset (id) | Legend | Controls |
|---|---|---|
| `fieldset.fieldset-card.settings-section#settings-theme` | `settings.theme` "Colours" | `div.radio-grid.radio-grid-210` of four `label.radio-card`, each `input type="radio" name="theme"` with `value` `light`, `dark`, `hc-light`, `hc-dark` and `data-preview` `''`, `t-dark`, `t-hcl`, `t-hcd`; then `span.swatch` (with the theme class, `components.md` section 8) holding "Aa" (`settings.swatch` "Aa"); then the name: `settings.theme.light` "Light", `settings.theme.dark` "Dark", `settings.theme.hc_light` "Strong contrast, light", `settings.theme.hc_dark` "Strong contrast, dark" |
| `#settings-accent` | `settings.accent` "Accent colour" | `div.radio-grid.radio-grid-150` of four `label.radio-card`: radio `name="accent"`, `value` `teal`, `indigo`, `plum`, `graphite`, `data-preview` `''`, `a-indigo`, `a-plum`, `a-graphite`; `span.swatch-dot` with the accent class; name `settings.accent.teal` "Teal", `.indigo` "Indigo", `.plum` "Plum", `.graphite` "Graphite". Then `p.help` `settings.accent_help` "Used for buttons, links, and the sidebar. The strong contrast colours do not use an accent colour." |
| `#settings-size` (`.settings-section-tight`) | `settings.size` "Text size" | `div.size-row` of five `label.check.size-option` (with `.size-small` to `.size-largest`): radio `name="text_size"`, `value` `small`, `standard`, `large`, `larger`, `largest`, `data-preview` `pv-s-small` to `pv-s-largest`; names `settings.size.small` "Small", `.standard` "Standard", `.large` "Large", `.larger` "Larger", `.largest` "Largest" |
| `#settings-spacing` (tight) | `settings.spacing` "Space between lines" | `div.size-row` of three `label.check`: radio `name="line_spacing"`, `value` `standard`, `roomy`, `very-roomy`, `data-preview` `pv-l-standard`, `pv-l-roomy`, `pv-l-very-roomy`; names `settings.spacing.standard` "Standard", `.roomy` "Roomy", `.very_roomy` "Very roomy" |
| `#settings-lettering` (tight) | `settings.lettering` "Lettering and movement" | `div.radio-grid` of six `label.radio-card.face-option` in the mock-up's order, each carrying its typeface class: radio `name="typeface"` with `value` and `data-preview` `atkinson` / `f-atk`, `lexend` / `f-lexend`, `inter` / `''`, `source-serif` / `f-serif4`, `device-sans` / `f-sans`, `device-serif` / `f-serif`; then `span` holding `span.face-name` and `span.face-hint`: `settings.face.atkinson` "Atkinson Hyperlegible" / `settings.face.atkinson_hint` "Made for easy reading"; `settings.face.lexend` "Lexend" / "Wide and open letters"; `settings.face.inter` "Inter" / "Standard. Plain and compact"; `settings.face.source_serif` "Source Serif" / "Book-style letters with serifs"; `settings.face.device_sans` "This device, sans-serif" / "The plain lettering your device uses"; `settings.face.device_serif` "This device, serif" / "The book-style lettering your device uses". Then `label.check.settings-divider`: `input type="checkbox" name="reduce_motion" value="1"` and `settings.motion` "Reduce movement and animation" |

      Each saved value is `checked`. Then `div.form-actions`: `button.button.button-primary.button-large` `type="submit"`: `settings.save` "Save settings"; `button.button.button-large` `type="submit"` `formaction` = `/settings/reset`: `settings.reset` "Back to standard".
   2. `section.settings-preview` `aria-labelledby="preview-title"`: `h2#preview-title.preview-heading` `settings.preview.heading` "Preview: how a record will look"; `div#settings-preview.preview-body` with the classes of the saved settings (theme, accent unless high contrast, typeface, `pv-s-*`, `pv-l-*`), holding: `div.preview-title` `settings.preview.title` "Stand mixer"; `div` > `div.preview-label` `settings.preview.room_label` "Room" + `div` `settings.preview.room` "Kitchen"; the same for `settings.preview.date_label` "Purchased" / `settings.preview.date` "14 March 2025" and `settings.preview.notes_label` "Notes" / `settings.preview.notes` "Comes with dough hook, whisk, and flat beater. Spare bowl is on the top shelf."; `div.preview-button` `settings.preview.button` "Save".

Success: `POST /settings` 303 to `/settings`, flash `notice.settings_saved` "Your display settings have been saved."; `POST /settings/reset` 303, flash `notice.settings_reset` "Your display settings are back to standard." The redirected page renders with the new root classes, so the whole interface changes at once.

A value outside the allowed list (only possible with a tampered request): 422, the page with the saved settings re-checked where valid, the problem `problem.options.invalid` (M1) at the fieldset (`settings-theme` and so on, which the error summary links to), field names mapped from the core's `theme`, `accent`, `typeface`, `text_size`, `line_spacing`, `reduce_motion`.

## 3. Live preview (`public/assets/js/settings-preview.js`, WP5)

Loaded by the layout only on this page (`data['script']` = `settings-preview`), with `defer`. Behaviour:

1. Find `form#settings-form` and `#settings-preview`; if either is missing, do nothing.
2. On every `change` event inside the form: build the preview's class list as `preview-body` plus the `data-preview` value of the checked radio in each of the groups `theme`, `accent`, `typeface`, `text_size`, `line_spacing`, skipping empty values, and skipping the accent value when the checked theme's value is `hc-light` or `hc-dark`. Assign it with `classList` operations (no `className` strings built from anything other than these attribute values, no `innerHTML`, no inline styles).
3. Nothing else: no network, no storage, no timers. Strict mode inside an IIFE, with the two SPDX `//` lines.

Without JavaScript the preview shows the saved settings and updates after Save (ui-design-plan section 8). The reduced-movement checkbox has no preview.

## 4. Keyboard, screen reader, narrow screens

- Tab order: top bar, then each radio group (Tab enters a group at its checked radio; arrow keys move within it), the checkbox, Save settings, Back to standard, footer. The preview has no focusable element.
- Each group is a `fieldset` with a `legend`; each radio's accessible name is its visible text (swatches are `aria-hidden`).
- At 390 px the preview wraps below the form; radio grids become one column. At 320 px with the Largest text size nothing scrolls sideways.

## 5. Differences from the mock-up

| Mock-up | Application | Reason |
|---|---|---|
| Accent note: "The strong contrast colours always use black and white." | "The strong contrast colours do not use an accent colour." | The high-contrast themes have non-neutral accent and warning tokens (ADR 0009 row C2); what holds is that they ignore the accent |
| "Save settings" is a link to the list | a submit button that returns to this page | It saves; returning to this page shows the result |
| "Back to standard" changes nothing | a submit button that resets and saves | It must work without JavaScript |
| Radio values are class names | the stored values of `user_settings` | Class names are never taken from requests (ADR 0008 decision 11); the preview reads `data-preview` |
