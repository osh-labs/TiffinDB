# M4 screen design: saved collections

Status: design for milestone M4. The editor follows `docs/design/model-ui/collection.html`; the collection view reuses the list view (`docs/design/m2/list-and-search.md`); the delete confirmation has no mock-up. Logic: `docs/specs/M4-core.md` sections 5, 6, 10.4; ADR 0019 decisions 1 to 7. Implemented by M4-WP7. Strings: `strings/en/collections.php` and `lists.php` (WP7). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Collection view | `GET /projects/{p}/collections/{c}` with `q`, `sort`, `page` | 200 |
| New collection (editor) | `GET /projects/{p}/tables/{t}/collections/new`; `POST /projects/{p}/tables/{t}/collections` | 200; 303 after Save; 422 |
| Edit a collection | `GET /projects/{p}/collections/{c}/edit`; `POST` the same | 200; 303; 422 |
| Delete confirmation | `GET /projects/{p}/collections/{c}/delete`; `POST` the same | 200; 303 to the table's list |

Open to Admins and granted Members (viewing `records.view`, the rest `collections.manage`). An unknown collection key, a collection of another project, and an ungranted person: 404.

## 2. Collection view

The list view (`docs/design/m2/list-and-search.md` sections 2 to 5) with these differences:

- Sidebar: the collection is the current item of the Collections group; no table is current.
- `div.page-head`: `h1` the collection's name; `p.lead` `lists.collection.lead` "{count|# record|# records} in {table} match. Sorted by {sort}." (M2 sort wording); `div.page-actions`: `a.button` `lists.collection.edit` "Edit collection" to the editor; the "Add {item}" link as on the table list.
- When rules are ignored: `div.notice.notice-warn` (`role="status"`) `lists.collection.broken` "{count|# rule of this collection no longer works and is ignored.|# rules of this collection no longer work and are ignored.} Edit the collection to fix or remove {count|it|them}." with the Edit link inside.
- List controls: find box and sort menu as M2 (the sort menu's default is the collection's sort); **no Filter link** (the collection is the filter); Apply button (M2).
- Records' links carry `<state>` with `collection={c}`, so the record page's Previous and Next follow the collection and "Back to list" returns to the collection.
- Empty: `div.empty-state` `lists.collection.empty` "No {items} match this collection yet. It updates by itself as records change." with the Edit link.

## 3. Editor (mock-up)

Page title: the collection's name, or `collections.new.title` "New collection" (`h1` the same). Breadcrumb: project, table (to the table's list), `collections.crumb` "Collection: {name}" or `collections.new.crumb` "New collection" (current). `main#main.page.page-collection`.

