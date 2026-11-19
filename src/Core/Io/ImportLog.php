<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;

/** Reads and writes `_tiffin_import_log` (`docs/formats/tiffin.md` section 4.7; M3 spec section 6.7). */
final class ImportLog
{
    private function __construct()
    {
    }

    /** Inserts one row; the caller holds the project write transaction. */
    public static function insert(Database $db, ImportReport $report): void
    {
        if (!Keys::isValid($report->key)) {
            throw new \InvalidArgumentException('An import log key is a key.');
        }
        $db->run(
            'INSERT INTO "_tiffin_import_log" ("key", "imported_at", "imported_by", "source", "plugin", "file_name", "file_size",'
            . ' "file_sha256", "table_key", "new_table", "rows_read", "rows_imported", "rows_skipped", "values_emptied", "links_added",'
            . ' "snapshot", "report") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $report->key, $report->importedAt, $report->importedBy, $report->source, $report->plugin, $report->fileName,
                $report->fileSize, $report->fileSha256, $report->tableKey, $report->newTable ? 1 : 0, $report->rowsRead,
                $report->rowsImported, $report->rowsSkipped, $report->valuesEmptied, $report->linksAdded, $report->snapshot,
                $report->reportJson(),
            ],
        );
    }

    /** Whether a row with this key exists. */
    public static function has(Database $db, string $key): bool
    {
        return (int) $db->value('SELECT COUNT(*) FROM "_tiffin_import_log" WHERE "key" = ?', [$key]) > 0;
    }

    /** The row with this key, or null (also for an invalid key). */
    public static function get(Database $db, string $key): ?ImportReport
    {
        if (!Keys::isValid($key)) {
            return null;
        }
        $row = $db->one(
            'SELECT "key", "imported_at", "imported_by", "source", "plugin", "file_name", "file_size", "file_sha256", "table_key",'
            . ' "new_table", "rows_read", "rows_imported", "rows_skipped", "values_emptied", "links_added", "snapshot", "report"'
            . ' FROM "_tiffin_import_log" WHERE "key" = ?',
            [$key],
        );
        if ($row === null) {
            return null;
        }
        $report = json_decode(is_string($row['report']) ? $row['report'] : '{}', true, 32, JSON_THROW_ON_ERROR);

        return new ImportReport(
            (string) $row['key'],
            (string) $row['imported_at'],
            is_string($row['imported_by']) ? $row['imported_by'] : null,
            (string) $row['source'],
            is_string($row['plugin']) ? $row['plugin'] : null,
            (string) $row['file_name'],
            (int) $row['file_size'],
            (string) $row['file_sha256'],
            is_string($row['table_key']) ? $row['table_key'] : null,
            (int) $row['new_table'] === 1,
            (int) $row['rows_read'],
            (int) $row['rows_imported'],
            (int) $row['rows_skipped'],
            (int) $row['values_emptied'],
            (int) $row['links_added'],
            is_string($row['snapshot']) ? $row['snapshot'] : null,
            is_array($report) ? $report : [],
        );
    }
}
