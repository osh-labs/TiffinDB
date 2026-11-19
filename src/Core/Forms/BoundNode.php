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
 */
interface BoundNode
{
    public function kind(): string;
}
