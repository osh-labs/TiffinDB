# M4 screen design: relation and file controls on the record form

Status: design for milestone M4. Extends `docs/design/m2/record.md` (record form, new record) with the relation and file controls of `docs/design/model-ui/record.html`. Logic: `docs/specs/M4-core.md` sections 4, 9, and 10.1; ADR 0018, ADR 0019 decisions 8 to 11. Implemented by M4-WP6 with the partials of M4-WP5. Strings: `strings/en/records.php` (WP6) unless marked as shared (`components.php`, WP5). Example data is synthetic.

## 1. What changes on the record form

Everything in `docs/design/m2/record.md` sections 2 and 3 stays, except the relation and attachment rows of its section 2.1 table and the image row, which become:

| Node | Markup |
|---|---|
| Field slot, relation allowing one link | `div.field`: `p.label` id `id('f', key)` with the label and, when required, "(required)" (M2 marker); help; errors (`p.error-text` id `id('err', key)`, as every field); then the `link-control` partial (`components.md` section 3.1) |
| Field slot, relation allowing many links | `div.field`: `p.label` (visually hidden when it is the only child of a `links` section, M2); help; errors; then the `link-rows` partial (section 3.2) |
| Field slot, attachment | `div.field`: `p.label` with the label and the required marker; help; errors; then the `file-rows` partial (section 3.3) |
| Image (form format `img`) | when servable (`docs/specs/M4-core.md` section 9): `img.file-image` with `src` the serving route and `alt` the stored alternative text; otherwise nothing |

The paths each control needs are built by `RecordController` from keys and ids the core returned:

| Control | Path |
|---|---|
| Linked record | `/projects/{p}/tables/{other}/records/{id}` (no state) |
| Choose, Link | `/projects/{p}/tables/{t}/records/{r}/links/{f}` |
| Open, image link | `/projects/{p}/tables/{t}/records/{r}/files/{f}/{a}` |
| Image `src` | `/projects/{p}/files/{a}` |
| Replace | `/projects/{p}/tables/{t}/records/{r}/files/{f}/new?replace={a}` |
| Remove | `/projects/{p}/tables/{t}/records/{r}/files/{f}/{a}/remove` |
| Add a file | `/projects/{p}/tables/{t}/records/{r}/files/{f}/new` |

## 2. Unlink forms

After `form#record-form` and before `div.record-actions` (no nesting), for each relation field allowing many links that the bound form shows and that has at least one live link, when the person can edit: `form.unlink-form` with id `id('unlink', key)`, `method="post"`, `action` = `/projects/{p}/tables/{t}/records/{r}/links/{f}/remove` (no state), holding the `csrf` partial and `input type="hidden" name="back" value="record"`. The form has no visible content; the Unlink buttons inside the record form name it with `form="<id>"` and carry the target id as their own `name="target" value="..."`.

The one-link control has no Unlink: choosing replaces, and "Unlink" for one link is on the picker (`picker.md` section 2).

## 3. Reading order and keyboard

Tab order inside a section is the visual order: the linked title, "Choose a different room"; each linked row's title, then "Unlink"; "Link another warranty"; each file's image link (images only), "Open", "Replace", "Remove"; "Add a file". Pressing Enter on a link goes to its page; on Unlink submits its form. Enter in a one-line text input still saves the record (the default button of `#record-form` is Save, M2; the Unlink buttons belong to other forms and are never its default button).

Screen reader: each Unlink, Open, Replace, Remove has an accessible name naming its record or file (`components.md` section 3); "Choose a different room" and "Link a record" are described by the field's label. Images are inside links whose name is the file's name (the `alt`).

## 4. Results of the actions (notices on the record page)

Flash notices (M1 `notice` partial) shown on the record page after the 303:

| After | Key | Text |
|---|---|---|
| Unlink from the record form | `notice.link_removed` | "{title} is no longer linked." |
| A file added | `notice.file_added` | "{name} has been added." |
| A file replaced | `notice.file_replaced` | "{name} has replaced {old}." |
| A file removed | `notice.file_removed` | "{name} has been removed." |

`{title}` is the linked record's title (or "{Item} {id}"); `{name}` and `{old}` are file names. All are escaped plain text.

## 5. Required relation and attachment fields

A required relation or attachment field shows "(required)" after its label (M2 marker). Saving an existing record whose required field shown on the form has no live link or no file gives the 422 page: the problem `problem.link.required` "Link at least one record here." or `problem.attachment.required` "Add at least one file here." under the field's label (`p.error-text` id `id('err', key)`, referenced by the control's `aria-describedby` where a control exists), and an error-summary link to `#` + `id('f', key)` (the `p.label` of the slot, which has that id, so following the link moves the reading position to the field). The values typed are kept as for every 422 (M2).

A new record never enforces these fields; the page tells the person that links and files come after the first save (section 6).

## 6. New record

As M2 section 3, plus: a relation or attachment slot shows its label and, in place of controls, the after-save note of its partial (`components.link.after_save`, `components.link.after_save_many`, `components.file.after_save`). No leave note.

When the new-record form was opened from a picker (`?from=<field key>-<record id>`, `picker.md` section 4):

- `p.lead` becomes `records.new.lead_from` "Fill in what you know. When you save, this {item} is linked to {title} and you go back to choosing." (`{title}` the origin record's title).
- A hidden input `name="from"` with the same value is inside `#record-form`.
- "Cancel" goes back to the picker (`/projects/{p}/tables/{origin table}/records/{origin}/links/{f}`) instead of the list.
- Success: 303 to the picker with flash `notice.link_added_new` "{title} has been added and linked." (`{title}` the new record's title). A link refused (for example the origin's one-link field holds a record in the trash): 422 on the new-record page with the problem in the summary (`problem.link.trashed_holds`), nothing created.
- Breadcrumb: the origin's project, table, and record, then `records.new.crumb` "New {item}".

## 7. Without JavaScript

Nothing changes: every control is a link or a submit button.

## 8. Narrow layout

At 390 px the Details grid is one column (M2); `.link-control`, linked rows, and file rows wrap; the image takes at most the column width and 220 high. At 320 px with the Largest text size nothing scrolls sideways; long titles and file names wrap inside their rows.
