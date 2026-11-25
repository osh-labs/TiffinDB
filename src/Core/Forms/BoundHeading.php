<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A heading of a bound form: level 2 or 3 and the document's inline nodes. */
final class BoundHeading implements BoundNode
{
    /** @param list<Inline> $children */
    public function __construct(public readonly int $level, public readonly array $children, public readonly ?string $span = null)
    {
    }

    public function kind(): string
    {
        return 'heading';
    }

    public function span(): ?string
    {
        return $this->span;
    }
}
