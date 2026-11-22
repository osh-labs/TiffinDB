<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * A record the relation picker offers (M4 spec section 4.4): its id and
 * title, and, when the other side allows one link, the record of this
 * table that already holds it (choosing it would be refused).
 */
final class Candidate
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $title,
        public readonly ?LinkedRecord $takenBy = null,
    ) {
    }
}
