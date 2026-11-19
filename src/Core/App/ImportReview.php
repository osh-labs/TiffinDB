<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\System\ProjectRecord;

/** The review step of an import (M3 spec section 13.1). */
final class ImportReview
{
    /**
     * @param list<TableSummary> $tables for the existing-table menu
     * @param list<ReviewColumn> $columns
     * @param list<ReviewWarning> $warnings
     * @param list<Problem> $problems
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly string $jobKey,
        public readonly string $fileName,
        public readonly string $readAs,
        public readonly int $rows,
        public readonly bool $headingRow,
        public readonly int $columnCount,
        public readonly bool $overLimit,
        public readonly int $replacedCharacters,
        public readonly string $target,
        public readonly ?string $newTableName,
        public readonly ?string $tableKey,
        public readonly array $tables,
        public readonly array $columns,
        public readonly array $warnings,
        public readonly array $problems,
        public readonly int $importCount,
        public readonly bool $previewPartial,
    ) {
    }
}
