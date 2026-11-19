# M2 screen designs

Status: designs for milestone M2, provisional until reviewed. They say how the three M2 mock-up screens (`docs/design/model-ui/list.html`, `record.html`, `settings.html`) are built in the application, and design the M2 screens that have no mock-up (ui-design-plan section 6.2: search results across tables, empty states, error pages, confirmation pages).

| Document | Screens |
|---|---|
| `components.md` | Stylesheet classes added to `app.css` for M2, with measurements taken from the mock-ups |
| `list-and-search.md` | List view (mock-up), filter page, search results across tables, and their empty states |
| `record.md` | New record, record form (mock-up), record in the trash, edit conflict, validation errors |
| `settings.md` | Reading and display settings (mock-up) with the live preview |
| `states.md` | Shared patterns: empty states, error pages, confirmation pages, the undo notice |

How to read them: as `docs/design/m1/README.md`. Every screen lists its elements in reading order, every string as a key with its English text, its states, keyboard and screen-reader behaviour, behaviour without JavaScript, and the narrow layout. The web-layer rules they rely on are in `docs/specs/M2-screens.md` (and `docs/specs/M1-screens.md`, which still applies); the core behaviour behind each action is in `docs/specs/M2-records.md`.

Conventions used below:

- `{p}`, `{t}`, `{r}` are a project key, a table key, and a record id. `<state>` is the list view state carried in query strings (`q`, `sort`, `filter`, `op`, `v`, `v2`, `page`; `docs/specs/M2-records.md` section 4).
- "Item" stands for the table's `item_name` ("item" in the examples; capitalised where it starts a sentence) and "Items" for the table's name.
- Strings with a count use the plural placeholder of `docs/specs/M2-screens.md` section 6.2: `{count|# record|# records}` prints "1 record" or "12 records".

No static wireframes are included, for the reason given in the M1 README. All example names and data are synthetic.
