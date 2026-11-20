# ADR 0019: Saved collections, and how relations are edited

Status: Accepted (provisional, awaiting maintainer confirmation). Settles the saved collections of plan section 3.3 (`_tiffin_collections`, "saved filters and sorts") and ui-design-plan section 6.1 (`collection.html`: name, all or any, rule rows, sort, live match preview), the relation picker of ui-design-plan section 6.2 ("Choose a different room", "Link a warranty"), the M4 session plan section 3 questions 4 and 5 (maintainer not answered; defaults applied), and P105 for relation fields. Format: `docs/formats/tiffin.md` version 4 (section 4.8, upgrade in section 11.3) and `docs/formats/json-export.md` section 7. Implementation design: `docs/specs/M4-core.md` sections 4 to 8 and 10, `docs/specs/M4-screens.md`, `docs/design/m4/`.

## Context

M2 built a list view with one filter condition from the query string (ADR 0013 decision 4, P89) and reserved its `Condition` value for collections. Collections are stored in the project file, so their rule model is a format commitment. Every screen must work without JavaScript (ui-design-plan section 10), forms cannot be nested, and the record form already uses a version token over its row and link ids (P85). The record mock-up shows relation controls inside the record form ("Choose a different room", "Unlink", "Link a warranty").

## Decisions: saved collections

### 1. Storage (format version 4)

One table `_tiffin_collections`: `key` (the project key scope of tables, fields, relations, and forms), `table_key` (deleting the table deletes its collections), `name` (1 to 60 characters, unique in the project ignoring ASCII case, because the sidebar lists every collection of the project by name), `match_mode` (`all` or `any`), `rules` (JSON), `sort` (a list sort token or null for the table's default order), `position` (order in the sidebar; new collections go last; no reordering screen in M4), and created and updated times and person keys. Exact SQL in `docs/formats/tiffin.md` sections 4 and 11.3.

### 2. Rule model

- A rule is `{"field": <field key>, "op": <operator>, "values": [<text>, ...]}`: the M2 `Condition` (field key, operator, operands), so the list filter and collections share one model.
- At most 10 rules; all of them or any of them must match (`match_mode`); no nested groups. A collection with no rules lists every live record of its table (a saved order).
- Operands are stored as text in each field type's canonical form (the form `toText` writes, for example `329.00` for currency, `2023-01-01` for a date) and are checked again with the field's current options every time the collection is read, so a change of options can never make a stored operand mean something else silently.
- Choice operands are the choice's **key**, not its label (the plan proposed labels), so renaming a choice keeps the rule working; removing the choice breaks it.
- Relation operands are record ids of the linked table.

### 3. Operators (session plan section 3 row 5, default)

| Field types | Tests |
|---|---|
| Text, long text, web address, email, phone | contains, is exactly (ASCII case ignored), is empty, is not empty |
| Number, currency, rating, duration | is, is less than, is more than, is empty |
| Date, date and time | is on, is before, is after, is empty (operands are dates; a date and time is on a day from 00:00:00 to 23:59:59) |
| Choice | is, is not chosen (as the M2 filter) |
| Checkbox | is ticked, is not ticked (as the M2 filter) |
| Relation | links to, has no links (as the M2 filter) |

Time, auto-number, and attachment fields cannot be used in rules in v1. "Contains" matches as search does (ASCII case-insensitive substring, P87). Empty values never match "is", "contains", "less", "more", "on", "before", or "after". The mock-up's "is on or after" becomes "is after" (listed as a deviation).

### 4. Broken rules

A rule whose field no longer exists, is no longer of a type the operator allows, or whose operand no longer passes the field's check (a removed choice, a lowered rating maximum, a linked record no longer live) is **broken**: it is kept as stored, shown in the editor with a plain explanation, and ignored by the query. Opening a collection with a broken rule never gives an error page; the collection view says how many rules are ignored. A sort naming a field that no longer exists falls back to the table's default order.

### 5. Ownership (session plan section 3 row 4, default)

Collections are shared: everyone with access to the project sees every collection, and Admins and granted Members (who hold `collections.manage`, ADR 0002 and the M1 permission table) may create, change, and delete any of them. Viewing needs `records.view`. At most 50 collections per project. Deleting a collection goes through a confirmation page (it cannot be undone); its records are untouched.

### 6. Query composition (security-critical)

`ListQuery` gains an optional rule set (match mode and conditions). `ListSql` builds the SQL of every condition, the list filter's and the rules', through one method with the existing `Sql` fragment type; values are always bound parameters; field names come only from definition objects (P107). The M2 one-condition filter is the one-rule case of the same method.

### 7. Screens without script

A collection opens in the list view at `/projects/{project}/collections/{collection}` with the list's paging, find, and sort (no extra filter there); record Previous and Next follow the collection. The editor is one form: "Add a rule", each rule's "Remove", and "Preview" are submit buttons that redisplay the form with the posted values; changing a rule's field is applied by any of them (the test and value controls then match the field). Enter in any text box previews (the form's default button is a Preview button placed first in the form and hidden from view). The preview shows how many records match and the first five. An optional script presses Preview when a rule's field changes; without it nothing is lost.

