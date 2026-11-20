<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * A valid list request: order, at most one filter condition, search text,
 * page (`docs/specs/M2-records.md` section 3.6), and the rules of a
 * collection when one is listed (`docs/specs/M4-core.md` section 5.1).
 */
final class ListQuery
{
    public function __construct(
        public readonly Sort $sort,
        public readonly ?Condition $filter,
        public readonly ?string $search,
        public readonly int $page,
        public readonly ?RuleSet $rules = null,
    ) {
        if ($page < 1) {
            throw new \InvalidArgumentException('Pages start at 1.');
        }
    }
}
