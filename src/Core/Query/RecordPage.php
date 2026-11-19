<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** One page of a list (`docs/specs/M2-records.md` section 3.7). */
final class RecordPage
{
    /** @param list<RecordData> $records */
    public function __construct(
        public readonly array $records,
        /** Live records matching the filter and search. */
        public readonly int $total,
        /** Live records in the table. */
        public readonly int $tableTotal,
        /** After clamping to 1..pages. */
        public readonly int $page,
        /** max(1, ceil(total / PAGE_SIZE)). */
        public readonly int $pages,
        public readonly Sort $sort,
    ) {
    }
}
