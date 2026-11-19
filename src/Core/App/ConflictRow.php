<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\FieldDef;

/** One differing field of an edit conflict: the saved canonical value and the text the person posted. */
final class ConflictRow
{
    public function __construct(
        public readonly FieldDef $field,
        public readonly string $label,
        public readonly mixed $saved,
        public readonly string $yours,
    ) {
    }
}
