<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** The archive import page (M3 spec section 13.4). */
final class ArchiveImportScreen
{
    public function __construct(public readonly int $maxBytes)
    {
    }
}
