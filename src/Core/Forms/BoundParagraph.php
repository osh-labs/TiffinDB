<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A paragraph of a bound form: the document's inline nodes. */
final class BoundParagraph implements BoundNode
{
    /** @param list<Inline> $children */
    public function __construct(public readonly array $children, public readonly ?string $span = null)
    {
    }

    public function kind(): string
    {
        return 'paragraph';
    }

    public function span(): ?string
    {
        return $this->span;
    }
}
