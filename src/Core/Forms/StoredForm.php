<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** One row of `_tiffin_forms` (`docs/formats/tiffin.md` section 4.6). */
final class StoredForm
{
    public function __construct(
        public readonly string $key,
        public readonly string $tableKey,
        public readonly string $name,
        public readonly int $format,
        public readonly string $html,
        public readonly string $css,
        public readonly bool $isDefault,
        public readonly bool $generated,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }
}
