<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * Grid placement of a block (`docs/formats/tiffin.md` section 12.1): first
 * column 1 to 12, row 1 to 50, span '1' to '12' or 'full'. At least one is
 * given; a block without placement has none (null).
 */
final class Placement
{
    public function __construct(
        public readonly ?int $col = null,
        public readonly ?int $row = null,
        public readonly ?string $span = null,
    ) {
        if ($col === null && $row === null && $span === null) {
            throw new \InvalidArgumentException('A placement needs at least one value.');
        }
        if ($col !== null && ($col < 1 || $col > FormFormat::MAX_COLUMN)) {
            throw new \InvalidArgumentException('The column must be 1 to 12.');
        }
        if ($row !== null && ($row < 1 || $row > FormFormat::MAX_ROW)) {
            throw new \InvalidArgumentException('The row must be 1 to 50.');
        }
        if ($span !== null && !FormRules::isSpan($span)) {
            throw new \InvalidArgumentException('The span must be 1 to 12 or full.');
        }
    }

    /**
     * $placement with its span replaced by $span, column and row kept; null
     * when nothing is left.
     */
    public static function withSpanOf(?self $placement, ?string $span): ?self
    {
        $col = $placement?->col;
        $row = $placement?->row;
        if ($col === null && $row === null && $span === null) {
            return null;
        }

        return new self($col, $row, $span);
    }

    /** True when the block has a column or a row (an explicit position). */
    public function isPlaced(): bool
    {
        return $this->col !== null || $this->row !== null;
    }

    public function isFull(): bool
    {
        return $this->span === 'full';
    }
}
