<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** One row of `_tiffin_attachments` (format section 8). */
final class AttachmentRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $mediaType,
        public readonly string $originalName,
        public readonly string $createdAt,
        public readonly ?string $createdBy,
    ) {
    }

    public function summary(): AttachmentSummary
    {
        return new AttachmentSummary($this->id, $this->originalName, $this->mediaType, $this->size, $this->createdAt, $this->createdBy, $this->sha256);
    }
}
