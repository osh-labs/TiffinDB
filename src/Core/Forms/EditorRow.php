<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Schema\FieldDef;

/** One row of the form editor, in reading order (M5 spec section 6.1). */
final class EditorRow
{
    public function __construct(
        public readonly string $path,
        /** 'section', 'field', 'missing' (a slot naming no field of the table), 'heading', 'paragraph', 'divider', or 'image' */
        public readonly string $kind,
        /** 0 in the root, 1 in a section */
        public readonly int $depth,
        /** for 'field' */
        public readonly ?FieldDef $field,
        /** for 'field' and 'missing' */
        public readonly ?string $fieldKey,
        /** the slot's own label, for 'field' */
        public readonly ?string $label,
        /** for 'section' */
        public readonly ?string $legend,
        /** 'auto', 'full', 'two-thirds', 'half', 'one-third', or 'other' (a span the editor does not offer) */
        public readonly string $width,
        /** the stored span, for 'other' */
        public readonly ?string $span,
        /** carries data-tiffin-col or data-tiffin-row */
        public readonly bool $placed,
        /** plain text: heading or paragraph text, image alt, '' otherwise */
        public readonly string $summary,
        /** for 'image' */
        public readonly ?int $attachmentId,
        /** for 'section': number of child rows */
        public readonly int $children,
        public readonly bool $canMoveUp,
        public readonly bool $canMoveDown,
    ) {
    }
}
