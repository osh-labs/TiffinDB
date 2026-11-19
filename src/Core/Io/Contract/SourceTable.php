<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/**
 * One table of a source file: a sheet, or the one table of a CSV (M3 spec
 * section 3.2). Names and headings are the file's own text, taken as
 * given (bounded in size only): the core cleans them where it uses them
 * (Inference::fieldName, MappingCheck::defaultTableName).
 */
final class SourceTable
{
    public const MAX_COLUMNS = 200;
    /** Four bytes per character of the longest cell (20,000 characters). */
    public const MAX_HEADING_BYTES = 80000;
    public const MAX_NAME_BYTES = 1024;

    /** @param list<string> $columns headings ("Column n" when the file has none), 1 to 200 */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly ?int $rowCount,
        public readonly bool $hidden = false,
    ) {
        if (strlen($name) > self::MAX_NAME_BYTES) {
            throw new \InvalidArgumentException('A table name is at most 1,024 bytes.');
        }
        if (!array_is_list($columns) || $columns === [] || count($columns) > self::MAX_COLUMNS) {
            throw new \InvalidArgumentException('A source table has 1 to 200 columns.');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || strlen($column) > self::MAX_HEADING_BYTES) {
                throw new \InvalidArgumentException('Column headings are strings of at most 80,000 bytes.');
            }
        }
        if ($rowCount !== null && $rowCount < 0) {
            throw new \InvalidArgumentException('The row count is 0 or more.');
        }
    }
}
