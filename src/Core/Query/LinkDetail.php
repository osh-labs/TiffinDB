<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Schema\FieldDef;

/**
 * The secondary line of a linked-record row (P158): the linked table's
 * first list-column field other than its title, and the record's value
 * there as the field type's canonical value (never null).
 */
final class LinkDetail
{
    public function __construct(
        public readonly FieldDef $field,
        public readonly mixed $value,
    ) {
    }
}
