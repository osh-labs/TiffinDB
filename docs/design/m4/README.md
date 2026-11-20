# M4 screen designs

Status: designs for milestone M4 (M4-D1), provisional until reviewed (M4-R1). They say how the relation and file controls of the record mock-up (`docs/design/model-ui/record.html`) and the collection editor mock-up (`docs/design/model-ui/collection.html`) are built in the application, and design the M4 screens that have no mock-up (ui-design-plan section 6.2: relation picker; attachment upload and image view; trash and restore).

| Document | Screens |
|---|---|
| `components.md` | Stylesheet classes added to `app.css` for M4, the sidebar changes, and the shared partials (relation control, linked-record rows, file rows, leave note) |
| `record-controls.md` | Relation and file controls on the record form (mock-up), images, the new-record notes, required fields, the notices after link and file actions |
| `picker.md` | Relation picker: choose one, link many, find, pages, add a new record and link it |
| `files.md` | Upload (add and replace), file page (image view), remove confirmation, serving |
| `collections.md` | Collection editor (mock-up), collection view, delete confirmation, broken rules |
| `trash.md` | Trash screen, one table's trash, empty-the-trash confirmation |

How to read them: as `docs/design/m1/README.md` to `docs/design/m3/README.md`. Every screen lists its elements in reading order, every string as a key with its English text, its states, keyboard and screen-reader behaviour, behaviour without JavaScript, and the narrow layout. The web-layer rules are in `docs/specs/M4-screens.md`; the core behaviour behind each action is in `docs/specs/M4-core.md`; the decisions are ADR 0018 (attachments) and ADR 0019 (collections, relation editing).

Conventions: `{p}`, `{t}`, `{r}` are a project key, a table key, a record id; `{f}` a field key; `{a}` an attachment id; `{c}` a collection key. `<state>` is the list view state (M2), which from M4 may also hold `collection`. "Item"/"item" stands for a table's `item_name`, "Items" for its name; "{other item}" for the item name of the table at the other end of a relation. Strings with a count use the plural placeholder (`{count|# record|# records}`).

Every screen here works without JavaScript. The only script is the optional `collection-editor.js` (Preview on field change). Every form that changes data carries the CSRF field; no form is nested; every POST answers with 303.

Permissions: everything here is open to Admins and to Members granted the project, except "Empty the trash" (Admins only) and the project settings totals (Admins, as M1). An ungranted person gets 404 everywhere.

No static wireframes are included, for the reason given in the M1 README. All example names and data are synthetic ("Home Inventory", "Stand mixer", "Example Member").
