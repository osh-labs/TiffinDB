<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) TiffinDB contributors
-->

# TiffinDB host check

`host-check.php` is a single PHP file you upload to a web host to see whether the host can run TiffinDB. It answers open question **plan Q2** (PHP 8.3 with `pdo_sqlite`, `zip`, `xmlreader`; a data directory outside the web root; `Authorization` header pass-through; upload, memory and execution-time limits) and supports the M7 exit criterion "release archive installs on the reference shared host".

The script runs on PHP 7.4 and later, so an old PHP still gets a report instead of a parse error. It has no dependencies.

## Procedure

1. Open `host-check.php` in a text editor. Near the top, set `HOST_CHECK_TOKEN` to a random value of at least 16 characters using only letters and digits (A-Z, a-z, 0-9). While it is empty or invalid, the file refuses all web requests with 403.
2. Upload the file to the web root of the account on the reference shared host.
3. Visit `https://<your-site>/host-check.php?token=<your token>`.
4. Copy the whole page (plain text) into the issue or notes for plan Q2.
5. **Delete `host-check.php` from the host.** Do not leave it there. Also delete any directory named `tiffindb-hostcheck-` followed by 16 hex characters, beside the script or in the directory above the web root; these exist only if a run was interrupted (the next run removes ones older than 10 minutes by itself).

Optional, over SSH: `php host-check.php` in the web root. The CLI needs no token. It skips the Apache and HTTP checks, and PHP limits can differ from the web server's, so the HTTP run is the one that counts.

Add `&selftest=0` to the URL to skip the self-requests described below.

## What it does

- Reads PHP settings and extension status.
- Opens a throwaway in-memory SQLite database to test FTS5 and JSON1.
- Looks for directories left by an earlier interrupted run (names `tiffindb-hostcheck-` plus 16 hex characters, beside the script and in `<docroot>/..`). It removes those older than 10 minutes, never follows symbolic links, and reports what it removed or left. If `is_link` is disabled it skips this sweep (WARN) and, at the end, removes only the files and directories it created itself, by name.
- In the parent of the document root (`<docroot>/..`), creates a uniquely named directory, creates a SQLite database in WAL mode, writes and reads a row, tests `flock`, then deletes everything it created (including `-wal` and `-shm` files). From the CLI, or when the document root is unknown, it uses the parent of the script's directory instead (`<script-dir>/..`); that is outside the web root only if the script sits directly in the web root, and the report line says so.
- Beside the script, creates a uniquely named directory. Over HTTP with self-requests on, it also writes small probe files there: `.htaccess` files (deny-all, a rewrite rule), a tiny PHP probe, and the SHA-256 hash of a per-run random nonce. With `selftest=0` no probe files are written. The directory is deleted at the end.
- Over HTTP only, and unless `selftest=0`: makes a few plain HTTP/1.0 requests **to the local address and port that received your request** (`SERVER_ADDR` and `SERVER_PORT`; under the PHP built-in server, its bind address). The request's `Host` header is sent along so the same site answers, but it never decides where the connection goes. The requests check that the deny-all directory is not served (expects 403 or 404), that the rewrite rule works, and whether a dummy `Authorization` header reaches PHP, with and without a rewrite-rule workaround. They are authenticated with the per-run nonce; the token is never sent. HTTPS certificates are verified. These self-requests are the only network calls the script makes.
- Every call to a function a host may disable (for example `chmod`, `mkdir`, `scandir`, `fopen`) is checked first, and the whole run is wrapped so that an error still produces a report and cleanup.

The `fs.cleanup` line confirms that nothing was left behind. If it fails, it names the leftover directory (as a masked path); delete it by hand. The script never claims removal it could not confirm.

## What the output contains

Included: PASS / WARN / FAIL / INFO lines; PHP version, server API name and OS family (for example `Linux`); SQLite version and FTS5/JSON1 availability; extension versions where they differ from the PHP version; Argon2id or bcrypt; PHP limit values; names of disabled functions from a fixed list; whether `open_basedir` is set (not its value); masked labels and names of leftover `tiffindb-hostcheck-` directories; the server family (Apache, LiteSpeed, nginx, built-in, unknown); whether PHP sees HTTPS; HTTP status codes and fixed failure categories from the self-requests; where the `Authorization` header arrived (`direct`, `via-rewrite`, `absent`); and a JSON block with the same data.

Not included: hostnames, IPv4 or IPv6 addresses, usernames, absolute paths, environment variables, server software version strings, `phpinfo()`, the token (raw or URL-encoded), the nonce, network error text, or the `Authorization` header value. Paths appear only as `<docroot>`, `<docroot>/..` or `<script-dir>`; PHP error messages are masked the same way. The output is meant to be safe to paste into a public issue, but read it before you post it.

## Reading the results

- **FAIL**: TiffinDB cannot run as designed. The script exits with code 1 in the CLI.
- **WARN**: works with limits or a fallback; note it in plan Q2.
- **PASS**: meets the assumption.
- **INFO**: context only, or a check that was skipped (for example, the web checks in the CLI).

Exit codes (CLI): 0 = no FAIL lines, 1 = at least one FAIL line, 2 = unknown option.

Results that block TiffinDB:

- `php.version` below 8.3.
- `ext.pdo_sqlite`, `ext.zip`, `ext.xmlreader`, `ext.mbstring`, `ext.session`, `ext.json` missing; `crypto.random_bytes` failing.
- `sqlite.version` below 3.7.0 (no WAL), or `fs.sqlite_file` failing.
- `fs.flock` failing, or required functions listed under `php.disable_functions`.
- `web.htaccess_deny` or `web.htaccess_rewrite` failing on Apache: `.htaccess` overrides are ignored, so routing and the deny-all fallback for `data/` will not work.

Results that matter but do not block v1:

- `fs.outside_webroot_writable` WARN: `data/` would have to live inside the web root behind a deny-all `.htaccess`, which the installer must then verify.
- `fs.sqlite_journal_mode` other than `wal`: SQLite falls back to rollback journals (slower, more lock contention).
- `web.https` WARN: PHP sees plain HTTP. `X-Forwarded-Proto` is not trusted (a client can send it); behind a TLS-terminating proxy this line may WARN even though visitors use HTTPS. Note that case in plan Q2.
- `web.authorization_header` WARN: the post-v1 MCP server would not work on this host. A PASS with value `via-rewrite` means TiffinDB's `.htaccess` must carry the `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` line.
- `crypto.argon2id` WARN: passwords use bcrypt instead.
- `sqlite.fts5` WARN, low limits: record them; they shape defaults and documentation.

The thresholds for limits (128M memory, 30 s execution time, 8M upload and post size, 10 files, 1000 input vars) and the SQLite 3.35 advisory are provisional values chosen for this check, not decisions recorded elsewhere.

## Limitations

- Under the PHP built-in server the web checks report WARN (it does not read `.htaccess`), and self-requests need `PHP_CLI_SERVER_WORKERS=2` or more.
- Self-requests are skipped when the server does not report `SERVER_ADDR`/`SERVER_PORT`, and fail on hosts that block connections to their own address or present a certificate that does not verify; the affected lines then report "skipped" or "inconclusive".
- A 500 from the deny probe means `.htaccess` was read but a directive was rejected; the line reports WARN and needs a manual look at the host's `AllowOverride` settings.
