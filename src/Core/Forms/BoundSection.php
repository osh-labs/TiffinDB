<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * A section of a bound form. grid: the role is `details` (laid out as the
 * auto-fit Details grid on wide screens). columns: a direct bound child
 * has a span of 1 to 12, so the section is laid out in twelve columns
 * instead, whatever its role (M5 spec section 8).
 */
final class BoundSection implements BoundNode
{
    /** @param list<BoundNode> $children */
    public function __construct(
        public readonly ?string $role,
        public readonly ?string $legend,
        public readonly array $children,
        public readonly bool $grid,
        public readonly bool $spanFull,
        public readonly bool $columns = false,
        public readonly ?string $span = null,
    ) {
    }

    public function kind(): string
    {
        return 'section';
    }

    public function span(): ?string
    {
        return $this->span;
    }
}
