# M1 screen design: people and per-project sharing

Status: design for milestone M1 (undesigned screens, ui-design-plan section 6.2). Logic: `docs/specs/M1-foundation.md` sections 6 and 9.3, 9.4. Implemented by M1-WP10. Every name in this document is synthetic.

## 1. Overview

| Screen | URL | Template |
|---|---|---|
| People | `GET /admin/people` | `templates/pages/people/index.php` |
| Add a person | `GET /admin/people/new`, `POST /admin/people` | `templates/pages/people/new.php` |
| Person | `GET /admin/people/{person}`; `POST` to `/admin/people/{person}`, `.../password`, `.../projects`, `.../reactivate` | `templates/pages/people/show.php` |
| Stop someone signing in | `GET`, `POST /admin/people/{person}/deactivate` | `templates/pages/people/deactivate.php` |
| Sharing | `GET`, `POST /projects/{project}/sharing` | `templates/pages/sharing.php` |

Who: Admins only. A Member who opens any of these gets the 403 page (the people screens) or the 404 page (sharing for a project not shared with them), decided by the core. Controllers call `AuthOperations::require(actor, people.manage)` (or `project.manage` for sharing) before rendering a form.

Shell: signed-in shell; `nav` = `people` for the people screens, `projects` for sharing. Strings: `strings/en/people.php` (WP10), including the sharing strings.

Role names everywhere: `role.admin` "Admin", `role.member` "Member".

## 2. People

`main#main.page`:

1. Flash notice when present.
2. `div.page-head`: `div` with `h1` `people.title` "People" and `p.lead` `people.lead` "Everyone who can sign in to this TiffinDB."; `div.page-actions` with `a.button.button-primary` to `/admin/people/new`: `people.add` "Add a person".
3. `div.table-wrap` > `table.data-table`:
   - `caption.visually-hidden`: `people.caption` "People who can sign in, and their roles".
   - `thead`: `people.col.name` "Name", `people.col.username` "Username", `people.col.role` "Role", `people.col.projects` "Projects", `people.col.status` "Signing in".
   - One row per person, ordered by name: `th scope="row"` with a link to `/admin/people/{key}` holding the display name, followed for the signed-in Admin by ` ` and `people.you` "(you)"; `td` username; `td` role name; `td` for Admins `people.projects.all` "Every project", for Members `count.projects.one` "1 project", `count.projects.many` "{count} projects", or `people.projects.none` "None"; `td` `people.status.active` "Can sign in" or `people.status.inactive` "Stopped".
4. `p.notice.notice-muted`: `people.admin_note` "There must always be at least one Admin who can sign in. Two Admins are safer: if one forgets their password, the other can set a temporary one."

States: there is always at least one person (the Admin viewing). Flash notices after actions on other screens: `notice.person_added`, `notice.person_saved`, `notice.password_set`, `notice.person_stopped`, `notice.person_restarted`, `notice.access_saved` (section 8).

390 px: the table scrolls inside its box; the head stacks.

## 3. Add a person

`main#main.page`:

