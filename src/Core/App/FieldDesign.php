<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\RelationDef;

/**
 * One field for its editor (M1 spec section 9.5): its relation and linked
 * table for a relation field, the types it can be changed to, and how many
 * records hold a value in it (live and trashed).
 */
final class FieldDesign
{
    /** @param list<string> $convertibleTo type ids, in FieldTypes order */
    public function __construct(
        public readonly FieldDef $field,
        public readonly ?RelationDef $relation,
        public readonly ?string $linkedTableName,
        public readonly array $convertibleTo,
        public readonly int $valueCount,
    ) {
    }
}
