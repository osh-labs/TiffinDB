# ADR 0022: Snapshot restore, project deletion, the audit-log screen, and Admin recovery

Status: Accepted (provisional, awaiting maintainer confirmation). Settles the P49 items assigned to M7 (P170): snapshot restore, project deletion, and the audit-log screen; and Admin recovery without shell access (P27, P49; `docs/build/M5-M7-session-plan.md` section 3 row 6, applied as its default). Settles section 8.2 rows 6 to 8 and 10 of that plan. Adds a project lock (new), the snapshot reason `pre-restore`, the staging kinds `restores` and `deleted`, and the audit actions `snapshot.restored`, `project.deleted`, `auth.recovery_started`, and `auth.recovered` (amends ADR 0012 decisions 3 and 4, and P127). No project format step and no system schema step. The implementation design is `docs/specs/M7-release.md` and `docs/specs/M7-screens.md`; screens are in `docs/design/m7/`.

## Context

Snapshots have existed since M1 (ADR 0012 decision 3), but the only way back to one is by hand with nobody using TiffinDB (`docs/formats/tiffin.md` section 10). A project cannot be deleted at all. The audit log is written from M1 and read only by `AuditOperations::recent`. An Admin who forgets the password can be helped by another Admin, or by `tools/admin/reset-password.php` with shell access; a lone Admin on a host with no shell has no way back in.

Two facts shape the design. First, every request opens project files directly with SQLite in WAL mode; replacing or removing a file under an open connection can lose the newest changes or corrupt the database, and nothing in the tree today stops another request from holding one. Second, snapshots hold the database only; the attachment files beside it are shared, content-addressed, and swept when no row names them (ADR 0018 decision 6, P151, P175).

## Decisions

### 1. A project lock

Each project folder holds `project.lock`, an empty file. Every opening of a project through `ProjectOpener` takes a shared `flock` on it and holds it until the connection is closed (the end of the request at the latest; the operating system releases it if the process dies). Restore and deletion take an exclusive lock, waiting at most 10 seconds; when they cannot get it, they change nothing and answer "busy, try again". A shared lock is waited for at most 2 seconds and then also answers busy, which happens only while a restore or deletion holds the exclusive lock.

When the file system does not support locks (the call fails for a reason other than contention), normal opening goes on without the lock and logs that once per request, while restore and deletion refuse with a plain message: they never run without exclusion. A repository test keeps `ProjectFile::open` callable only from `ProjectOpener`, so no path opens a project without the lock.

### 2. Snapshot restore replaces the file under the exclusive lock

Restore is for Admins (`snapshots.manage`) through a confirmation page. In the core:

1. The chosen snapshot must be one `Snapshots::list` returns (a name, never a path). It is copied into the staging area and checked there: SQLite header, application id, format, `user_version` from 1 to the current version, the project key equal to this project's, and `PRAGMA integrity_check` `ok`. A snapshot that fails is refused and nothing else happens.
2. Under the exclusive lock: a `pre-restore` snapshot of the current file is taken (so the restore itself can be undone by restoring that), the current file is checkpointed and closed so that no `-wal` or `-shm` file is left, the checked copy is moved into the project folder and renamed over `project.tiffin` (one atomic rename), and any `-wal` or `-shm` file left is removed.
3. After the lock is released, the project is opened as usual, so a snapshot of an older format version is upgraded after its own `format-upgrade` snapshot. The project's meta name is set to the registry name, and `updated_at` and `updated_by` record the Admin.
4. Files: attachment files that no row of the restored database names are swept (as after emptying the trash), and attachment rows whose file is not on disk are counted. The confirmation page states both counts before the Admin confirms, and the result page states them after. Serving a file that is missing already answers "not found" (M4).

A failure at any step before the rename leaves the current file untouched; the rename either happens or does not. This automates the by-hand procedure of the format document, which it replaces as the recommended way. The session plan's wording, "replaces the project file inside the project lock", is kept; the lock is new because none existed.

The alternative of rebuilding the live file in one transaction from the snapshot's contents (the archive-import rebuilder) was not chosen: it is not an exact restore (it empties values that fail checks, drops the import log, rewrites forms), it holds one long write transaction on large projects, and it would turn the reviewed archive rebuilder into a second, in-place code path.

### 3. Project deletion moves the folder into the staging area

Deletion is for Admins (`project.manage`) through a confirmation page that states the counts of live records, records in the trash, stored files and their total size, snapshots, and Members with access; offers a project archive download first (a link to the export page); and requires the project's name to be typed. The core checks the typed name (white space trimmed, ASCII case ignored, as names are unique) and refuses it otherwise; it also asks again when more records exist than the page stated (the P82 pattern).

