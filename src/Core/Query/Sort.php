<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Storage\Keys;

/** One list order: a field key, 'added', or 'changed' (`docs/specs/M2-records.md` section 3.6). */
final class Sort
{
    public function __construct(public readonly string $by, public readonly bool $descending)
    {
        if ($by !== 'added' && $by !== 'changed' && !Keys::isValid($by)) {
            throw new \InvalidArgumentException('A sort is by a field key, added, or changed.');
        }
        if ($by === 'changed' && !$descending) {
            throw new \InvalidArgumentException('Recently changed sorts newest first only.');
        }
    }

    /** '<by>.asc' or '<by>.desc', as in the query string. */
    public function token(): string
    {
        return $this->by . ($this->descending ? '.desc' : '.asc');
    }
}
