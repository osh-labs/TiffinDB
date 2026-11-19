<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** One record as the record layer reads it (`docs/specs/M2-records.md` section 3.1). */
final class RecordData
{
    /**
     * @param array<string, mixed> $values field key => canonical value (null when empty), every field with a column
     * @param array<string, list<LinkedRecord>> $links relation field key => live targets, in link order
     * @param array<string, list<AttachmentSummary>> $attachments attachment field key => files, in link order
     */
    public function __construct(
        public readonly int $id,
        public readonly array $values,
        public readonly array $links,
        public readonly array $attachments,
        public readonly string $createdAt,
        public readonly ?string $createdBy,
        public readonly string $updatedAt,
        public readonly ?string $updatedBy,
        public readonly ?string $trashedAt,
        public readonly ?string $trashedBy,
        /** RecordVersion::of() of the stored row and its links. */
        public readonly string $version,
    ) {
    }
}
