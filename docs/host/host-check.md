# Host check

The host check is one PHP file, `tools/host-check/host-check.php`, that you upload to a web host to see whether it can run TiffinDB before you install. It is not needed for installing; the setup page checks the essentials itself. `tools/host-check/README.md` has the full procedure and the meaning of every line.

## When to run it

- Before the first installation on a new host.
- After the host changes PHP version or server software.
- When something listed in [Requirements](requirements.md) is in doubt, such as `.htaccess` handling or file locks.

## Running it, in short

1. Open `tools/host-check/host-check.php` in a text editor and set `HOST_CHECK_TOKEN` near the top to a random value of at least 16 letters and digits.
2. Upload it to the web root and open `https://tiffindb.example.com/host-check.php?token=<your token>` (with your own site and token).
3. Read or save the page.
4. Delete `host-check.php` from the host. Also delete any folder named `tiffindb-hostcheck-` followed by 16 hex characters beside it or in the folder above the web root (only left after an interrupted run).

The output names no host names, addresses, user names, or absolute paths, but read it before you share it.

## Reading the lines

Each line starts with PASS, WARN, FAIL, or INFO:

| Result | Means |
|---|---|
| FAIL | TiffinDB cannot run as designed on this host |
| WARN | Works with a limit or a fallback |
| PASS | Meets the requirement |
| INFO | Context only, or a check that was skipped |

Lines that block TiffinDB:

| Line | What to do |
|---|---|
| `php.version` below 8.3 | Switch the site to PHP 8.3 or later in the control panel |
| `ext.pdo_sqlite`, `ext.zip`, `ext.xmlreader`, `ext.mbstring`, `ext.session`, `ext.json` missing | Ask the host to turn the extension on |
| `crypto.random_bytes` failing | Ask the host; TiffinDB cannot run safely without it |
| `sqlite.version` too old, or `fs.sqlite_file` failing | Ask the host for a newer SQLite, or a folder where SQLite files can be written. TiffinDB itself needs 3.35.5 or later, which the setup page checks |
| `fs.flock` failing | File locks do not work: TiffinDB runs, but restoring snapshots and deleting projects in the browser are refused (see [Requirements](requirements.md#file-system)) |
| Required functions listed under `php.disable_functions` | Ask the host to allow them |
| `web.htaccess_deny` or `web.htaccess_rewrite` failing on Apache | `.htaccess` files are ignored: TiffinDB's addresses and the protection of `data/` do not work. Ask the host to allow `.htaccess` overrides |

The host check README lists `fs.flock` among the blocking lines. TiffinDB itself goes on without locks for normal use, as described in the table, but a host without locks cannot restore or delete in the browser.

Lines that matter but do not block:

| Line | Means |
|---|---|
| `fs.outside_webroot_writable` WARN | The data folder cannot go above the web root; it goes in `data/` inside the TiffinDB folder (unless `data_dir` names another place), and setup tests that it cannot be fetched |
| `fs.sqlite_journal_mode` other than `wal` | SQLite falls back to rollback journals: slower, more waiting between requests |
| `web.https` WARN | PHP sees plain HTTP. Behind a proxy that ends HTTPS this can be a WARN although visitors use HTTPS: see [HTTPS and reverse proxies](https-and-proxies.md) |
| `ext.dom`, `ext.filter`, `ext.libxml` WARN | The XLSX plugins report themselves unavailable; everything else works |
| `crypto.argon2id` WARN | Passwords are stored with bcrypt instead |
| `fs.free` WARN | Less than 1 GiB free beside the web root; snapshots and exports need room |
| Low limits (memory, execution time, upload and post size) | See [Limits](limits.md) |
| `web.authorization_header` WARN | Needed only by a planned later feature (a server for external tools); nothing in this version uses it |

Under the PHP built-in server, the web checks report WARN because it does not read `.htaccess` files.
