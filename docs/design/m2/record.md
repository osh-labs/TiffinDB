# M2 screen design: record screens

Status: design for milestone M2. The record form follows `docs/design/model-ui/record.html` (shared with M4, which adds the relation picker and attachment upload); the other screens have no mock-up. Logic: `docs/specs/M2-records.md` sections 3, 5, and 7. Implemented by M2-WP7 with the components of M2-WP5. Strings: `strings/en/records.php` (WP7) unless marked as shared. Example data is synthetic.

## 1. Overview

| # | Screen | URL | Status | Template |
|---|---|---|---|---|
| 2 | Record form | `GET /projects/{p}/tables/{t}/records/{r}` with `<state>`; `POST` the same URL to save | 200; 422 with errors | `templates/pages/records/form.php` |
| 3 | New record | `GET .../records/new` with `<state>`; `POST .../records` with `<state>` to create | 200; 422 | `templates/pages/records/form.php` |
| 4 | Record in the trash | `GET .../records/{r}` when the record is in the trash | 200 | `templates/pages/records/trashed.php` |
| 5 | Edit conflict | Response to the save `POST` when the record changed meanwhile | 409 | `templates/pages/records/conflict.php` |
| 6 | Move to trash, restore | `POST .../records/{r}/trash`, `POST .../records/{r}/restore` with `<state>` | 303 | none |

Shell: signed-in shell, no sidebar, breadcrumb (ADR 0009 row A3), `nav` `''`. Permissions: viewing needs `records.view`, saving and creating `records.edit`, trash and restore `records.trash`; every one of these is held by Admins and by Members with a grant, so in M2 nobody sees a read-only record screen. A record id that is not a positive whole number, an id that does not exist, and a Member without a grant all give the 404 page.

The `<state>` query string is carried on every link and form action of these screens and passed back to the list, so Previous, Next, Back to list, and the redirects after saving keep the list's order, search, filter, and page.

## 2. Record form

Page title: the record's title (or `records.untitled` "{Item} {id}").

`main#main.page.page-record` (`.page-record-narrow` when the form has no `Details` section), in reading order:

