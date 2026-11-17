<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\System\ProjectRecord;

/** One active project and whether a person can use it (M1 spec section 9.3). */
final class ProjectAccess
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly bool $granted,
    ) {
    }
}
