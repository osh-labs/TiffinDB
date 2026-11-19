<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\Problem;

/** Records::query(): the valid query, and the problems of the parts it dropped. */
final class NormalisedQuery
{
    public function __construct(
        public readonly ListQuery $query,
        public readonly ?Problem $filterProblem,
        public readonly ?Problem $searchProblem,
    ) {
    }
}
