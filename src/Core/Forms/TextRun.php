<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * Text in inline content: not empty, no control characters other than tab
 * and line feed. Adjacent runs are not allowed (FormRules::inlines), so a
 * parsed document has exactly one run between two elements.
 */
final class TextRun implements Inline
{
    public function __construct(public readonly string $text)
    {
        if ($text === '' || !FormRules::isRunText($text)) {
            throw new \InvalidArgumentException('A text run must be non-empty text without control characters.');
        }
    }

    public function kind(): string
    {
        return 'text';
    }
}