1. Breadcrumb; flash; error summary at 422 (links to `id('f', 'name')` and to each row's first control, `id('f', 'rule-<n>-field')`).
2. `div`: `h1`; `p.lead` `collections.lead` "A collection is a saved search. It updates by itself as records change." (mock-up).
3. `form#collection-form.collection-form` POST (to `.../collections` for a new one, to `.../edit` for an existing one), `data-collection-editor="1"`, `csrf`:
   1. `button.default-action` `type="submit" name="action" value="preview" tabindex="-1"` `collections.preview` "Preview" (the first submit button, so Enter in any text box previews, P163).
   2. `div.field` (max 480 wide): `label.label` for `id('f', 'name')` `collections.name.label` "Collection name"; `input.input` `name="name"` `maxlength` 60.
   3. `fieldset.field-group`: `legend.legend-plain` `collections.match.legend` "Show {items} that match"; `div.match-row`: two `label.check` radios `name="match"`: `collections.match.all` "All of these rules", `collections.match.any` "Any of these rules".
   4. `div.rule-list`: per row n (1 to 10, posted order) the `rule-row` partial (section 4); then, while fewer than 10 rows: `button.button.button-strong` `type="submit" name="action" value="add"` `collections.add_rule` "Add a rule".
   5. `div.field`: `label.label` for `id('f', 'sort')` `collections.sort.label` "Sort by"; `select` `name="sort"` with `collections.sort.default` "As the table" (value empty) then the table's sort options in the M2 menu order and wording.
   6. `div.collection-actions`: `button.button.button-primary.button-large` `type="submit" name="action" value="save"` `collections.save` "Save collection"; `button.button.button-large` `type="submit" name="action" value="preview" data-preview="1"` `collections.preview` "Preview"; `a.button.button-large` `form.cancel` "Cancel" to the collection's view (existing) or the table's list (new).
4. For an existing collection: `p` with `a` `collections.delete.link` "Delete this collection" to the delete confirmation.
5. `section.collection-preview` `aria-labelledby="c-res"`: `h2#c-res` `collections.preview.heading` "{count|# {item} matches|# {items} match}" followed by `span.preview-count-note` `collections.preview.first` "(first {shown} shown)" when more match than are shown; `div.table-wrap` with `table.data-table` (M2 classes): `th scope="col"` for the title and up to three list columns (`aria-sort` on the column the chosen sort orders by, when shown), rows with the title linked to the record (`<state>` with the collection when saved); with no match: `p.static-value` `collections.preview.none` "Nothing matches these rules yet."; with problems that stop the preview: `p.static-value` `collections.preview.fix` "Fix the rules above to see what matches."

Actions (all POST to the same address, `docs/specs/M4-screens.md` section 4 rule 5): `save` stores and answers 303 to the collection view with `notice.collection_saved` "The collection has been saved."; `preview`, `add`, `remove-<n>` re-render the editor with the posted values (200, nothing stored). Changing a rule's field takes effect on any press: the row's tests and value control then match the new field (an unfitting test resets to the field's first test). A new collection opens with one empty row.

Script (optional, `collection-editor.js`): changing a rule's field select or the match radios presses the visible Preview. Without script the person presses Preview (or Enter in a text box).

## 4. Rule row (`templates/partials/collections/rule-row.php`)

`fieldset.rule-row` (`.rule-broken` when the stored rule is broken): `legend` `collections.rule.legend` "Rule {n}"; then:

1. `select.rule-control` `name="rules[n][field]"` `id` `id('f', 'rule-n-field')` `aria-label` `collections.rule.field_label` "Field for rule {n}" `data-rule-field="1"`: `collections.rule.field_none` "Choose a field" (value empty) then the table's fields usable in rules (`CollectionRules::testsFor` not empty), in field order, by label.
2. `select.rule-control` `name="rules[n][test]"` `aria-label` `collections.rule.test_label` "Test for rule {n}": the field's tests (below); absent until a field is chosen.
3. The value control, `aria-label` `collections.rule.value_label` "{Kind} for rule {n}" where `{Kind}` is the control's kind word (`collections.kind.text` "Text", `collections.kind.number` "Number", `collections.kind.amount` "Amount", `collections.kind.rating` "Rating", `collections.kind.duration` "Length of time", `collections.kind.date` "Date", `collections.kind.choice` "Value", `collections.kind.record` "Record"); absent for tests that take no value:

   | Field type | Control (`name="rules[n][value]"` unless said) |
   |---|---|
   | text-like | `input type="text"` `maxlength` 200 |
   | number, currency | `input type="text" inputmode="decimal"` |
   | rating | `select` 1 to the field's maximum |
   | duration | `input type="text"` with `p.help` `input.duration.help` (M2) |
   | date, date-time | `input type="date"` |
   | choice | `select` of the field's choices: value the choice **key**, text the label |
   | relation | `select` `name="rules[n][record]"` of up to 500 records of the linked table (title or "{Item} {id}"), with `p.help` `collections.rule.records_more` "Only the first 500 are listed." when there are more |

4. `button.button.button-plain` `type="submit" name="action" value="remove-{n}"` `aria-label` `collections.rule.remove_label` "Remove rule {n}": `collections.rule.remove` "Remove".
5. `p.error-text.rule-problem` with the row's problems (422), or for a broken rule `p.rule-problem` with the broken reason (`collection.broken.*`, `docs/specs/M4-core.md` section 11) prefixed by `collections.rule.broken` "This rule no longer works and is ignored:".

Tests by field type (`collections.test.<token>`):

| Field types | Token: text |
|---|---|
| text-like | `contains`: "contains"; `is`: "is exactly"; `empty`: "is empty"; `not-empty`: "is not empty" |
| number, currency, rating, duration | `is`: "is"; `less`: "is less than"; `more`: "is more than"; `empty`: "is empty" |
| date, date-time | `on`: "is on"; `before`: "is before"; `after`: "is after"; `empty`: "is empty" |
| choice | `is`: "is"; `empty`: "is not chosen" |
| checkbox | `ticked`: "is ticked"; `not-ticked`: "is not ticked" |
| relation | `links-to`: "links to"; `empty`: "has no links" |

The `collections.test.` prefix is a runtime prefix (`docs/specs/M4-screens.md` section 7); each key is the token with `-` kept (`collections.test.not-empty`).

## 5. Delete confirmation

Page title and `h1`: `collections.delete.title` "Delete {name}?". M2 confirmation pattern: `p` `collections.delete.body` "The collection is deleted. Its {items} are not changed or moved to the trash. This cannot be undone."; `form` POST with `csrf`; `button.button.button-danger.button-large` `collections.delete.submit` "Delete the collection"; `a.button.button-large` `form.cancel` to the editor. Success: 303 to the table's list with `notice.collection_deleted` "{name} has been deleted."

## 6. Keyboard and screen reader

Tab order in the editor: name, match radios (one group; arrows choose), per row field, test, value, Remove; Add a rule; Sort; Save collection, Preview, Cancel; Delete link; the preview table's links. Enter in the name or a value box previews (default action). After Add, Remove, or Preview the page reloads at the top with no notice; the preview heading is an `h2` the person can reach by heading navigation. Every rule control has the mock-up's `aria-label`s. No positive `tabindex`; the hidden default button has `tabindex="-1"` and is never reached by Tab.

## 7. Narrow layout

At 390 px each rule row stacks its controls one per line, Remove last; the match radios wrap; the preview table scrolls inside its box (M2). At 320 px Largest nothing scrolls sideways outside the table box.
