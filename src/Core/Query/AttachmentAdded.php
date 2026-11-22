<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * The result of Attachments::add (M4 spec section 4.2.1): the new file,
 * the file it replaced (or null), and the hashes of rows the write
 * deleted, for Attachments::deleteFiles after the write returns.
 */
final class AttachmentAdded
{
    /** @param list<string> $freedHashes */
    public function __construct(
        public readonly AttachmentSummary $attachment,
        public readonly ?AttachmentSummary $replaced,
        public readonly array $freedHashes,
    ) {
    }
}
