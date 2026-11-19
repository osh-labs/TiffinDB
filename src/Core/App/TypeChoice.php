<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * One entry of a column's type menu on the review page: a field type id,
 * or `link:<table key>` with the linked table's name.
 */
final class TypeChoice
{
    public function __construct(
        public readonly string $value,
        public readonly ?string $tableName = null,
    ) {
    }
}
