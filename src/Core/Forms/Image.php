<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * An `img`: an attachment id (`src="attachment:<id>"`, never an outside
 * address) and alternative text of 0 to 200 characters.
 */
final class Image implements Block
{
    public function __construct(
        public readonly int $attachmentId,
        public readonly string $alt,
        public readonly ?Placement $placement = null,
    ) {
        if ($attachmentId < 1) {
            throw new \InvalidArgumentException('The attachment id must be 1 or more.');
        }
        if (!FormRules::isPlainText($alt, 0, FormFormat::MAX_ALT)) {
            throw new \InvalidArgumentException('The alternative text must be 0 to 200 characters of plain text.');
        }
    }

    public function kind(): string
    {
        return 'image';
    }

    public function placement(): ?Placement
    {
        return $this->placement;
    }
}
