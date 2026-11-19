<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** One live record (M3 spec section 3.3); values by field key, every field of the table. */
final class ExportRecord
{
    /** @param array<string, ExportValue> $values */
    public function __construct(
        public readonly int $id,
        public readonly string $createdAt,
        public readonly ?string $createdBy,
        public readonly string $updatedAt,
        public readonly ?string $updatedBy,
        public readonly array $values,
    ) {
    }
}
