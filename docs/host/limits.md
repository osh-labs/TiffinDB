# Limits

TiffinDB's limits are fixed in its code. Your host's PHP settings can lower the upload limits; they cannot raise them.

## Uploads

| Limit | Value |
|---|---|
| One uploaded file (a record's file, an import file, a project archive) | 64 MiB, or less when PHP's `upload_max_filesize` or `post_max_size` is lower |
| A whole form submission | PHP's `post_max_size`; a larger one is refused with a "too large" page (HTTP 413) before TiffinDB reads it |
| Free space kept back | An upload is refused when it would leave less than 100 MiB free in the data folder's disk |
| Files per file field of one record | 100 |
| Empty files | Refused |
| A form's HTML (uploaded or edited) | 256 KiB |
| File names | At most 255 characters; longer names are shortened, keeping an extension of up to 16 letters or digits |

The upload pages state the limit that applies on your host. TiffinDB sets no quota per person or project; a project's settings show its number of stored files and their total size.

## Imports

| Limit | Value |
|---|---|
| Rows saved per import | 50,000 |
| Rows read from the source per import, empty rows included | 100,000 |
| Columns per table | 200 |
| Characters per cell | 20,000 |
| One CSV record, line breaks inside quotes included | 1 MiB |
| New records one import may add to linked tables, for titles not found there | 10,000 |
| XLSX files (zip contents) | at most 10,000 entries, 256 MiB per entry and 512 MiB in all when unpacked, and a compression ratio of at most 200 |

An import is read in steps of at most 2,000 rows or about 5 seconds each, one request per step, so a large file does not need a long PHP time limit for reading. Saving the rows into the project happens afterwards in one request, as one transaction, after an `import` snapshot.

## Project archives

| Limit | Value |
|---|---|
| Upload | 64 MiB, or the PHP limits (see [Backup and restore](backup-and-restore.md#restoring-a-large-archive-by-hand) for larger archives) |
| Entries in the zip | 100,000 |
| `project.tiffin` in the archive, unpacked | 1 GiB |
| All entries, unpacked | 4 GiB |

## Lists and pages

Record lists, the relation picker, and the activity log show 50 entries per page. A search term is at most 200 characters. A project holds at most 50 saved collections, each with at most 10 rules.

## What the host's PHP settings change

- `upload_max_filesize` and `post_max_size` lower every upload limit above. The host check reports both.
- `memory_limit` and `max_execution_time` limit how large an export, an archive, or the saving step of an import can be before PHP stops the request. TiffinDB does not change either setting. The host check reports them, and warns below 128M and 30 seconds.
- Free disk space and the host's quota limit uploads, snapshots, and exports. The host check's `fs.free` line shows free space; it cannot read a quota.

## Slow cases

These have not been measured on a shared host; they may take longer there:

- XLSX imports: a step late in a large workbook can take several seconds, because reading resumes from a position deep in the file (over 5 seconds per step for the largest test workbooks on the build machine).
- Saving a large import, and importing a large project archive (TiffinDB rebuilds the project from the archive in one request).
- Restoring a snapshot or deleting a project waits up to 10 seconds for requests that have the project open, then asks you to try again.
