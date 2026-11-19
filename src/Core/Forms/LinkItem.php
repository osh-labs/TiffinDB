<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A linked record shown on a relation field: its table, id, and title (null when it has none). */
final class LinkItem
{
    public function __construct(
        public readonly string $tableKey,
        public readonly int $id,
        public readonly ?string $title,
    ) {
    }
}
