<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * A saved collection for the sidebar and lists (M4 spec section 10.4):
 * live records matching its working rules, and how many rules are broken.
 */
final class CollectionSummary
{
    public function __construct(
        public readonly string $key,
        public readonly string $tableKey,
        public readonly string $name,
        public readonly int $count,
        public readonly int $broken,
    ) {
    }
}
