<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Schema\FieldDef;

/** One search hit: the record, its title, and the matched field other than the title with its text (both null when only the title matched). */
final class SearchResultHit
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $title,
        public readonly ?FieldDef $field,
        public readonly ?string $text,
    ) {
    }
}
