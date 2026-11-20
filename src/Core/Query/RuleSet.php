<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * Resolved, query-ready conditions joined by one match mode
 * (`docs/specs/M4-core.md` section 5.1): 1 to 10 conditions in query form.
 * ListSql checks each condition against the table when it builds SQL.
 */
final class RuleSet
{
    public const MAX_CONDITIONS = 10;

    /** @var list<Condition> */
    public readonly array $conditions;

    /** @param list<Condition> $conditions */
    public function __construct(public readonly MatchMode $match, array $conditions)
    {
        if (!array_is_list($conditions) || $conditions === [] || count($conditions) > self::MAX_CONDITIONS) {
            throw new \InvalidArgumentException('A rule group holds 1 to 10 conditions.');
        }
        foreach ($conditions as $condition) {
            if (!$condition instanceof Condition) {
                throw new \InvalidArgumentException('A rule set holds conditions only.');
            }
        }
        $this->conditions = $conditions;
    }
}
