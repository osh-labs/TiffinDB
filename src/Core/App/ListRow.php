<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** One row of a record list (M2 spec section 7.2): the record id, its title text, and a cell per column. */
final class ListRow
{
    /** @param array<string, Cell> $cells field key => cell, in column order */
    public function __construct(
        public readonly int $id,
        public readonly ?string $title,
        public readonly array $cells,
    ) {
    }
}
