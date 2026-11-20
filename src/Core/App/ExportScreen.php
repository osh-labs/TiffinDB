<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\System\ProjectRecord;

/**
 * The export page (M3 spec section 13.2). `$projectBytes` is about the
 * size of the project archive (M4 CF-5): the project file plus the
 * regular files under its `attachments/<2>/` folders.
 */
final class ExportScreen
{
    /**
     * @param list<TableSummary> $tables
     * @param list<ExportFormat> $formats CSV, Excel workbook, JSON, then others by name
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly array $tables,
        public readonly array $formats,
        public readonly bool $archive = true,
        public readonly int $projectBytes = 0,
    ) {
    }
}
