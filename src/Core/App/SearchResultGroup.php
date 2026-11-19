<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\TableDef;

/** The matches in one table: their count and the first few hits. */
final class SearchResultGroup
{
    /** @param list<SearchResultHit> $hits */
    public function __construct(
        public readonly TableDef $table,
        public readonly int $total,
        public readonly array $hits,
    ) {
    }
}
