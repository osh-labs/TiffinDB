# M1 screen design: tables and the table and field editor

Status: design for milestone M1 (undesigned screens, ui-design-plan section 6.2). Logic: `docs/specs/M1-foundation.md` sections 7, 8, and 9.5. Implemented by M1-WP11. Example data is synthetic.

## 1. Overview

| # | Screen | URL | Who | Template |
|---|---|---|---|---|
| 2 | Project settings | `GET`, `POST /projects/{p}/settings` | Admin | `templates/pages/projects/settings.php` |
| 3 | Tables | `GET /projects/{p}/tables` | Admin; Member with a grant (read-only) | `templates/pages/tables/index.php` |
| 4 | New table | `GET /projects/{p}/tables/new`, `POST /projects/{p}/tables` | Admin | `templates/pages/tables/new.php` |
| 5 | Table fields | `GET /projects/{p}/tables/{t}/fields`; `POST .../settings` | Admin | `templates/pages/tables/fields.php` |
| 6 | Delete table | `GET`, `POST /projects/{p}/tables/{t}/delete` | Admin | `templates/pages/tables/delete.php` |
| 7 | Add a field | `GET /projects/{p}/tables/{t}/fields/new` (optionally `?type=`), `POST .../fields` | Admin | `templates/pages/fields/choose-type.php`, `templates/pages/fields/new.php` |
| 8 | Change a field | `GET`, `POST .../fields/{f}`; `POST .../fields/{f}/move` | Admin | `templates/pages/fields/edit.php` |
| 9 | Change a field's type | `GET`, `POST .../fields/{f}/type` | Admin | `templates/pages/fields/type.php` |
| 10 | Delete a field | `GET`, `POST .../fields/{f}/delete` | Admin | `templates/pages/fields/delete.php` |
| 11 | Confirm clearing values | Response to `POST .../fields/{f}` or `.../type`, status 409 | Admin | `templates/pages/fields/confirm.php` |

Shell: signed-in shell, `nav` = `projects`. Every screen has a breadcrumb starting `shell.nav.projects` "Projects" (to `/projects`), then the project name (to `/projects/{p}/tables`), then the table name (to `.../fields`) where relevant. Strings: `strings/en/schema.php` and `strings/en/types.php` (WP11).

Permissions: the controllers call `AuthOperations::require(actor, schema.change, project)` before rendering screens 2 and 4 to 11 (project settings uses `project.manage`), so a Member gets the 403 page and someone without a grant the 404 page. The tables page uses `project.view` and `AuthOperations::can(actor, schema.change, project)` to decide whether to show editing controls.

Option inputs are named `opt_<option>` (for example `opt_max_length`) and mapped by the controller to the core's option names; problems with field `options.<name>` map to element id `opt-<name with _ as ->`.

## 2. Project settings

`main#main.page`: breadcrumb (Projects, project, `schema.settings.crumb` "Settings"); error summary; `h1` `schema.settings.title` "Project settings"; `form.form.form-card.form-wide` (POST `.../settings`): `csrf`; field `project-name` (label `projects.field.name` "Project name", help `projects.field.name_help`, `name="name"`, value = current name); `div.form-actions`: primary `button.button.button-primary.button-large` `schema.settings.submit` "Save"; `a` Cancel to `/projects/{p}/tables`.

Success: 303 to `/projects/{p}/tables`, flash `notice.project_renamed` "The project is now called {name}."

## 3. Tables

`main#main.page`:

1. Breadcrumb: Projects, then the project name as the current item.
2. Flash notice.
3. `div.page-head`: `div` with `h1` the project name and `p.lead` `count.tables.one` "1 table" or `count.tables.many` "{count} tables" (`schema.tables.lead_empty` "No tables yet" when 0); Admins: `div.page-actions` with `a.button.button-primary` to `.../tables/new`: `schema.tables.add` "Add a table", `a.button` to `.../sharing`: `projects.sharing` "Sharing", `a.button` to `.../settings`: `schema.settings.title` "Project settings".
4. With tables: `div.table-wrap` > `table.data-table`: `caption.visually-hidden` `schema.tables.caption` "Tables in {project}"; `thead`: `schema.tables.col.table` "Table", `th.num` `schema.tables.col.fields` "Fields", `th.num` `schema.tables.col.records` "Records"; rows in table order: `th scope="row"` with, for Admins, a link to `.../tables/{t}/fields` holding the table name and `aria-label` `schema.tables.change_label` "Change the fields of {table}", for Members the plain name; `td.num` field count; `td.num` live record count.
5. Without tables: `p.notice` with `schema.tables.empty.admin` "This project has no tables yet. Select Add a table to make one." (Admins) or `schema.tables.empty.member` "This project has no tables yet." (Members).
6. Members: `p.notice.notice-muted` `schema.tables.member_note` "Only Admins can change tables and fields."

