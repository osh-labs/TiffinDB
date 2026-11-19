<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** A block of a form: section, field slot, heading, paragraph, divider, or image. */
interface Block
{
    public function kind(): string;

    public function placement(): ?Placement;
}
