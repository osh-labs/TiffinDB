<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * One record found by Records::search(): its title, and the first
 * searchable field other than the title whose text holds the search text,
 * with that text (both null when only the title matched).
 */
final class SearchHit
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $title,
        public readonly ?string $fieldKey,
        public readonly ?string $text,
    ) {
    }
}
