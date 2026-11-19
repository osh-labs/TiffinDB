<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * A file written into an export job folder, for the web layer to send and
 * then hand back to ExportOperations::finish (M3 spec section 10.2).
 */
final class ExportFile
{
    public function __construct(
        public readonly string $jobKey,
        public readonly string $path,
        public readonly string $fileName,
        public readonly string $mediaType,
        public readonly int $size,
    ) {
    }
}
