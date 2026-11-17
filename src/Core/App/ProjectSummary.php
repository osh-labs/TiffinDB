<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\System\ProjectRecord;

/**
 * A project on the project list (M1 spec section 9.4). When its file
 * cannot be opened, `available` is false and the file-derived values are
 * empty (no tables, no records, no last change).
 */
final class ProjectSummary
{
    /** @param list<string> $tableNames by position */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly bool $available,
        public readonly array $tableNames,
        public readonly int $recordCount,
        public readonly ?string $updatedAt,
        public readonly ?string $updatedByName,
        public readonly int $memberCount,
    ) {
    }
}
