<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** One file attached to a record, as the record layer lists it (no file content). */
final class AttachmentSummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $originalName,
        public readonly string $mediaType,
        public readonly int $size,
    ) {
    }
}
