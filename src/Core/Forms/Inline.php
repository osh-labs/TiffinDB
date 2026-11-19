<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/** Inline content of a heading, paragraph, or inline element: text, strong, em, br, or a. */
interface Inline
{
    public function kind(): string;
}
