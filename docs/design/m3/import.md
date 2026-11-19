# M3 screen design: importing a file into a table

Status: design for milestone M3. Step 2 follows `docs/design/model-ui/import.html`; steps 1, 3, and 4, the reading page, the reading options, and the failure page have no mock-up. Logic: `docs/specs/M3-io.md` sections 5 and 6. Implemented by M3-WP9 with the components of M3-WP3. Strings: `strings/en/import.php` (WP9) unless marked shared (`io.php`, WP3). Example data is synthetic.

## 1. Overview

| # | Screen | URL | Status |
|---|---|---|---|
| 2 | Step 1, choose a file | `GET /projects/{p}/import`; `POST` the same URL to upload | 200; 422 |
| 3 | Reading the file (progress) | `GET /projects/{p}/import/{job}` while reading; `POST .../continue` | 200; 303 |
| 4 | Step 2, check the fields (mock-up) | `GET /projects/{p}/import/{job}` in review; `POST` the same URL to accept | 200; 422 |
| 5 | Reading options | `GET`/`POST /projects/{p}/import/{job}/reading` | 200; 422; 303 |
| 6 | Step 3, import (progress) | `GET /projects/{p}/import/{job}` while preparing or ready; `POST .../continue` | 200; 303 |
| 7 | Step 4, report | `GET /projects/{p}/import/{job}/report` | 200 |
| 8 | Import failed | `GET /projects/{p}/import/{job}` when failed | 200 |
| - | Start again | `POST /projects/{p}/import/{job}/cancel` | 303 to step 1 |

Shell: signed-in shell, no sidebar, breadcrumb (project name, then `import.crumb` "Import"), `main#main.page.page-io`. Page `h1` on every step: `import.title` "Import a file". Steps partial (shared `steps.import.label` "Import steps") on screens 2, 4, 6, 7 with `steps.import.choose` "Choose a file", `steps.import.check` "Check the fields", `steps.import.import` "Import", `steps.import.report` "Read the report"; the visible prefix is `steps.done` "Step {n}, done", `steps.now` "Step {n}, now", or `steps.next` "Step {n}" (shared). Reading and the reading options sit under step 1 (step 1 "now"); preparing and saving under step 3.

## 2. Step 1: choose a file

Page title: `import.title`.

1. Breadcrumb; flash; error summary at 422.
2. `h1` "Import a file". Steps: 1 now.
3. `p.lead` `import.start.lead` "Bring rows from a spreadsheet or CSV file into this project. Nothing is saved until you have checked the fields."
4. `form` (`method="post"`, `enctype="multipart/form-data"`, `action` this URL): `csrf`;
   - `file-field` (shared): name `file`, label `import.start.file` "File", help `import.start.file_help` "CSV or Excel workbook (.xlsx), up to {max}." (`{max}` from `Format::fileSize` of the effective limit); `accept` = the extensions of enabled import plugins with a leading dot.
   - When more than one enabled import plugin exists: `fieldset.panel` with `legend` `import.start.format` "Read the file as": radio `import.start.format_auto` "Decide from the file name" (checked by default), then one radio per enabled import plugin with the plugin's name (plain text) and, in `span.help`, its extensions (".csv, .txt"). Name `format`, values `auto` or `<kind>/<id>`.
   - `div.io-actions`: `button.button.button-primary.button-large` `import.start.continue` "Continue"; `a.button.button-large` `import.start.cancel` "Cancel" to the project (`/projects/{p}`).
5. `p.help` `import.start.note` "Files stay on this server and are removed when the import is finished, or after a day."

422 problems at the file field: `problem.upload.*`, `problem.import.format_unknown` (with the format radios shown even when there would otherwise be one plugin), `problem.import.unreadable` (`{format}` the plugin name), `problem.import.unsupported`, `problem.import.too_many_columns`, `problem.import.failed`.

After a successful upload: 303 to `.../import/{job}` (screen 3 or, when the whole file was read in the upload request, screen 4).

No JavaScript. Keyboard: Tab to the file input, Enter or Space opens the browser's chooser. Screen reader: label, help with the size limit, and any error are announced with the input.

