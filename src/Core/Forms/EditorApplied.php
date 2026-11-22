<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Error\Problem;

/**
 * The result of FormEditor::apply (M5 spec section 6.2): the document with
 * the posted labels, legends, and widths applied, the problems found, and
 * the refused texts as typed (trimmed), keyed by the problem's input name.
 */
final class EditorApplied
{
    /**
     * @param list<Problem> $problems
     * @param array<string, string> $typed
     */
    public function __construct(
        public readonly FormDocument $document,
        public readonly array $problems,
        public readonly array $typed,
    ) {
    }
}
