<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** An `h2` or `h3` with inline content. */
final class Heading implements Block
{
    /** @var list<Inline> */
    public readonly array $children;

    /** @param list<Inline> $children */
    public function __construct(
        public readonly int $level,
        array $children,
        public readonly ?Placement $placement = null,
    ) {
        if ($level !== 2 && $level !== 3) {
            throw new \InvalidArgumentException('The heading level must be 2 or 3.');
        }
        $this->children = FormRules::inlines($children);
    }

    public function kind(): string
    {
        return 'heading';
    }

    public function placement(): ?Placement
    {
        return $this->placement;
    }
}
