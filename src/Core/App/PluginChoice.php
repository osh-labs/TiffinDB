<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/** An enabled import plugin offered on the first import step (M3 spec section 13.1). */
final class PluginChoice
{
    /** @param list<string> $extensions */
    public function __construct(
        public readonly string $ref,
        public readonly string $name,
        public readonly array $extensions,
    ) {
    }
}
