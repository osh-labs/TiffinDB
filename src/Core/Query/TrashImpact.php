<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * What emptying the trash would delete (M4 spec section 4.5): records in
 * the trash per table key, their total, and the stored files that would go
 * with them.
 */
final class TrashImpact
{
    /** @param array<string, int> $records table key => records in the trash */
    public function __construct(
        public readonly array $records,
        public readonly int $total,
        public readonly int $files,
    ) {
    }
}
