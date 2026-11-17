<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

use TiffinDB\Core\Schema\Type\FieldType;

/**
 * The column definition SQL of a field type (`docs/formats/tiffin.md`
 * section 6), as one of a fixed list of string literals. The text a type
 * returns is matched exactly against that list and the literal is used, so
 * nothing a type class returns is ever placed in SQL unchecked.
 *
 * @internal Private helper of the schema engine.
 */
final class ColumnDefinition
{
    private function __construct()
    {
    }

    /** The column definition, or \LogicException for a type without a column or with an unknown definition. */
    public static function of(FieldType $type): string
    {
        return match ($type->columnDefinition()) {
            'TEXT' => 'TEXT',
            'NUMERIC' => 'NUMERIC',
            'INTEGER' => 'INTEGER',
            'INTEGER NOT NULL DEFAULT 0' => 'INTEGER NOT NULL DEFAULT 0',
            default => throw new \LogicException('The field type has no known column definition.'),
        };
    }
}
