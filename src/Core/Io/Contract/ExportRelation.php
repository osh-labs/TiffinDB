<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** One relation whose both tables are in scope (M3 spec section 3.3). */
final class ExportRelation
{
    public function __construct(
        public readonly string $key,
        public readonly string $leftTable,
        public readonly string $leftField,
        public readonly string $leftMax,
        public readonly string $rightTable,
        public readonly string $rightField,
        public readonly string $rightMax,
    ) {
    }
}
