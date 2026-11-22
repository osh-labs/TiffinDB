<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * A collection's rules checked against the table as it is now
 * (`docs/specs/M4-core.md` section 6.3): the working rules as a RuleSet
 * (null when none works), each stored rule's state in stored order, how
 * many are broken, and the resolved sort (null for the default order).
 */
final class ResolvedRules
{
    /** @param list<RuleState> $states */
    public function __construct(
        public readonly ?RuleSet $set,
        public readonly array $states,
        public readonly int $broken,
        public readonly ?Sort $sort,
    ) {
    }
}
