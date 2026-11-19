<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** An import plugin (M3 spec section 3.2, contract API 1). Expected failures are PluginFailure. */
interface Importer
{
    /** Reading options with values detected from the file (for CSV: encoding, delimiter, heading row). */
    public function detect(SourceFile $file): Settings;

    /** What the file holds when read with $settings. Reads no more than needed. */
    public function inspect(SourceFile $file, Settings $settings): Inspection;

    /**
     * Data rows of table $table (0-based index into Inspection::$tables),
     * starting at data row $from (0-based, heading row not counted), at most
     * $limit rows. Each row is a list of exactly count(columns) cells; a cell
     * is a string or null (empty). Rows past the end are not returned.
     *
     * @return iterable<int, list<?string>>
     */
    public function rows(SourceFile $file, Settings $settings, int $table, int $from, int $limit): iterable;
}
