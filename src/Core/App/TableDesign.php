<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\TableDef;

/** A table with its fields by position, its live record count, and its trash count (M1 spec section 9.5). */
final class TableDesign
{
    /** @param list<FieldSummary> $fields */
    public function __construct(
        public readonly TableDef $table,
        public readonly array $fields,
        public readonly int $recordCount,
        public readonly int $trashedCount,
    ) {
    }
}