## 3. Reading the file (progress)

Shown only when the file has more rows than one chunk (2,000).

1. Breadcrumb; `h1`; steps (1 now).
2. `div.progress` (shared): label `import.reading.label` "Reading {file}" (`{file}` the uploaded name, escaped); `progress` with `value` = rows read and no `max` (the total is unknown for CSV; for XLSX the sheet's row count when the library reports it); status `import.reading.status` "{count|# row|# rows} read so far."
3. `continue-form` (shared): action `.../continue`, button `import.continue` "Continue", `data-working` `import.working` "Working…", `auto` true.
4. `p.help` `import.reading.help` "Large files are read in parts. Without JavaScript, choose Continue after each part."
5. `form` POST `.../cancel` with `button.button` `import.start_again` "Start again".

With the script (`import-continue.js`), the form submits itself 400 ms after the page loads; without it, the person presses Continue. When reading finishes, the redirect goes to screen 4.

## 4. Step 2: check the fields (mock-up)

Page title: `import.review.title` "Check the fields". In reading order, as `import.html`:

1. Breadcrumb; flash; error summary at 422 (problems link to `id('f', 'table_name')`, `id('f', 'table')`, or the column's control `id('f', 'name', <n>)` / `id('f', 'field', <n>)`).
2. `h1` "Import a file". Steps: 1 done, 2 now.
3. `facts` (shared) `import.review.found` "What was found" (`id` `i-file`): `import.review.file` "File" = the uploaded name; `import.review.read_as` "Read as" = the plugin's `readAs` text (plain); `import.review.rows` "Rows" = `import.review.rows_heading` "{count|# row|# rows}, plus a heading row" or `import.review.rows_plain` "{count|# row|# rows}" when there is no heading row; `import.review.columns` "Columns" = the count. Then `a` `import.review.change_reading` "Change how the file is read" to `.../reading` (44 px tall, as the mock-up).
4. `form#import-review` (`method="post"`, `action` this URL): `csrf`;
   1. `fieldset.panel` `legend` `import.review.target` "Where should the rows go?":
      - `div.choice-inline`: `label.check` radio `target=new` (checked by default) `import.review.target_new` "A new table called", then `input.input` `type="text"` `name="table_name"` `id('f', 'table_name')` `aria-label` `import.review.table_name_label` "Name of the new table" `maxlength="60"`, value the proposed name.
      - `div.choice-inline` (only when the project has tables): radio `target=existing` `import.review.target_existing` "Add to an existing table", then `select` `name="table"` `id('f', 'table')` `aria-label` `import.review.table_label` "Existing table" with the project's tables.
      - Choosing the other radio does not change the table below until the page is sent: `p.help` `import.review.target_help` "If you switch to an existing table, choose Check again to match the columns to its fields." and `button.button` `name="action" value="recheck"` `import.review.recheck` "Check again" (both shown only when the project has tables). The page is rebuilt for the chosen target; nothing is imported.
   2. `section` (`aria-labelledby="i-fields"`): `h2#i-fields` `import.review.fields` "Fields"; `p.help` `import.review.fields_help` "TiffinDB has guessed a name and a type for each column. Change anything that looks wrong." Then `div.table-scroll` > `table.mapping-table` (partial `import/mapping-table`), `caption.visually-hidden` `import.review.caption` "Columns in the file and the fields they become":
      - New-table target, header row: `import.col.include` "Bring in", `import.col.column` "Column in file", `import.col.name` "Field name", `import.col.type` "Type", `import.col.first` "First values".
      - Per column: `td` checkbox `name="include[<n>]" value="1"` `aria-label` `import.review.include_label` "Bring in {column}" (checked as proposed); `th scope="row"` the heading (plain; `.mapping-left-out` when unticked); `td` `input.input` `name="name[<n>]"` `id('f', 'name', n)` `aria-label` `import.review.name_label` "Field name for {column}" `maxlength="60"`; `td` `select` `name="type[<n>]"` `aria-label` `import.review.type_label` "Type for {column}" with the type menu: type names `import.type.<id>` ("Text", "Long text", "Number", "Currency", "Date", "Time", "Date and time", "Duration", "Checkbox", "Choice", "Rating", "Web address", "Email", "Phone"), and `import.type.link` "Link to {table}" for each link choice (value `link:<table key>`); `td.mapping-first` the first values joined by "; " (escaped, each cut to 60 characters with "…"), with `import.review.left_out` " (left out)" appended when the column is unticked by default (mock-up "1; 2 (left out)").
      - Existing-table target, header row: "Bring in", "Column in file", `import.col.field` "Goes into", "First values". The "Goes into" cell: `select` `name="field[<n>]"` `id('f', 'field', n)` `aria-label` `import.review.field_label` "Field for {column}" with `import.review.field_none` "Leave out" first, then the table's importable fields (name, and in brackets the type name, "Room (Link to Rooms)").
   3. Warnings, when any: `warning-panel` (shared) heading `import.warning.heading` "{count|# thing|# things} to know before importing", items in this order:
      - `import.warning.unreadable` "{count|# value|# values} in \"{column}\" could not be read as {type}. They will be left empty and listed in the report." (one per column)
      - `import.warning.not_a_choice` "{count|# value|# values} in \"{column}\" are not choices of {field}. They will be left empty and listed in the report."
      - `import.warning.links_added` "{count|# name|# names} in \"{column}\" are not in the {table} table yet. They will be added as new {items}." (`{items}` the target table's name in lower case)
      - `import.warning.extra_cells` "{count|# row has|# rows have} more values than there are columns. The extra values will be left out."
      - `import.warning.replaced` "{count|# character|# characters} could not be read and will appear as \"�\"."
      - `import.warning.over_limit` "The file has more than {max} rows. Only the first {max} will be imported."
      - `import.warning.partial` "These counts are for the first {count} rows. The report will list every value." (last, when the job has more than 5,000 rows)
5. `div.io-actions` (after the form, so the cancel form is not nested): `button.button.button-primary.button-large` `type="submit"` `form="import-review"` `name="action" value="import"` `import.review.import` "Import {count|# row|# rows}"; `form.inline-form` POST `.../cancel` with `csrf` and `button.button.button-large` `import.start_again` "Start again"; `p.io-note` `import.review.note` "Nothing is saved until you choose Import. The rows are saved all together, or not at all."

Implicit submission: pressing Enter in a text input of the form submits it with its first submit button in tree order: "Check again" when the project has tables (it sits in the target fieldset), otherwise "Import". Either is safe: "Import" only checks the mapping and starts preparing; the rows are saved only when the person presses "Save the rows" on step 3, which never submits itself (section 6).

On "Import": `saveMapping`; problems give 422 with the summary; otherwise 303 to `.../import/{job}` (screen 6), whose first chunk starts. On "Check again": the mapping is saved as given without validation of names (only the target changes), and the page is drawn again (303).

Keyboard: the mapping table is a sequence of native controls in row order; Tab moves across a row then to the next. The scrolling box needs no focus of its own. Screen reader: every control names its column; the warning panel is a region with a heading.

Narrow layout: the facts list stacks; the table scrolls inside its box (its minimum width is 860 as in the mock-up); the actions row wraps.

## 5. Reading options

Page title: `import.reading_options.title` "How the file is read".

1. Breadcrumb; error summary at 422.
2. `h1` `import.reading_options.title`. Steps: 1 now.
3. `p.lead` `import.reading_options.lead` "Change these if the columns or characters on the last page looked wrong. The file is read again."
4. `form` POST `.../reading`: `csrf`; for each plugin setting, in order:
   - kind `choice`: `fieldset.field-group` `legend.legend-plain` the label (plain), help when any, radios (each `label.check`) with the choice labels; name `setting[<name>]`.
   - kind `boolean`: `label.check` checkbox `name="setting[<name>]" value="1"` with the label; help after it.
   - For XLSX, the `sheet` setting lists sheets; hidden sheets are labelled `import.reading_options.hidden_sheet` "{name} (hidden)".
5. `div.io-actions`: `button.button.button-primary.button-large` `import.reading_options.apply` "Read the file again"; `a.button.button-large` `import.reading_options.back` "Back" to `.../import/{job}`.

On success: the job returns to reading (303 to `.../import/{job}`); the mapping is rebuilt from the new reading.

## 6. Step 3: import (progress)

1. Breadcrumb; `h1`; steps (1 and 2 done, 3 now).
2. While preparing: `progress` label `import.preparing.label` "Checking the rows", `max` = rows, `value` = prepared; status `import.preparing.status` "{done} of {total|# row|# rows} checked." When ready: label `import.saving.label` "Saving the rows", `value` = `max`; status `import.saving.status` "Everything is checked. Choose Save the rows to save them all together."
3. `continue-form` with `import.continue` "Continue" (`auto` true) while preparing; when ready, the button reads `import.save` "Save the rows" with `auto` **false**: saving always waits for a deliberate press.
4. `p.help` `import.preparing.help` "Nothing has been saved yet. If you leave now, the project stays as it was."
5. "Start again" form (as screen 3), shown only while preparing.

When the target table changed meanwhile, the save step redirects to screen 4 with the notice `import.notice.target_changed` (= `problem.import.target_changed` text) in the error summary. A busy database (another save at the same moment) shows the M1 busy page; going back to the import address offers "Save the rows" again.

## 7. Step 4: report

Page title: `import.report.title` "Import report".

1. Breadcrumb; flash `import.notice.done` "{count|# row was|# rows were} imported into {table}." (`role="status"`).
2. `h1` "Import a file". Steps: 1 to 3 done, 4 now.
3. `facts` `import.report.summary` "What happened": `import.report.file` "File" (name); `import.report.table` "Table" = the table's name (a link to its list) and, when the import created it, `import.report.new_table` " (new table)"; `import.report.imported` "Records added" = count; `import.report.skipped` "Rows left out" = count, with `import.report.skipped_empty` "{count|# empty row|# empty rows}" and `import.report.skipped_limit` "rows over the {max}-row limit" as a short list when non-zero; `import.report.emptied` "Values left empty" = count; `import.report.links` "Linked records added" = count (only when non-zero); `import.report.snapshot` "Copy taken before" = `import.report.snapshot_text` "A copy of the project was saved first ({name})."
4. When values were left empty: `section.panel` `h2` `import.report.columns` "Values left empty": `table.report-table` with `caption.visually-hidden`, columns `import.report.col_field` "Field", `import.report.col_count` "Values", `import.report.col_rows` "Rows"; the rows cell lists the first 20 row numbers joined by ", " and `import.report.more_rows` "and {count} more" when there are more; `p.help` `import.report.rows_help` "Row 1 is the first row after the heading row."
5. When required fields were left empty: `p` `import.report.required` "{count|# record has|# records have} no value in the required field {field}. They were imported anyway; fill them in when you open the records."
6. When link records were added: `p` per relation `import.report.links_added` "{count|# record was|# records were} added to {table}."
7. `div.io-actions`: `a.button.button-primary.button-large` `import.report.open` "Open the table" to its list; `a.button.button-large` `import.report.another` "Import another file" to step 1.

The report is read from the project's import log, so it can be opened again later at the same address while the project exists. A table deleted since then is named `import.report.table_gone` "a table that has since been deleted", without a link.

## 8. Import failed

Page title: `import.failed.title` "The import did not finish".

1. Breadcrumb; `h1` `import.failed.title`.
2. `p` `import.failed.body` "Nothing was saved. The project is exactly as it was before." Then the reason: `import.failed.commit` "Saving the rows went wrong." or `import.failed.read` "The file could not be read to the end." (`problem.import.*` text when a plugin gave a reason).
3. `form` POST `.../cancel`: `button.button.button-primary.button-large` `import.failed.again` "Start again".

## 9. Notices and errors summary

| Situation | Response |
|---|---|
| Member with a grant opens any import address | 403 page (M1) |
| Member without a grant, unknown project, unknown or someone else's job | 404 page |
| Upload larger than PHP accepts | 413 page "That file is too large" (`docs/specs/M3-screens.md` section 2.3) |
| Job removed by the 24-hour clean-up | 404 page; step 1 is reached from the sidebar |
