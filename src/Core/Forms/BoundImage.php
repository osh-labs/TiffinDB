<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * An image block of a form joined with what Core\App checked (M4 spec
 * section 9, P173): servable is true only when the attachment is linked
 * from a live record through an attachment field and has one of the four
 * inline image types. A template shows nothing for an image that is not
 * servable.
 */
final class BoundImage implements BoundNode
{
    public function __construct(
        public readonly int $attachmentId,
        public readonly string $alt,
        public readonly bool $servable,
        public readonly ?Placement $placement = null,
    ) {
        if ($attachmentId < 1) {
            throw new \InvalidArgumentException('The attachment id must be 1 or more.');
        }
    }

    public function kind(): string
    {
        return 'image';
    }
}