1. The registry row is set to the new state `deleting`, so from then on the project is "not found" for everyone and is listed nowhere.
2. Under the exclusive lock, the project folder (checked to be a directory and not a symbolic link) is renamed to `<data>/staging/deleted/<key>/`. If that rename fails, the state goes back to `active` and the page says the project could not be deleted; nothing is removed.
3. The registry row is deleted; its grants go with it (`ON DELETE CASCADE`, as built); audit rows keep their entries with the project left empty (`ON DELETE SET NULL`, as built).
4. The folder in the staging area is removed with the existing staging-only recursive removal (P127), which never follows a link. A removal that fails part-way is finished by the staging sweep. A `deleting` row left by a process that died is finished by the sweep that already clears stale `creating` rows.

This keeps the one rule that allows recursive removal (inside the staging area only) instead of adding a second one for project folders, which the session plan proposed (amending P64 and P127). The move is the only operation on the live folder, and it is atomic. There is no trash for projects in v1. `project.deleted` keeps the deleted project's name in its detail, so the audit-log screen can still say which project it was.

### 4. The audit-log screen is read-only

Admins only (`audit.view`). Newest first, 50 entries per page, filtered by person, by project, and by action (any combination), with the filters in the query string. Each entry shows when, who (the person's display name, or "TiffinDB" when no person acted), what (a sentence per action from the strings file), which project (its current name, or the deleted project's name from `project.deleted`, or nothing), and the detail values as escaped plain text. No entry can be changed or removed from the screen. The core resolves names, so the web layer needs no system-database access. Failed sign-in entries keep their 90-day retention (as built).

### 5. Admin recovery by a code file

- On the sign-in page, a link leads to a recovery page that explains the procedure. Its "Start recovery" button asks TiffinDB to write a one-time code into `recovery-code.txt` in the data folder, which only someone with access to the host's files (a file manager, SFTP, or a shell) can read. The code has the form and strength of the setup code (16 characters, about 78 bits).
- The person then types the username of an active Admin, the code, and a new password twice. On success TiffinDB sets the password (no change required at the next sign-in), ends every session of that account, clears its sign-in throttle, deletes the code file, records `auth.recovered` with no acting person, and sends the person to the sign-in page.
- A code is valid for 30 minutes and is used at most once. Starting again while a valid code exists does not replace it. After 5 wrong attempts the code file is deleted. Attempts are throttled by address and in total with the sign-in throttle's rules. Codes are compared with `hash_equals`. A code is never shown in a page, a log line, or an audit entry.
- Every outcome other than success shows the same message, whether the username is unknown, belongs to a Member, belongs to an inactive account, or the code is wrong or expired, and the password is hashed in every case so the timing does not differ. Password rules are checked first and reported, because they depend only on what was typed.
- The start page says the same thing whether or not a code file was written, and never shows a path. Starting is throttled (at most 5 starts an hour in total) and audited as `auth.recovery_started` with no acting person.
- An operator who does not want this path sets `admin_recovery` to false in `config/local.php`; the link and the pages then do not exist (404). The host documentation also recommends a second Admin account.
- No system schema change: the code lives in the file; attempts use the existing throttle table.

A visitor without file access learns nothing and changes nothing except the existence of a code file nobody can read. Someone with file access to the data folder can already read every project, so the recovery path gives them nothing they did not have.

### 6. About page

`/about`, for every signed-in person: the version, the licence, the "Source code" link, the third-party components and their licences (from `config/notices.php`, each licence text on its own page), and the plugins from the plugin registry. Admins also see the PHP and SQLite versions. The footer of the signed-in shell links to it.

## Consequences

- Every project request takes one `flock` on one file in the project folder. Restore and deletion wait for running requests to finish, at most 10 seconds, and otherwise answer busy. Hosts whose file system does not support locks cannot restore or delete in the browser; they can still restore and delete by hand.
- A restore can be undone by restoring its `pre-restore` snapshot, except for attachment files swept by the restore. The confirmation page says how many files the restore will delete.
- Deleting a project is irreversible inside TiffinDB. The archive offer and the typed name are the safeguards.
- Restore and deletion are audited; the audit log is visible to Admins.
- A lone Admin can recover without a shell, provided someone can read one file in the data folder.
- `docs/formats/tiffin.md` sections 1 and 10 describe `project.lock`, the `pre-restore` reason, and restore in TiffinDB; no format version changes, because an older TiffinDB ignores both (it skips snapshot names with an unknown reason and never reads the lock file).
