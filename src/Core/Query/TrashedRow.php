<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * A record in the trash as Trash::list returns it (P74): its id, when and
 * by whom it was moved, and its title text (null without a title field or
 * value).
 */
final class TrashedRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $trashedAt,
        public readonly ?string $trashedBy,
        public readonly ?string $title,
    ) {
    }
}
