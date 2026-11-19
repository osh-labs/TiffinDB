<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A `p` with inline content. */
final class Paragraph implements Block
{
    /** @var list<Inline> */
    public readonly array $children;

    /** @param list<Inline> $children */
    public function __construct(array $children, public readonly ?Placement $placement = null)
    {
        $this->children = FormRules::inlines($children);
    }

    public function kind(): string
    {
        return 'paragraph';
    }

    public function placement(): ?Placement
    {
        return $this->placement;
    }
}
