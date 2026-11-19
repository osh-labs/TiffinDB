<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** One attachment linked from a live record in scope (M3 spec section 3.3). */
final class ExportAttachment
{
    public function __construct(
        public readonly int $id,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $mediaType,
        public readonly string $originalName,
        public readonly string $createdAt,
    ) {
    }
}
