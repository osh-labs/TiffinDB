<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\TableDef;

/** The trashed records of one table on the trash screen (M4 spec section 10.5). */
final class TrashSection
{
    /** @param list<TrashedRecord> $records */
    public function __construct(
        public readonly TableDef $table,
        public readonly int $count,
        public readonly array $records,
        public readonly int $page,
        public readonly int $pages,
    ) {
    }
}
