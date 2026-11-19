<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Io\Contract\Setting;

/** An enabled export plugin on the export page (M3 spec section 13.2); names and labels are plain text (P133). */
final class ExportFormat
{
    /**
     * @param list<string> $scopes `table`, `project`
     * @param list<Setting> $tableSettings
     * @param list<Setting> $projectSettings
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $name,
        public readonly string $extension,
        public readonly array $scopes,
        public readonly array $tableSettings,
        public readonly array $projectSettings,
    ) {
    }
}
