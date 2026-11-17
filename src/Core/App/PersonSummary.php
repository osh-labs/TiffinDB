<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\UserRecord;

/** A person on the people list, with the number of active projects they can use (M1 spec section 9.3). */
final class PersonSummary
{
    public function __construct(
        public readonly UserRecord $person,
        public readonly int $projectCount,
    ) {
    }
}
