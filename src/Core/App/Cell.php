<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Forms\LinkItem;
use TiffinDB\Core\Schema\FieldDef;

/**
 * One cell of a record list: the field, its canonical value (null when
 * empty, and for relation and attachment fields), up to three linked
 * records with the count of the rest, and the number of files.
 */
final class Cell
{
    public const MAX_LINKS = 3;

    /** @param list<LinkItem> $links */
    public function __construct(
        public readonly FieldDef $field,
        public readonly mixed $value,
        public readonly array $links,
        public readonly int $moreLinks,
        public readonly int $fileCount,
    ) {
    }
}
