<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** A record a relation field links to, or a record in a pick list. */
final class LinkedRecord
{
    public function __construct(
        public readonly string $tableKey,
        public readonly int $id,
        /** The title field's canonical text (toText), or null when there is no title field or no value. */
        public readonly ?string $title,
    ) {
    }
}
