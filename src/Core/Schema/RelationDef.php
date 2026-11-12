<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

/**
 * One relation, as `_tiffin_relations` describes it (M1 spec section 7.3,
 * `docs/formats/tiffin.md` section 7). `leftMax` is how many right records
 * one left record may link to; `rightMax` the same the other way.
 */
final class RelationDef
{
    public function __construct(
        public readonly string $key,
        public readonly string $leftTable,
        public readonly string $leftField,
        public readonly Cardinality $leftMax,
        public readonly string $rightTable,
        public readonly string $rightField,
        public readonly Cardinality $rightMax,
    ) {
    }

    /** The quoted SQL name of the join table. */
    public function sql(): string
    {
        return Identifier::joinTable($this->key);
    }

    /** 'left' or 'right'; \InvalidArgumentException for a field that is neither side. */
    public function sideOf(string $fieldKey): string
    {
        return match ($fieldKey) {
            $this->leftField => 'left',
            $this->rightField => 'right',
            default => throw new \InvalidArgumentException('The field is not a side of this relation.'),
        };
    }

    public function otherField(string $fieldKey): string
    {
        return $this->sideOf($fieldKey) === 'left' ? $this->rightField : $this->leftField;
    }

    public function otherTable(string $fieldKey): string
    {
        return $this->sideOf($fieldKey) === 'left' ? $this->rightTable : $this->leftTable;
    }

    /** How many records the record holding this field may link to. */
    public function maxFor(string $fieldKey): Cardinality
    {
        return $this->sideOf($fieldKey) === 'left' ? $this->leftMax : $this->rightMax;
    }
}
