<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * A stored file the person may download (M4 spec section 10.2, ADR 0018
 * decision 5): its path in the store, the media type to send (the stored
 * type for the four image types, else application/octet-stream), the
 * cleaned original name, its size on disk, and whether to show it inline.
 */
final class ServedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $mediaType,
        public readonly string $fileName,
        public readonly int $size,
        public readonly bool $inline,
    ) {
    }
}
