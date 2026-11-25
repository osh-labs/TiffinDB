# Requirements

The setup page checks most of these before anything is saved (step 1, "Check this server"). The [host check](host-check.md) checks the rest, including Apache's handling of `.htaccess` files and file locks.

## PHP

- PHP 8.3 or later.
- Required extensions: `pdo_sqlite`, `zip`, `xmlreader`. Without one of them, setup stops at step 1.
- Extensions for XLSX spreadsheets: `dom`, `filter`, `libxml`. Without them TiffinDB works, but the XLSX import and export plugins report themselves unavailable. Setup shows a warning, not a failure.
- Secure random numbers (`random_bytes`) must work.
- Passwords are stored with Argon2id when PHP offers it, otherwise with bcrypt. Both are accepted.

## SQLite

SQLite 3.35.5 or later, as reported by PHP's `pdo_sqlite`. Setup refuses an older version. TiffinDB uses WAL journal mode where the file system allows it.

## Web server

- Apache with `.htaccess` files read (`AllowOverride` must allow the directives used, including `Require` and the rewrite directives).
- `mod_rewrite`: `public/.htaccess` sends every request that is not an existing file to `public/index.php`, so TiffinDB's addresses (such as `/projects`) do not work without it. In the layout where the whole TiffinDB folder is under the web root, the root `.htaccess` passes every request into `public/`, and refuses every request when `mod_rewrite` is missing.
- `mod_headers`: `public/.htaccess` uses it to send a restrictive Content-Security-Policy, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and one week of caching for the static files (CSS, JavaScript, fonts, SVG, the web manifest, licence texts). Without `mod_headers` the static files are served without these headers; the pages TiffinDB generates still carry their own headers, because PHP sends them.
- HTTPS is strongly recommended. Without it, setup shows a warning, and passwords cross the network unencrypted. See [HTTPS and reverse proxies](https-and-proxies.md).

This behaviour is reasoned from `.htaccess` and `public/.htaccess`; it has not been run on a real Apache server by the TiffinDB build. The host check's `web.htaccess_deny` and `web.htaccess_rewrite` lines test it on your host.

The PHP built-in web server is for development only. It does not read `.htaccess` files.

## File system

- The web server's account must be able to create and write the data folder (see [Installing](install.md#the-data-folder)).
- File locks (`flock`) must work in the data folder. TiffinDB takes a lock on `project.lock` in a project's folder every time it opens the project:
  - Every request takes a shared lock. When the file system cannot lock, normal use goes on without the lock, and TiffinDB writes a line to the server log.
  - Restoring a snapshot and deleting a project take an exclusive lock. When the file system cannot lock, both are refused with "This server cannot lock files, so TiffinDB cannot do this safely here. Nothing was changed." Use the by-hand procedures in [Backup and restore](backup-and-restore.md#restoring-a-snapshot-by-hand) and [Deleting a project by hand](backup-and-restore.md#deleting-a-project-by-hand) instead.
  - The host check's `fs.flock` line tests locks in the folder above the web root.
- Network file systems may emulate `flock` with other locks or not support it at all; prefer a local disk for the data folder.
- Leave room for snapshots and exports beside the projects. The host check's `fs.free` line shows the free space; a quota set by the host can be lower.

## What the host check reports

`tools/host-check/README.md` describes how to run the host check and every line it prints. [Host check](host-check.md) summarises which lines block TiffinDB.