## 4. New table

`main#main.page`: breadcrumb (Projects, project, `schema.table.new.crumb` "New table"); error summary; `div` with `h1` `schema.table.new.title` "New table" and `p.lead` `schema.table.new.lead` "A table holds one kind of thing, such as items, rooms, or recipes.".

`form.form.form-card.form-wide` (POST `/projects/{p}/tables`):

1. `csrf`.
2. Field `table-name`: label `schema.table.name` "Table name"; help `schema.table.name_help` "A plural word works well, for example Items or Recipes."; `name="name"`.
3. Field `table-item`: label `schema.table.item` "One record is called"; help `schema.table.item_help` "Used on buttons, for example Add item. Use a singular word in lower case."; `input.input.input-medium` `name="item_name"`, value `item` by default.
4. `label.check`: `input#table-with-name type="checkbox" name="with_name" value="1"` (checked by default) and `schema.table.with_name` "Start with a Name field"; `p.help#table-with-name-help` `schema.table.with_name_help` "Most tables need one. You can rename it later."
5. `div.form-actions`: primary `schema.table.new.submit` "Create table"; Cancel to `.../tables`.

Field map: `name` to `table-name`, `item_name` to `table-item`. Success: 303 to `.../tables/{t}/fields`, flash `notice.table_created` "The table {table} has been created."

## 5. Table fields

`main#main.page`:

1. Breadcrumb: Projects, project, the table name (current).
2. Flash notice; error summary at 422 (table settings form).
3. `div.page-head`: `div` with `h1` the table name and `p.lead` `schema.fields.lead` "{fields}. {records}." where `{fields}` is `count.fields.one` "1 field" / `count.fields.many` "{count} fields" and `{records}` is `count.records.one` "1 record" / `count.records.many` "{count} records"; `div.page-actions` with `a.button.button-primary` to `.../fields/new`: `schema.fields.add` "Add a field".
4. `section.section` `aria-labelledby="fields-title"`: `h2#fields-title.section-title` `schema.fields.title` "Fields".
   - With fields: `div.table-wrap` > `table.data-table`: `caption.visually-hidden` `schema.fields.caption` "Fields in {table}, in the order they appear"; `thead`: `schema.fields.col.field` "Field", `schema.fields.col.type` "Type", `schema.fields.col.required` "Must be filled in", `schema.fields.col.order` "Order"; rows in field order:
     - `th scope="row"`: link to `.../fields/{f}` holding the field name; for the title field, then ` ` and `span.help` `schema.fields.title_marker` "(shown as the title)".
     - `td`: the type label (`type.<id>`); for a relation `type.relation.to` "Link to {table}", then on a new line `span.help` `schema.links.one` "one each" or `schema.links.many` "several each".
     - `td`: `schema.fields.required.yes` "Yes" or `schema.fields.required.no` "No".
     - `td` > `div.cell-actions`: unless first, `form` (POST `.../fields/{f}/move`, `csrf`, hidden `direction` = `up`) with `button.button` `schema.fields.up` "Up" and `aria-label` `schema.fields.up_label` "Move {field} up"; unless last, the same with `down`, "Down", `schema.fields.down_label` "Move {field} down".
   - Without fields: `p.notice` `schema.fields.empty` "This table has no fields yet. Select Add a field to make one."
