<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** The collection a record list shows (M4 spec section 10.4). */
final class CollectionFacts
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly int $broken,
        public readonly bool $canManage,
    ) {
    }
}
