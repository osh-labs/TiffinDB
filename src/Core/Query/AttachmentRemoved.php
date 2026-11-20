<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * The result of Attachments::remove (M4 spec section 4.2.2): the file
 * unlinked and the hashes of rows the write deleted, for
 * Attachments::deleteFiles after the write returns.
 */
final class AttachmentRemoved
{
    /** @param list<string> $freedHashes */
    public function __construct(
        public readonly AttachmentSummary $attachment,
        public readonly array $freedHashes,
    ) {
    }
}
