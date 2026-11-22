<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Query\AttachmentSummary;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/**
 * The page of one file attached to a live record (M4 spec section 10.2,
 * P172): who added it (display name), whether it is shown in the page
 * (one of the four image types), and whether the person may replace or
 * remove it.
 */
final class FileScreen
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly RecordRef $record,
        public readonly FieldDef $field,
        public readonly AttachmentSummary $file,
        public readonly ?string $addedBy,
        public readonly bool $inline,
        public readonly bool $canEdit,
    ) {
    }
}
