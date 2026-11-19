<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A `br`. */
final class LineBreak implements Inline
{
    public function kind(): string
    {
        return 'br';
    }
}
