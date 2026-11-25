<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** A finished project deletion, with what it removed (M7 spec section 6.1). */
final class ProjectDeleted
{
    public function __construct(
        public readonly string $name,
        public readonly int $records,
        public readonly int $trashed,
        public readonly int $files,
        public readonly int $snapshots,
    ) {
    }
}