1. Breadcrumb (shared): project name (to `/projects/{p}`), table name (to the list with `<state>`), the record's title (current).
2. Flash notice; error summary at 422 (shared partials; the summary links to each field's control id).
3. `div.page-head`:
   - `div`: `h1` the title; `p.lead`:
     - with a position in the list: `records.lead` "{Item} {position} of {total}. Last saved {when}.";
     - without: `records.lead_saved` "Last saved {when}."

     `{when}` is `date.at` "{date} at {time}" (shared, `docs/specs/M2-screens.md` section 7) from `_updated_at` in the configured time zone, with `{date}` from `Format::relativeDate` in lower case when it is "Today" or "Yesterday" ("Last saved today at 14:32"). This replaces the mock-up's "All changes saved" (no autosave, ADR 0013 decision 7).
   - `div.record-nav`, only with a position: "Previous" and "Next" (`records.previous`, `records.next`) as `a.button` to the neighbouring record with `<state>` and `rel="prev"`/`rel="next"`, or `span.button` with `aria-disabled="true"` at either end. Each link's `aria-label` is `records.previous_label` "Previous {item}: {title}" or `records.next_label` "Next {item}: {title}".
4. `form#record-form.record-form` (`method="post"`, `action` = this URL with `<state>`): `csrf`; hidden `input name="version"` with the record's version token; then the bound form (section 2.1).
5. `div.record-actions`:
   - `div.record-actions-main`: `button.button.button-primary.button-large` `type="submit"` `form="record-form"`: `records.save` "Save"; `a.button.button-large` to the list with `<state>`: `records.back` "Back to list".
   - `form.trash-form` (`method="post"`, `action` = `.../records/{r}/trash` with `<state>`): `csrf`; `button.button.button-danger.button-large` `type="submit"`: `records.trash` "Move to trash".
6. `p.record-meta`: `records.meta.added` "Added {date} by {name}." (or `records.meta.added_unknown` "Added {date}." when the person is unknown), then `records.meta.changed` "Last changed {date} by {name}." (or `records.meta.changed_unknown` "Last changed {date}."), then `records.meta.trash_note` "Records in the trash can be restored." Dates use `Format::relativeDate`; names are display names looked up by the core.

Success of a save: 303 to the same URL with `<state>`, flash `notice.record_saved` "Your changes have been saved." Validation failure: 422, the same page with the values as typed, each problem at its field and in the summary, page title `form.errors.page_title` (M1). A version mismatch: the conflict page (section 5).

### 2.1 The bound form

Built from the table's stored form, or the generated layout (ADR 0014 decision 4), by the templates `templates/partials/record-form/*.php` (WP7) using the shared per-type partials (`docs/specs/M2-screens.md` section 5):

| Node | Markup |
|---|---|
| Section | `fieldset.fieldset-card.record-section` (plus `.record-grid` when its role is `details`) with `legend.legend` = the stored legend (absent when the stored section has none), then its children |
| Field slot, editable type | `div.field` (plus `.span-full` when the slot spans the full width): `label.label` `for` = `$this->id('f', <key>)` holding the label (slot label or field name) and, when required, ` ` and `span.label-optional` `input.required` "(required)" (shared); `p.help` with id `id('help', key)` holding the field's help, when not empty; `p.error-text` with id `id('err', key)` per problem; then the control from the shared `field-input` partial with `id` `id('f', key)`, `name` `f_<key>`, `aria-describedby` listing the help and error ids present, `aria-invalid="true"` with an error, and `required="required"` when required |
| Field slot, rating | `fieldset.field-group` (`margin: 0; padding: 0; border: 0`, the M1 `.legend-plain` pattern): `legend.legend-plain` the label and marker; help and error `p`s; `div.choice-row` of `label.check` radios (shared partial). The first radio has `id('f', key)` so the error summary can link to it |
| Field slot, checkbox | `div.field`: `label.check.label` holding `input type="checkbox"` (id `id('f', key)`, `name` `f_<key>`, `value="1"`) and the label text, bold (mock-up "Under warranty"); help and error `p`s after the label, linked by `aria-describedby` |
| Field slot, auto-number | `div.field`: `p.label` with id `id('f', key)` holding the label; `p.static-value` the number, or on a new record `input.auto_number_new` "Given when you save" (shared). No input |
| Field slot, relation | `div.field`: `p.label` with id `id('f', key)` (visually hidden when the slot is the only child of a section with role `links`, because the legend already names it); with links, `ul.link-list` of `li.link-row` each holding `a` to the linked record (`/projects/{p}/tables/{other}/records/{id}`, no state) with its title or "{Item} {id}"; without, `p.static-value` `input.links.none` "No linked records"; then `p.help` `input.links.help` "Linked to the {table} table." (shared). No buttons: choosing and unlinking arrive in M4 |
| Field slot, attachment | `div.field`: `p.label` with id `id('f', key)`; per file `div.file-row` holding `span` the original name and `span.help` `input.file.facts` "({type}, {size})" (shared; type from the media type: `file.type.pdf` "PDF", `file.type.image` "image", `file.type.other` "file"; size from `Format::fileSize`); without files `p.static-value` `input.files.none` "No files". No links or buttons: opening and adding files arrive in M4 |
| Field slot naming a field that does not exist | nothing |
| Heading | `h2.section-title` or `h3` with the inline content |
| Paragraph | `p` with the inline content |
| Divider | `hr` |
| Image | nothing in M2 (attachment serving is M4) |
| Inline | text escaped; `strong`, `em`, `br` as themselves; a link as its text only (ADR 0014 decision 1) |

Fields of the table that the form does not name are not shown and not posted; saving leaves them unchanged (ADR 0013 decision 7).

## 3. New record

As section 2 with these differences:

- Breadcrumb last item `records.new.crumb` "New {item}"; `h1` `records.new.title` "New {item}"; `p.lead` `records.new.lead` "Fill in what you know. You can change it later."
- No record navigation, no version input, no trash form, no meta line.
- The form posts to `.../records` with `<state>`. Actions: primary `records.create` "Save"; `a.button.button-large` to the list with `<state>`: `form.cancel` "Cancel" (M1, through the shared `form-actions` partial is not possible here because the button uses `form="record-form"`; the template writes the same classes).
- Checkboxes start unticked, everything else empty.

Success: 303 to the new record's URL with `<state>`, flash `notice.record_added` "The {item} has been added." Validation failure: 422 as in section 2.

## 4. Record in the trash

`main#main.page.page-record.page-record-narrow`: breadcrumb as section 2; `h1` `records.trashed.title` "This {item} is in the trash"; `p` `records.trashed.body` "{title} was moved to the trash {date}. Restore it to see and change it again." (`{date}` from `Format::relativeDate`, lower case for today and yesterday as in section 2); `div.form-actions` with `form` (POST `.../records/{r}/restore` with `<state>`, `csrf`) holding `button.button.button-primary.button-large` `records.restore` "Restore", then `a.button.button-large` to the list with `<state>`: `records.back` "Back to list".

Restore success: 303 to the record's URL with `<state>`, flash `notice.record_restored` "{title} has been restored." Saving a record that is in the trash (a stale form) gives the 404 page.

## 5. Edit conflict

`main#main.page.page-record.page-record-narrow`, status 409:

1. Breadcrumb as section 2.
2. `h1` `records.conflict.title` "This {item} was changed while you were editing it".
3. `p` `records.conflict.body` "{name} saved a change {when}, after you opened it. What you typed has not been saved yet." (`records.conflict.body_unknown` "It was saved again {when}, after you opened it. What you typed has not been saved yet." when the person is unknown; `{when}` as section 2.)
4. When at least one field shown on the form differs between the saved record and what was typed: `div.table-wrap` > `table.data-table.compare-table`: `caption.visually-hidden` `records.conflict.caption` "Fields that differ"; `thead`: `records.conflict.col.field` "Field", `records.conflict.col.saved` "Saved version", `records.conflict.col.yours` "Your version"; one row per differing field in form order: `th scope="row"` the label; `td` the saved value's display text; `td` the typed text. An empty value shows `records.conflict.empty` "(empty)". Otherwise `p` `records.conflict.same` "The other change was to fields you did not change here, so saving keeps both."
5. `div.form-actions`: `a.button.button-primary.button-large` to the record's URL with `<state>`: `records.conflict.keep` "Keep the saved version"; then `form` (POST the record's URL with `<state>`): `csrf`, hidden `version` = the record's current token, one hidden `f_<key>` per field the original form posted, with the typed text (the M1 re-post rule, provisional decision P59); `button.button.button-danger.button-large` `records.conflict.overwrite` "Save my version anyway".

