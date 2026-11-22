<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/**
 * The reset confirmation page (M5 spec section 9.1): whether the default
 * form is already the standard one (a stored row marked generated whose
 * text is the generated text).
 */
final class FormResetScreen
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly bool $isStandard,
    ) {
    }
}
