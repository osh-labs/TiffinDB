<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * Where a new record is being added from (M4 spec section 10.1, P154):
 * the record and relation field whose picker offered "Add a new ...", the
 * record's title, the item name of its table, and whether that side
 * allows one link only.
 */
final class LinkOrigin
{
    public function __construct(
        public readonly string $tableKey,
        public readonly string $recordId,
        public readonly string $fieldKey,
        public readonly ?string $recordTitle,
        public readonly string $itemName,
        public readonly bool $onlyOne,
    ) {
    }
}