## Decisions: relations

### 8. Requiredness of relation fields (closes P105 with ADR 0018 decision 7)

A required relation field is enforced when an existing record is saved and its effective form shows the field: with no live link the save is refused at that field. Linking and unlinking themselves are never refused for requiredness (unlinking the last link is allowed; the next save asks for one). Creating a record and imports never enforce it (P118).

### 9. The picker is a page of its own

`/projects/{project}/tables/{table}/records/{record}/links/{field}`: the record's current links with "Unlink", a find box, and a paged list (50 per page, the other table's default order) of the other table's live records that are not linked yet, each with a "Link" button (or "Choose" when the field allows one link, which replaces the current link). Each button is an immediate, separate POST that returns to the same picker page, with the find text and page kept.

The plan proposed radio buttons or checkboxes with one Save. That loses every tick when the person moves to another page of candidates or uses the find box, because without script the paging and find links cannot carry unsaved ticks (a GET form would carry the CSRF token in the address). Immediate actions keep the page usable at any size, need no draft state, and each is idempotent ("make linked", "make unlinked"), so they need no version token: two people linking at the same time cannot overwrite each other. "Choose" on a one-link field is last-writer-wins, which is what the person asked for.

A candidate that the other side allows to be linked only once and that is already linked to another record is listed with "Linked to {title}" and no button. A one-link field whose current link is to a record in the trash cannot take another link until that record is restored and unlinked, or the trash is emptied; the picker says so.

### 10. Controls on the record form

- "Choose a different room", "Link a warranty", "Add a file", "Open", "Replace", and "Remove" are links to their own pages (picker, upload page, file page, confirmation). "Unlink" is a submit button that belongs, through the HTML `form` attribute, to one small form per relation field placed after the record form (forms cannot be nested).
- Unsaved edits in the record form are not carried to those pages (carrying them needs server-side drafts). The record form says so next to these controls: "Save your changes first: the buttons here leave this page."
- On a new record that has never been saved, the relation and file sections show what they will hold and the note "You can add links once this {item} is saved." (or "files"); the first Save returns to the same record, where they work.

### 11. Adding a new record from the picker

The picker's "Add a new {item}" link opens the other table's new-record form with a `from` parameter naming the field and record (`<field key>-<record id>`, validated by the core; never an address). Saving creates the new record and links it in one transaction, then returns to the picker. On a one-link field the new record replaces the current link; the link reads "Add a new room and choose it" so that is said before the press. (The plan proposed returning to the picker with the new record ticked; with immediate actions there is nothing to tick.)

### 12. Audit

`collection.saved` (created or changed; target the collection; detail: table key, rule count, match mode) and `collection.deleted` are added to the audit actions (amends ADR 0012 decision 4). Linking and unlinking are record edits and are not audited (ADR 0012 decision 4).

## Alternatives considered

| Topic | Option | Reason not chosen |
|---|---|---|
| Rules | Nested groups | Hard to show without script; rarely needed at household scale |
| Rules | SQL text or an expression language | Injection surface; not portable |
| Rules | Choice operands as labels | A rename silently breaks the rule |
| Rules | Error on a broken rule | A schema change would break pages |
| Rules | Broken rule matches nothing | Surprising under "any"; the plan's default ("ignored") is kept and the view says so |
| Ownership | Per-person collections | Changes the table and the archive rules; households share data |
| Names | Unique per table | The sidebar lists collections of every table by name |
| Picker | Ticks and one Save | Ticks are lost across pages and find without script (decision 9) |
| Picker | Inline select of all records on the record form | Unusable beyond a few hundred records; the M2 pick list stops at 500 |
| Picker | Save the record form when leaving for the picker | Nested forms are not allowed; would need drafts or script |
| New and link | Return with the new record ticked | No ticks in an immediate-action picker |
| Editor | Rule rows added by script only | Every screen must work without script |

## Consequences

- Linking many records means one press per record. Household-sized lists make this acceptable; a "link all shown" action can be added later without a format change.
- A rule set is limited to 10 flat rules.
- Sidebar counts run one count query per collection on list pages (at most 50).

## Costly to reverse

The `_tiffin_collections` table, the rule JSON, the operator identifiers, and the collection address shape, once projects and archives hold them. The picker behaviour is cheap to change.
