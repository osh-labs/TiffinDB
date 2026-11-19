<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/** One page of a table's record list with its view state (M2 spec section 7.2). */
final class RecordListing
{
    /**
     * @param list<TableSummary> $tables for the sidebar
     * @param list<FieldDef> $columns the title field (when set), then up to six more fields, skipping long text
     * @param list<ListRow> $rows
     * @param list<SortOption> $sortOptions
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly array $tables,
        public readonly int $trashCount,
        public readonly array $columns,
        public readonly array $rows,
        public readonly int $total,
        public readonly int $tableTotal,
        public readonly int $page,
        public readonly int $pages,
        public readonly int $from,
        public readonly int $to,
        public readonly array $sortOptions,
        public readonly string $sort,
        public readonly ?ActiveFilter $filter,
        public readonly ?Problem $filterProblem,
        public readonly ?Problem $searchProblem,
        public readonly ?string $search,
        public readonly ListRequest $request,
        public readonly bool $hasFields,
        public readonly bool $hasFilterable,
        public readonly bool $canChangeSchema,
    ) {
    }
}
