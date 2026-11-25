<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** A plugin whose manifest was read, as its manifest names it (M7 spec section 10). */
final class AboutPlugin
{
    /** @param 'import'|'export' $kind */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $kind,
        public readonly string $licence,
        public readonly bool $enabled,
    ) {
    }
}
