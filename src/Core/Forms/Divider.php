<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** An `hr`. */
final class Divider implements Block
{
    public function __construct(public readonly ?Placement $placement = null)
    {
    }

    public function kind(): string
    {
        return 'divider';
    }

    public function placement(): ?Placement
    {
        return $this->placement;
    }
}
