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
 * The upload page of an attachment field of a live record (M4 spec
 * section 10.2): the file being replaced, when any; the core size cap
 * (the web layer shows the smaller of it and the PHP limits); whether the
 * field takes images only; and how many files the field holds now.
 */
final class UploadScreen
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly RecordRef $record,
        public readonly FieldDef $field,
        public readonly ?AttachmentSummary $replacing,
        public readonly int $maxBytes,
        public readonly bool $imagesOnly,
        public readonly int $count,
    ) {
    }
}
