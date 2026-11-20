<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * One file attached to a record, as the record layer lists it (no file
 * content): its attachment id, cleaned original name, the media type
 * decided when it was added, its size, and when, by whom, and with what
 * SHA-256 it was added (M4 spec section 4.1).
 */
final class AttachmentSummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $originalName,
        public readonly string $mediaType,
        public readonly int $size,
        public readonly string $createdAt = '',
        public readonly ?string $createdBy = null,
        public readonly string $sha256 = '',
    ) {
    }
}
