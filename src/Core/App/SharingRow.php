<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\UserRecord;

/** One Member, active or not, and whether they hold a grant on the project (M1 spec section 9.4). */
final class SharingRow
{
    public function __construct(
        public readonly UserRecord $member,
        public readonly bool $granted,
    ) {
    }
}
