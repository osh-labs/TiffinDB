<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Forms\LinkItem;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;

/**
 * What the filter page offers (M2 spec section 7.2): the filterable fields
 * in field order, the chosen one, its choices or the records it can link
 * to (at most Records::PICKER_LIMIT; $more when there are others), and the
 * project's tables.
 */
final class FilterOptions
{
    /**
     * @param list<FieldDef> $fields
     * @param list<string> $choices
     * @param list<LinkItem> $records
     * @param list<TableSummary> $tables
     */
    public function __construct(
        public readonly TableDef $table,
        public readonly array $fields,
        public readonly ?FieldDef $field,
        public readonly array $choices,
        public readonly array $records,
        public readonly bool $more,
        public readonly array $tables,
    ) {
    }
}
