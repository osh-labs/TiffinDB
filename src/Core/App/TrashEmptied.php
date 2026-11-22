<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** What emptying the trash deleted: records, and stored files (M4 spec section 10.5, P177). */
final class TrashEmptied
{
    public function __construct(public readonly int $records, public readonly int $files)
    {
    }
}
