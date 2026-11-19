<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Io\Contract\Setting;
use TiffinDB\Core\Io\Contract\SourceTable;
use TiffinDB\Core\System\ProjectRecord;

/**
 * The reading options of an import job (M3 spec section 13.1). Labels,
 * help, and `readAs` are the plugin's plain text (P133).
 */
final class ReadingScreen
{
    /**
     * @param list<Setting> $settings with the job's current values
     * @param list<SourceTable> $tables
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly string $jobKey,
        public readonly string $fileName,
        public readonly array $settings,
        public readonly array $tables,
        public readonly int $tableIndex,
        public readonly string $readAs,
    ) {
    }
}
