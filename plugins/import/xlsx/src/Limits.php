<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

/**
 * The limits of the XLSX import plugin. The first group repeats the core's
 * IoLimits values of M3 spec section 6.8 (a plugin may not name core
 * classes other than the contract); the second group is this plugin's own
 * defences (spec section 9; WP5 notes).
 *
 * @internal
 */
final class Limits
{
    // Spec section 6.8 (IoLimits): zip directory checks of section 9.1.
    public const MAX_ENTRIES = 10000;
    public const MAX_ENTRY_BYTES = 268435456;
    public const MAX_TOTAL_BYTES = 536870912;
    public const MAX_RATIO = 200;
    /** Entries at most this large are not held to MAX_RATIO. */
    public const RATIO_FREE_BYTES = 1048576;

    // Spec section 6.8 (IoLimits): rows and columns.
    public const MAX_COLUMNS = 200;
    public const CHUNK_ROWS = 2000;
    public const MAX_SOURCE_ROWS = 100000;
    /** Rows read after the heading row to find the column count (spec 9.2). */
    public const WIDTH_ROWS = 1000;

    // This plugin's own defences.
    /** Bytes of an XML part read for the prolog check (spec 9.1). */
    public const PROLOG_BYTES = 4096;
    /** Bytes read at a time when an entry's real size is counted. */
    public const READ_CHUNK = 65536;
    /** Worksheets a workbook may list. */
    public const MAX_SHEETS = 255;
    /** Strings in the shared strings part, and their bytes in memory. */
    public const MAX_SHARED_STRINGS = 2000000;
    public const MAX_SHARED_BYTES = 33554432;
    /**
     * Bytes kept of one string: more than 20,000 characters (the core's
     * MAX_CELL_CHARS) at four bytes each, so a longer value still reaches
     * the core as too long and is reported there.
     */
    public const MAX_STRING_BYTES = 80004;
    /** Cell formats (`cellXfs`) read from the styles part (Excel allows 64,000). */
    public const MAX_FORMATS = 65536;
    /** Custom number formats read, and the longest format code classified (Excel's 255). */
    public const MAX_NUMBER_FORMATS = 1024;
    public const MAX_FORMAT_CODE = 255;

    // Work limits: XML reads (Xml::read and Xml::next) per phase of one call.
    /** Package and workbook relationships, the workbook part, content types. */
    public const STEPS_STRUCTURE = 1000000;
    /** The styles part (cell formats and number formats). */
    public const STEPS_STYLES = 1000000;
    /** The shared strings part: enough for MAX_SHARED_STRINGS rich strings; once per job. */
    public const STEPS_SHARED = 12000000;
    /** The column scans of one call (all sheets of an inspection that are not cached). */
    public const STEPS_COLUMNS = 8000000;
    /** One rows() call: skipping to `from` plus up to CHUNK_ROWS rows of up to 200 columns. */
    public const STEPS_ROWS = 6000000;

    // The job cache (JobCache) in the job's temporary folder.
    /** Largest cache file read back. */
    public const MAX_CACHE_BYTES = 8388608;
    /** The largest row number and column index of the format (Excel's XFD1048576). */
    public const MAX_SHEET_ROW = 1048576;
    public const MAX_SHEET_COLUMN = 16384;

    private function __construct()
    {
    }
}
