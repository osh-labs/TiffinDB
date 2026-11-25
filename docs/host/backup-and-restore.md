# Backup and restore

What TiffinDB stores is in the data folder (see [Installing](install.md#the-data-folder)):

| Path in the data folder | Holds |
|---|---|
| `system.sqlite` | People, passwords (hashed), sharing, plugin settings, the activity log |
| `projects/<key>/` | One project: its database `project.tiffin`, its stored files `attachments/`, its `snapshots/` |
| `staging/` | Work in progress (uploads being checked, imports, deletions); not needed in a backup |

`<key>` is the project's key, the last part of the project's address: `/projects/q7mzk2wbx4ae` is the folder `projects/q7mzk2wbx4ae/`. The project file format is documented in `docs/formats/tiffin.md`, so a backup can be read with ordinary SQLite tools and no TiffinDB code.

## Project archives

A project archive is a zip file holding one project: its database, with every table, record, form, collection, and records in the trash, and every stored file. It does not hold snapshots, people, or sharing.

- **Make one:** open the project, then its Export page, then "Download the project archive".
- **Restore one:** on the project list, "Import a project archive". This always makes a new project, shared with nobody; your other projects are not changed. TiffinDB checks the archive before using it and reports anything it left out.
- **Upload limit:** at most 64 MiB, or less when PHP's `upload_max_filesize` or `post_max_size` is lower. The import page shows the limit that applies.

### Restoring a large archive by hand

An archive over the upload limit can be restored by hand, by someone who can open the server's files. This skips TiffinDB's checks of the archive, so use it only for archives you made yourself. The full procedure is section 13.4 of `docs/formats/tiffin.md`; in short:

1. In TiffinDB, create a new, empty project with the name you want, open it once, and note its key from its address.
2. With nobody using TiffinDB, delete `project.tiffin`, `project.tiffin-wal`, and `project.tiffin-shm` in that project's folder, and empty its `attachments/` folder.
3. Unpack the archive on your computer. Copy its `project.tiffin` into the project's folder, and its `attachments/` entries into the folder's `attachments/`, keeping their two-character subfolders.
4. Before or after uploading, open the copied `project.tiffin` with an SQLite tool and set the key to the folder's name, for example `UPDATE _tiffin_meta SET value = 'q7mzk2wbx4ae' WHERE key = 'project_key';`. TiffinDB refuses a file whose key differs from its folder.
5. Open the project in TiffinDB. A file of an older format version is upgraded after a snapshot.

## Snapshots

A snapshot is a complete copy of a project's database, `project.tiffin`, taken before a risky change: a format upgrade, deleting a table or a field, changing a field's type or options, emptying the trash, importing rows, restoring a snapshot, or when an Admin asks for one. Snapshots are in `projects/<key>/snapshots/`, each a self-contained SQLite file that opens on its own.

- The 20 newest snapshots other than manual ones are kept; older ones are deleted when a new one is taken. Manual snapshots are kept until someone deletes them from the folder.
- Snapshots hold the database only, not the stored files in `attachments/`. A snapshot is therefore not a full backup: a file removed after the snapshot was taken is not in it.
- Snapshots are inside the project folder, on the same disk. They protect against mistakes, not against losing the server.

### Restoring a snapshot in TiffinDB

The Snapshots screen is described here from its design; it is being built and has not been checked against the finished screen. An Admin opens the project's settings, then "Snapshots", and chooses "Restore…" on a snapshot. The confirmation page states the number of records then and now, how many stored files were added after the snapshot and will be deleted, and how many files the snapshot names that are no longer stored and will show as missing.

On "Restore this snapshot", TiffinDB:

1. checks the snapshot (it must be a project file of this project, of a format version it knows, and pass SQLite's integrity check), and changes nothing if it fails;
2. takes a `pre-restore` snapshot of the project as it is now, so the restore can itself be undone by restoring that snapshot;
3. replaces `project.tiffin` with the snapshot, under an exclusive lock on `project.lock`, waiting at most 10 seconds for requests that have the project open; when they do not finish, nothing is changed and the page asks you to try again;
4. deletes stored files that no record of the restored project names. Restoring the `pre-restore` snapshot afterwards brings back the database, but not those files.

A snapshot from an older format version is upgraded when the project is next opened. When the server cannot lock files (see [Requirements](requirements.md#file-system)), restoring in TiffinDB is turned off; restore by hand instead.

### Restoring a snapshot by hand

With nobody using TiffinDB, in the project's folder (`docs/formats/tiffin.md` section 10):

1. Rename `project.tiffin`, to keep it (for example to `project-old.tiffin.bak`).
2. Copy the chosen snapshot from `snapshots/` to `project.tiffin` in the same folder.
3. Delete `project.tiffin-wal` and `project.tiffin-shm` if they exist; they belong to the old file.

If the snapshot has an older format version, TiffinDB upgrades it the next time the project is opened. Stored files are not changed by this procedure; files the restored database does not name are deleted by TiffinDB later.

## Copying folders by hand

Copying is the backup to use for a large project, for the system database, and for the whole installation. Copy while nobody uses TiffinDB: SQLite keeps recent changes in `-wal` files while a database is in use, and a copy of `project.tiffin` alone, taken while `project.tiffin-wal` exists, may lack the newest changes (`docs/formats/tiffin.md` section 9).

- **One project:** copy the folder `projects/<key>/` with everything in it. If you cannot be sure nobody is using TiffinDB, copy `project.tiffin`, `project.tiffin-wal`, and `project.tiffin-shm` together, and `attachments/`.
- **The system database:** copy `system.sqlite`, with `system.sqlite-wal` and `system.sqlite-shm` if they exist. Without it, a restored installation has its projects but no people and no sharing.
- **Everything:** copy the whole data folder, and `config/local.php` if you have one. `staging/` may be left out.

To restore a whole installation, put the copied data folder back in its place (or set `data_dir` to where it now is) and install the same or a newer release.

## Deleting a project by hand

An Admin deletes a project in TiffinDB from the project's settings, "Delete this project", after typing the project's name (described from its design; the screen is being built). That needs file locks. When the server cannot lock files:

1. With nobody using TiffinDB, move the folder `projects/<key>/` out of the data folder, or delete it.
2. In TiffinDB, delete the project from its settings as usual. The page says the project's folder is not in the data folder; deleting then removes the project from the list and from everyone's access, and the activity log keeps a line saying it was deleted.