Pressing "Save my version anyway" saves through the normal path, so it can meet a conflict again if a third save happened meanwhile, or validation errors (then 422 on the record form).

## 6. Move to trash and restore

- `POST .../trash`: the record moves to the trash (no confirmation page, ADR 0013 decision 8); 303 to the list with `<state>`, flash `notice.record_trashed` "{title} has been moved to the trash." with an Undo button that posts to `.../records/{r}/restore` (`states.md` section 4).
- `POST .../restore`: as section 4.
- Either on a record already in that state: nothing changes; the same redirect and notice.

## 7. Keyboard, screen reader, narrow screens

- Tab order on the record form: skip link, top bar, breadcrumb links, Previous, Next, each control in form order (rating radios as one group: Tab enters the group, arrows choose), Save, Back to list, Move to trash, footer link. Enter in a one-line text input saves (implicit submission through the button's `form` attribute). No positive `tabindex`; no focus is moved by script.
- Every control has a visible label; required fields say "(required)" in the label; errors are tied to controls with `aria-describedby` and listed in the summary with links.
- Relation and file lists are plain lists of links and text.
- At 390 px the `Details` grid is one column, the record navigation wraps under the title, the action row wraps (Save, Back to list, then Move to trash on its own line). At 320 px with the Largest text size nothing scrolls sideways; long values wrap.
- Without JavaScript every part works; there is no script on these screens.
