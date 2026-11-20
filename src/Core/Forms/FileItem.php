<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A file shown on an attachment field: its attachment id, original name, media type, and size in bytes. */
final class FileItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $mediaType,
        public readonly int $size,
    ) {
    }
}
