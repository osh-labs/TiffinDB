<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A `strong` element with inline content. */
final class Strong implements Inline
{
    /** @var list<Inline> */
    public readonly array $children;

    /** @param list<Inline> $children */
    public function __construct(array $children)
    {
        $this->children = FormRules::inlines($children);
    }

    public function kind(): string
    {
        return 'strong';
    }
}
