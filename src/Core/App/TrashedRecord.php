<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** A record in the trash (M1 spec section 9.6): who trashed it is a person key, the title its title field's text. */
final class TrashedRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $trashedAt,
        public readonly ?string $trashedBy,
        public readonly ?string $title,
    ) {
    }
}
