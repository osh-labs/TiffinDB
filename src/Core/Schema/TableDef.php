<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

/** One user table, as `_tiffin_tables` describes it (M1 spec section 7.3). */
final class TableDef
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $itemName,
        public readonly int $position,
        public readonly ?string $titleFieldKey,
    ) {
    }

    /** The quoted SQL name of the table. */
    public function sql(): string
    {
        return Identifier::table($this->key);
    }
}
