# Upgrading

An upgrade replaces the application's files and keeps the data folder and `config/local.php`. There is no upgrade script: the data is upgraded by TiffinDB itself the first time it is used.

Do the upgrade while nobody uses TiffinDB. Between steps 3 and 4 the site does not work.

## Procedure

1. **Make a backup.** Either a project archive of each project (each project's Export page, "Download the project archive"), or a copy of the whole data folder made while nobody uses TiffinDB (see [Backup and restore](backup-and-restore.md)).
2. **Download and check the new release.** Download `tiffindb-<version>.zip` and its `.sha256` file, and check that the SHA-256 of the zip file matches (see [Installing](install.md#1-check-the-download)).
3. **Delete the application folders of the installed copy.** In the TiffinDB folder, delete these folders:
   - `public/assets/`
   - `src/`
   - `templates/`
   - `strings/`
   - `vendor/`
   - `tools/`
   - `docs/`
   - every plugin folder listed under `plugins/` in the installed copy's own `MANIFEST.txt` (the old one), and every plugin folder listed under `plugins/` in the new release's `MANIFEST.txt`: for example `plugins/import/csv/`, `plugins/import/xlsx/`, `plugins/export/csv/`, `plugins/export/xlsx/`, `plugins/export/json/`. A plugin folder is listed when lines of `MANIFEST.txt` start with its path after the SHA-256. Using the old list as well removes a plugin the new release no longer ships; it would otherwise stay behind and could keep running.

   Keep `config/local.php`, the data folder, and plugin folders you added yourself. None of these is inside the folders above unless you put it there. If your data folder is `data/` inside the TiffinDB folder, it is not in the list: do not delete it.

   An installed copy without a `MANIFEST.txt` (not installed from a release archive) has no old list: delete the plugin folders the new release lists, and any other plugin folder under `plugins/import/` or `plugins/export/` that you did not add yourself.
4. **Upload the new files.** Upload the contents of the new archive's top folder `tiffindb-<version>/` into the TiffinDB folder, over the installed copy, including the `.htaccess` files. Files outside the folders of step 3 (such as `public/index.php`, `config/app.php`, and the `.htaccess` files) are replaced by the upload.
5. **Open TiffinDB.** On the first request, the system database is upgraded when it has an older version, after a copy named `system-before-v<n>-<time>.sqlite` is made beside it (`<n>` is the version it is upgraded to, `<time>` the UTC time). Each project is upgraded when it is first opened, after a snapshot with the reason `format-upgrade`. A project that is never opened stays as it is until then.
6. **If a page says a newer TiffinDB wrote the data,** the copy you uploaded is older than the data. "This TiffinDB needs a newer version" means the data folder's system database is newer; "This project needs a newer TiffinDB" means one project is newer. Nothing was changed. Upload the newer release again.

The About page (footer link "About TiffinDB") shows the version that is running.

## After upgrading

- `format-upgrade` snapshots are kept and pruned like other automatic snapshots (the 20 newest are kept). TiffinDB does not remove the `system-before-v<n>-*.sqlite` copy; delete it by hand once the upgraded installation works.
- A failed project upgrade leaves the project at its old version. Opening it again retries the upgrade, without taking another `format-upgrade` snapshot of the same starting version.
- To go back to the older release, restore the backup of step 1 and upload the older release: an older TiffinDB refuses data that a newer one has upgraded.

## Why the folders are deleted first

Uploading over an old copy would leave behind files that the new release removed. Deleting the release's folders first means that only the new release's files remain. Plugin folders matter most: TiffinDB finds plugins by looking at the folders that exist, so a stale plugin folder would still be listed, and its code would run whenever it is turned on.
