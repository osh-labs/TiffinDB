<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * A node of a bound form. Templates branch on kind() ('section', 'field',
 * 'heading', 'paragraph', 'divider') and read properties; they never name
 * a class.
 *
 * Every kind carries `public readonly ?string $span`, returned by span().
 */
interface BoundNode
{
    public function kind(): string;

    /** The block's `data-tiffin-span` ('1' to '12' or 'full'), or null (M5 spec section 8). */
    public function span(): ?string;
}