1. Partial `breadcrumb`: `people.title` "People" (to `/admin/people`), then `people.new.title` "Add a person".
2. Error summary at 422.
3. `h1` `people.new.title` "Add a person".
4. `form.form.form-card.form-wide` `method="post"` `action` = `/admin/people`:
   1. Partial `csrf`.
   2. `div.field`: label for `person-name`: `people.field.name` "Name"; `p.help#person-name-help`: `people.field.name_help` "Shown to others, for example Example Member."; error; `input#person-name.input` `name="display_name"` `autocomplete="off"` `required="required"`.
   3. `div.field`: label for `person-username`: `people.field.username` "Username"; `p.help#person-username-help`: `people.field.username_help` "Used to sign in. Letters, numbers, dots, dashes, and underscores."; error; `input#person-username.input.input-medium` `name="username"` `autocomplete="off"` `autocapitalize="none"` `spellcheck="false"` `required="required"`.
   4. `fieldset` (no card; `div.field`-like spacing) with `legend.legend-plain` `people.field.role` "Role"; error `p.error-text#person-role-error`; `div.radio-grid` with two `label.radio-card`:
      - `input type="radio" name="role" value="member"` (checked by default); `span` > `span.radio-card-title` `role.member` "Member", `span.radio-card-hint` `people.role.member_hint` "Can view and change records in the projects shared with them."
      - `input type="radio" name="role" value="admin"`; title `role.admin` "Admin", hint `people.role.admin_hint` "Can also add people, create and share projects, and change tables and fields."
   5. `div.field`: label for `person-password`: `people.field.temporary` "Temporary password"; `p.help#person-password-help`: `people.field.temporary_help` "Give this to the person yourself. They will choose their own password the first time they sign in."; error; `input#person-password.input.input-medium` `type="text"` `name="temporary_password"` `autocomplete="off"` `spellcheck="false"` `required="required"`, value = a fresh `PeopleOperations::suggestPassword()` (or, at 422, the value submitted: it is the Admin's own temporary password, already on their screen).
   6. `fieldset` with `legend.legend-plain` `people.field.projects` "Projects they can use"; `p.help` `people.field.projects_help` "Admins can use every project, so this matters only for Members."; `ul.check-list` with one `li` > `label.check` > `input type="checkbox" name="projects[]" value="{project key}"` and the project name, per active project, by name; when there are none, `p` `people.field.no_projects` "There are no projects yet."
   7. `div.form-actions`: `button.button.button-primary.button-large` `people.new.submit` "Add person"; `a` to `/admin/people`: `form.cancel` "Cancel".

Field map: `display_name` to `person-name`, `username` to `person-username`, `role` to `person-role-error`'s group (the first radio has `id="person-role"` for the summary link), `temporary_password` to `person-password`.

Success: 303 to `/admin/people/{key}`, flash `notice.person_added` "{name} has been added. Give them their username and temporary password."

## 4. Person

`main#main.page`:

1. Partial `breadcrumb`: People, then the person's name.
2. Flash notice; error summary at 422 (only one form can fail at a time; the page is rendered again with that form's values).
3. `div`: `h1` the person's display name; `p.lead`: `people.show.lead` "{username}, {role}." followed, when inactive, by ` ` and `people.show.stopped` "Cannot sign in at the moment."
4. `form.form` `method="post"` `action` = `/admin/people/{key}`, containing `csrf` and `fieldset.fieldset-card` with `legend.legend` `people.details.title` "Details":
   - Name, Username, Role fields as in section 3 (ids `edit-name`, `edit-username`, `edit-role`), filled with the current values.
   - `div.form-actions`: `button.button.button-primary` `people.details.submit` "Save details".
5. Members only: `form.form` `method="post"` `action` = `/admin/people/{key}/projects` with `fieldset.fieldset-card`, `legend.legend` `people.access.title` "Projects they can use"; `ul.check-list` of every active project, checked when granted; `div.form-actions`: `button.button` `people.access.submit` "Save projects". Admins instead get `section.fieldset-card` with `h2.legend` (same title) and `p` `people.access.admin` "Admins can use every project."
6. `form.form` `method="post"` `action` = `/admin/people/{key}/password` with `fieldset.fieldset-card`, `legend.legend` `people.password.title` "Password":
   - `p` `people.password.intro` "If {name} has forgotten their password, set a temporary one. They will be signed out everywhere and will choose a new password when they next sign in."
   - When the person is the signed-in Admin: `p` `people.password.self` "This is your own account: you will be signed out too."
   - `div.field`: label for `reset-password` `people.field.temporary` "Temporary password"; help, error; `input#reset-password.input.input-medium` `type="text"` `name="temporary_password"` `autocomplete="off"`, value = a fresh suggestion.
   - `div.form-actions`: `button.button` `people.password.submit` "Set temporary password".
7. `section.fieldset-card` with `h2.legend` `people.signin.title` "Signing in":
   - Active: `p` `people.signin.active` "{name} can sign in."; then, unless the person is the signed-in Admin or the last active Admin, `a.button.button-danger` to `/admin/people/{key}/deactivate`: `people.signin.stop` "Stop {name} from signing in". For oneself: `p.help` `people.signin.self` "You cannot stop yourself from signing in."; for the last Admin: `p.help` `people.signin.last_admin` "{name} is the only Admin who can sign in, so they cannot be stopped."
   - Inactive: `p` `people.signin.inactive` "{name} cannot sign in. Their name stays on the changes they made."; `form.form` `method="post"` `action` = `/admin/people/{key}/reactivate` with `button.button` `people.signin.restart` "Let {name} sign in again".

One primary button ("Save details"). Field maps: details form `display_name` to `edit-name`, `username` to `edit-username`, `role` to `edit-role`; password form `temporary_password` to `reset-password`; core problems with a null field (last Admin, admin has all) go to the summary only.

Successes, each 303 back to this page with a flash: `notice.person_saved` "The details of {name} have been saved." (when the signed-in Admin demoted or renamed themselves and their session ended, 303 to `/sign-in` instead), `notice.access_saved` "The projects {name} can use have been saved.", `notice.password_set` "{name} now has a temporary password. They will choose a new one when they next sign in." (for oneself: 303 to `/sign-in`), `notice.person_restarted` "{name} can sign in again."

## 5. Stop someone from signing in (confirmation)

`main#main.page`:

1. Partial `breadcrumb`: People, the person's name (link), `people.stop.crumb` "Stop signing in".
2. `h1` `people.stop.title` "Stop {name} from signing in?"
3. `p` `people.stop.body` "{name} will be signed out now and will not be able to sign in. Nothing they made is deleted, and you can let them sign in again later."
4. `form.form` `method="post"` `action` = `/admin/people/{key}/deactivate`: `csrf`; `div.form-actions` with `button.button.button-danger.button-large` `people.stop.submit` "Stop {name} from signing in" and `a` to `/admin/people/{key}`: `form.cancel` "Cancel".

Success: 303 to `/admin/people/{key}` with `notice.person_stopped` "{name} can no longer sign in." A refusal (oneself, last Admin) renders the person page with the summary, status 422.

## 6. Sharing a project

| | |
|---|---|
| Purpose | Choose which Members can use one project |
| Who | Admins (`project.manage`) |
| URL | `GET`, `POST /projects/{project}/sharing` |

`main#main.page`:

1. Partial `breadcrumb`: `shell.nav.projects` "Projects" (to `/projects`), the project name (to `/projects/{key}/tables`), `sharing.crumb` "Sharing".
2. `div`: `h1` `sharing.title` "Share {project}"; `p.lead` `sharing.lead` "Admins can always use every project. Choose which Members can use this one. They will be able to view and change its records."
3. With at least one active Member: `form.form.form-wide` `method="post"` `action` = `/projects/{key}/sharing`:
   1. `csrf`.
   2. `fieldset.fieldset-card` with `legend.legend` `sharing.members` "Members"; `ul.check-list`, one `li` > `label.check` > `input type="checkbox" name="members[]" value="{person key}"` (checked when granted) and `span` > the display name and `span.check-hint` with the username, per active Member by name.
   3. When inactive Members hold a grant: `p.help` `sharing.inactive.one` "Also shared with 1 person who cannot sign in at the moment." or `sharing.inactive.many` "Also shared with {count} people who cannot sign in at the moment."
   4. `div.form-actions`: `button.button.button-primary.button-large` `sharing.submit` "Save sharing"; `a` to `/projects`: `form.cancel`.
4. With no active Members: `div.notice` with `p` `sharing.none` "There are no Members yet. Add people first, then share the project with them." and `a.button.button-primary` to `/admin/people/new`: `people.add` "Add a person".

Success: 303 to `/projects` with `notice.sharing_saved` "Sharing for {project} has been saved."

## 7. Behaviour common to these screens

- Keyboard: every control in reading order; radio groups are native (arrow keys move within a group); checkbox lists are plain checkboxes.
- Screen reader: each table has a caption and row headers; checkbox labels wrap their inputs; the destructive action is a separate confirmation page whose `h1` is the question; flash notices use `role="status"`.
- Without JavaScript: identical (no script on these pages).
- 390 px: forms span the width; radio cards stack (one per row, `minmax(250, 1fr)`); checkbox lists wrap long names; the people table scrolls inside its box.

## 8. Strings (`strings/en/people.php`)

| Key | Text |
|---|---|
| `role.admin` | Admin |
| `role.member` | Member |
| `people.title` | People |
| `people.lead` | Everyone who can sign in to this TiffinDB. |
| `people.add` | Add a person |
| `people.caption` | People who can sign in, and their roles |
| `people.col.name` | Name |
| `people.col.username` | Username |
| `people.col.role` | Role |
| `people.col.projects` | Projects |
| `people.col.status` | Signing in |
| `people.you` | (you) |
| `people.projects.all` | Every project |
| `people.projects.none` | None |
| `count.projects.one` | 1 project |
| `count.projects.many` | {count} projects |
| `people.status.active` | Can sign in |
| `people.status.inactive` | Stopped |
| `people.admin_note` | There must always be at least one Admin who can sign in. Two Admins are safer: if one forgets their password, the other can set a temporary one. |
| `people.new.title` | Add a person |
| `people.new.submit` | Add person |
| `people.field.name` | Name |
| `people.field.name_help` | Shown to others, for example Example Member. |
| `people.field.username` | Username |
| `people.field.username_help` | Used to sign in. Letters, numbers, dots, dashes, and underscores. |
| `people.field.role` | Role |
| `people.role.member_hint` | Can view and change records in the projects shared with them. |
| `people.role.admin_hint` | Can also add people, create and share projects, and change tables and fields. |
| `people.field.temporary` | Temporary password |
| `people.field.temporary_help` | Give this to the person yourself. They will choose their own password the first time they sign in. |
| `people.field.projects` | Projects they can use |
| `people.field.projects_help` | Admins can use every project, so this matters only for Members. |
| `people.field.no_projects` | There are no projects yet. |
| `people.show.lead` | {username}, {role}. |
| `people.show.stopped` | Cannot sign in at the moment. |
| `people.details.title` | Details |
| `people.details.submit` | Save details |
| `people.access.title` | Projects they can use |
| `people.access.submit` | Save projects |
| `people.access.admin` | Admins can use every project. |
| `people.password.title` | Password |
| `people.password.intro` | If {name} has forgotten their password, set a temporary one. They will be signed out everywhere and will choose a new password when they next sign in. |
| `people.password.self` | This is your own account: you will be signed out too. |
| `people.password.submit` | Set temporary password |
| `people.signin.title` | Signing in |
| `people.signin.active` | {name} can sign in. |
| `people.signin.stop` | Stop {name} from signing in |
| `people.signin.self` | You cannot stop yourself from signing in. |
| `people.signin.last_admin` | {name} is the only Admin who can sign in, so they cannot be stopped. |
| `people.signin.inactive` | {name} cannot sign in. Their name stays on the changes they made. |
| `people.signin.restart` | Let {name} sign in again |
| `people.stop.crumb` | Stop signing in |
| `people.stop.title` | Stop {name} from signing in? |
| `people.stop.body` | {name} will be signed out now and will not be able to sign in. Nothing they made is deleted, and you can let them sign in again later. |
| `people.stop.submit` | Stop {name} from signing in |
| `sharing.crumb` | Sharing |
| `sharing.title` | Share {project} |
| `sharing.lead` | Admins can always use every project. Choose which Members can use this one. They will be able to view and change its records. |
| `sharing.members` | Members |
| `sharing.inactive.one` | Also shared with 1 person who cannot sign in at the moment. |
| `sharing.inactive.many` | Also shared with {count} people who cannot sign in at the moment. |
| `sharing.submit` | Save sharing |
| `sharing.none` | There are no Members yet. Add people first, then share the project with them. |
| `notice.person_added` | {name} has been added. Give them their username and temporary password. |
| `notice.person_saved` | The details of {name} have been saved. |
| `notice.access_saved` | The projects {name} can use have been saved. |
| `notice.password_set` | {name} now has a temporary password. They will choose a new one when they next sign in. |
| `notice.person_stopped` | {name} can no longer sign in. |
| `notice.person_restarted` | {name} can sign in again. |
| `notice.sharing_saved` | Sharing for {project} has been saved. |