5. `form.form` (POST `.../tables/{t}/settings`) with `fieldset.fieldset-card`, `legend.legend` `schema.table.settings` "Table settings":
   - Field `settings-name` (`schema.table.name`, `name="name"`), field `settings-item` (`schema.table.item`, `name="item_name"`), both with their help.
   - Field `settings-title`: label `schema.table.title_field` "Field shown as the title"; help `schema.table.title_field_help` "Shown as each record's name in lists and links."; `select#settings-title.input` `name="title_field"`: `option value=""` `schema.table.title_none` "None", then each field whose type can be a title.
   - `div.form-actions`: `button.button` `schema.table.settings_submit` "Save table settings".
6. `section.fieldset-card` with `h2.legend` `schema.table.delete_title` "Delete this table": `p` `schema.table.delete_intro` "Deletes the table, its records, and the links to it from other tables. TiffinDB saves a copy of the project first."; `a.button.button-danger` to `.../tables/{t}/delete`: `schema.table.delete` "Delete table".

Field map (settings form): `name` to `settings-name`, `item_name` to `settings-item`, `title_field` to `settings-title`. Success: 303 back with `notice.table_saved` "The table settings have been saved." Moving a field: 303 back to this page, with no notice.

## 6. Delete table (confirmation)

`main#main.page`: breadcrumb (Projects, project, table, `schema.delete.crumb` "Delete"); error summary at 422; `h1` `schema.table.delete_confirm.title` "Delete the table {table}?"; `p` `schema.table.delete_confirm.body` "This deletes {records} and every field in the table." (`{records}` from `count.records.*`); when other tables link to it: `p` `schema.table.delete_confirm.links` "These fields in other tables link to it and will be deleted too:" and `ul` of `schema.table.delete_confirm.link` "{field} in {table}"; `p` `schema.delete.copy` "TiffinDB saves a copy of the project before deleting. The person who looks after this TiffinDB can restore it from that copy."

`form.form` (POST `.../tables/{t}/delete`): `csrf`; `div.field` with `label.check`: `input#delete-confirm type="checkbox" name="confirm" value="1" required="required"` and `schema.table.delete_confirm.check` "I understand that {records} will be deleted."; error `p.error-text#delete-confirm-error`; `div.form-actions`: `button.button.button-danger.button-large` `schema.table.delete` "Delete table"; Cancel to `.../fields`.

Without the box ticked: 422 with `schema.delete.confirm_required` "Tick the box to confirm." at `delete-confirm` (checked by the web layer; the core is not called). Success: 303 to `.../tables`, flash `notice.table_deleted` "The table {table} has been deleted."

## 7. Add a field

### 7.1 Step 1: choose the type (`GET .../fields/new`, no valid `type`)

`main#main.page`: breadcrumb (Projects, project, table, `schema.field.new.crumb` "Add a field"); `h1` `schema.field.new.title` "Add a field to {table}".

`form.form.form-wide` `method="get"` `action` = `.../fields/new` (a GET form: choosing a type is view state, ADR 0008 decision 3):

- `fieldset.fieldset-card` with `legend.legend` `schema.field.type_question` "What will this field hold?". Inside, one group per type group in this order, each a `fieldset` with `legend.legend-plain` and a `div.radio-grid` of `label.radio-card`s (`input type="radio" name="type" value="<id>"`, `span.radio-card-title` `type.<id>`, `span.radio-card-hint` `type.<id>.hint`):

| Group (`type.group.*`) | Types |
|---|---|
| `words` "Words" | text, long_text |
| `numbers` "Numbers and money" | number, currency, rating, auto_number |
| `time` "Dates and times" | date, time, date_time, duration |
| `choices` "Yes or no, and choices" | checkbox, choice |
| `contact` "Contact and web" | email, phone, url |
| `links` "Files and links" | attachment, relation |

- `div.form-actions`: `button.button.button-primary.button-large` `schema.field.next` "Next"; Cancel to `.../fields`.

With `?type=` present but unknown, step 1 is shown with the error `schema.field.type_required` "Choose what the field will hold." above the radio groups (status 422).

### 7.2 Step 2: name and settings (`GET .../fields/new?type=<id>`, `POST .../fields`)

`main#main.page`: breadcrumb as 7.1; error summary; `div` with `h1` `schema.field.new.type_title` "New field: {type}" (the type label as listed, for example "New field: Date and time") and `p.lead` `schema.field.new.type_lead` "In the table {table}." followed by a link to `.../fields/new`: `schema.field.change_type_choice` "Choose a different type".

