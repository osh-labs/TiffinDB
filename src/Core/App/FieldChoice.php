<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** A field of an existing table a column may go into (review page); $linkedTable names a relation's other table. */
final class FieldChoice
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $linkedTable = null,
    ) {
    }
}
