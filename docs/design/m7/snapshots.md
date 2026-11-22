# M7 screen design: snapshots and restore

Status: design for milestone M7 part 1 (no mock-up; P49, P170). Logic: `docs/specs/M7-release.md` sections 4 and 5; ADR 0022 decisions 1 and 2. Implemented by M7-WP4. Strings: `strings/en/snapshots.php` (WP4). Example data is synthetic.

## 1. Overview

| Screen | URL | Status |
|---|---|---|
| Snapshots | `GET /projects/{p}/snapshots` | 200 (Admins); 403 (Members) |
| Take a snapshot | `POST /projects/{p}/snapshots` | 303 to the list |
| Restore confirmation | `GET /projects/{p}/snapshots/{s}/restore` | 200; 422 when the snapshot cannot be used |
| Restore | `POST /projects/{p}/snapshots/{s}/restore` | 303 to the list; 422; 503 when the project is busy |

Shell: signed-in shell, breadcrumb (project name to `/projects/{p}`, `snapshots.crumb` "Snapshots"; on the confirmation also `snapshots.restore.crumb` "Restore"), layout `settings` (list) and `record-narrow` (confirmation). Reached from Project settings, "Snapshots".

## 2. Snapshots

Page title and `h1`: `snapshots.title` "Snapshots".

1. Breadcrumb; flash.
2. `p.lead` `snapshots.lead` "A snapshot is a saved copy of this project's records, tables, forms, and collections. TiffinDB saves one before every risky change and keeps the 20 newest of those. Snapshots you take yourself are kept until someone removes them from the data folder."
3. `p.help` `snapshots.files_note` "Snapshots do not hold the stored files. Files that a snapshot names but that were removed since will be missing after a restore."
4. `form` POST `/projects/{p}/snapshots` (`csrf`) with `button.button.button-primary` `snapshots.take` "Take a snapshot now".
5. With no snapshots: `empty-state` with `snapshots.empty` "No snapshots yet."
6. Otherwise `div.table-scroll` > `table.data-table.snapshot-table` with `caption.visually-hidden` `snapshots.table.caption` "Snapshots, newest first":
   - Head: `snapshots.col.when` "Saved", `snapshots.col.reason` "Why", `snapshots.col.size` "Size", `snapshots.col.action` (visually hidden) "Action".
   - One row per snapshot, newest first: the time (`Format::dateTime`, with `time datetime`), the reason in words (`snapshots.reason.<reason>`, below), the size (formatted as the project settings totals format sizes, as built), and `a` `snapshots.restore_link` "Restore…" with `aria-label` `snapshots.restore_label` "Restore the snapshot saved {when}" to the confirmation.
7. When locks are not available on this server (`canRestore` false): `notice` (warning) `snapshots.no_lock` "This server cannot lock files, so restoring in TiffinDB is turned off here. The host documentation describes restoring by hand." and the Restore links are not drawn.

Reason words:

| Key | English |
|---|---|
| `snapshots.reason.format-upgrade` | Before an upgrade of TiffinDB |
| `snapshots.reason.table-delete` | Before a table was deleted |
| `snapshots.reason.field-delete` | Before a field was deleted |
| `snapshots.reason.field-type-change` | Before a field's type was changed |
| `snapshots.reason.field-options` | Before a field's options were changed |
| `snapshots.reason.empty-trash` | Before the trash was emptied |
| `snapshots.reason.import` | Before rows were imported |
| `snapshots.reason.manual` | Taken by an Admin |
| `snapshots.reason.pre-restore` | Before a restore |

Take: 303 back with flash `notice.snapshot_taken` "A snapshot was saved: {when}."

## 3. Restore confirmation

Page title and `h1`: `snapshots.restore.title` "Restore this snapshot?".

1. Breadcrumb.
2. `dl.facts` (as built): `snapshots.restore.saved` "Saved" with the time; `snapshots.restore.reason` "Why" with the reason words; `snapshots.restore.records` "Records" with `snapshots.restore.records_value` "{then|# record|# records} in the snapshot, {now|# record|# records} now".
3. `p` `snapshots.restore.body` "{project} will go back to how it was when this snapshot was saved. Everything changed since then will be undone: records, tables and fields, forms, collections, and the trash. TiffinDB first saves a snapshot of the project as it is now, so you can come back to it."
4. When `filesRemoved` is over 0: `p` `snapshots.restore.files_removed` "{count|# stored file was|# stored files were} added after this snapshot and will be deleted."
5. When `filesMissing` is over 0: `p` `snapshots.restore.files_missing` "{count|# file this snapshot names is|# files this snapshot names are} no longer stored and will show as missing."
6. When the snapshot's format version is older: `p.help` `snapshots.restore.older` "This snapshot was saved by an earlier version of TiffinDB. It will be brought up to date after the restore."
7. `p.help` `snapshots.restore.others` "People using this project now may need to reload their page. If someone is saving a change at this moment, you will be asked to try again."
8. `div.danger-zone` > `form` POST (`csrf`); `div.form-actions`: `button.button.button-danger.button-large` `snapshots.restore.submit` "Restore this snapshot"; `a.button.button-large` `form.cancel` to the list.

422: the same page with the error summary (`problem.snapshot.unreadable` or `problem.lock.unavailable`, no field) and without the form.

## 4. Results

| Action | Result |
|---|---|
| Restore | 303 to the list; flash `notice.snapshot_restored` "{project} is back to how it was on {date}. A snapshot of how it was just before was saved first." or `notice.snapshot_restored_missing` (the same text, then "{count|# file is|# files are} missing from that copy.") |
| Project busy | 503 busy page (as built), nothing changed |
| Snapshot gone meanwhile | 404 |

The new `pre-restore` snapshot is at the top of the list afterwards, so going back is one more restore.

## 5. Keyboard and screen reader

Tab order on the list: breadcrumb, "Take a snapshot now", each row's "Restore…". The table has a caption and column headers; each restore link names the snapshot's time. On the confirmation: breadcrumb, "Restore this snapshot", "Cancel". Notices use `role="status"`. No script.

## 6. Narrow layout

The table scrolls inside its own box (as built); the page never scrolls sideways. The confirmation's facts stack (as built). At 320 px Largest the buttons stack full width.
