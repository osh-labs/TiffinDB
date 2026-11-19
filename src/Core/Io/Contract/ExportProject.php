<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** Project facts for an export (M3 spec section 3.3). */
final class ExportProject
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly int $fileFormatVersion,
        public readonly string $exportedAt,
        public readonly string $exportedWith,
    ) {
    }
}
