<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * The result of Trash::empty (M4 spec section 4.5): records deleted and
 * the hashes of the attachment rows deleted with them, for
 * Attachments::deleteFiles after the write returns.
 */
final class EmptiedTrash
{
    /** @param list<string> $freedHashes */
    public function __construct(
        public readonly int $records,
        public readonly array $freedHashes,
    ) {
    }
}
