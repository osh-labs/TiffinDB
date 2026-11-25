<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/** The form upload page (M5 spec section 9.1): the table and the size cap of a form file. */
final class FormUploadScreen
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly int $maxBytes,
    ) {
    }
}
