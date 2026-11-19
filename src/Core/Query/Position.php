<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** Where a record stands in a list: 1-based index, list size, and its neighbours' ids. */
final class Position
{
    public function __construct(
        public readonly int $index,
        public readonly int $total,
        public readonly ?int $previous,
        public readonly ?int $next,
    ) {
    }
}
