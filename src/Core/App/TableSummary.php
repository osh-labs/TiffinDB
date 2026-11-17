<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\TableDef;

/** A table on the tables list: its field count and live record count (M1 spec section 9.5). */
final class TableSummary
{
    public function __construct(
        public readonly TableDef $table,
        public readonly int $fieldCount,
        public readonly int $recordCount,
    ) {
    }
}
