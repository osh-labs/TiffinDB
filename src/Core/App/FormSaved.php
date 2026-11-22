<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * A form save that went through (M5 spec section 9.2): the table, whether
 * the stored text is now the generated one, whether anything was written,
 * and (source and upload only) whether the stored text differs from the
 * text as typed or uploaded, line breaks aside.
 */
final class FormSaved
{
    public function __construct(
        public readonly string $tableKey,
        public readonly bool $generated,
        public readonly bool $changed,
        public readonly bool $rewritten,
    ) {
    }
}