`form.form.form-card.form-wide` (POST `.../fields`):

1. `csrf`; `input type="hidden" name="type"` with the type id.
2. Field `field-name`: label `schema.field.name` "Field name"; help `schema.field.name_help` "For example Purchased or Room."; `name="name"`.
3. Field `field-help`: label `schema.field.help` "Hint shown under the field" plus `span.label-optional` `form.optional`; help `schema.field.help_help` "For example: As printed on the receipt."; `name="help"`.
4. Except for checkbox and auto-number: `label.check` with `input#field-required type="checkbox" name="required" value="1"` and `schema.field.required` "People must fill this in".
5. The options partial for the type: `templates/partials/field-options/<id>.php` (section 7.3), in "new" mode.
6. `div.form-actions`: primary `schema.field.new.submit` "Add field"; Cancel to `.../fields`.

Field map: `name` to `field-name`, `help` to `field-help`, `options.<n>` to `opt-<n>`, `target` to `opt-target`, `reverse_name` to `opt-reverse-name`. Success: 303 to `.../fields`, flash `notice.field_added` "The field {field} has been added."

### 7.3 Options per type

Each partial receives `mode` (`new` or `edit`), `values` (current or submitted), `errors` (by element id), and, for relations, `tables` (list of `{key, name, isThis}`) and `itemName`. Every input below is a `div.field` with label, optional help, error, and control, in this order.

| Type | Inputs |
|---|---|
| `text`, `long_text` | `opt-max-length`: `schema.opt.max_length` "Longest allowed, in characters"; `input.input.input-short type="number" name="opt_max_length" min="1"` (max 10000 or 1000000); default 500 or 20000 |
| `number` | `opt-decimals`: `schema.opt.decimals` "Digits after the point"; `select.input.input-short name="opt_decimals"` with 0 to 6 (default 0). `opt-min`: `schema.opt.min` "Smallest allowed" + optional marker; `input.input.input-short name="opt_min" inputmode="decimal"`. `opt-max`: `schema.opt.max` "Largest allowed" + optional marker; `name="opt_max"` |
| `currency` | `opt-code`: `schema.opt.code` "Currency"; help `schema.opt.code_help` "Three letters, for example USD, EUR, or GBP."; `input.input.input-short name="opt_code" maxlength="3" autocapitalize="characters"`, default `USD`. `opt-minor-digits`: `schema.opt.minor_digits` "Digits after the point"; help `schema.opt.minor_digits_help` "Usually 2. This cannot be changed after the field is made."; `select.input.input-short name="opt_minor_digits"` 0 to 4, default 2. In edit mode the digits are shown as text: `schema.opt.minor_digits_fixed` "Digits after the point: {digits}. This cannot be changed." |
| `date`, `checkbox`, `url`, `email`, `phone` | `p.help` `schema.opt.none` "This type has no settings." |
| `time`, `date_time`, `duration` | `label.check`: `input#opt-seconds type="checkbox" name="opt_seconds" value="1"` and `schema.opt.seconds` "Show and enter seconds" |
| `choice` | New mode: `opt-choices`: `schema.opt.choices` "Choices, one per line"; help `schema.opt.choices_help` "For example Appliance, Tool, and Furniture, each on its own line."; `textarea.input name="opt_choices" rows="6"`. Edit mode: `fieldset` with `legend.legend-plain` `schema.opt.choices_existing` "Choices"; help `schema.opt.choices_edit_help` "Change a choice to rename it everywhere it is used. Empty a box to remove that choice."; one `input.input` per existing choice, `name="opt_choice_<choice key>"`, `aria-label` `schema.opt.choice_label` "Choice {number}"; then `opt-choices-new`: `schema.opt.choices_add` "Add more choices, one per line" + optional marker; `textarea.input name="opt_choices" rows="3"` |
| `rating` | `opt-max`: `schema.opt.rating_max` "Highest rating"; `select.input.input-short name="opt_max"` 3 to 10, default 5 |
| `attachment` | `label.check`: `input#opt-images-only type="checkbox" name="opt_images_only" value="1"` and `schema.opt.images_only` "Only pictures"; `p.help` `schema.opt.images_only_help` "Accept only image files, such as photos." |
| `auto_number` | New mode: `opt-start`: `schema.opt.start` "Start at"; help `schema.opt.start_help` "Existing records are numbered from here, in the order they were added. This cannot be changed later."; `input.input.input-short type="number" name="opt_start" min="0"`, default 1. Edit mode: `p` `schema.opt.start_fixed` "Numbers start at {start}. The next record gets {next}." |
| `relation` | New mode: `opt-target`: `schema.opt.target` "Link to records in"; `select.input name="opt_target"` with one `option` per table (`schema.opt.target_this` "{table} (this table)" for the current one). `fieldset` with `legend.legend-plain` `schema.opt.this_max` "Each {item} can link to"; error; two `label.check` radios `name="opt_this_max"`: `one` `schema.opt.max.one` "One record", `many` `schema.opt.max.many` "Several records" (default `one`). `fieldset` with `legend.legend-plain` `schema.opt.other_max` "Each record in the linked table can link to"; radios `name="opt_other_max"`: `one` `schema.opt.other.one` "One {item}", `many` `schema.opt.other.many` "Several of these" (default `many`). `opt-reverse-name`: `schema.opt.reverse_name` "Name of the matching field in the linked table"; help `schema.opt.reverse_name_help` "TiffinDB adds this field to the other table, so the link shows there too."; `input.input name="opt_reverse_name"`, default the current table's name. Edit mode: `p` `schema.opt.links_to` "Links to records in {table}."; the "Each {item} can link to" radios only (`opt_this_max`), mapped to the core option `links` |

