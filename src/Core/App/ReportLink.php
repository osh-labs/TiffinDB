<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** Records an import added to a linked table; $tableName is null when the table has been deleted since. */
final class ReportLink
{
    public function __construct(
        public readonly string $fieldKey,
        public readonly ?string $fieldName,
        public readonly string $tableKey,
        public readonly ?string $tableName,
        public readonly int $added,
    ) {
    }
}
