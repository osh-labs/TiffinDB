# M3 screen design: export

Status: design for milestone M3 (ui-design-plan section 6.2, "Export"; no mock-up). Logic: `docs/specs/M3-io.md` sections 8 to 11 and 13.2. Implemented by M3-WP10. Strings: `strings/en/export.php` (WP10). Example data is synthetic. Corrected to the as-built screen at the M3 exit audit (M3-X); section 3 lists what changed.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Export | `GET /projects/{p}/export` | 200 |
| Download a file export | `POST /projects/{p}/export` | 200 with the file; 422 with the page |
| Download the project archive | `POST /projects/{p}/export/archive` | 200 with the zip |

Shell: signed-in shell, breadcrumb (project name, `export.crumb` "Export"), `main#main.page.page-io`. Reached from the sidebar's "Export" link (Admins). A Member with a grant gets the 403 page.

## 2. Export page

Page title: `export.title` "Export".

1. Breadcrumb; flash; error summary at 422.
2. `h1` `export.title`.
3. `p.lead` `export.lead` "Take a copy of this project's records out of TiffinDB. Exports leave the project unchanged."
4. `section.panel` (`aria-labelledby="x-archive"`): `h2#x-archive` `export.archive.heading` "The whole project, for TiffinDB"; `p` `export.archive.body` "A project archive holds every table, record, form, and attached file, including records in the trash. Use it as a backup, or to move the project to another TiffinDB installation."; (as built there is no size line: the planned `export.archive.size` "About {size}." was not built, see section 3); `form` POST `.../export/archive` with `csrf` and `button.button.button-primary.button-large` `export.archive.download` "Download the project archive".
5. `form#export-form` (`method="post"`, `action` `.../export`): `csrf`;
   1. `fieldset.panel` `legend` `export.format.legend` "Records, for other programs": one radio per enabled export plugin (name `format`, value `<kind>/<id>`), the label the plugin's name (plain) followed in `span.help` by `export.format.scope_table` "one table per file", `export.format.scope_both` "one table, or every table as separate sheets", or `export.format.scope_project` "the whole project in one file", from the manifest's scopes. The first plugin in the order CSV, Excel workbook, JSON, then others by name, is checked by default. After the radios, when both a CSV and an XLSX export plugin are enabled: `p.help` `export.format.spreadsheet_hint` "Opening the file in a spreadsheet program? Choose the Excel workbook: its text is never treated as a formula." (P135; the plugin names are not interpolated, so this string names the format in words). When no export plugin is enabled: `p.notice` `export.format.none` "No export format is turned on. An Admin can turn formats on under Plugins."
   2. `fieldset.panel` `legend` `export.scope.legend` "Which records": radio `scope=table` `export.scope.table` "One table:" followed by `select` `name="table"` `aria-label` `export.scope.table_label` "Table to export" (tables in order, with record counts in the option text: `export.scope.table_option` "{table} ({count|# record|# records})"); radio `scope=project` `export.scope.project` "Every table". `p.help` `export.scope.help` "Records in the trash are not included." A format that offers only one scope ignores the other choice (the server applies the format's scope; the help under each format says which).
   3. `fieldset.panel` `legend` `export.options.legend` "Options": the union of the enabled plugins' settings for each scope is not shown at once; instead each setting is shown with the format it belongs to: for each enabled plugin with settings, `div.field-group` with `p.label` the plugin's name and its settings (checkboxes and radio groups as in the import reading options; names `setting[<plugin ref>][<name>]`). Only the chosen format's settings are applied. For CSV: "Add a column with each record's number" and "Protect against spreadsheet formulas" (off by default, P135) with the help "Puts an apostrophe before text that starts with =, +, -, @, a tab, or a carriage return, so spreadsheet programs do not treat it as a formula. The apostrophe becomes part of the value." (label and help are the CSV plugin's own plain text from its `Setting`, not string keys, P133; quoted here so the reviewer sees them).
   4. `div.io-actions`: `button.button.button-primary.button-large` `export.download` "Download"; `p.io-note` `export.note` "The file is made now and downloaded straight away. It is not kept on the server."

Without JavaScript nothing changes: every option is visible; the server applies only those of the chosen format.

On download (as built): the file is made in a staging job folder and streamed in the response to the same POST; there is no separate download URL and no GET that serves a file. The response carries `Content-Disposition: attachment` with an ASCII `filename` fallback (each non-ASCII byte replaced by `_`) and a `filename*` UTF-8 name, and `Cache-Control: no-store`. After the body is sent the controller calls `ExportOperations::finish`, which checks that the job belongs to the actor and removes the folder (a stale export folder is swept after one hour); `ignore_user_abort` keeps the clean-up running when the browser disconnects. The browser saves the file (name from the plugin's rules, for example `items.csv`, `home-inventory.xlsx`, `home-inventory.json`, `home-inventory.tiffindb.zip`); the page stays as it was. An export that fails gives 422 with `problem.export.failed` in the summary. Tampered values (unknown format, a scope the format does not offer, an unknown table) give 422 with `problem.options.invalid` at the fieldset.

Keyboard: radios by arrow keys inside each fieldset; Tab to the next group; Enter on Download. Screen reader: each group has a legend; the download starts without a page change, so the button keeps focus.

Narrow layout: panels stack; the table select takes the full width under its radio.

## 3. As built (M3 exit audit)

- Export is streamed from the POST (section 2, "On download"); the design's earlier wording of a download URL is withdrawn. Owner check and removal are in `ExportOperations::finish` (P123; `docs/specs/M3-io.md` section 10.2).
- CSV export writes checkbox values as `yes` and `no` (P145; the core's own text is `1` and `0`), so a CSV export imports back as a checkbox column. XLSX writes booleans as boolean cells and JSON as `true` and `false`.
- The formula-protection help names "=, +, -, @, a tab, or a carriage return" (the six leading characters the CSV writer protects, P122).
- No project size is shown in the archive panel (`export.archive.size` was not built). Open gap for a later milestone; not needed for the exit criteria.
