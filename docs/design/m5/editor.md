# M5 screen design: simple form editor

Status: design for milestone M5 (ui-design-plan section 6.2, "Simple form editor"; no mock-up). Logic: `docs/specs/M5-forms.md` sections 6 and 9.2; ADR 0020; P196 to P201. Implemented by M5-WP5 (page) on the components of M5-WP3 (`components.md`). Page strings: `strings/en/form-editor.php` (WP5). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Editor | `GET /projects/{p}/tables/{t}/form` | 200 |
| A change (move, hide, add, apply, section) | `POST /projects/{p}/tables/{t}/form` with `do` | 200 with the page; 422 when there are problems |
| Save | `POST /projects/{p}/tables/{t}/form` with `do=save` | 303 to the editor; 422 with problems |

Shell: breadcrumb Project / Tables / Items (to the table's Fields page), built with `ProjectController::crumbs` as the Fields page builds it, then `forms.editor.crumb` "Form"; layout `form`. Reached from the "Edit the form" action of the table's Fields page.

The page holds one `form#form-editor` (POST to the editor URL) around everything that changes the working copy, so every button posts every input. Nothing is stored until Save.

## 2. Page, in reading order

1. Page head: `h1` `forms.editor.title` "Form for {table}"; `p.lead` `forms.editor.lead` "Choose which fields the record screen shows, in what order, how wide, and in which sections. Records keep all their values, whatever the form shows."
2. Form tools navigation (`forms/tabs`, current `arrange`).
3. State note, one of:
   - Standard form (`isStandard`): `p.notice.notice-muted` `forms.editor.standard` "This is the standard form. It follows the table's fields by itself until you save a change."
   - Edited: `p.notice.notice-muted` `forms.editor.edited` "This form has been arranged by hand. Fields added to the table later appear at the end of its first section."
   - Stored form refused (`state` `refused`): `div.notice.notice-warn` with `p.notice-title` `forms.editor.refused_title` "The saved form could not be read" and `p` `forms.editor.refused` "Records are shown with the standard layout meanwhile. The editor starts from the standard layout; saving replaces the saved form. The Source page shows what is wrong."
4. `p.help#editor-help` `forms.editor.help` "Leave a label empty to use the field's name. Widths apply where the screen is wide enough; on narrow screens every field takes the whole width."
5. `form#form-editor` (POST, `csrf`, hidden `draft`, hidden `base`):
   1. The hidden apply button, first in tree order: `button.visually-hidden` `type="submit"` `name="do"` `value="apply"` `tabindex="-1"` with text `forms.editor.apply` "Apply changes". Pressing Enter in a text box applies the typed labels and names without moving anything (P163 pattern).
   2. `ol.form-rows` `aria-label` `forms.editor.rows_label` "Fields and sections, in reading order": one row per block (section 3).
   3. `div.form-actions-inline`: `button.button` `do=add-section` `forms.editor.add_section` "Add a section".
   4. `section.panel` `aria-labelledby` `not-on-form-title`: `h2#not-on-form-title.section-title` `forms.editor.not_on_form` "Not on this form". When every field is on the form: `p` `forms.editor.all_on_form` "Every field of {table} is on this form." Otherwise `ul.form-rows`, one `li.form-row` per field in field order: `.form-row-head` with the field's name and type, `span.form-row-kind` "Required" when required; for a required field `p.form-row-note` `forms.editor.required_hidden` "Required. While the form does not show it, records can be saved without it."; `button.button.button-plain` `do=add-{field key}` `forms.editor.add` "Add" with `aria-label` `forms.editor.add_aria` "Add {field} to the form".
   5. Report (`forms/report`), when there are unknown slots, pictures not shown, or empty links (the "not shown" part is already the list above and is left out here).
   6. `div.form-actions`: `button.button.button-primary.button-large` `do=save` `forms.editor.save` "Save the form"; `a.button.button-large` `form.cancel` "Cancel" to the Fields page; `p.help` `forms.editor.unsaved` "Changes stay on this page until you save them. Leaving the page drops them."

## 3. Rows

### 3.1 Field row (`kind` field)

`li.form-row` `id` = `id('row', path)`:

1. `.form-row-head`: `span.form-row-name` the field's name; `span.form-row-kind` the type label (M1 type names) and, when required, "Required".
2. `.form-row-inputs`:
   - Label: `label.label` `for` `id('label', path)` `forms.row.label` "Label"; `input.input` `type="text"` `name="label[{path}]"` `id` `id('label', path)` `maxlength="60"` value = the typed text (after a problem) or the slot's label or empty; `aria-describedby` `editor-help` and, with a problem, its error id; the error under it (`field-error`). Its accessible name is "Label for {field}" through `aria-label` `forms.row.label_aria` (the visible "Label" is its start).
   - Width: `label.label` `for` `id('width', path)` `forms.row.width` "Width"; `select.input` `name="width[{path}]"` with options `auto` "Automatic", `full` "Full width", `two-thirds` "Two thirds", `half` "Half", `one-third` "One third", and, only when the stored span is another value, `other` "As in the source: {span} of 12 columns" (selected); `aria-label` `forms.row.width_aria` "Width of {field}".
3. `p.form-row-note` `forms.row.placed` when the block carries `data-tiffin-col` or `data-tiffin-row`.
4. `.form-row-actions`: "Move up" (`do=up-{path}`, `aria-label` "Move up: {field}"; the visible text starts the name, WCAG 2.5.3), "Move down" (`do=down-{path}`), "Hide" (`do=hide-{path}`, `aria-label` "Hide {field}"). A move that cannot happen (first row up, last row down) is not shown.

A relation or attachment field is a field row like any other.

### 3.2 Missing slot (`kind` missing)

`li.form-row.form-row-missing`: `.form-row-head` with `span.form-row-name` the key (escaped) and `p.form-row-note` `forms.row.kind.missing` "This field is no longer in the table. It shows nothing."; actions Move up, Move down, Remove (`do=remove-{path}`).

### 3.3 Section row (`kind` section)

`li.form-row.form-row-section` `id` = `id('row', path)`:

1. `.form-row-head`: `span.form-row-kind` "Section"; `span.form-row-name` the legend or `forms.row.section_unnamed` "Section {number}, no name".
2. Section name: `label.label` `for` `id('legend', path)` "Section name"; `input.input` `name="legend[{path}]"` `maxlength="100"`, `aria-label` "Section name, section {number}" (the label starts the name); error under it.
3. `.form-row-actions`: Move up, Move down (whole section), `forms.row.ungroup` "Remove section" (`do=ungroup-{path}`, `aria-label` "Remove section {name} and keep what is in it").
4. `ol.form-rows.form-row-children` with the child rows, or `p.form-row-note` `forms.row.empty_section` "This section is empty."

### 3.4 Static rows (heading, text, divider, picture)

`li.form-row`: `.form-row-head` with `span.form-row-kind` ("Heading", "Text", "Divider", "Picture"); `p.form-row-summary`: the summary (heading or text, cut to 80 characters), `forms.row.divider` "A dividing line", or for a picture `forms.row.image_alt` "Description: {alt}" (or `forms.row.image_no_alt` "No description") and, when it cannot be shown, `p.form-row-note` `forms.row.image_hidden`; the placed note when it applies; actions Move up, Move down, Remove (`aria-label` "Remove Heading Where it is"). Static content is not editable here; the Source page edits it.

## 4. Results

| Action | Result |
|---|---|
| Move up, Move down | 200, the page with the row moved (crossing section edges as `docs/specs/M5-forms.md` 6.3); the same button on the moved row carries `autofocus`, or the other move button when the row reached an edge |
| Hide | 200; the field appears under "Not on this form"; focus (`autofocus`) on the next row's first button, or on "Add a section" when none follows |
| Add | 200; the field is the last row of the first section; `autofocus` on its "Move up" |
| Add a section | 200; the new section "New section" is the last row; `autofocus` on its Section name box |
| Remove section | 200; its rows stand in its place; `autofocus` on the first of them, or the next row |
| Remove (static, missing) | 200; as Hide |
| Enter in a text box (Apply) | 200; labels and names applied, nothing moved, no autofocus |
| A label or name breaks its rule | 422; error summary (layout) linking to each box; the box shows the typed text; no move or other action was made |
| Save | 303 to the editor with `notice.form_saved` "The form for {table} has been saved." (or `notice.form_saved_unchanged` "Nothing had changed, so the form was not saved again."); when the saved form equals the standard form, `notice.form_saved_standard` "The form for {table} has been saved. It is the same as the standard form, so it will follow the table's fields again." |
| Save after someone else saved | 422; summary `problem.form.changed` "Someone saved this form after you opened it. Save again to replace their version with yours." |
| Draft could not be read (tampered or broken) | 422; summary `problem.form.draft`; the page shows the saved form |
| An action that does not fit (stale or tampered button) | 422; summary `problem.form.action` |

Changes made by Apply, moves, and hides are not saved until Save. The browser may warn about resubmitting when the person reloads after a change (a POST page); the page says changes stay until saved.

## 5. Keyboard and screen reader

Tab order: breadcrumb, form tools, per row in reading order its Label box, Width menu, then its buttons (a section: name box, its buttons, then its children); "Add a section"; each "Add" under "Not on this form"; "Save the form"; "Cancel". The hidden Apply button has `tabindex="-1"` and is never reached by Tab. Each `li` is a list item of a labelled list, so a screen reader announces position ("3 of 9"); nested sections are nested lists. Every button's accessible name starts with its visible text and names its row. A changed page is announced through the moved control's focus (`autofocus`) and the layout's notice (`role="status"`) after Save. No script; the width menu does not submit on change (WCAG 3.2.2): the change applies on the next button press or Enter.

## 6. Without JavaScript

Every control is a submit button or a form field of the one form. The working copy is the hidden `draft` (the form's canonical text), parsed again on every request. Nothing is kept on the server between requests.

## 7. Narrow layout

At 390 px: the page head and form tools wrap; each row's label box and width menu stack (`.form-row-inputs` auto-fit); buttons wrap under them; nested section rows indent by 16 with the left rule. At 320 px Largest: one column, buttons one per line where needed, no sideways scroll.

## 8. Example (synthetic)

"Home Inventory", Items, after an Admin set Name to two thirds and Brand to one third, hid Serial number, added a section "Where it is" holding Room, and moved the heading "Care" up: rows read Details (Name: two thirds; Brand: one third; Price: half; Bought: half; Notes: full width), Heading "Care", Where it is (Room: automatic), Files (Manual, Photos), Warranties (Warranties). "Not on this form": Serial number.
