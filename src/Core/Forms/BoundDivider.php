<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A divider of a bound form. */
final class BoundDivider implements BoundNode
{
    public function kind(): string
    {
        return 'divider';
    }
}
