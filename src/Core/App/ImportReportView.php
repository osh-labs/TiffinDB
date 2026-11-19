<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Io\ImportReport;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/** An import report read from the project's import log (M3 spec section 13.1). */
final class ImportReportView
{
    /**
     * @param list<ReportColumn> $columns
     * @param list<ReportLink> $links
     * @param list<ReportRequired> $requiredEmpty
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly ?TableDef $table,
        public readonly ImportReport $report,
        public readonly array $columns,
        public readonly array $links = [],
        public readonly array $requiredEmpty = [],
    ) {
    }
}
