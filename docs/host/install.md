# Installing

Installing needs only a file manager or an SFTP program and a browser. Check the [requirements](requirements.md) first; the [host check](host-check.md) answers most of them before you upload anything.

## 1. Check the download

A release is a zip file `tiffindb-<version>.zip` with a file `tiffindb-<version>.zip.sha256` beside it that holds its SHA-256. On your own computer, compute the SHA-256 of the zip file and compare it with the one in the `.sha256` file. Do not install a file whose SHA-256 differs.

The zip holds one top folder, `tiffindb-<version>/`. Its `MANIFEST.txt` lists the SHA-256 of every other file in it, one `<sha256>  <path>` line per file, so an unpacked copy can be checked too.

## 2. Choose a layout

Pick one of two layouts. Paths below use `/home/example` as the hosting account's home folder and `public_html` as its web root; your host's names may differ.

**Layout A: the web root points at `public/` (recommended).** Upload the contents of `tiffindb-<version>/` into a folder outside the web root, for example `/home/example/tiffindb/`. In the hosting control panel, set the site's document root (also called the web directory) to `/home/example/tiffindb/public`. Only `public/` can then be reached from the web. The root `.htaccess` is not used in this layout.

**Layout B: the whole TiffinDB folder under the web root.** Use this when the document root cannot be changed. Upload the contents of `tiffindb-<version>/` into the web root, or into a folder below it, for example `/home/example/public_html/tiffindb/`. The root `.htaccess` passes every request into `public/`, so `src/`, `templates/`, `vendor/`, `tools/`, and the data folder cannot be fetched; when `mod_rewrite` is missing it refuses every request instead. TiffinDB then answers at the address of that folder, for example `https://tiffindb.example.com/tiffindb/`.

Either way, unpack the zip on your computer and upload the files, or upload the zip and unpack it with the host's file manager. Keep the folder structure as it is in the zip, and include the `.htaccess` files (some programs hide files whose names start with a dot).

The root `.htaccess` behaviour is reasoned, not run, by the TiffinDB build: the host check's `web.htaccess_rewrite` and `web.htaccess_deny` lines test it on your host.

## The data folder

TiffinDB keeps everything it stores in one data folder: the system database `system.sqlite` (accounts, sharing, the activity log), one folder per project under `projects/`, and its working area `staging/`. The format of a project folder is described in `docs/formats/tiffin.md`.

Setup chooses the data folder in this order:

1. When `config/local.php` sets `data_dir`, that folder and no other (see [Configuration](configuration.md)).
2. Otherwise `tiffindb-data`, beside the TiffinDB folder (`../tiffindb-data` from it), when the web server can create it there and it is outside the web site's document root.
3. Otherwise `data/` inside the TiffinDB folder.

In layout A the sibling folder (`/home/example/tiffindb-data`) is outside the document root and is chosen. In layout B it is usually inside the web root, so `data/` is chosen, unless the TiffinDB files are the web root itself. A folder is treated as outside only when PHP reports the document root and both paths can be resolved; otherwise it is treated as inside.

Setup writes a `.htaccess` file into the data folder with the line `Require all denied`. When the data folder may be inside the web site, setup then asks the web server, from the server itself, for a test file in the data folder, and continues only when that request is refused. If the file can be fetched, or the answer is unclear, setup stops and shows how to fix it. This test cannot see an exposure through another virtual host, alias, or proxy that reaches the folder by a different path; a data folder outside the web root avoids that question. Behind a proxy or on an unusual port, see [`probe_port`](https-and-proxies.md#the-probe-port).

Setup refuses a data folder that already holds files it did not put there. To choose another place, set `data_dir`.

## 3. Run the setup page

Open the site in a browser. Before installation every address sends you to `/setup`, which has three steps.

1. **Check this server.** A table of checks: PHP version, SQLite, the extensions, secure random numbers, password storage, HTTPS, and where the data folder will go. Each row says "Ready", "Works, with a note", or "Needs fixing". Fix the rows marked "Needs fixing" and check again.
2. **Make the data folder safe.** Setup creates the data folder, writes its `.htaccess`, and runs the test described above. Nothing is saved before this step.
3. **Create the first Admin.** To show that you look after the server, open the file `setup-code.txt` in the data folder with the file manager or SFTP program and copy the code from it (16 letters and digits, written in groups of four). Type it with your name, a username, and a password of at least 10 characters. Wrong codes are limited: after 5 wrong codes, setup waits before it accepts another, starting at 30 seconds.

When the first Admin is created, TiffinDB deletes `setup-code.txt`, signs you in, and shows the project list. If the code file is missing while step 3 is open, loading `/setup` again writes a new one. After installation `/setup` is not found (404).

## 4. After installing

- Create a second Admin account (People): see [Accounts and recovery](accounts-and-recovery.md).
- Set `secure_cookies` when the site is reached through a proxy that ends HTTPS: see [HTTPS and reverse proxies](https-and-proxies.md).
- Make a first backup plan: see [Backup and restore](backup-and-restore.md).
- Delete the host check script if you uploaded it (see [Host check](host-check.md)).
