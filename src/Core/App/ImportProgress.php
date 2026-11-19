<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** Progress of a read or prepare chunk (M3 spec section 13.1); $total is null when unknown. */
final class ImportProgress
{
    public function __construct(
        public readonly int $done,
        public readonly ?int $total,
        public readonly bool $finished,
    ) {
    }
}
