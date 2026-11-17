<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;

/**
 * A field in a table design (M1 spec section 9.5). For a relation field,
 * the name of the linked table and how many records this field's record
 * may link to; null for every other field.
 */
final class FieldSummary
{
    public function __construct(
        public readonly FieldDef $field,
        public readonly ?string $linkedTableName,
        public readonly ?Cardinality $links,
    ) {
    }
}
