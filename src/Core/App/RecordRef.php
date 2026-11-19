<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** A record named in a notice: its id and title (null when it has none). */
final class RecordRef
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $title,
    ) {
    }
}
