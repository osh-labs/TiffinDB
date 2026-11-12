<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

/** What a schema change does to stored values (M1 spec section 7.4): records affected and values cleared. */
final class Impact
{
    public function __construct(public readonly int $records, public readonly int $values)
    {
    }
}
