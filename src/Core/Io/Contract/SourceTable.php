<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** One table of a source file: a sheet, or the one table of a CSV (M3 spec section 3.2). */
final class SourceTable
{
    public const MAX_COLUMNS = 200;
    public const MAX_HEADING = 20000;

    /** @param list<string> $columns headings ("Column n" when the file has none), 1 to 200 */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly ?int $rowCount,
        public readonly bool $hidden = false,
    ) {
        if (!Values::isText($name, 0, 255)) {
            throw new \InvalidArgumentException('A table name is at most 255 characters.');
        }
        if (!array_is_list($columns) || $columns === [] || count($columns) > self::MAX_COLUMNS) {
            throw new \InvalidArgumentException('A source table has 1 to 200 columns.');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || !Values::isText($column, 0, self::MAX_HEADING, true)) {
                throw new \InvalidArgumentException('Column headings are text.');
            }
        }
        if ($rowCount !== null && $rowCount < 0) {
            throw new \InvalidArgumentException('The row count is 0 or more.');
        }
    }
}
