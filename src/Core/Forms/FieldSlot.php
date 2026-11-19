<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Storage\Keys;

/** A field slot (`div data-tiffin-field`): a field key and an optional label. */
final class FieldSlot implements Block
{
    public function __construct(
        public readonly string $fieldKey,
        public readonly ?string $label = null,
        public readonly ?Placement $placement = null,
    ) {
        if (!Keys::isValid($fieldKey)) {
            throw new \InvalidArgumentException('The field key is not valid.');
        }
        if ($label !== null && !FormRules::isPlainText($label, 1, FormFormat::MAX_LABEL)) {
            throw new \InvalidArgumentException('The label must be 1 to 60 characters of plain text.');
        }
    }

    public function kind(): string
    {
        return 'field';
    }

    public function placement(): ?Placement
    {
        return $this->placement;
    }
}
