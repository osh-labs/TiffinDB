# M7 screen design: delete a project

Status: design for milestone M7 part 1 (no mock-up; P49, P170). Logic: `docs/specs/M7-release.md` section 6; ADR 0022 decision 3. Implemented by M7-WP4. Strings: `strings/en/projects.php` (WP4). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Delete a project | `GET /projects/{p}/delete` | 200 (Admins); 403 (Members) |
| Delete | `POST /projects/{p}/delete` with `confirm_name`, `expected` | 303 to `/projects`; 422; 200 when the counts grew; 503 when busy |

Shell: signed-in shell, breadcrumb (project name to `/projects/{p}`, `projects.delete.crumb` "Delete"), layout `record-narrow`. Reached from Project settings, "Delete this project".

## 2. The page

Page title and `h1`: `projects.delete.title` "Delete {project}?".

1. Breadcrumb; error summary after a refusal.
2. `p.lead` `projects.delete.lead` "This deletes the project for good. It cannot be undone in TiffinDB."
3. `dl.facts` (as built): `projects.delete.records` "Records" with `{count|# record|# records}`; `projects.delete.trashed` "In the trash" with `{count|# record|# records}`; `projects.delete.files` "Stored files" with `projects.delete.files_value` "{count|# file|# files}, {size}"; `projects.delete.snapshots` "Snapshots" with `{count|# snapshot|# snapshots}`; `projects.delete.members` "Shared with" with `{count|# Member|# Members}`.
4. `p` `projects.delete.body` "Its records, tables, forms, collections, stored files, and snapshots are removed from the server. The people it was shared with lose access. The activity log keeps a line saying it was deleted."
When the project's folder is missing from the data folder (`folderMissing`, P239): instead of items 3 to 5, `div.notice` (warning) `projects.delete.folder_missing` "This project's folder is not in the data folder, so there is nothing to remove from the server. Deleting removes the project from the list and from everyone's access." The form below is unchanged.
5. `div.notice` (information) `projects.delete.archive` "Keep a copy first: download a project archive from the export page. A project archive can be imported again later." with `a` `projects.delete.archive_link` "Go to Export" to `/projects/{p}/export`.
6. `div.danger-zone` > `form` POST (`csrf`, hidden `expected` = records plus trashed as shown):
   - `div.field`: `label` for `confirm-name` `projects.delete.name_label` "Type the project's name to confirm"; `p.help#confirm-name-help` `projects.delete.name_help` "Type {project}."; after a refusal `p.error-text#confirm-name-error` with `problem.project.delete_name`; `input.input#confirm-name` `type="text"` `name="confirm_name"` `autocomplete="off"` `spellcheck="false"` `autocapitalize="off"` `aria-describedby` (help, and the error when present), the typed value kept after a refusal.
   - `div.form-actions`: `button.button.button-danger.button-large` `projects.delete.submit` "Delete for good"; `a.button.button-large` `form.cancel` to Project settings.

The name check is in the core; the button is never disabled (no script), and an empty or wrong name gives 422.

## 3. States and results

| State | Shown |
|---|---|
| Wrong name | 422: the same page, error summary linking to the field, the field's error, the typed text kept |
| More records than the page showed | 200: the page with the new counts and `div.notice` (warning) `projects.delete.changed` "The project changed while you were looking. Check the new numbers." (P82 pattern) |
| Locks not available on this server, or the folder could not be moved | 422: the summary with `problem.lock.unavailable` or `problem.project.delete_failed`; nothing was deleted |
| Project busy | 503 busy page (as built); nothing was deleted |
| Deleted | 303 to `/projects`; flash `notice.project_deleted` "{name} has been deleted." |

## 4. Keyboard and screen reader

Tab order: breadcrumb, "Go to Export", the name field, "Delete for good", "Cancel". The field's label and help are read together; the error is tied to the field. The heading names the project, so a screen reader announces what will be deleted first. No script.

## 5. Narrow layout

Facts stack (as built); the field is full width; buttons stack at 320 px Largest. No sideways scroll.
