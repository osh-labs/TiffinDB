# M1 screen design: new project

Status: design for milestone M1 (undesigned screen, ui-design-plan section 6.2). Logic: `docs/specs/M1-foundation.md` section 9.4.1. Implemented by M1-WP11.

| | |
|---|---|
| Purpose | Create a project, optionally with its first table |
| Who | Admins (`project.create`); a Member gets the 403 page (the controller calls `AuthOperations::require` before rendering) |
| URL | `GET /projects/new`; `POST /projects` |
| Shell | Signed-in shell, `nav` = `projects` |
| Template | `templates/pages/projects/new.php` |
| Strings | `strings/en/projects.php` |
| Page title | `projects.new.title` |

## 1. Elements

`main#main.page`:

1. Partial `breadcrumb`: `shell.nav.projects` "Projects" (to `/projects`), then `projects.new.title` "New project".
2. Error summary at 422.
3. `div`: `h1` `projects.new.title` "New project"; `p.lead` `projects.new.lead` "A project is one database. Add tables to it, then share it with the people who will use it."
4. `form.form.form-card.form-wide` `method="post"` `action` = `/projects`:
   1. Partial `csrf`.
   2. `div.field`: label for `project-name`: `projects.field.name` "Project name"; `p.help#project-name-help`: `projects.field.name_help` "For example Home Inventory or Recipes."; error `p.error-text#project-name-error`; `input#project-name.input` `name="name"` `autocomplete="off"` `required="required"` `aria-describedby="project-name-help"` (plus the error id).
   3. `div.field`: label for `project-first-table`: `projects.field.first_table` "First table" followed by `span.label-optional` `form.optional` "(optional)"; `p.help#project-first-table-help`: `projects.field.first_table_help` "For example Items. TiffinDB gives it a Name field to start with. Leave this empty to add tables later."; error; `input#project-first-table.input` `name="first_table"` `autocomplete="off"`.
   4. `div.form-actions`: `button.button.button-primary.button-large` `projects.new.submit` "Create project"; `a` to `/projects`: `form.cancel` "Cancel".

Field map: `name` to `project-name`, `first_table` to `project-first-table`.

## 2. States

| State | Status | What changes |
|---|---|---|
| Empty | 200 | As above |
| Errors (empty name, too long, name already used, first-table problems) | 422 | Summary, field errors, values kept |
| Success | 303 | To `/projects/{key}/tables`, flash `notice.project_created` "{name} has been created." or, with a first table, `notice.project_created_table` "{name} has been created, with the table {table}." |
| Member | 403 | Kernel page |

## 3. Behaviour

- Keyboard: Project name, First table, Create project, Cancel. Enter submits.
- Screen reader: the optional marker is part of the label text; help and errors are tied to the inputs.
- Without JavaScript: identical.
- 390 px: the card spans the width; the buttons wrap.

## 4. Strings (`strings/en/projects.php`, new-project part)

| Key | Text |
|---|---|
| `projects.new.title` | New project |
| `projects.new.lead` | A project is one database. Add tables to it, then share it with the people who will use it. |
| `projects.field.name` | Project name |
| `projects.field.name_help` | For example Home Inventory or Recipes. |
| `projects.field.first_table` | First table |
| `projects.field.first_table_help` | For example Items. TiffinDB gives it a Name field to start with. Leave this empty to add tables later. |
| `projects.new.submit` | Create project |
| `notice.project_created` | {name} has been created. |
| `notice.project_created_table` | {name} has been created, with the table {table}. |
