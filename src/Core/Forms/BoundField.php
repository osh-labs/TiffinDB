<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Schema\FieldDef;

/**
 * A field slot of a bound form (M2 spec section 5.7): the field, the label
 * to show, the text for its input, its value, problems, links, and files.
 */
final class BoundField implements BoundNode
{
    /**
     * @param list<Problem> $problems
     * @param list<LinkItem> $links
     * @param list<FileItem> $files
     */
    public function __construct(
        public readonly FieldDef $field,
        public readonly string $label,
        public readonly string $inputText,
        public readonly bool $submitted,
        public readonly mixed $value,
        public readonly array $problems,
        public readonly bool $editable,
        public readonly array $links,
        public readonly array $files,
        public readonly bool $spanFull,
        public readonly bool $labelHidden,
        public readonly bool $isNew,
    ) {
    }

    public function kind(): string
    {
        return 'field';
    }
}
