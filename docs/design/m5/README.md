# M5 screen designs

Status: designs for milestone M5 (M5-D1), provisional until reviewed (M5-R1). They design the M5 screens that have no mock-up (ui-design-plan section 6.2: simple form editor and source view), the upload and reset pages that go with them, and the record-screen changes (widths and links) that the editor's output needs.

| Document | Screens |
|---|---|
| `components.md` | Stylesheet classes added to `app.css` for M5, the width layout of the record form, links in forms, the shared partials (form tools navigation, editor row, report), and the entry action on the Fields page |
| `editor.md` | Simple form editor: rows, labels, widths, moves, sections, hide and add, save, problems, focus |
| `source.md` | Source view (show, check, save, problems with lines), download, upload |
| `reset.md` | Back to the standard form (confirmation) |

How to read them: as `docs/design/m1/README.md` to `docs/design/m4/README.md`. Every screen lists its elements in reading order, every string as a key with its English text, its states, keyboard and screen-reader behaviour, behaviour without JavaScript, and the narrow layout. The web-layer rules are in `docs/specs/M5-screens.md`; the core behaviour behind each action is in `docs/specs/M5-forms.md`; the decisions are ADR 0020.

Conventions: `{p}`, `{t}` are a project key and a table key; `{path}` an editor row path (`3` or `3-2`, `docs/specs/M5-forms.md` section 6.1). "Items" stands for the table's name. Strings with a count use the plural placeholder (`{count|# field|# fields}`). Strings hold no `<` or `>` and no web address (`TemplateRulesTest`), so element names in strings are written as words.

Every screen here works without JavaScript; M5 adds no script. Every form that changes data carries the CSRF field; no form is nested; every POST answers with 303, except the editor's working-copy actions and the source view's Check, which answer 200 (or 422) with the page, as the collection editor does (P163).

Permissions: every screen here is for Admins only (`forms.edit`). A Member who opens one gets the 403 page; an ungranted person and an unknown table get 404. The record-screen changes (widths, links) are seen by everyone who may see the record.

Shell: signed-in shell; breadcrumb Project / Tables / Items / the page (`docs/specs/M5-screens.md` section 4). No sidebar.

No static wireframes are included, for the reason given in the M1 README. All example names and data are synthetic ("Home Inventory", "Items", "Stand mixer", "Example Admin", `https://example.com/manual`).
