<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

/**
 * Review warnings (M3 spec section 6.4, P120): over the first $rows stored
 * rows, per included source column the values that will be left empty,
 * and per link column the distinct titles not found (which the import will
 * add) and the distinct titles the target's title field refuses (which
 * will be dropped). Counts only, never values.
 */
final class PreviewResult
{
    /**
     * @param array<int, int> $emptied column => count
     * @param array<int, int> $linksAdded column => distinct titles not found
     * @param array<int, int> $linksRefused column => distinct titles refused
     */
    public function __construct(
        public readonly int $rows,
        public readonly bool $partial,
        public readonly array $emptied,
        public readonly array $linksAdded,
        public readonly array $linksRefused,
        public readonly int $importCount,
    ) {
    }
}
