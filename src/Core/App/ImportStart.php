<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\System\ProjectRecord;

/** The first import step (M3 spec section 13.1). */
final class ImportStart
{
    /**
     * @param list<PluginChoice> $formats enabled import plugins, by id
     * @param int $maxBytes the core's upload limit (IoLimits::MAX_UPLOAD_BYTES); the web layer lowers it to PHP's limits
     * @param list<TableSummary> $tables
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly array $formats,
        public readonly int $maxBytes,
        public readonly array $tables,
    ) {
    }
}
