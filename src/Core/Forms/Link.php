<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * An `a` element: an http or https address accepted by the url field
 * type's strict rules, kept as given, and inline content without links.
 * M2 shows link text as plain text (ADR 0014 decision 1).
 */
final class Link implements Inline
{
    /** @var list<Inline> */
    public readonly array $children;

    /** @param list<Inline> $children */
    public function __construct(public readonly string $href, array $children)
    {
        if (!FormRules::isHref($href)) {
            throw new \InvalidArgumentException('The link address is not an accepted http or https address.');
        }
        $this->children = FormRules::inlines($children);
        if (FormRules::holdsLink($this->children)) {
            throw new \InvalidArgumentException('A link may not hold another link.');
        }
    }

    public function kind(): string
    {
        return 'link';
    }
}
