<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Query\CandidatePage;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/** The relation picker of a live record (M4 spec section 10.1, ADR 0019 decision 9). */
final class PickerScreen
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly RecordRef $record,
        public readonly FieldDef $field,
        public readonly TableDef $other,
        public readonly CandidatePage $page,
        public readonly bool $canAdd,
    ) {
    }
}
