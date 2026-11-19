<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * A `fieldset`: an optional role word and legend, then blocks other than
 * sections.
 */
final class Section implements Block
{
    /** @var list<Block> */
    public readonly array $children;

    /** @param list<Block> $children */
    public function __construct(
        public readonly ?string $role,
        public readonly ?string $legend,
        array $children,
        public readonly ?Placement $placement = null,
    ) {
        if ($role !== null && !FormRules::isRole($role)) {
            throw new \InvalidArgumentException('The section role is not valid.');
        }
        if ($legend !== null && !FormRules::isPlainText($legend, 1, FormFormat::MAX_LEGEND)) {
            throw new \InvalidArgumentException('The legend must be 1 to 100 characters of plain text.');
        }
        if (!array_is_list($children)) {
            throw new \InvalidArgumentException('Section children must be a list.');
        }
        foreach ($children as $child) {
            if (!$child instanceof Block || $child instanceof self) {
                throw new \InvalidArgumentException('A section holds blocks other than sections.');
            }
        }
        $this->children = $children;
    }

    public function kind(): string
    {
        return 'section';
    }

    public function placement(): ?Placement
    {
        return $this->placement;
    }
}
