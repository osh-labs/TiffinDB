<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * A warning on the review page (M3 spec section 13.1). Kinds and their
 * parameters: `unreadable` (column, count, type), `not_a_choice` (column,
 * count, field), `too_long` (column, count), `links_added` (column, table,
 * items, count), `link_unreadable` (column, table, count), `extra_cells`
 * (count), `replaced` (count), `over_limit` (max), `preview_partial`
 * (count). Never a cell value.
 */
final class ReviewWarning
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly string $kind,
        public readonly array $params,
    ) {
    }
}
