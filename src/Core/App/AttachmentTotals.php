<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** Distinct stored files of a project and their total size in bytes (M4 spec section 10.3). */
final class AttachmentTotals
{
    public function __construct(public readonly int $files, public readonly int $bytes)
    {
    }
}
