<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** An edit conflict: the saved record with its current version, and the posted fields that differ from it. */
final class ConflictView
{
    /** @param list<ConflictRow> $rows */
    public function __construct(
        public readonly RecordScreen $screen,
        public readonly array $rows,
    ) {
    }
}
