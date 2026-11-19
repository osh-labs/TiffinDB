<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** A required field an import left empty in $count records; $fieldName is null when the field is gone. */
final class ReportRequired
{
    public function __construct(
        public readonly string $fieldKey,
        public readonly ?string $fieldName,
        public readonly int $count,
    ) {
    }
}