`{item}` is the current table's item name ("item").

## 8. Change a field

`main#main.page`: breadcrumb (Projects, project, table, field name); flash; error summary; `div` with `h1` `schema.field.edit.title` "Change the field {field}" and `p.lead`: `schema.field.edit.type` "Type: {type}." then, when the type can change, a link to `.../fields/{f}/type`: `schema.field.edit.change_type` "Change the type", or `schema.field.edit.type_fixed` "The type of this field cannot be changed."; then `p` `schema.field.edit.values` "{count} records have a value in this field." (or `schema.field.edit.values_one` "1 record has a value in this field.", `schema.field.edit.values_none` "No records have a value in this field yet."; relation and attachment fields omit this line).

`form.form.form-card.form-wide` (POST `.../fields/{f}`): `csrf`; field name, hint, required (as 7.2, filled with current values); options partial in edit mode; `div.form-actions`: primary `schema.field.edit.submit` "Save field"; Cancel to `.../fields`.

`section.fieldset-card` with `h2.legend` `schema.field.delete_title` "Delete this field": `p` `schema.field.delete_intro` "Deletes the field and its values. TiffinDB saves a copy of the project first."; `a.button.button-danger` to `.../fields/{f}/delete`: `schema.field.delete` "Delete field".

Saving a change that clears values (removed choice in use, lower rating maximum): status 409, screen 11. Success: 303 to `.../fields`, flash `notice.field_saved` "The field {field} has been saved."

## 9. Change a field's type

`main#main.page`: breadcrumb (…, field name, `schema.type.crumb` "Type"); error summary; `div` with `h1` `schema.type.title` "Change the type of {field}" and `p.lead` `schema.type.now` "It is now: {type}."; `p` `schema.type.intro` "TiffinDB converts the values it can. Values it cannot convert are cleared, and it tells you how many before it does anything. It saves a copy of the project first."

`form.form.form-wide` (POST `.../fields/{f}/type`): `csrf`; `fieldset.fieldset-card` with `legend.legend` `schema.type.question` "Change it to"; `div.radio-grid` of `label.radio-card`s for each type in `FieldDesign::$convertibleTo` (title and hint as 7.1); error `p.error-text#type-choice-error` (the first radio has `id="type-choice"`); `div.form-actions`: primary `schema.type.submit` "Change type"; Cancel to `.../fields/{f}`.

The new type starts with its default options (a choice type takes its choices from the existing values); its settings can be changed afterwards on screen 8. Lossy: status 409, screen 11. Success: 303 to `.../fields/{f}`, flash `notice.type_changed` "{field} is now a {type} field." or, when values were cleared, `notice.type_changed_cleared` "{field} is now a {type} field. {count} values could not be converted and were cleared."

