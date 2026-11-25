# M5 components: classes, widths, links, and shared partials

Status: design for milestone M5, implemented by M5-WP3 (`docs/specs/M5-screens.md` sections 3, 6, 7, 8). M5 screens use these classes and the M1 to M4 classes (`docs/design/m1/components.md` to `docs/design/m4/components.md`).

Values are **design px** at the Standard text size, written in `rem` in `app.css` by the M0 rule, except borders and the focus ring. Colours are tokens only; no token is added or redefined. No inline style anywhere.

## 1. Widths on the record form

### 1.1 Markup

A section (or the form's root) whose bound node has `columns` true is written as:

```
fieldset.fieldset-card.record-section
  legend.legend            (when the section has one)
  div.form-area            (the container the width rule measures)
    div.form-cols          (the 12-column grid)
      div.cell.span-6      (one per child block; span-1 to span-12; no span class for no span or full)
        the field group, heading, paragraph, divider, or image as in M2 and M4
```

The root in column mode: `form.record-form` holds `div.form-area > div.form-cols > div.cell...`, each cell holding a section or a block. A section or root without `columns` is written exactly as in M2 to M4 (the `Details` auto-fit grid of P95, `span-full` for `full`).

### 1.2 Classes

| Class | Properties |
|---|---|
| `.form-area` | `container-type: inline-size; min-width: 0` |
| `.form-cols` | `display: grid; grid-template-columns: minmax(0, 1fr); gap: 20; align-items: start` |
| `.cell` | `min-width: 0` |
| Inside `@container (min-width: 40rem)` | `.form-cols { grid-template-columns: repeat(12, minmax(0, 1fr)) }`, `.form-cols > .cell { grid-column: span 12 }`, `.form-cols > .span-1 { grid-column: span 1 }` ... `.form-cols > .span-12 { grid-column: span 12 }` |

Why a container query: 40 rem follows the person's text size (720 design px at Standard; about 1,120 screen px at Largest), so widths only apply where each third still holds a readable label and control. A media query would measure the window in the browser's default font size and ignore the setting. A browser without container queries shows one column, because every span rule is inside the query.

Reading order: cells flow in source order, wrapping when a row is full; no `order`, no explicit placement, no dense packing. `data-tiffin-col` and `data-tiffin-row` have no effect (format 12.4).

Labels and controls inside a narrow cell: as M2 (`overflow-wrap: anywhere` on labels; inputs `width: 100%; min-width: 0`). Long text and rating fields generated with `full` keep the whole width.

### 1.3 What the editor's widths look like (1360 px, Standard, page 1120 wide)

| Width | Columns | Cell width (about) |
|---|---|---|
| Full width | 12 | 1,028 |
| Two thirds | 8 | 678 |
| Half | 6 | 504 |
| One third | 4 | 329 |
| Automatic (in a section with widths) | 12 | 1,028 |

At 390 px and at Largest text on most screens, every cell is the full width.

## 2. Links in forms

`a.form-link` with `href` from the node and the literal `rel="noopener noreferrer"`; no `target`, no `title`. Style: the M1 link style (underlined, `--accent` text, visited not distinguished, 3 px focus ring). No icon for "outside link": the address is not shown, so the person sees only the text the Admin wrote; the record screen is not a place for long addresses. A link with no text is not written. A link's text keeps `strong` and `em` inside it.

## 3. Form tools navigation (partial `forms/tabs`)

Shown at the top of the editor, source, upload, and reset pages, under the page head.

| Element | Detail |
|---|---|
| `nav.form-tabs` | `aria-label` `forms.tabs.label` "Form tools" |
| `ul.form-tabs-list` | `display: flex; flex-wrap: wrap; gap: 8 16; list-style: none; margin: 0 0 24; padding: 0` |
| `li` > `a.form-tab` | `display: inline-flex; align-items: center; min-height: 44; padding: 0 14; border-radius: 999px; font-weight: 700` (pill, as the current sidebar item). The current page: `aria-current="page"`, `background: var(--soft)`, `color: var(--ink)` |

Items in order: `forms.tabs.arrange` "Arrange" (editor), `forms.tabs.source` "Source", `forms.tabs.upload` "Upload a form file", `forms.tabs.download` "Download the form" (a plain link to the download; never current), `forms.tabs.reset` "Back to the standard form".

Data: `table` base path, `current` one of `arrange`, `source`, `upload`, `reset`.

## 4. Editor rows (partial `forms/row`)

One `li` per row of the editor (`docs/specs/M5-forms.md` section 6.1). A section row's `li` holds its children's rows in a nested `ol`. Full behaviour in `editor.md`; this is the anatomy.

| Class | Properties |
|---|---|
| `.form-rows` | `list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12` (the outer `ol` and each nested `ol`) |
| `.form-row` | `display: flex; flex-direction: column; gap: 12; padding: 16; border: 1px solid var(--line); border-radius: 12; background: var(--surface)` |
| `.form-row-section` | with `.form-row`: `background: var(--sunk); border-width: 2px` (a section; its nested rows sit on `--surface`) |
| `.form-row-head` | `display: flex; flex-wrap: wrap; align-items: baseline; gap: 4 12` |
| `.form-row-name` | `font-weight: 700; overflow-wrap: anywhere` (field name, section name, or kind) |
| `.form-row-kind` | `color: var(--ink2)` (type label, "Heading", "Required") |
| `.form-row-summary` | `margin: 0; color: var(--ink2); overflow-wrap: anywhere` (static content summary) |
| `.form-row-inputs` | `display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16.6667rem), 1fr)); gap: 12 20` (label and width side by side when room) |
| `.form-row-actions` | `display: flex; flex-wrap: wrap; gap: 8 12` (Move up, Move down, Hide or Remove), buttons `.button.button-plain` |
| `.form-row-note` | with `.help`: `margin: 0` (placed by the source; picture not shown; empty section) |
| `.form-row-missing` | with `.form-row`: `border-style: dashed` (a slot naming no field) |
| `.form-row-children` | the nested `ol.form-rows` of a section: `margin-top: 4; padding-left: 16; border-left: 3px solid var(--line)` |

Strings of the partial (in `strings/en/forms.php`, WP3):

| Key | Text |
|---|---|
| `forms.row.kind.section` | Section |
| `forms.row.kind.heading` | Heading |
| `forms.row.kind.paragraph` | Text |
| `forms.row.kind.divider` | Divider |
| `forms.row.kind.image` | Picture |
| `forms.row.kind.missing` | This field is no longer in the table. It shows nothing. |
| `forms.row.required` | Required |
| `forms.row.label` | Label |
| `forms.row.label_aria` | Label for {field} |
| `forms.row.legend` | Section name |
| `forms.row.legend_aria` | Section name, section {number} |
| `forms.row.width` | Width |
| `forms.row.width_aria` | Width of {field} |
| `forms.width.auto` | Automatic |
| `forms.width.full` | Full width |
| `forms.width.two-thirds` | Two thirds |
| `forms.width.half` | Half |
| `forms.width.one-third` | One third |
| `forms.width.other` | As in the source: {span} of 12 columns |
| `forms.row.up` | Move up |
| `forms.row.up_aria` | Move up: {name} |
| `forms.row.down` | Move down |
| `forms.row.down_aria` | Move down: {name} |
| `forms.row.hide` | Hide |
| `forms.row.hide_aria` | Hide {field} |
| `forms.row.remove` | Remove |
| `forms.row.remove_aria` | Remove {name} |
| `forms.row.ungroup` | Remove section |
| `forms.row.ungroup_aria` | Remove section {name} and keep what is in it |
| `forms.row.section_unnamed` | Section {number}, no name |
| `forms.row.empty_section` | This section is empty. |
| `forms.row.divider` | A dividing line |
| `forms.row.image_alt` | Description: {alt} |
| `forms.row.image_no_alt` | No description |
| `forms.row.image_hidden` | This picture is not shown: its file is not a picture attached to a record. |
| `forms.row.placed` | Placed by the source. Shown in reading order. |

`{name}` in the aria strings is the field's name, the section's name (or `forms.row.section_unnamed`), or the kind followed by the summary for static rows ("Heading Where it is"), cut to 60 characters. `{number}` counts sections from 1 in reading order.

## 5. Report (partial `forms/report`)

Shown on the editor (always, when it has something to say) and on the source view after Check or Save.

`section.panel.form-report` `aria-labelledby` `form-report-title`: `h2#form-report-title.section-title` `forms.report.title` "What this form shows"; then, each only when it applies, in this order:

| Key | Text |
|---|---|
| `forms.report.ok` | Every field of {table} is on this form, and nothing needs attention. |
| `forms.report.not_shown` | {count\|# field is\|# fields are} not on this form: |
| (list) | `ul.form-report-list`, one `li` per field name; a required one followed by ` ` and `span.form-row-kind` `forms.row.required` |
| `forms.report.required` | Required fields that the form does not show are not required when records are saved through it. |
| `forms.report.unknown` | {count\|# field element names\|# field elements name} a field that is not in {table}. They show nothing. |
| `forms.report.images` | {count\|# picture is\|# pictures are} not shown, because the file is not a picture attached to a record. |
| `forms.report.empty_links` | {count\|# link has\|# links have} no text, so {count\|it is\|they are} not shown. |

`.form-report-list`: `margin: 0; padding-left: 24`.

## 6. Source text area

`textarea.input.source-input`: `font-family: ui-monospace, "SFMono-Regular", "Menlo", "Consolas", monospace` (device monospace; no font is bundled for it and none is fetched), `font-size: 0.8889rem` (16 design px at Standard; scales with the text size), `line-height: 1.5`, `min-height: 30rem`, `width: 100%`, `white-space: pre`, `overflow: auto` (the box scrolls sideways inside itself; the page never does), `tab-size: 2`. This is the one place where the person's typeface choice does not apply, because source text needs fixed-width characters to line up; the person's text size and themes still apply. Recorded as a deviation for the maintainer (`docs/specs/M5-screens.md` section 12).

## 7. Entry action on the Fields page

`templates/pages/tables/fields.php` page head gains a second action after "Add a field": `forms.entry` "Edit the form" to `.../tables/{t}/form` (secondary button). The Fields page is already for Admins only (schema), so no Member sees it.

## 8. Layouts

| Layout value | `main` | Pages |
|---|---|---|
| `form` | `main#main.page.page-form` (width 1120, as `page-record`) | editor, source |
| `form-narrow` | `main#main.page.page-form.page-form-narrow` (width 800) | upload, reset |
