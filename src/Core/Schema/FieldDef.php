<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

/** One field, as `_tiffin_fields` describes it (M1 spec section 7.3). */
final class FieldDef
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string $key,
        public readonly string $tableKey,
        public readonly string $name,
        public readonly string $type,
        public readonly array $options,
        public readonly bool $required,
        public readonly string $help,
        public readonly int $position,
        public readonly bool $hasColumn,
    ) {
    }

    /** The quoted SQL name of the field's column; \LogicException for a field without one. */
    public function sql(): string
    {
        if (!$this->hasColumn) {
            throw new \LogicException('This field has no column.');
        }

        return Identifier::column($this->key);
    }
}
