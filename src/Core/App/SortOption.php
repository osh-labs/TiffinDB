<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\FieldDef;

/** One entry of the sort menu: its token, the field (null for added and changed), its kind, and its direction. */
final class SortOption
{
    public function __construct(
        public readonly string $token,
        public readonly ?FieldDef $field,
        public readonly string $kind,
        public readonly bool $descending,
    ) {
        if (!in_array($kind, ['field', 'added', 'changed'], true)) {
            throw new \InvalidArgumentException('A sort kind is field, added, or changed.');
        }
    }
}
