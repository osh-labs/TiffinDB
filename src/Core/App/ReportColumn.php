<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** A field with values left empty, in an import report (format section 4.7.1); row numbers, never values. */
final class ReportColumn
{
    /** @param list<int> $rows the first 20 row numbers */
    public function __construct(
        public readonly string $fieldKey,
        public readonly string $fieldName,
        public readonly int $count,
        public readonly array $rows,
    ) {
    }
}
