# M1 screen designs

Status: designs for milestone M1, provisional until reviewed. They cover the four screen groups that had no mock-up (ui-design-plan section 6.2) and say how the two M1 mock-up screens are built in the application.

| Document | Screens |
|---|---|
| `components.md` | Stylesheet classes every M1 screen uses, with their measurements |
| `sign-in-and-projects.md` | Sign in and the project list (from the mock-ups); change your own password |
| `installer.md` | Setup: requirement check, data-folder safety test, first Admin |
| `people-and-sharing.md` | People, add a person, a person's page, stop someone signing in, sharing a project |
| `new-project.md` | New project |
| `schema-editor.md` | Project settings, tables, new table, table fields, delete table, add, change, retype, and delete a field, and the confirmation for clearing values |

How to read them:

- They extend the shell and tokens of the model UI (`docs/design/model-ui/`, `docs/design/tokens.css`) and the M0 application shell. They use only classes from `public/assets/css/app.css` as extended by `components.md`; no inline styles, no inline scripts, no network resources.
- Each screen lists its elements in reading order, with every string as a key and its English text, its states, keyboard and screen-reader behaviour, behaviour without JavaScript, and the 390 px layout.
- The web-layer rules they rely on (shells, partials, form conventions, string files, the visual check) are in `docs/specs/M1-screens.md`; the core behaviour behind each action is in `docs/specs/M1-foundation.md`.
- What "matches" means for screens with and without a mock-up is defined in `docs/specs/M1-screens.md` section 8.

No static wireframes are included: the element lists are precise enough to build from, and HTML wireframes would need either inline styles or a copy of the application stylesheet that could drift.

All example names and data are synthetic.