## 10. Delete a field (confirmation)

`main#main.page`: breadcrumb (…, field name, `schema.delete.crumb` "Delete"); `h1` `schema.field.delete_confirm.title` "Delete the field {field}?"; `p` `schema.field.delete_confirm.body` "Its values in {records} will be deleted." (`count.records.*` of `valueCount`; relation and attachment fields use `schema.field.delete_confirm.body_links` "Every link or file in this field will be removed."); for a relation, `p` `schema.field.delete_confirm.reverse` "The matching field {other} in {table} is deleted too."; `p` `schema.delete.copy`.

`form.form` (POST `.../fields/{f}/delete`): `csrf`; `div.form-actions`: `button.button.button-danger.button-large` `schema.field.delete` "Delete field"; Cancel to `.../fields/{f}`.

Success: 303 to `.../fields`, flash `notice.field_deleted` "The field {field} has been deleted."

## 11. Confirm clearing values

Shown with status 409 when the core throws `ConfirmationRequired` for a field change or a type change.

`main#main.page`: breadcrumb as the screen it came from; `h1` `schema.confirm.title` "Clear {values} values?" (`schema.confirm.title_one` "Clear 1 value?" for one); `p` `schema.confirm.body` "With this change, {values} values in {records} records no longer fit and will be cleared. TiffinDB saves a copy of the project first." (`{records}` from `count.records.*`).

`form.form` with the same `action` as the form that was submitted: `csrf`; every submitted field again as `input type="hidden"` (name and value escaped; never a password, and none exist on these forms); `input type="hidden" name="confirm" value="1"`; `div.form-actions`: `button.button.button-danger.button-large` `schema.confirm.submit` "Clear {values} values and save" (`schema.confirm.submit_one` "Clear 1 value and save"); `a` back to the form's page: `schema.confirm.back` "Go back without saving".

## 12. Behaviour common to these screens

- Keyboard: reading order everywhere; the field table's move buttons are separate small forms, each reachable by Tab, with names that say which field moves; the type picker is native radio groups (arrow keys within a group, Tab between groups).
- Screen reader: tables have captions and row headers; the type picker's groups are fieldsets with legends; confirmation pages put the consequence in the `h1` and first paragraph; flash notices use `role="status"`.
- Without JavaScript: identical. The two-step "Add a field" works with an ordinary GET form; the relation settings do not depend on the selected table (they use the current table's item name only), so nothing needs updating on the page.
- 390 px: tables scroll inside `div.table-wrap`; radio cards stack one per row; move buttons wrap within their cell; forms span the width.

## 13. Strings

`strings/en/schema.php` (WP11): every `schema.*`, `notice.*`, and `count.*` key above, with the text given where it is first named, plus:

| Key | Text |
|---|---|
| `schema.settings.crumb` | Settings |
| `schema.delete.crumb` | Delete |
| `schema.delete.copy` | TiffinDB saves a copy of the project before deleting. The person who looks after this TiffinDB can restore it from that copy. |
| `schema.delete.confirm_required` | Tick the box to confirm. |
| `count.tables.one` | 1 table |
| `count.tables.many` | {count} tables |
| `count.fields.one` | 1 field |
| `count.fields.many` | {count} fields |
| `count.records.one` | 1 record |
| `count.records.many` | {count} records |
| `notice.project_renamed` | The project is now called {name}. |
| `notice.table_created` | The table {table} has been created. |
| `notice.table_saved` | The table settings have been saved. |
| `notice.table_deleted` | The table {table} has been deleted. |
| `notice.field_added` | The field {field} has been added. |
| `notice.field_saved` | The field {field} has been saved. |
| `notice.field_deleted` | The field {field} has been deleted. |
| `notice.type_changed` | {field} is now a {type} field. |
| `notice.type_changed_cleared` | {field} is now a {type} field. {count} values could not be converted and were cleared. |

`strings/en/types.php` (WP11): `docs/specs/M1-screens.md` section 5.4.

The strings test (T-WEB-19) and the template rules test (T-WEB-11) check that every key used exists and every key written is used or carries a run-time prefix; WP11 runs both before reporting.
