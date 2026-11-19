<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\System\ProjectRecord;

/** A search across the tables of one project (M2 spec section 7.2). */
final class SearchResults
{
    /**
     * @param list<SearchResultGroup> $groups
     * @param list<TableSummary> $tables for the sidebar
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly ?string $search,
        public readonly ?Problem $problem,
        public readonly int $total,
        public readonly array $groups,
        public readonly array $tables,
        public readonly int $trashCount,
    ) {
    }
}
