<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A section of a bound form. grid: the role is `details` (laid out in columns on wide screens). */
final class BoundSection implements BoundNode
{
    /** @param list<BoundNode> $children */
    public function __construct(
        public readonly ?string $role,
        public readonly ?string $legend,
        public readonly array $children,
        public readonly bool $grid,
        public readonly bool $spanFull,
    ) {
    }

    public function kind(): string
    {
        return 'section';
    }
}
