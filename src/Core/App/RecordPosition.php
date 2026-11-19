<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** A record's place in its list (1-based) and its neighbours with their titles. */
final class RecordPosition
{
    public function __construct(
        public readonly int $index,
        public readonly int $total,
        public readonly ?int $previousId,
        public readonly ?string $previousTitle,
        public readonly ?int $nextId,
        public readonly ?string $nextTitle,
    ) {
    }
}
