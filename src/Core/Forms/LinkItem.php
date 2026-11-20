<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Schema\FieldDef;

/**
 * A linked record shown on a relation field: its table, id, and title
 * (null when it has none). On a many-link field, the secondary line of its
 * row (P158): the detail field and its value, copied by Core\App from
 * Query's LinkDetail (Core\Forms does not use Core\Query); both null for
 * none.
 */
final class LinkItem
{
    public function __construct(
        public readonly string $tableKey,
        public readonly int $id,
        public readonly ?string $title,
        public readonly ?FieldDef $detailField = null,
        public readonly mixed $detailValue = null,
    ) {
    }
}
