# M4 components: classes, sidebar, and shared partials

Status: design for milestone M4, implemented by M4-WP5 (`docs/specs/M4-screens.md` sections 5 to 8 and 12). M4 screens use these classes and the M1 to M3 classes (`docs/design/m1/components.md` to `docs/design/m3/components.md`). Values are taken from the inline styles of `record.html` and `collection.html` where they show them.

Values are **design px** at the Standard text size, written in `rem` in `app.css` by the M0 rule, except borders and the focus ring. Colours are tokens only; no token is added or redefined.

## 1. Classes

### 1.1 Relation control (one link) and linked-record rows (many links)

| Class | Properties | From the mock-up |
|---|---|---|
| `.link-control` | `display: flex; flex-wrap: wrap; align-items: center; gap: 12; min-height: 48; padding: 6 6 6 14; border: 2px solid var(--ctl); border-radius: 8` | the Room box |
| `.link-chosen` | `flex: 1 1 auto; font-weight: 700; overflow-wrap: anywhere` (the linked title, a link) | "Kitchen" |
| `.link-none` | `flex: 1 1 auto; color: var(--ink2)` | (not in the mock-up) "No room chosen" |
| `.link-list` (M2) | gains `gap: 10` (M2: no gap) | the warranties list |
| `.linked-row` | with `.link-row`: `display: flex; flex-wrap: wrap; align-items: center; gap: 8 16; padding: 8 8 8 14; border: 1px solid var(--line); border-radius: 8` | each warranty |
| `.linked-main` | `flex: 1 1 240; min-width: 0`; its link `font-weight: 700; overflow-wrap: anywhere` | |
| `.link-detail` | `display: block; color: var(--ink2)` | "Ends 14 Mar 2027" |
| `.control-actions` | `display: flex; flex-wrap: wrap; gap: 12; align-items: center` (a row of buttons under a control) | Photo buttons |
| `.button-strong` | with `.button`: `padding: 0 18` (the "Link a warranty", "Add a file" buttons; `.button` already has `font-weight: 700`) | |
| `.button-plain` | with `.button`: `padding: 0 16; font-weight: 400` (Unlink, Remove, Choose a different room) | |
| `.leave-note` | with `.help`: `margin: 0` | (added, D-35) |

### 1.2 File rows and images

| Class | Properties | From the mock-up |
|---|---|---|
| `.file-list` | `list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8` | |
| `.file-row` (M2) | becomes `display: flex; flex-wrap: wrap; align-items: center; gap: 8 16; padding: 8 8 8 14; border: 1px solid var(--line); border-radius: 8; background: var(--sunk)` | the receipt row |
| `.file-name` | `flex: 1 1 200; min-width: 0; overflow-wrap: anywhere` | |
| `.file-facts` | `color: var(--ink2)` (inside `.file-name`) | "(PDF, 84 KB)" |
| `.file-open` | `display: flex; align-items: center; min-height: 44` (the Open link) | "Open" |
| `.file-image-link` | `display: inline-block; flex: 0 0 auto; line-height: 0; border-radius: 8` (focus ring as every link) | |
| `.file-image` | `display: block; max-width: min(100%, 320); max-height: 220; width: auto; height: auto; object-fit: contain; border: 1px solid var(--line); border-radius: 8; background: var(--sunk)` | the 220-high, 320-wide photo box |
| `.file-row-image` | with `.file-row`: `align-items: flex-start` (a row that starts with an image) | |
| `.file-figure` | `margin: 0; display: flex; flex-direction: column; gap: 12` (file page) | |
| `.file-image-large` | `display: block; max-width: 100%; max-height: 70vh; width: auto; height: auto; object-fit: contain; border: 1px solid var(--line); border-radius: 12; background: var(--sunk)` (file page) | |

### 1.3 Picker

| Class | Properties |
|---|---|
| `.page-picker` | with `.page`: `max-width: var(--width-form)` (800) |
| `.picker-list` | `list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8` |
| `.picker-row` | as `.linked-row` (border 1px `--line`, radius 8, padding 8 8 8 14, flex wrap, gap 8 16, align center) |
| `.picker-title` | `flex: 1 1 240; min-width: 0; overflow-wrap: anywhere` |
| `.picker-taken` | `color: var(--ink2)` (the "Linked to {title}" text in place of a button) |
| `.picker-find` | `display: flex; flex-wrap: wrap; gap: 12; align-items: flex-end` (find box and button; the label above the box as every field) |
| `.picker-section` | `display: flex; flex-direction: column; gap: 12` |

