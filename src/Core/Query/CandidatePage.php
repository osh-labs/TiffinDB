<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\Problem;

/**
 * One page of the relation picker (M4 spec section 4.4): the record's
 * live links in link order, the candidates of this page, their total,
 * the page (clamped) and page count, how many links point to records in
 * the trash, whether this side allows one link, the search text used (or
 * null), and the problem that refused a search (the list is then
 * unfiltered).
 */
final class CandidatePage
{
    /**
     * @param list<LinkedRecord> $linked
     * @param list<Candidate> $candidates
     */
    public function __construct(
        public readonly array $linked,
        public readonly array $candidates,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pages,
        public readonly int $trashedLinks,
        public readonly bool $onlyOne,
        public readonly ?string $search,
        public readonly ?Problem $searchProblem,
    ) {
    }
}
