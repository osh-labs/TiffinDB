<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\TableDef;

/** What restoring every record of a table's trash did (M4 spec section 10.5). */
final class RestoredAll
{
    public function __construct(public readonly TableDef $table, public readonly int $count)
    {
    }
}