### 1.4 Collection editor and view

| Class | Properties | From the mock-up |
|---|---|---|
| `.page-collection` | with `.page`: `max-width: var(--width-form)` (800); the preview table inside its `.table-wrap` | |
| `.collection-form` | `display: flex; flex-direction: column; gap: 24` | |
| `.rule-list` | `display: flex; flex-direction: column; gap: 12` | rules column |
| `.rule-row` | `fieldset`: `margin: 0; padding: 14; display: flex; flex-wrap: wrap; align-items: center; gap: 12; border: 1px solid var(--line); border-radius: 8; background: var(--sunk)` | each rule |
| `.rule-row legend` | `padding: 0 6; font-size: 16; color: var(--ink2)` | "Rule 1" |
| `.rule-control` | `flex: 1 1 200` (the field select, test select, value control) | |
| `.rule-control-wide` | `flex: 1 1 180` (the value control) | |
| `.rule-problem` | `flex: 1 0 100%` (an error text or a broken-rule explanation inside the row) | |
| `.rule-broken` | with `.rule-row`: `border: 2px solid var(--warn); padding: 13` | (added) |
| `.match-row` | `display: flex; flex-wrap: wrap; gap: 4 28` (the two radios) | |
| `.collection-actions` | `display: flex; flex-wrap: wrap; gap: 12; padding-top: 20; border-top: 1px solid var(--line)` | Save and Cancel row |
| `.collection-preview` | `section`: `display: flex; flex-direction: column; gap: 12` | match preview |
| `.preview-count-note` | `font-size: 16; font-weight: 400; color: var(--ink2)` | "(first 5 shown)" |
| `.default-action` | the visually hidden pattern (`.visually-hidden` properties) for the hidden first Preview button; it never receives focus by Tab (`tabindex="-1"`) | (added, P163) |

### 1.5 Trash

| Class | Properties |
|---|---|
| `.page-trash` | with `.page`: `max-width: var(--width-wide)` (1120) |
| `.trash-section` | `section.panel` (M3) with `gap: 12` |
| `.trash-list` | `list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8` |
| `.trash-row` | as `.linked-row` |
| `.trash-title` | `flex: 1 1 240; min-width: 0; overflow-wrap: anywhere; font-weight: 700` |
| `.trash-meta` | `display: block; color: var(--ink2); font-weight: 400` |
| `.trash-head` | `display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12` (section heading and "Restore all in {table}") |
| `.danger-zone` | `display: flex; flex-wrap: wrap; align-items: center; gap: 12; padding-top: 20; border-top: 1px solid var(--line)` (the "Empty the trash" area) |

### 1.6 Sidebar

| Class | Properties |
|---|---|
| `.sidebar-add` | with `.sidebar-link`: `font-weight: 700` (and the M2 sidebar colours) |

The trash line uses the existing `.sidebar-link` and `.sidebar-count` (it was a `.sidebar-item`).

### 1.7 Sizes and targets

| Element | Minimum |
|---|---|
| Unlink, Link, Choose, Remove, Replace, Add, Restore, Open links and buttons | 44 high (as the mock-up) |
| Save collection, Upload ("Add the file"), confirmation buttons | 48 high (`.button-large`) |
| Rule selects and inputs | 48 high (M1 input rule) |

At 390 px and 320 px Largest: `.link-control`, `.linked-row`, `.file-row`, `.picker-row`, `.trash-row`, `.rule-row` wrap their children onto new lines; buttons keep their full text; long titles and file names wrap (`overflow-wrap: anywhere`); images never exceed the column.

## 2. Sidebar (list view and collection view)

In reading order, `nav.sidebar` (M2, M3):

