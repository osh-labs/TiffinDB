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

    /** This slot with another label (null: none); the same object when it is unchanged. */
    public function withLabel(?string $label): self
    {
        return $label === $this->label ? $this : new self($this->fieldKey, $label, $this->placement);
    }

    /**
     * This slot with another span (null: none), column and row kept; the
     * same object when it is unchanged.
     */
    public function withSpan(?string $span): self
    {
        if ($span === $this->placement?->span) {
            return $this;
        }

        return new self($this->fieldKey, $this->label, Placement::withSpanOf($this->placement, $span));
    }

    public function placement(): ?Placement
    {
        return $this->placement;
    }
}
