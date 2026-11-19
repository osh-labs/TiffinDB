<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

/** Import, export, and archive limits (M3 spec section 6.8, P116, P139). Constants only. */
final class IoLimits
{
    public const MAX_UPLOAD_BYTES = 67108864;
    public const MAX_ROWS = 50000;
    public const MAX_COLUMNS = 200;
    public const MAX_CELL_CHARS = 20000;
    public const CHUNK_ROWS = 2000;
    public const CHUNK_SECONDS = 5;
    public const MAX_SOURCE_ROWS = 100000;
    public const PREVIEW_ROWS = 5000;
    public const MAX_CSV_RECORD_BYTES = 1048576;
    public const XLSX_MAX_ENTRIES = 10000;
    public const XLSX_MAX_ENTRY_BYTES = 268435456;
    public const XLSX_MAX_TOTAL_BYTES = 536870912;
    public const XLSX_MAX_RATIO = 200;
    public const ARCHIVE_MAX_ENTRIES = 100000;
    public const ARCHIVE_MAX_PROJECT_BYTES = 1073741824;
    public const ARCHIVE_MAX_TOTAL_BYTES = 4294967296;
    /** Free disk space kept back at upload time (section 6.8). */
    public const DISK_RESERVE_BYTES = 104857600;
    /** New records one import may add to linked tables for titles not found there (P142). */
    public const MAX_NEW_LINK_RECORDS = 10000;
    /** First row numbers kept per column in the import report (format section 4.7.1). */
    public const REPORT_ROWS = 20;

    private function __construct()
    {
    }
}
