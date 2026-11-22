# M4 screen design: trash and restore

Status: design for milestone M4 (ui-design-plan section 6.2, "Trash and restore"; no mock-up). Logic: `docs/specs/M4-core.md` sections 4.5 and 10.5; P2 (items stay until an Admin empties the trash), P168. Implemented by M4-WP8. Strings: `strings/en/trash.php` (WP8). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Trash | `GET /projects/{p}/trash` | 200 |
| One table's trash | `GET /projects/{p}/trash?table={t}&page={n}` | 200 |
| Restore one record | `POST /projects/{p}/trash/restore` with `table`, `record` (and `page`, `view` = `all` or `table`) | 303 back |
| Restore all in a table | `POST /projects/{p}/trash/restore-all` with `table` | 303 back |
| Empty confirmation | `GET /projects/{p}/trash/empty` | 200 (Admins); 403 (Members) |
| Empty the trash | `POST /projects/{p}/trash/empty` with `expected` | 303 to the trash; 200 when the counts changed |

Shell: signed-in shell, breadcrumb (project name to `/projects/{p}`, `trash.crumb` "Trash"), `main#main.page.page-trash`. Reached from the sidebar's "In the trash" link. Open to Admins and granted Members, except emptying (Admins). An unknown table key and an ungranted person: 404.

## 2. Trash

Page title and `h1`: `trash.title` "Trash".

1. Breadcrumb; flash.
2. `p.lead`: `trash.lead` "{count|# record is|# records are} in the trash. Records stay here until an Admin empties the trash. Restoring a record brings back its links and files." With nothing in the trash: the `empty-state` partial (M2) with `trash.empty_state` "The trash is empty." and a link to the project (`trash.back` "Back to {project}").
3. Per table with trashed records, in table order, `section.panel.trash-section` `aria-labelledby` = `id('trash', table key)`:
   - `div.trash-head`: `h2` with the table's name and `span.help` `trash.section.count` "{count|# record|# records}"; when the person may restore: `form.inline-form` POST `.../trash/restore-all` (`csrf`, hidden `table`) with `button.button.button-plain` `trash.restore_all` "Restore all in {table}".
   - `ul.trash-list`; per record (newest first, at most 50) `li.trash-row`: `span.trash-title` the title (or "{Item} {id}"; plain text, not a link: the record page of a trashed record only offers Restore) and `span.trash-meta` `trash.row.meta` "Moved to the trash {date} by {name}." (or `trash.row.meta_unknown` "Moved to the trash {date}." when the person is unknown; `{date}` from `Format::relativeDate`); `form.inline-form` POST `.../trash/restore` (`csrf`, hidden `table`, `record`, `view`, `page`) with `button.button.button-plain` `trash.restore` "Restore", `aria-label` `trash.restore_label` "Restore {title}".
   - When the table has more than 50: `a` `trash.section.more` "Show all {count} {items} in the trash" to `?table={t}`.
4. For Admins, when anything is in the trash: `div.danger-zone`: `p` `trash.empty.note` "Emptying the trash deletes these records and the files only they hold, for good."; `a.button.button-danger.button-large` `trash.empty.link` "Empty the trash" to the confirmation.

One table's trash (`?table={t}`): the same page with only that table's section, 50 per page, the `pager` partial (M2) keeping `table`, and `a` `trash.all_tables` "Show every table" above the section.

## 3. Results

| Action | Result |
|---|---|
| Restore | 303 back to the same view and page; flash `notice.record_restored` "{title} has been restored." (M2 key) |
| Restore all | 303 to the trash; flash `notice.trash_restored_all` "{count|# record|# records} in {table} {count|has|have} been restored." |
| Restore a record that is no longer in the trash | 303 back with no change and the same notice (M1 restore is a no-op for live records) |

## 4. Empty the trash (confirmation)

Admins only. Page title and `h1`: `trash.empty.title` "Empty the trash?". M2 confirmation pattern:

1. `p` `trash.empty.body` "{count|# record|# records} will be deleted for good. A copy of the project is kept as a snapshot first, but files are not part of snapshots."
2. `ul` with one `li` per table: `trash.empty.table` "{table}: {count|# record|# records}".
3. `p` `trash.empty.files` "{files|# file|# files} that only these records hold will be deleted." (absent when 0).
4. `form` POST `.../trash/empty` with `csrf` and hidden `expected` = the record count; `div.form-actions`: `button.button.button-danger.button-large` `trash.empty.submit` "Empty the trash"; `a.button.button-large` `form.cancel` to the trash.

Success: 303 to the trash with `notice.trash_emptied` "The trash has been emptied: {count|# record|# records} and {files|# file|# files} deleted." When more records are in the trash than the page showed (someone trashed more meanwhile), the confirmation is shown again with the new counts and `div.notice` `trash.empty.changed` "The trash changed while you were looking. Check the new numbers." (P82 pattern). With nothing in the trash: 303 to the trash with no notice.

A Member who opens the confirmation gets the 403 page; the trash screen never shows them the link.

## 5. Keyboard and screen reader

Tab order: breadcrumb, per section "Restore all in {table}" then each "Restore {title}", "Show all ...", pager, "Empty the trash". Each section is labelled by its heading. Notices use `role="status"`. No script.

## 6. Narrow layout

Sections stack; the section head wraps "Restore all" under the heading; each row wraps its Restore button under the title and meta line. No sideways scroll at 320 px Largest.
