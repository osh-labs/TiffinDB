<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Io\Archive\RebuildReport;
use TiffinDB\Core\System\ProjectRecord;

/** A project made from an archive (M3 spec section 13.4). */
final class ArchiveImportResult
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly RebuildReport $report,
    ) {
    }
}
