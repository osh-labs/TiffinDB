<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\FieldDef;

/**
 * The filter a list applies: the field, the operator ('is', 'empty',
 * 'between', 'links-to'), its operands, and for links-to the title of the
 * linked record (null when it has none).
 */
final class ActiveFilter
{
    /** @param list<string> $operands */
    public function __construct(
        public readonly FieldDef $field,
        public readonly string $operator,
        public readonly array $operands,
        public readonly ?string $linkTitle,
    ) {
    }
}
