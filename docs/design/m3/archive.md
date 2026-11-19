# M3 screen design: import a project archive

Status: design for milestone M3 (no mock-up; ADR 0017). Logic: `docs/specs/M3-io.md` sections 11 and 13.4. Implemented by M3-WP10. Strings: `strings/en/archive.php` (WP10). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Import a project archive | `GET /projects/import`; `POST` the same URL | 200; 422; 303 to the result |
| Name already taken | Response to the POST when the name is in use; `POST /projects/import/{job}` with a new name | 422 |
| Result | `GET /projects/{p}` is not used; the result is shown by redirecting to `/projects/{new key}/tables` with a flash notice and, when anything was changed, a report notice | 303 |

Shell: signed-in shell, breadcrumb (`projects.crumb` "Projects" to `/projects`, `archive.crumb` "Import a project archive"), `main#main.page.page-io`. Reached from the project list ("Import a project archive", Admins only). Members get the 403 page.

## 2. Form

Page title: `archive.title` "Import a project archive".

1. Breadcrumb; error summary at 422.
2. `h1` `archive.title`.
3. `p.lead` `archive.lead` "Make a new project from an archive downloaded from TiffinDB's Export page. Your other projects are not changed."
4. `form` (`method="post"`, `enctype="multipart/form-data"`): `csrf`; `file-field` name `file`, label `archive.file` "Archive file", help `archive.file_help` "A .zip file made by TiffinDB, up to {max}.", `accept` `.zip`; `div.field`: `label` `archive.name` "Name for the new project" (with `span.label-optional` "(optional)", M1 marker), `p.help` `archive.name_help` "Leave empty to use the name stored in the archive.", `input.input` `name="name"` `id('f', 'name')` `maxlength` as the M1 project-name limit.
5. `div.io-actions`: `button.button.button-primary.button-large` `archive.import` "Import"; `a.button.button-large` `archive.cancel` "Cancel" to `/projects`.
6. `p.help` `archive.note` "TiffinDB checks the archive before using it. The new project is shared with nobody until you share it."

422 problems at the file field: `problem.upload.*` and every `problem.archive.*` (part 1 section 14).

## 3. Name already taken

The same page, 422, with the file field replaced by `p` `archive.checked` "The archive {file} has been checked." (the uploaded name, escaped), and the name field showing the name with the problem `problem.name.taken`; the form posts to `/projects/import/{job}` (the checked archive is kept for one hour). Buttons as above.

## 4. Result

303 to `/projects/{new key}/tables` with flash `archive.notice.done` "The project {name} has been made from the archive." When the rebuild changed anything, a second paragraph in the notice: `archive.notice.changes` "Some things were changed to fit TiffinDB's rules: {list}." where `{list}` joins, in this order and only when non-zero, `archive.change.values` "{count|# value|# values} left empty", `archive.change.links` "{count|# link|# links} dropped", `archive.change.forms_dropped` "{count|# form|# forms} replaced by the standard layout", `archive.change.forms_extended` "{count|# form|# forms} given missing fields", `archive.change.files` "{count|# file|# files} missing". The same counts are kept in the new project's import log (`docs/formats/tiffin.md` section 4.7). (A flash carries strings and integers only, M1; the controller builds the list text with `t()` and passes it as one string parameter.)