1. Project block (M2).
2. Tables group (M2).
3. **Collections group** (new): `div.sidebar-group`; `h2.sidebar-heading` `sidebar.collections` "Collections"; per collection `a.sidebar-link` to `/projects/{p}/collections/{c}` with `span` the name and `span.sidebar-count` the number of matching records (`aria-current="page"` on the collection being viewed); then `a.sidebar-link.sidebar-add` `sidebar.new_collection` "New collection" to `/projects/{p}/tables/{t}/collections/new` for the table being listed. The group is drawn when there is a collection or a table to add one to.
4. Data group (M3, Admins).
5. Foot: "Tables and fields" (M2); **the trash line becomes `a.sidebar-link`** to `/projects/{p}/trash` holding `span` `sidebar.trash` "In the trash" and `span.sidebar-count`. Never current.

## 3. Shared partials

Every text below is in `strings/en/components.php` unless marked. `{item}` and `{items}` in these strings are the **other** table's item name and name, passed by the caller as plain text.

### 3.1 `link-control` (a relation field that allows one link)

Inside the M2 `div.field` of the slot, after `p.label` (id `id('f', key)`, the label), help, and errors:

1. `div.link-control`:
   - with a link: `a.link-chosen` to `/projects/{p}/tables/{other}/records/{id}` with the title (or "{Item} {id}", M2 `records.untitled` rule);
   - without: `span.link-none` `components.link.none` "No {item} chosen";
   - when the person can edit and the record exists: `a.button.button-plain` to the picker (`.../records/{r}/links/{f}`) with text `components.link.choose_other` "Choose a different {item}" (with a link) or `components.link.choose` "Choose a record" (without; English articles cannot be chosen from a table's item name, so the label names the table through `aria-describedby`); `aria-describedby` = the label id (mock-up).
2. `p.help` `input.links.help` "Linked to the {table} table." (M2, unchanged).
3. When the person can edit and the record exists: the leave note (3.4). On a new record instead: `p.help` `components.link.after_save` "You can choose one once this record is saved."

### 3.2 `link-rows` (a relation field that allows many links)

1. With links: `ul.link-list`; per linked record `li.link-row.linked-row`:
   - `div.linked-main`: `a` to the linked record with its title; when a detail exists, `span.link-detail` "{field} {value}" (the field's name and its display text, P158; for example "Ends 14 Mar 2027").
   - When the person can edit: `button.button.button-plain` `type="submit"` `form` = the field's unlink form id, `name="target"`, `value` = the linked id, `aria-label` `components.link.unlink_label` "Unlink {title}", text `components.link.unlink` "Unlink".
2. Without links: `p.static-value` `input.links.none` "No linked records" (M2).
3. When the person can edit and the record exists: `a.button.button-strong` to the picker with `aria-describedby` = the label id: `components.link.add_first` "Link a record" (no links yet) or `components.link.add` "Link another {item}" (mock-up "Link a warranty"; see the article note in 3.1); then `p.help` `components.link.unlink_help` "Unlinking does not delete the {item} record." (mock-up); then the leave note. On a new record: `p.help` `components.link.after_save_many` "You can add links once this record is saved."

The unlink form itself is printed by the record page after the record form (`docs/design/m4/record-controls.md` section 2).

### 3.3 `file-rows` (an attachment field)

1. With files: `ul.file-list`; per file `li.file-row` (plus `.file-row-image` when it is an image):
   - for an image (one of the four types): `a.file-image-link` to the file page holding `img.file-image` (`src` the serving route, `alt` the file's name);
   - `span.file-name`: the original name, then ` ` and `span.file-facts` `input.file.facts` "({type}, {size})" (M2; type words `file.type.pdf` "PDF", `file.type.image` "image", `file.type.other` "file");
   - `a.file-open` to the file page: `components.file.open` "Open", `aria-label` `components.file.open_label` "Open {name}";
   - when the person can edit: `a.button.button-plain` to the upload page with `replace={a}`: `components.file.replace` "Replace", `aria-label` `components.file.replace_label` "Replace {name}"; `a.button.button-plain` to the remove confirmation: `components.file.remove` "Remove", `aria-label` `components.file.remove_label` "Remove {name}".
2. Without files: `p.static-value` `input.files.none` "No files" (M2).
3. When the person can edit and the record exists: `a.button.button-strong` to the upload page: `components.file.add` "Add a file" (`components.file.add_image` "Add an image" when the field takes images only); then the leave note. On a new record: `p.help` `components.file.after_save` "You can add files once this record is saved."

### 3.4 `leave-note`

`p.help.leave-note` `components.leave_note` "Save your changes first: the buttons here leave this page."
