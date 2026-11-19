<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** The matches of one table in a project search. */
final class SearchGroup
{
    /** @param list<SearchHit> $hits at most Records::SEARCH_HITS_PER_TABLE, in the table's default order */
    public function __construct(
        public readonly string $tableKey,
        public readonly int $total,
        public readonly array $hits,
    ) {
    }
}
