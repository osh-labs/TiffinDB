<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\System\ProjectRecord;

/** The trash screen of a project (M4 spec section 10.5, P168). */
final class TrashScreen
{
    /** @param list<TrashSection> $sections */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly array $sections,
        public readonly int $total,
        public readonly bool $canRestore,
        public readonly bool $canEmpty,
    ) {
    }
}
