<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** One exported table with its fields by position (M3 spec section 3.3). */
final class ExportTable
{
    /** @param list<ExportField> $fields */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $itemName,
        public readonly ?string $titleField,
        public readonly array $fields,
        public readonly int $recordCount,
    ) {
    }
}
