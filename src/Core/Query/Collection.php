<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** One saved collection as stored in `_tiffin_collections` (`docs/specs/M4-core.md` section 6.1). */
final class Collection
{
    /** @param list<CollectionRule> $rules */
    public function __construct(
        public readonly string $key,
        public readonly string $tableKey,
        public readonly string $name,
        public readonly MatchMode $match,
        public readonly array $rules,
        public readonly ?string $sort,
        public readonly int $position,
        public readonly string $createdAt,
        public readonly ?string $createdBy,
        public readonly string $updatedAt,
        public readonly ?string $updatedBy,
    ) {
    }
}
